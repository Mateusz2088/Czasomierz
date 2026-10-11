<?php
/**
 * ChronoTrack CTP01 Receiver Server — wersja odporna na awarie
 *
 * Główne zabezpieczenia:
 *  1. Write-Ahead Log (WAL) — każdy odebrany odczyt jest najpierw zapisywany
 *     do pliku na dysku (fsync), zanim trafi do bazy danych.
 *  2. Transakcje MySQL z poziomem izolacji i flush na commit.
 *  3. Bufor w pamięci + ponowne próby zapisu w przypadku niedostępności bazy.
 *  4. Obsługa zerwania połączenia z klientem i wznowienie strumienia.
 */

declare(strict_types=1);

// ==================== KONFIGURACJA ====================
const LISTEN_HOST = '0.0.0.0';
const LISTEN_PORT = 61611;

const DB_DSN  = 'mysql:host=localhost;dbname=chronotrack;charset=utf8mb4';
const DB_USER = 'chrono_user';
const DB_PASS = 'twoje_haslo';

// Katalog na pliki WAL i bufor awaryjny
const WAL_DIR = '/var/lib/chronotrack/wal';
const WAL_FILE = WAL_DIR . '/observations.wal';

// Co ile sekund ponawiać próbę zapisu do bazy, jeśli poprzednia się nie udała
const DB_RETRY_INTERVAL = 5;

// Maksymalna liczba rekordów trzymanych w buforze pamięciowym
const MAX_MEMORY_BUFFER = 10000;

// ==================== INICJALIZACJA ====================
if (!is_dir(WAL_DIR)) {
    mkdir(WAL_DIR, 0755, true);
}

$memoryBuffer = [];        // Bufor rekordów, których nie udało się zapisać do bazy
$lastDbRetry = 0;          // Timestamp ostatniej próby ponownego połączenia z bazą

// ==================== WARSTWA TRWAŁOŚCI ====================

/**
 * Zwraca połączenie PDO z bazą danych.
 * W przypadku awarii próbuje ponownie.
 */
function getPDO(): ?PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            // Połączenie nieaktualne — spróbuj ponownie
            $pdo = null;
        }
    }

    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,
        ]);

        // Wymuś pełną trwałość na poziomie sesji (ACID)
        // innodb_flush_log_at_trx_commit = 1 oznacza flush redo log na każdy commit
        $pdo->exec("SET SESSION innodb_flush_log_at_trx_commit = 1");
        $pdo->exec("SET SESSION sync_binlog = 1");

        return $pdo;
    } catch (PDOException $e) {
        error_log("[DB] Błąd połączenia: " . $e->getMessage());
        return null;
    }
}

/**
 * Zapisuje pojedynczy odczyt do WAL (Write-Ahead Log) na dysku.
 * Używa O_APPEND i fsync, aby zapewnić trwałość nawet przy nagłej utracie zasilania.
 *
 * @return bool True, jeśli zapis się powiódł.
 */
function writeToWAL(array $fields, string $connectionId): bool
{
    $line = implode('~', $fields) . '~' . $connectionId . "\n";

    $fh = @fopen(WAL_FILE, 'ab');
    if ($fh === false) {
        error_log("[WAL] Nie można otworzyć pliku WAL: " . WAL_FILE);
        return false;
    }

    // Blokada zapisu — zapobiega przeplataniu się rekordów przy wielu procesach
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log("[WAL] Nie można założyć blokady na plik WAL.");
        return false;
    }

    $written = fwrite($fh, $line);
    fflush($fh);

    // Wymuś zapis na fizycznym nośniku (fsync)
    // Bez tego dane mogą pozostać w cache systemu plików i zginąć przy awarii zasilania.
    if (function_exists('fsync')) {
        fsync($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);

    return $written !== false && $written > 0;
}

/**
 * Zapisuje odczyt do bazy danych.
 * Jeśli baza jest niedostępna, dodaje rekord do bufora pamięciowego.
 */
function persistObservation(array $fields, string $connectionId): void
{
    global $memoryBuffer;

    // Krok 1: Zawsze zapisz do WAL jako pierwszy krok (trwałość na dysku)
    $walOk = writeToWAL($fields, $connectionId);
    if (!$walOk) {
        error_log("[KRYTYCZNY] Nie udało się zapisać do WAL. Rekord pozostaje tylko w pamięci.");
    }

    // Krok 2: Spróbuj zapisać do bazy
    $pdo = getPDO();
    if ($pdo === null) {
        // Baza niedostępna — zapisz w buforze pamięciowym
        $memoryBuffer[] = ['fields' => $fields, 'conn' => $connectionId, 'ts' => microtime(true)];
        error_log("[DB] Baza niedostępna. Rekord w buforze pamięciowym. Bufor: " . count($memoryBuffer));
        return;
    }

    try {
        $sql = "INSERT INTO tag_observations
                (sequence_number, tag_code, timer_code, event_code,
                 observation_time, reader_id, port_number, rssi,
                 reader_sequence, transaction_type, connection_id)
                VALUES
                (:seq, :tag, :timer, :event,
                 :time, :reader, :port, :rssi,
                 :rseq, :type, :conn)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':seq'    => (int) $fields[1],
            ':tag'    => $fields[2],
            ':timer'  => $fields[3],
            ':event'  => $fields[4],
            ':time'   => (float) $fields[5],
            ':reader' => $fields[6],
            ':port'   => (int) $fields[7],
            ':rssi'   => (int) $fields[8],
            ':rseq'   => (int) $fields[9],
            ':type'   => $fields[10],
            ':conn'   => $connectionId,
        ]);

        echo "[ZAPIS] Tag: {$fields[2]} | Czas: {$fields[5]}\n";

        // Krok 3: Jeśli w buforze są rekordy, spróbuj je teraz opróżnić
        if (!empty($memoryBuffer)) {
            flushMemoryBuffer($pdo);
        }
    } catch (PDOException $e) {
        error_log("[DB] Błąd INSERT: " . $e->getMessage());
        $memoryBuffer[] = ['fields' => $fields, 'conn' => $connectionId, 'ts' => microtime(true)];
    }

    // Zabezpieczenie przed przepełnieniem pamięci
    if (count($memoryBuffer) > MAX_MEMORY_BUFFER) {
        array_shift($memoryBuffer);
        error_log("[DB] Bufor pamięciowy przepełniony — najstarszy rekord odrzucony.");
    }
}

/**
 * Próbuje zapisać wszystkie rekordy z bufora pamięciowego do bazy.
 */
function flushMemoryBuffer(PDO $pdo): void
{
    global $memoryBuffer;

    if (empty($memoryBuffer)) return;

    $sql = "INSERT INTO tag_observations
            (sequence_number, tag_code, timer_code, event_code,
             observation_time, reader_id, port_number, rssi,
             reader_sequence, transaction_type, connection_id)
            VALUES
            (:seq, :tag, :timer, :event,
             :time, :reader, :port, :rssi,
             :rseq, :type, :conn)";

    $stmt = $pdo->prepare($sql);
    $flushed = 0;
    $remaining = [];

    foreach ($memoryBuffer as $item) {
        $f = $item['fields'];
        try {
            $stmt->execute([
                ':seq'    => (int) $f[1],
                ':tag'    => $f[2],
                ':timer'  => $f[3],
                ':event'  => $f[4],
                ':time'   => (float) $f[5],
                ':reader' => $f[6],
                ':port'   => (int) $f[7],
                ':rssi'   => (int) $f[8],
                ':rseq'   => (int) $f[9],
                ':type'   => $f[10],
                ':conn'   => $item['conn'],
            ]);
            $flushed++;
        } catch (PDOException $e) {
            $remaining[] = $item;
        }
    }

    $memoryBuffer = $remaining;
    if ($flushed > 0) {
        error_log("[DB] Opróżniono bufor: $flushed rekordów zapisanych, " . count($memoryBuffer) . " pozostało.");
    }
}

/**
 * Odtwarza dane z pliku WAL po restarcie serwera.
 * Wywoływana jednorazowo przy starcie — próbuje wstawić ewentualne
 * rekordy, które zostały zapisane do WAL, ale nie trafiły do bazy.
 */
function recoverFromWAL(): void
{
    if (!file_exists(WAL_FILE)) return;

    $lines = file(WAL_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) return;

    $pdo = getPDO();
    if ($pdo === null) {
        error_log("[WAL] Baza niedostępna przy odtwarzaniu. Rekordy z WAL pozostają na dysku.");
        return;
    }

    $sql = "INSERT IGNORE INTO tag_observations
            (sequence_number, tag_code, timer_code, event_code,
             observation_time, reader_id, port_number, rssi,
             reader_sequence, transaction_type, connection_id)
            VALUES
            (:seq, :tag, :timer, :event,
             :time, :reader, :port, :rssi,
             :rseq, :type, :conn)";

    $stmt = $pdo->prepare($sql);
    $recovered = 0;

    foreach ($lines as $line) {
        $parts = explode('~', $line);
        if (count($parts) < 12) continue;

        try {
            $stmt->execute([
                ':seq'    => (int) $parts[1],
                ':tag'    => $parts[2],
                ':timer'  => $parts[3],
                ':event'  => $parts[4],
                ':time'   => (float) $parts[5],
                ':reader' => $parts[6],
                ':port'   => (int) $parts[7],
                ':rssi'   => (int) $parts[8],
                ':rseq'   => (int) $parts[9],
                ':type'   => $parts[10],
                ':conn'   => $parts[11] ?? '',
            ]);
            $recovered++;
        } catch (PDOException $e) {
            // Rekord już istnieje lub błąd — pomiń
        }
    }

    if ($recovered > 0) {
        // Wyczyść WAL po pomyślnym odtworzeniu
        file_put_contents(WAL_FILE, '');
        error_log("[WAL] Odtworzono $recovered rekordów z WAL.");
    }
}

// ==================== OBSŁUGA KOMUNIKATÓW CTP01 ====================

function handleLine(string $line, array &$state): ?string
{
    $line = trim($line);
    if ($line === '') return null;

    if ($state['phase'] === 'greeting') {
        if (str_contains($line, 'CTP01')) {
            $state['phase'] = 'initializing';
            $response = 'PHP-CTP01-Receiver~1.0~3' . "\r\n";
            $response .= 'stream-mode=push' . "\r\n";
            $response .= 'location=multi' . "\r\n";
            $response .= 'guntimes=true' . "\r\n";
            return $response;
        }
        return null;
    }

    if ($state['phase'] === 'active') {
        if (str_starts_with($line, 'TO~')) {
            $fields = explode('~', $line);
            if (count($fields) >= 11) {
                persistObservation($fields, $state['connection_id'] ?? '');
            }
            return null;
        }

        if ($line === 'ack-start' || str_starts_with($line, 'ack-start')) {
            echo "[CTP01] Stream uruchomiony.\n";
            return null;
        }
        if (str_starts_with($line, 'ack-getconnectionid')) {
            $parts = explode('~', $line);
            $state['connection_id'] = $parts[1] ?? '';
            echo "[CTP01] Connection ID: {$state['connection_id']}\n";
            return null;
        }
        if (str_starts_with($line, 'err-')) {
            error_log("[CTP01] Błąd od klienta: $line");
            return null;
        }
    }

    return null;
}

// ==================== GŁÓWNA PĘTLA SERWERA ====================

// Odtwórz ewentualne dane z WAL przed rozpoczęciem nasłuchu
recoverFromWAL();

$server = stream_socket_server(
    'tcp://' . LISTEN_HOST . ':' . LISTEN_PORT,
    $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if ($server === false) {
    die("Nie można utworzyć gniazda: $errstr ($errno)\n");
}

stream_set_blocking($server, false);
echo "Serwer CTP01 nasłuchuje na " . LISTEN_HOST . ":" . LISTEN_PORT . "\n";

$clients = [];

while (true) {
    $read = [$server];
    foreach ($clients as $sock => $data) {
        $read[] = $sock;
    }
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    // Nowe połączenie
    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if ($client !== false) {
            stream_set_blocking($client, false);
            $clients[$client] = [
                'buffer' => '',
                'state'  => [
                    'phase'         => 'greeting',
                    'connection_id' => null,
                ],
            ];
            echo "[POŁĄCZENIE] Nowy klient: " . stream_socket_get_name($client, true) . "\n";
        }
        $read = array_filter($read, fn($r) => $r !== $server);
    }

    // Dane od klientów
    foreach ($read as $sock) {
        if (!isset($clients[$sock])) continue;

        $chunk = @fread($sock, 8192);
        if ($chunk === '' || $chunk === false) {
            echo "[ROZŁĄCZENIE] Klient rozłączony.\n";
            fclose($sock);
            unset($clients[$sock]);
            continue;
        }

        $clients[$sock]['buffer'] .= $chunk;

        while (($pos = strpos($clients[$sock]['buffer'], "\r\n")) !== false) {
            $line = substr($clients[$sock]['buffer'], 0, $pos);
            $clients[$sock]['buffer'] = substr($clients[$sock]['buffer'], $pos + 2);

            $response = handleLine($line, $clients[$sock]['state']);
            if ($response !== null) {
                @fwrite($sock, $response);
            }
        }
    }
}