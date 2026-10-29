<?php
session_start();
require_once __DIR__ . '/DB_CLASS.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$eventCode = $_SESSION['current_event'] ?? null;
$message = '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$formValues = [
    'sequence_number' => '',
    'tag_code' => '',
    'timer_code' => '',
    'observation_time' => date('Y-m-d\TH:i'),
    'reader_id' => '',
    'port_number' => '',
    'rssi' => '0',
    'reader_sequence' => '0',
    'transaction_type' => 'T',
    'connection_id' => '',
];

if ($eventCode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Nieprawidłowy token formularza.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            if (!$editId) {
                throw new RuntimeException('Nieprawidłowy identyfikator wpisu.');
            }

            DB::execute(
                'DELETE FROM tag_observations WHERE id = :id AND event_code = :event',
                ['id' => $editId, 'event' => $eventCode]
            );
        } elseif ($action === 'add' || $action === 'update') {
            $formValues = array_merge($formValues, $_POST);

            foreach (['sequence_number', 'port_number', 'rssi', 'reader_sequence'] as $field) {
                $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
                if ($value === false) {
                    throw new RuntimeException('Pola liczbowe muszą zawierać poprawne liczby całkowite.');
                }
                $formValues[$field] = $value;
            }

            foreach (['tag_code', 'timer_code', 'reader_id', 'transaction_type'] as $field) {
                $formValues[$field] = trim((string) ($_POST[$field] ?? ''));
                if ($formValues[$field] === '') {
                    throw new RuntimeException('Uzupełnij wszystkie wymagane pola.');
                }
            }

            if (strlen($formValues['transaction_type']) !== 1) {
                throw new RuntimeException('Typ transakcji musi mieć jeden znak.');
            }

            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) ($_POST['observation_time'] ?? '')
            );
            if (!$date) {
                throw new RuntimeException('Podaj poprawny czas obserwacji.');
            }

            $timestamp = $date->format('U') . '.' . substr($date->format('u'), 0, 2);
            $params = [
                'sequence_number' => $formValues['sequence_number'],
                'tag_code' => $formValues['tag_code'],
                'timer_code' => $formValues['timer_code'],
                'event_code' => $eventCode,
                'observation_time' => $timestamp,
                'reader_id' => $formValues['reader_id'],
                'port_number' => $formValues['port_number'],
                'rssi' => $formValues['rssi'],
                'reader_sequence' => $formValues['reader_sequence'],
                'transaction_type' => $formValues['transaction_type'],
                'connection_id' => trim((string) ($_POST['connection_id'] ?? '')) ?: null,
            ];

            if ($action === 'add') {
                DB::execute(
                    'INSERT INTO tag_observations
                     (sequence_number, tag_code, timer_code, event_code, observation_time,
                      reader_id, port_number, rssi, reader_sequence, transaction_type, connection_id)
                     VALUES
                     (:sequence_number, :tag_code, :timer_code, :event_code, :observation_time,
                      :reader_id, :port_number, :rssi, :reader_sequence, :transaction_type, :connection_id)',
                    $params
                );
            } else {
                if (!$editId) {
                    throw new RuntimeException('Nieprawidłowy identyfikator wpisu.');
                }

                $params['id'] = $editId;
                DB::execute(
                    'UPDATE tag_observations SET
                        sequence_number = :sequence_number,
                        tag_code = :tag_code,
                        timer_code = :timer_code,
                        observation_time = :observation_time,
                        reader_id = :reader_id,
                        port_number = :port_number,
                        rssi = :rssi,
                        reader_sequence = :reader_sequence,
                        transaction_type = :transaction_type,
                        connection_id = :connection_id
                     WHERE id = :id AND event_code = :event_code',
                    $params
                );
            }
        } else {
            throw new RuntimeException('Nieznana operacja.');
        }

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (Throwable $e) {
        error_log('[manageInserts] ' . $e->getMessage());
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Nie udało się zapisać zmian. Sprawdź dane i spróbuj ponownie.';
    }
}

if ($eventCode && $editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $record = DB::fetchOne(
        'SELECT * FROM tag_observations WHERE id = :id AND event_code = :event',
        ['id' => $editId, 'event' => $eventCode]
    );

    if ($record) {
        $formValues = array_merge($formValues, $record);
        $date = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6f', (float) $record['observation_time'])
        );
        if ($date) {
            $formValues['observation_time'] = $date
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d\TH:i');
        }
    } else {
        $editId = null;
        $message = 'Nie znaleziono wpisu dla aktualnego wydarzenia.';
    }
}

$trackers = $eventCode ? DB::getLapList($eventCode) : [];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel zarządzania wpisami</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header><h1>Panel zarządzania wpisami</h1></header>
<?php include __DIR__ . '/nav.php'; ?>
<main>
<?php if ($eventCode): ?>
    <h2>Aktualne wydarzenie: <?= h($eventCode) ?></h2>

    <?php if ($message !== ''): ?>
        <p role="alert"><?= h($message) ?></p>
    <?php endif; ?>

    <h2><?= $editId ? 'Edytuj wpis' : 'Dodaj wpis' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">
        <?php if ($editId): ?>
            <input type="hidden" name="id" value="<?= h($editId) ?>">
        <?php endif; ?>

        <label>Numer sekwencyjny
            <input type="number" name="sequence_number" required value="<?= h($formValues['sequence_number']) ?>">
        </label>
        <label>Tag
            <input name="tag_code" maxlength="20" required value="<?= h($formValues['tag_code']) ?>">
        </label>
        <label>Kod timera
            <input name="timer_code" maxlength="10" required value="<?= h($formValues['timer_code']) ?>">
        </label>
        <label>Czas obserwacji
            <input type="datetime-local" name="observation_time" required value="<?= h($formValues['observation_time']) ?>">
        </label>
        <label>ID czytnika
            <input name="reader_id" maxlength="20" required value="<?= h($formValues['reader_id']) ?>">
        </label>
        <label>Numer portu
            <input type="number" name="port_number" required value="<?= h($formValues['port_number']) ?>">
        </label>
        <label>RSSI
            <input type="number" name="rssi" required value="<?= h($formValues['rssi']) ?>">
        </label>
        <label>Sekwencja czytnika
            <input type="number" name="reader_sequence" required value="<?= h($formValues['reader_sequence']) ?>">
        </label>
        <label>Typ transakcji
            <input name="transaction_type" maxlength="1" required value="<?= h($formValues['transaction_type']) ?>">
        </label>
        <label>ID połączenia
            <input name="connection_id" maxlength="64" value="<?= h($formValues['connection_id'] ?? '') ?>">
        </label>
        <button type="submit"><?= $editId ? 'Zapisz zmiany' : 'Dodaj wpis' ?></button>
        <?php if ($editId): ?><a href="manageInserts.php">Anuluj</a><?php endif; ?>
    </form>

    <h2>Wpisy</h2>
    <table class="wpisyTable">
        <thead>
        <tr>
            <th>Tag</th><th>Numer startowy</th><th>Imię i nazwisko</th>
            <th>Dystans</th><th>Ilość max okrążeń</th><th>Czas UTC</th>
            <th>Czas lokalny</th><th>Akcje</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($trackers as $tracker): ?>
            <?php
            $date = DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%.6f', (float) $tracker['observation_time'])
            );
            ?>
            <tr>
                <td><?= h($tracker['tag_code']) ?></td>
                <td><?= h($tracker['start_number'] ?? '') ?></td>
                <td><?= h(($tracker['first_name'] ?? '') . ' ' . ($tracker['last_name'] ?? '')) ?></td>
                <td><?= h($tracker['distance'] ?? '') ?></td>
                <td><?= h($tracker['KmPerLap'] ?? '') ?></td>
                <td><?= h($tracker['observation_time']) ?></td>
                <td><?= $date ? h($date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s:u')) : '' ?></td>
                <td>
                    <a href="?edit=<?= h($tracker['id']) ?>">Edytuj</a>
                    <form method="post" onsubmit="return confirm('Usunąć ten wpis?');">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= h($tracker['id']) ?>">
                        <button type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>Brak aktywnego wydarzenia. Ustaw aktualne wydarzenie, aby zarządzać wpisami.</p>
<?php endif; ?>
</main>
</body>
</html>