<?php
/**
 * ChronoTrack CTP01 Receiver Server — wersja poprawiona
 *
 * Naprawione problemy:
 *  - Przejście fazy initializing -> active (obsługa getconnectionid / ack-start).
 *  - WAL w formacie JSON-lines (odporne na znaki specjalne w polach).
 *  - Okresowy checkpoint WAL (plik nie rośnie w nieskończoność).
 *  - Deduplikacja bufora pamięci w O(1).
 *  - Walidacja pól ramki TO~.
 *  - Timeout bezczynnego klienta.
 *  - Graceful shutdown obsługiwany przez pcntl (jeśli dostępne).
 *
 * UWAGA: format WAL został zmieniony z "pola~pola~..." na JSON-lines,
 *        więc stare pliki .wal nie będą odczytywane po aktualizacji.
 */

declare(strict_types=1);

// ==================== KONFIGURACJA ====================
const LISTEN_HOST = '0.0.0.0';
const LISTEN_PORT = 61611;

$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'app_db';
$user = getenv('DB_USER') ?: 'app_user';
$pass = getenv('DB_PASSWORD') ?: 'app_password';

define('DB_DSN', "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4");
define('DB_USER', $user);
define('DB_PASS', $pass);

const WAL_DIR       = '/var/lib/chronotrack/wal';
const WAL_FILE      = WAL_DIR . '/observations.wal';

const DB_RETRY_INTERVAL       = 5;                // ponawianie flush co 5 s
const MAX_MEMORY_BUFFER       = 10000;            // limit rekordów w pamięci
const WAL_CHECKPOINT_BYTES    = 10 * 1024 * 1024; // 10 MB – próg checkpointu
const WAL_CHECKPOINT_INTERVAL = 60;               // minimalny odstęp checkpointu (s)
const WAL_CHECKPOINT_FORCE    = 300;              // wymuś checkpoint po 5 min

const CLIENT_IDLE_TIMEOUT = 300;                  // 5 minut bezczynności

// ==================== STAN GLOBALNY ====================
if (!is_dir(WAL_DIR)) {
    @mkdir(WAL_DIR, 0755, true);
}

/** @var array<string, array{fields:array, conn:string, ts:float}> */
$memoryBuffer      = [];   // klucz => rekord
$lastDbRetry       = 0;
$lastWalCheckpoint = 0;
$shutdown          = false;

// ==================== OBSŁUGA SYGNAŁÓW ====================
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = function (int $signo) use (&$shutdown): void {
        echo "[SYGNAŁ] Otrzymano $signo – zamykanie...\n";
        $shutdown = true;
    };
    @pcntl_signal(SIGTERM, $handler);
    @pcntl_signal(SIGINT, $handler);
}

// ==================== WARSTWA TRWAŁOŚCI ====================
function getPDO(): ?PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            $pdo = null;
        }
    }

    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET SESSION innodb_flush_log_at_trx_commit = 1");
        $pdo->exec("SET SESSION sync_binlog = 1");
        $pdo->exec("SET SESSION wait_timeout = 28800");
        return $pdo;
    } catch (PDOException $e) {
        error_log("[DB] Błąd połączenia: " . $e->getMessage());
        return null;
    }
}

/**
 * Klucz rekordu — teraz uwzględnia connection_id, żeby dwa różne
 * strumienie mogły niezależnie raportować ten sam odczyt.
 */
function observationKey(array $fields, string $connectionId): string
{
    return implode('|', [
        (string) ($fields[1] ?? ''),
        (string) ($fields[2] ?? ''),
        (string) ($fields[6] ?? ''),
        (string) ($fields[5] ?? ''),
        $connectionId,
    ]);
}

/**
 * Walidacja ramki TO~:
 *  - co najmniej 11 pól,
 *  - indeksy 1, 5, 7, 8, 9 muszą być liczbowe.
 */
function validateFields(array $fields): bool
{
    if (count($fields) < 11) return false;
    foreach ([1, 5, 7, 8, 9] as $i) {
        if (!isset($fields[$i]) || !is_numeric($fields[$i])) return false;
    }
    return true;
}

function enqueueMemoryRecord(array $fields, string $connectionId): void
{
    global $memoryBuffer;
    $key = observationKey($fields, $connectionId);
    if (isset($memoryBuffer[$key])) return;

    $memoryBuffer[$key] = [
        'fields' => $fields,
        'conn'   => $connectionId,
        'ts'     => microtime(true),
    ];

    while (count($memoryBuffer) > MAX_MEMORY_BUFFER) {
        $firstKey = array_key_first($memoryBuffer);
        if ($firstKey === null) break;
        unset($memoryBuffer[$firstKey]);
        error_log("[DB] Bufor pamięciowy przepełniony — najstarszy rekord odrzucony.");
    }
}

/**
 * Zapis do WAL w formacie JSON-lines. Zapewnia trwałość i odporne
 * parsowanie (znaki ~ i \ w polach nie psują struktury).
 */
function writeToWAL(array $fields, string $connectionId): bool
{
    $payload = json_encode(
        ['f' => $fields, 'c' => $connectionId, 't' => time()],
        JSON_UNESCAPED_UNICODE
    );
    if ($payload === false) {
        error_log("[WAL] Błąd serializacji JSON.");
        return false;
    }

    $fh = @fopen(WAL_FILE, 'ab');
    if ($fh === false) {
        error_log("[WAL] Nie można otworzyć pliku WAL: " . WAL_FILE);
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log("[WAL] Nie można założyć blokady na plik WAL.");
        return false;
    }

    $written = fwrite($fh, $payload . "\n");
    fflush($fh);
    if (function_exists('fsync')) {
        @fsync($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    return $written !== false && $written > 0;
}

function buildInsertStatement(PDO $pdo): PDOStatement
{
    return $pdo->prepare(
        "INSERT IGNORE INTO tag_observations
           (sequence_number, tag_code, timer_code, event_code,
            observation_time, reader_id, port_number, rssi,
            reader_sequence, transaction_type, connection_id)
         VALUES
           (:seq, :tag, :timer, :event,
            :time, :reader, :port, :rssi,
            :rseq, :type, :conn)"
    );
}

function bindAndExecute(PDOStatement $stmt, array $fields, string $connectionId): void
{
    $stmt->execute([
        ':seq'    => (int)   $fields[1],
        ':tag'    => (string) $fields[2],
        ':timer'  => (string) $fields[3],
        ':event'  => (string) $fields[4],
        ':time'   => (float) $fields[5],
        ':reader' => (string) $fields[6],
        ':port'   => (int)   $fields[7],
        ':rssi'   => (int)   $fields[8],
        ':rseq'   => (int)   $fields[9],
        ':type'   => (string) $fields[10],
        ':conn'   => $connectionId,
    ]);
}

function persistObservation(array $fields, string $connectionId): void
{
    global $memoryBuffer;

    if (!validateFields($fields)) {
        error_log("[WALIDACJA] Nieprawidłowa ramka TO: " . substr(implode('~', $fields), 0, 200));
        return;
    }

    // 1. WAL — trwałość na dysku
    if (!writeToWAL($fields, $connectionId)) {
        error_log("[KRYTYCZNY] Nie udało się zapisać do WAL.");
    }

    // 2. Baza
    $pdo = getPDO();
    if ($pdo === null) {
        enqueueMemoryRecord($fields, $connectionId);
        error_log("[DB] Baza niedostępna. Bufor: " . count($memoryBuffer));
        return;
    }

    try {
        bindAndExecute(buildInsertStatement($pdo), $fields, $connectionId);
        echo "[ZAPIS] Tag: {$fields[2]} | Czas: {$fields[5]}\n";
    } catch (PDOException $e) {
        error_log("[DB] Błąd INSERT: " . $e->getMessage());
        enqueueMemoryRecord($fields, $connectionId);
        return;
    }

    // 3. Opróżnij bufor (jeśli coś zalega)
    if (!empty($memoryBuffer)) {
        flushMemoryBuffer($pdo);
    }
}

function flushMemoryBuffer(PDO $pdo): void
{
    global $memoryBuffer;
    if (empty($memoryBuffer)) return;

    $stmt    = buildInsertStatement($pdo);
    $flushed = 0;

    foreach ($memoryBuffer as $key => $item) {
        try {
            bindAndExecute($stmt, $item['fields'], (string) $item['conn']);
            unset($memoryBuffer[$key]);
            $flushed++;
        } catch (PDOException $e) {
            // rekord zostaje w buforze i będzie ponowiony przy następnym flushu
        }
    }

    if ($flushed > 0) {
        error_log("[DB] Opróżniono bufor: $flushed rekordów, pozostało: " . count($memoryBuffer));
    }
}

function maybeRetryDbFlush(): void
{
    global $lastDbRetry, $memoryBuffer;

    if (empty($memoryBuffer)) return;
    $now = time();
    if (($now - $lastDbRetry) < DB_RETRY_INTERVAL) return;
    $lastDbRetry = $now;

    $pdo = getPDO();
    if ($pdo === null) return;
    flushMemoryBuffer($pdo);
}

/**
 * Okresowy checkpoint WAL — odtwarza (INSERT IGNORE) wszystkie wpisy
 * z pliku i czyści go, jeśli wszystkie operacje się powiodły.
 * Bezpieczne: proces jest jednowątkowy, więc pomiędzy odczytem
 * a truncate nie zostaną dopisane nowe rekordy.
 */
function checkpointWAL(): void
{
    if (!file_exists(WAL_FILE)) return;

    $lines = @file(WAL_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || empty($lines)) return;

    $pdo = getPDO();
    if ($pdo === null) {
        error_log("[WAL] Checkpoint pominięty — baza niedostępna.");
        return;
    }

    $stmt      = buildInsertStatement($pdo);
    $persisted = 0;
    $errors    = 0;

    foreach ($lines as $line) {
        $record = json_decode($line, true);
        if (!is_array($record) || !isset($record['f']) || !is_array($record['f'])) {
            continue;
        }
        $fields = $record['f'];
        $connId = (string) ($record['c'] ?? '');
        if (!validateFields($fields)) continue;

        try {
            bindAndExecute($stmt, $fields, $connId);
            $persisted++;
        } catch (PDOException $e) {
            $errors++;
        }
    }

    if ($errors === 0) {
        file_put_contents(WAL_FILE, '', LOCK_EX);
        error_log("[WAL] Checkpoint OK: $persisted wpisów, plik wyczyszczony.");
    } else {
        error_log("[WAL] Checkpoint częściowy: $persisted OK, $errors błędów — plik pozostawiony.");
    }
}

function maybeCheckpointWAL(): void
{
    global $lastWalCheckpoint;

    $now     = time();
    $elapsed = $now - $lastWalCheckpoint;
    if ($elapsed < WAL_CHECKPOINT_INTERVAL) return;

    if (!file_exists(WAL_FILE)) {
        $lastWalCheckpoint = $now;
        return;
    }
    $size = @filesize(WAL_FILE);
    if ($size === false || $size === 0) {
        $lastWalCheckpoint = $now;
        return;
    }

    // Checkpoint gdy plik duży LUB gdy minęło dużo czasu (nawet mały plik).
    if ($size < WAL_CHECKPOINT_BYTES && $elapsed < WAL_CHECKPOINT_FORCE) {
        return;
    }

    $lastWalCheckpoint = $now;
    checkpointWAL();
}

/**
 * Odtworzenie WAL po starcie — próbuje wstawić wszystkie wpisy
 * do bazy i czyści plik tylko przy pełnym sukcesie.
 */
function recoverFromWAL(): void
{
    if (!file_exists(WAL_FILE)) return;

    $lines = @file(WAL_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || empty($lines)) return;

    $pdo = getPDO();
    if ($pdo === null) {
        error_log("[WAL] Baza niedostępna przy odtwarzaniu. Plik pozostaje.");
        return;
    }

    $stmt      = buildInsertStatement($pdo);
    $recovered = 0;
    $errors    = 0;

    foreach ($lines as $line) {
        $record = json_decode($line, true);
        if (!is_array($record) || !isset($record['f']) || !is_array($record['f'])) {
            continue;
        }
        $fields = $record['f'];
        $connId = (string) ($record['c'] ?? '');
        if (!validateFields($fields)) continue;

        try {
            bindAndExecute($stmt, $fields, $connId);
            $recovered++;
        } catch (PDOException $e) {
            $errors++;
        }
    }

    if ($errors === 0) {
        file_put_contents(WAL_FILE, '', LOCK_EX);
        error_log("[WAL] Odtworzono $recovered rekordów (plik wyczyszczony).");
    } else {
        error_log("[WAL] Odtworzono $recovered, $errors błędów — plik pozostawiony.");
    }
}

// ==================== OBSŁUGA KOMUNIKATÓW CTP01 ====================
function handleLine(string $line, array &$state): ?string
{
    $line = trim($line);
    if ($line === '') return null;

    // ----- Faza powitania -----
    if ($state['phase'] === 'greeting') {
        if (str_contains($line, 'CTP01')) {
            $state['phase'] = 'initializing';
            return "PHP-CTP01-Receiver~1.0~3\r\n"
                 . "stream-mode=push\r\n"
                 . "location=multi\r\n"
                 . "guntimes=true\r\n";
        }
        return null;
    }

    // ----- Faza inicjalizacji: przejście do active -----
    if ($state['phase'] === 'initializing') {
        if (str_starts_with($line, 'getconnectionid')) {
            $connId = bin2hex(random_bytes(8));
            $state['connection_id'] = $connId;
            echo "[CTP01] Przydzielono connection ID: $connId\n";
            return "ack-getconnectionid~$connId\r\n";
        }
        if (str_starts_with($line, 'ack-start')) {
            $state['phase'] = 'active';
            echo "[CTP01] Stream uruchomiony.\n";
            return null;
        }
        if (str_starts_with($line, 'err-')) {
            error_log("[CTP01] Błąd od klienta w inicjalizacji: $line");
            return null;
        }
        // Nieznana linia – logujemy, ale nie przerywamy
        error_log("[CTP01] Nieoczekiwana linia w inicjalizacji: " . substr($line, 0, 100));
        return null;
    }

    // ----- Faza aktywnego strumienia -----
    if ($state['phase'] === 'active') {
        if (str_starts_with($line, 'TO~')) {
            $fields = explode('~', $line);
            if (count($fields) >= 11) {
                persistObservation($fields, $state['connection_id'] ?? '');
            } else {
                error_log("[CTP01] Ramka TO zbyt krótka: " . count($fields) . " pól");
            }
            return null;
        }
        if (str_starts_with($line, 'getconnectionid')) {
            $connId = bin2hex(random_bytes(8));
            $state['connection_id'] = $connId;
            return "ack-getconnectionid~$connId\r\n";
        }
        if (str_starts_with($line, 'ack-start')) {
            echo "[CTP01] Ponowne potwierdzenie startu.\n";
            return null;
        }
        if (str_starts_with($line, 'err-')) {
            error_log("[CTP01] Błąd od klienta: $line");
            return null;
        }
    }

    return null;
}

// ==================== START SERWERA ====================
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

while (!$shutdown) {
    maybeRetryDbFlush();
    maybeCheckpointWAL();

    // Zamknięcie bezczynnych klientów
    $now = time();
    foreach ($clients as $sock => $data) {
        if ($now - $data['last_activity'] > CLIENT_IDLE_TIMEOUT) {
            echo "[TIMEOUT] Klient bezczynny > " . CLIENT_IDLE_TIMEOUT . "s — zamykanie.\n";
            @fclose($sock);
            unset($clients[$sock]);
        }
    }

    $read = [$server];
    foreach ($clients as $sock => $_) {
        $read[] = $sock;
    }
    $write  = null;
    $except = null;

    $ret = @stream_select($read, $write, $except, 0, 200000);
    if ($ret === false) {
        if ($shutdown) break;
        // Chwilowy błąd / EINTR — nie kręć się w pętli
        usleep(100000);
        continue;
    }

    // Nowe połączenie
    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if ($client !== false) {
            stream_set_blocking($client, false);
            $clients[$client] = [
                'buffer'        => '',
                'last_activity' => time(),
                'state'         => [
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
            $name = @stream_socket_get_name($sock, true);
            echo "[ROZŁĄCZENIE] Klient rozłączony" . ($name ? ": $name" : '') . ".\n";
            @fclose($sock);
            unset($clients[$sock]);
            continue;
        }

        $clients[$sock]['last_activity'] = time();
        $clients[$sock]['buffer']       .= $chunk;

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

// ==================== ZAMYKANIE ====================
echo "[ZAMYKANIE] Zatrzymywanie serwera...\n";

// Best effort: spróbuj opróżnić bufor pamięci do bazy przed wyjściem
if (!empty($memoryBuffer)) {
    $pdo = getPDO();
    if ($pdo !== null) {
        flushMemoryBuffer($pdo);
    }
    if (!empty($memoryBuffer)) {
        error_log(
            "[ZAMYKANIE] " . count($memoryBuffer) .
            " rekordów pozostało w buforze pamięci (kopia bezpieczeństwa jest w WAL)."
        );
    }
}

foreach ($clients as $sock => $_) {
    @fclose($sock);
}
@fclose($server);

echo "[ZAMYKANIE] Zakończono.\n";