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

$message = '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: null;
$formValues = [
    'id' => '',
    'start_number' => '',
    'first_name' => '',
    'last_name' => '',
    'event_id' => '',
    'distance_id' => '',
    'addictional_text' => '',
    'gender' => 'M',
    'birth_date' => '',
    'city' => '',
    'country' => '',
    'club' => '',
    'email' => '',
    'phone_number' => '',
    'disability' => '0',
    'about_me' => '',
    'chip_number' => '',
];

$events = DB::getManagementEvents();
if ($selectedEventId === null && !empty($events)) {
    $selectedEventId = (int) $events[0]['id'];
}

if ($selectedEventId !== null) {
    $selectedEventId = (int) $selectedEventId;
}

$distanceOptions = $selectedEventId !== null ? DB::getDistancesByEvent($selectedEventId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Nieprawidłowy token formularza.');
        }

        $action = $_POST['action'] ?? '';
        $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $distanceId = filter_input(INPUT_POST, 'distance_id', FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if (!$editId) {
                throw new RuntimeException('Nieprawidłowy identyfikator zawodnika.');
            }

            DB::deleteParticipantForManagement($editId);
            header('Location: manageUsers.php' . ($eventId ? '?event_id=' . (int)$eventId : ''));
            exit;
        }

        if (!in_array($action, ['add', 'update'], true)) {
            throw new RuntimeException('Nieznana operacja.');
        }

        if (!$eventId || !$distanceId) {
            throw new RuntimeException('Wybierz poprawne wydarzenie i dystans.');
        }

        $formValues = array_merge($formValues, $_POST);

        $startNumber = filter_var($_POST['start_number'] ?? null, FILTER_VALIDATE_INT);
        if ($startNumber === false || $startNumber < 1) {
            throw new RuntimeException('Numer startowy musi być liczbą całkowitą większą od zera.');
        }

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $gender = strtoupper(trim((string) ($_POST['gender'] ?? '')));
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        foreach ([$firstName, $lastName, $city, $country, $email] as $fieldValue) {
            if ($fieldValue === '') {
                throw new RuntimeException('Uzupełnij wszystkie wymagane pola.');
            }
        }

        if (!in_array($gender, ['M', 'F'], true)) {
            throw new RuntimeException('Płeć musi być ustawiona na M albo F.');
        }

        if (DateTimeImmutable::createFromFormat('Y-m-d', $birthDate) === false) {
            throw new RuntimeException('Data urodzenia ma niepoprawny format.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adres e-mail jest niepoprawny.');
        }

        $disability = isset($_POST['disability']) ? 1 : 0;
        $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
        $club = trim((string) ($_POST['club'] ?? ''));
        $additionalText = trim((string) ($_POST['addictional_text'] ?? ''));
        $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
        $chipNumber = trim((string) ($_POST['chip_number'] ?? ''));
        if (strlen($chipNumber) > 255) {
            throw new RuntimeException('Numer chipu nie może przekraczać 255 znaków.');
        }

        $params = [
            'start_number' => $startNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'event_id' => $eventId,
            'distance_id' => $distanceId,
            'addictional_text' => $additionalText !== '' ? $additionalText : null,
            'gender' => $gender,
            'birth_date' => $birthDate,
            'city' => $city,
            'country' => $country,
            'club' => $club !== '' ? $club : null,
            'email' => $email,
            'phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
            'disability' => $disability,
            'about_me' => $aboutMe !== '' ? $aboutMe : null,
        ];

        if ($action === 'update' && !$editId) {
            throw new RuntimeException('Nieprawidłowy identyfikator zawodnika.');
        }

        DB::saveParticipantForManagement($params, $action === 'update' ? $editId : null, $chipNumber !== '' ? $chipNumber : null);

        $redirectEventId = $eventId ?: $selectedEventId;
        header('Location: manageUsers.php' . ($redirectEventId ? '?event_id=' . (int) $redirectEventId : ''));
        exit;
    } catch (Throwable $e) {
        error_log('[manageUsers] ' . $e->getMessage());
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się zapisać zmian. Sprawdź dane i spróbuj ponownie.';
        $formValues = array_merge($formValues, $_POST);
        $selectedEventId = (int) ($_POST['event_id'] ?? $selectedEventId ?? 0);
        $distanceOptions = $selectedEventId ? DB::getDistancesByEvent($selectedEventId) : [];
    }
}

if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $participant = DB::getParticipantForManagement($editId);
    if ($participant) {
        $formValues = array_merge($formValues, $participant);
        $selectedEventId = (int) $participant['event_id'];
        $distanceOptions = DB::getDistancesByEvent($selectedEventId);
    } else {
        $editId = null;
        $message = 'Nie znaleziono zawodnika do edycji.';
    }
}

$participants = DB::getParticipantsForManagement($selectedEventId);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel biegaczy</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
    <h1>Panel zarządzania biegaczami</h1>
</header>
<?php include __DIR__ . '/nav.php'; ?>
<main>
    <?php if ($message !== ''): ?>
        <p class="alert" role="alert"><?= h($message) ?></p>
    <?php endif; ?>

    <form method="get" class="filterForm">
        <label>
            Wydarzenie
            <select name="event_id" onchange="this.form.submit()">
                <?php foreach ($events as $event): ?>
                    <option value="<?= h($event['id']) ?>" <?= (string) $selectedEventId === (string) $event['id'] ? 'selected' : '' ?>>
                        <?= h($event['event_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if (!empty($events)): ?>
        <h2><?= $editId ? 'Edytuj zawodnika' : 'Dodaj biegacza' ?></h2>
        <form method="post" class="runnerForm">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">
            <?php if ($editId): ?>
                <input type="hidden" name="id" value="<?= h($editId) ?>">
            <?php endif; ?>

            <div class="formGrid">
                <label>
                    Numer startowy
                    <input type="number" name="start_number" min="1" required value="<?= h($formValues['start_number']) ?>">
                </label>

                <label>
                    Imię
                    <input type="text" name="first_name" required value="<?= h($formValues['first_name']) ?>">
                </label>

                <label>
                    Nazwisko
                    <input type="text" name="last_name" required value="<?= h($formValues['last_name']) ?>">
                </label>

                <label>
                    Wydarzenie
                    <select name="event_id" required>
                        <?php foreach ($events as $event): ?>
                            <option value="<?= h($event['id']) ?>" <?= (string) ($formValues['event_id'] !== '' ? $formValues['event_id'] : $selectedEventId) === (string) $event['id'] ? 'selected' : '' ?>>
                                <?= h($event['event_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Dystans
                    <select name="distance_id" required>
                        <?php foreach ($distanceOptions as $distance): ?>
                            <option value="<?= h($distance['id']) ?>" <?= (string) $formValues['distance_id'] === (string) $distance['id'] ? 'selected' : '' ?>>
                                <?= h($distance['distance']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Dodatkowy tekst
                    <input type="text" name="addictional_text" value="<?= h($formValues['addictional_text']) ?>">
                </label>

                <label>
                    Płeć
                    <select name="gender" required>
                        <option value="M" <?= ($formValues['gender'] === 'M' || $formValues['gender'] === '' ? 'selected' : '') ?>>Mężczyzna</option>
                        <option value="F" <?= $formValues['gender'] === 'F' ? 'selected' : '' ?>>Kobieta</option>
                    </select>
                </label>

                <label>
                    Data urodzenia
                    <input type="date" name="birth_date" required value="<?= h($formValues['birth_date']) ?>">
                </label>

                <label>
                    Miasto
                    <input type="text" name="city" required value="<?= h($formValues['city']) ?>">
                </label>

                <label>
                    Kraj
                    <input type="text" name="country" required value="<?= h($formValues['country']) ?>">
                </label>

                <label>
                    Klub
                    <input type="text" name="club" value="<?= h($formValues['club']) ?>">
                </label>

                <label>
                    E-mail
                    <input type="email" name="email" value="<?= h($formValues['email']) ?>">
                </label>

                <label>
                    Telefon
                    <input type="tel" name="phone_number" value="<?= h($formValues['phone_number']) ?>">
                </label>

                <label class="checkboxLabel">
                    <input type="checkbox" name="disability" value="1" <?= (int) ($formValues['disability'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Niepełnosprawność
                </label>

                <label class="fullWidth">
                    O mnie
                    <textarea name="about_me" rows="4"><?= h($formValues['about_me']) ?></textarea>
                </label>
                <label>
                    Numer chipu
                    <input type="text" name="chip_number" value="<?= h($formValues['chip_number']) ?>">
                </label>
            </div>

            <div class="actions">
                <button type="submit"><?= $editId ? 'Zapisz zmiany' : 'Dodaj zawodnika' ?></button>
                <?php if ($editId): ?>
                    <a href="manageUsers.php?event_id=<?= h((string) $selectedEventId) ?>" class="secondaryButton">Anuluj</a>
                <?php endif; ?>
            </div>
        </form>

        <h2>Lista biegaczy</h2>
        <table class="runnerTable">
            <thead>
            <tr>
                <th>Nr</th>
                <th>Chip</th>
                <th>Imię</th>
                <th>Nazwisko</th>
                <th>Wydarzenie</th>
                <th>Dystans</th>
                <th>Płeć</th>
                <th>Data urodzenia</th>
                <th>Miasto</th>
                <th>Kraj</th>
                <th>Klub</th>
                <th>E-mail</th>
                <th>Telefon</th>
                <th>Niepełnosprawność</th>
                <th>Akcje</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($participants as $participant): ?>
                <tr>
                    <td><?= h($participant['start_number']) ?></td>
                    <td><?= h($participant['chip_number'] ?? '') ?></td>
                    <td><?= h($participant['first_name']) ?></td>
                    <td><?= h($participant['last_name']) ?></td>
                    <td><?= h($participant['event_name'] ?? '') ?></td>
                    <td><?= h($participant['distance'] ?? '') ?></td>
                    <td><?= h($participant['gender']) ?></td>
                    <td><?= h($participant['birth_date']) ?></td>
                    <td><?= h($participant['city']) ?></td>
                    <td><?= h($participant['country']) ?></td>
                    <td><?= h($participant['club'] ?? '') ?></td>
                    <td><?= h($participant['email']) ?></td>
                    <td><?= h($participant['phone_number'] ?? '') ?></td>
                    <td><?= ((int) ($participant['disability'] ?? 0) === 1) ? 'Tak' : 'Nie' ?></td>
                    <td>
                        <div class="tableActions">
                            <a href="?event_id=<?= h((string) $selectedEventId) ?>&edit=<?= h((string) $participant['id']) ?>">Edytuj</a>
                            <form method="post" onsubmit="return confirm('Usunąć tego biegacza?');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h((string) $participant['id']) ?>">
                                <input type="hidden" name="event_id" value="<?= h((string) $selectedEventId) ?>">
                                <button type="submit">Usuń</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Brak zapisanych wydarzeń. Najpierw dodaj wydarzenie, aby móc dodać biegacza.</p>
    <?php endif; ?>
</main>
</body>
</html>
