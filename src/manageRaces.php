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
$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: null;
$raceEditId = filter_input(INPUT_GET, 'edit_race', FILTER_VALIDATE_INT) ?: null;
$distanceEditId = filter_input(INPUT_GET, 'edit_distance', FILTER_VALIDATE_INT) ?: null;
$categoryEditId = filter_input(INPUT_GET, 'edit_category', FILTER_VALIDATE_INT) ?: null;

$formRace = [
    'event_name' => '',
    'date_start' => '',
    'date_end' => '',
    'location' => '',
    'organization_name' => '',
];

$formDistance = [
    'event_id' => '',
    'distance' => '',
    'KmPerLap' => '',
];
$formCategory = [
    'category_name' => '',
    'min_age' => '0',
    'max_age' => '',
];

$events = DB::fetchAll('SELECT * FROM events ORDER BY date_start DESC');
if ($selectedEventId === null && !empty($events)) {
    $selectedEventId = (int) $events[0]['id'];
}
if ($selectedEventId !== null) {
    $selectedEventId = (int) $selectedEventId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Nieprawidłowy token formularza.');
        }

        if (in_array($action, ['category_add', 'category_update', 'category_delete'], true)) {
            $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: null;
            $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: null;
            if (!$eventId) {
                throw new RuntimeException('Wybierz wydarzenie dla kategorii wiekowej.');
            }

            if ($action === 'category_delete') {
                if (!$categoryId) {
                    throw new RuntimeException('Nieprawidłowy identyfikator kategorii.');
                }
                DB::deleteAgeCategory($eventId, $categoryId);
            } else {
                $formCategory = array_merge($formCategory, $_POST);
                $categoryName = trim((string) ($_POST['category_name'] ?? ''));
                $minAge = filter_var($_POST['min_age'] ?? null, FILTER_VALIDATE_INT);
                $maxAgeInput = trim((string) ($_POST['max_age'] ?? ''));
                $maxAge = $maxAgeInput === '' ? null : filter_var($maxAgeInput, FILTER_VALIDATE_INT);

                if ($categoryName === '' || strlen($categoryName) > 80) {
                    throw new RuntimeException('Nazwa kategorii jest wymagana i może mieć maksymalnie 80 znaków.');
                }
                if ($minAge === false || $minAge < 0 || $minAge > 150) {
                    throw new RuntimeException('Wiek od musi być liczbą całkowitą od 0 do 150.');
                }
                if ($maxAgeInput !== '' && ($maxAge === false || $maxAge < $minAge || $maxAge > 150)) {
                    throw new RuntimeException('Wiek do musi być pusty albo liczbą od wieku początkowego do 150.');
                }
                if (DB::ageCategoryRangeOverlaps($eventId, $minAge, $maxAge, $action === 'category_update' ? $categoryId : null)) {
                    throw new RuntimeException('Przedział wieku nakłada się na inną kategorię tego wydarzenia.');
                }
                if ($action === 'category_update' && !$categoryId) {
                    throw new RuntimeException('Nieprawidłowy identyfikator kategorii.');
                }

                DB::saveAgeCategory(
                    $eventId,
                    $categoryName,
                    $minAge,
                    $maxAge,
                    $action === 'category_update' ? $categoryId : null
                );
            }

            header('Location: manageRaces.php?event_id=' . $eventId);
            exit;
        }

        if ($action === 'race_add' || $action === 'race_update') {
            $eventId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
            $eventName = trim((string) ($_POST['event_name'] ?? ''));
            $start = trim((string) ($_POST['date_start'] ?? ''));
            $end = trim((string) ($_POST['date_end'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $organizationName = trim((string) ($_POST['organization_name'] ?? ''));

            if ($eventName === '' || $start === '' || $end === '') {
                throw new RuntimeException('Nazwa wydarzenia, data rozpoczęcia i data zakończenia są wymagane.');
            }

            $startDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $start);
            $endDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $end);
            if ($startDate === false || $endDate === false) {
                throw new RuntimeException('Data wydarzenia ma niepoprawny format.');
            }
            if ($endDate < $startDate) {
                throw new RuntimeException('Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.');
            }

            $params = [
                'event_name' => $eventName,
                'date_start' => $startDate->format('Y-m-d H:i:s'),
                'date_end' => $endDate->format('Y-m-d H:i:s'),
                'location' => $location !== '' ? $location : null,
                'organization_name' => $organizationName !== '' ? $organizationName : null,
            ];

            if ($action === 'race_add') {
                $eventId = (int) DB::insert(
                    'INSERT INTO events (event_name, date_start, date_end, location, organization_name)
                     VALUES (:event_name, :date_start, :date_end, :location, :organization_name)',
                    $params
                );
            } else {
                if (!$eventId) {
                    throw new RuntimeException('Nieprawidłowy identyfikator wydarzenia.');
                }
                $params['id'] = $eventId;
                DB::execute(
                    'UPDATE events SET
                        event_name = :event_name,
                        date_start = :date_start,
                        date_end = :date_end,
                        location = :location,
                        organization_name = :organization_name
                     WHERE id = :id',
                    $params
                );
            }

            $selectedEventId = $eventId;
            header('Location: manageRaces.php?event_id=' . (int) $selectedEventId);
            exit;
        }

        if ($action === 'race_delete') {
            $eventId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
            if (!$eventId) {
                throw new RuntimeException('Nieprawidłowy identyfikator wydarzenia.');
            }

            DB::beginTransaction();
            try {
                DB::execute('DELETE FROM chip WHERE participant_id IN (SELECT id FROM participants WHERE event_id = :event_id)', ['event_id' => $eventId]);
                DB::execute('DELETE FROM participants WHERE event_id = :event_id', ['event_id' => $eventId]);
                DB::execute('DELETE FROM distances WHERE event_id = :event_id', ['event_id' => $eventId]);
                DB::execute('DELETE FROM events WHERE id = :event_id', ['event_id' => $eventId]);
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $events = DB::fetchAll('SELECT * FROM events ORDER BY date_start DESC');
            $selectedEventId = !empty($events) ? (int) $events[0]['id'] : null;
            header('Location: manageRaces.php' . ($selectedEventId ? '?event_id=' . $selectedEventId : ''));
            exit;
        }

        if ($action === 'distance_add' || $action === 'distance_update') {
            $distanceId = filter_input(INPUT_POST, 'distance_id', FILTER_VALIDATE_INT) ?: null;
            $distanceValue = filter_var($_POST['distance'] ?? null, FILTER_VALIDATE_FLOAT);
            $lapValue = filter_var($_POST['KmPerLap'] ?? null, FILTER_VALIDATE_FLOAT);
            $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: null;

            if (!$eventId) {
                throw new RuntimeException('Wybierz wydarzenie dla dystansu.');
            }
            if ($distanceValue === false || $distanceValue <= 0) {
                throw new RuntimeException('Dystans musi być wartością liczbową większą od zera.');
            }
            if ($lapValue === false || $lapValue <= 0) {
                throw new RuntimeException('Km na okrążenie musi być wartością liczbową większą od zera.');
            }

            $params = [
                'event_id' => $eventId,
                'distance' => number_format((float) $distanceValue, 2, '.', ''),
                'KmPerLap' => number_format((float) $lapValue, 2, '.', ''),
            ];

            if ($action === 'distance_add') {
                DB::execute(
                    'INSERT INTO distances (event_id, distance, KmPerLap) VALUES (:event_id, :distance, :KmPerLap)',
                    $params
                );
            } else {
                if (!$distanceId) {
                    throw new RuntimeException('Nieprawidłowy identyfikator dystansu.');
                }
                $params['id'] = $distanceId;
                DB::execute(
                    'UPDATE distances SET event_id = :event_id, distance = :distance, KmPerLap = :KmPerLap WHERE id = :id',
                    $params
                );
            }

            $selectedEventId = $eventId;
            header('Location: manageRaces.php?event_id=' . (int) $selectedEventId);
            exit;
        }

        if ($action === 'distance_delete') {
            $distanceId = filter_input(INPUT_POST, 'distance_id', FILTER_VALIDATE_INT) ?: null;
            $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: null;
            if (!$distanceId || !$eventId) {
                throw new RuntimeException('Nieprawidłowy identyfikator dystansu.');
            }

            DB::beginTransaction();
            try {
                DB::execute(
                    'DELETE FROM chip WHERE participant_id IN (SELECT id FROM participants WHERE distance_id = :distance_id)',
                    ['distance_id' => $distanceId]
                );
                DB::execute('DELETE FROM participants WHERE distance_id = :distance_id', ['distance_id' => $distanceId]);
                DB::execute('DELETE FROM distances WHERE id = :distance_id', ['distance_id' => $distanceId]);
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            header('Location: manageRaces.php?event_id=' . (int) $eventId);
            exit;
        }

        throw new RuntimeException('Nieznana operacja.');
    } catch (Throwable $e) {
        error_log('[manageRaces] ' . $e->getMessage());
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Nie udało się zapisać zmian. Sprawdź dane i spróbuj ponownie.';

        $formRace = array_merge($formRace, $_POST);
        $formDistance = array_merge($formDistance, $_POST);
        $formCategory = array_merge($formCategory, $_POST);
        $selectedEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) ?: $selectedEventId;
        if (($action ?? '') === 'category_update') {
            $categoryEditId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: null;
        }
    }
}

if ($raceEditId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $race = DB::fetchOne('SELECT * FROM events WHERE id = :id', ['id' => $raceEditId]);
    if ($race) {
        $formRace = array_merge($formRace, $race);
        $formRace['date_start'] = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $race['date_start'])
            ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $race['date_start'])->format('Y-m-d\TH:i')
            : '';
        $formRace['date_end'] = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $race['date_end'])
            ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $race['date_end'])->format('Y-m-d\TH:i')
            : '';
        $selectedEventId = (int) $race['id'];
    } else {
        $raceEditId = null;
        $message = 'Nie znaleziono wydarzenia do edycji.';
    }
}

if ($distanceEditId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $distance = DB::fetchOne('SELECT * FROM distances WHERE id = :id', ['id' => $distanceEditId]);
    if ($distance) {
        $formDistance = array_merge($formDistance, $distance);
        $selectedEventId = (int) $distance['event_id'];
    } else {
        $distanceEditId = null;
        $message = 'Nie znaleziono dystansu do edycji.';
    }
}

$selectedEventId = $selectedEventId !== null ? (int) $selectedEventId : null;
$distanceList = $selectedEventId !== null ? DB::fetchAll(
    'SELECT * FROM distances WHERE event_id = :event_id ORDER BY distance ASC',
    ['event_id' => $selectedEventId]
) : [];
$ageCategories = $selectedEventId !== null ? DB::getAgeCategoriesByEvent($selectedEventId) : [];

if ($categoryEditId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($ageCategories as $category) {
        if ((int) $category['id'] === $categoryEditId) {
            $formCategory = array_merge($formCategory, $category);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel wydarzeń i dystansów</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
    <h1>Panel wydarzeń i dystansów</h1>
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
                    <option value="<?= h((string) $event['id']) ?>" <?= $selectedEventId !== null && (int) $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                        <?= h($event['event_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <section class="filterForm">
        <h2><?= $raceEditId ? 'Edytuj wydarzenie' : 'Dodaj wydarzenie' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $raceEditId ? 'race_update' : 'race_add' ?>">
            <?php if ($raceEditId): ?>
                <input type="hidden" name="id" value="<?= h((string) $raceEditId) ?>">
            <?php endif; ?>

            <div class="formGrid">
                <label>
                    Nazwa wydarzenia
                    <input type="text" name="event_name" required value="<?= h($formRace['event_name']) ?>">
                </label>
                <label>
                    Data rozpoczęcia
                    <input type="datetime-local" name="date_start" required value="<?= h($formRace['date_start']) ?>">
                </label>
                <label>
                    Data zakończenia
                    <input type="datetime-local" name="date_end" required value="<?= h($formRace['date_end']) ?>">
                </label>
                <label>
                    Lokalizacja
                    <input type="text" name="location" value="<?= h($formRace['location'] ?? '') ?>">
                </label>
                <label>
                    Organizator
                    <input type="text" name="organization_name" value="<?= h($formRace['organization_name'] ?? '') ?>">
                </label>
            </div>

            <div class="actions">
                <button type="submit"><?= $raceEditId ? 'Zapisz zmiany' : 'Dodaj wydarzenie' ?></button>
                <?php if ($raceEditId): ?>
                    <a href="manageRaces.php?event_id=<?= h((string) ($selectedEventId ?? '')) ?>" class="secondaryButton">Anuluj</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (!empty($events)): ?>
        <section class="filterForm">
            <h2><?= $distanceEditId ? 'Edytuj dystans' : 'Dodaj dystans' ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="<?= $distanceEditId ? 'distance_update' : 'distance_add' ?>">
                <?php if ($distanceEditId): ?>
                    <input type="hidden" name="distance_id" value="<?= h((string) $distanceEditId) ?>">
                <?php endif; ?>

                <div class="formGrid">
                    <label>
                        Wydarzenie
                        <select name="event_id" required>
                            <?php foreach ($events as $event): ?>
                                <option value="<?= h((string) $event['id']) ?>" <?= $selectedEventId !== null && (int) $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                                    <?= h($event['event_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Dystans (km)
                        <input type="number" step="0.01" min="0.01" name="distance" required value="<?= h($formDistance['distance']) ?>">
                    </label>
                    <label>
                        Km na okrążenie
                        <input type="number" step="0.01" min="0.01" name="KmPerLap" required value="<?= h($formDistance['KmPerLap']) ?>">
                    </label>
                </div>

                <div class="actions">
                    <button type="submit"><?= $distanceEditId ? 'Zapisz dystans' : 'Dodaj dystans' ?></button>
                    <?php if ($distanceEditId): ?>
                        <a href="manageRaces.php?event_id=<?= h((string) ($selectedEventId ?? '')) ?>" class="secondaryButton">Anuluj</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section>
            <h2>Lista wydarzeń</h2>
            <table class="runnerTable">
                <thead>
                <tr>
                    <th>Nazwa</th>
                    <th>Data startu</th>
                    <th>Data zakończenia</th>
                    <th>Lokalizacja</th>
                    <th>Organizator</th>
                    <th>Akcje</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= h($event['event_name']) ?></td>
                        <td><?= h($event['date_start']) ?></td>
                        <td><?= h($event['date_end']) ?></td>
                        <td><?= h($event['location'] ?? '') ?></td>
                        <td><?= h($event['organization_name'] ?? '') ?></td>
                        <td>
                            <div class="tableActions">
                                <a href="?event_id=<?= h((string) $event['id']) ?>&edit_race=<?= h((string) $event['id']) ?>">Edytuj</a>
                                <form method="post" onsubmit="return confirm('Usunąć to wydarzenie wraz z przypisanymi dystansami i biegaczami?');">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="race_delete">
                                    <input type="hidden" name="id" value="<?= h((string) $event['id']) ?>">
                                    <button type="submit">Usuń</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section>
            <h2>Dystanse dla wybranego wydarzenia</h2>
            <?php if ($selectedEventId !== null): ?>
                <table class="runnerTable">
                    <thead>
                    <tr>
                        <th>Dystans</th>
                        <th>Km na okrążenie</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($distanceList as $distance): ?>
                        <tr>
                            <td><?= h((string) $distance['distance']) ?> km</td>
                            <td><?= h((string) $distance['KmPerLap'] ?? '0.00') ?> km</td>
                            <td>
                                <div class="tableActions">
                                    <a href="?event_id=<?= h((string) $selectedEventId) ?>&edit_distance=<?= h((string) $distance['id']) ?>">Edytuj</a>
                                    <form method="post" onsubmit="return confirm('Usunąć ten dystans?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="distance_delete">
                                        <input type="hidden" name="event_id" value="<?= h((string) $selectedEventId) ?>">
                                        <input type="hidden" name="distance_id" value="<?= h((string) $distance['id']) ?>">
                                        <button type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Brak aktywnego wydarzenia.</p>
            <?php endif; ?>
        </section>

        <section class="filterForm">
            <h2><?= $categoryEditId ? 'Edytuj kategorię wiekową' : 'Dodaj kategorię wiekową' ?></h2>
            <?php if ($selectedEventId !== null): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $categoryEditId ? 'category_update' : 'category_add' ?>">
                    <input type="hidden" name="event_id" value="<?= h((string) $selectedEventId) ?>">
                    <?php if ($categoryEditId): ?>
                        <input type="hidden" name="category_id" value="<?= h((string) $categoryEditId) ?>">
                    <?php endif; ?>

                    <div class="formGrid">
                        <label>
                            Nazwa kategorii
                            <input type="text" name="category_name" maxlength="80" required value="<?= h($formCategory['category_name']) ?>" placeholder="np. 30–39 lat">
                        </label>
                        <label>
                            Wiek od
                            <input type="number" name="min_age" min="0" max="150" step="1" required value="<?= h($formCategory['min_age']) ?>">
                        </label>
                        <label>
                            Wiek do (opcjonalnie)
                            <input type="number" name="max_age" min="0" max="150" step="1" value="<?= h($formCategory['max_age']) ?>" placeholder="Bez górnego limitu">
                        </label>
                    </div>
                    <p>Granice przedziału są włączne. Puste pole „Wiek do” oznacza brak górnego limitu.</p>
                    <div class="actions">
                        <button type="submit"><?= $categoryEditId ? 'Zapisz kategorię' : 'Dodaj kategorię' ?></button>
                        <?php if ($categoryEditId): ?>
                            <a href="manageRaces.php?event_id=<?= h((string) $selectedEventId) ?>" class="secondaryButton">Anuluj</a>
                        <?php endif; ?>
                    </div>
                </form>

                <h3>Kategorie wiekowe wydarzenia</h3>
                <?php if ($ageCategories !== []): ?>
                    <table class="runnerTable">
                        <thead>
                        <tr>
                            <th>Nazwa</th>
                            <th>Wiek od</th>
                            <th>Wiek do</th>
                            <th>Akcje</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ageCategories as $category): ?>
                            <tr>
                                <td><?= h($category['category_name']) ?></td>
                                <td><?= h((string) $category['min_age']) ?></td>
                                <td><?= $category['max_age'] === null ? 'Bez limitu' : h((string) $category['max_age']) ?></td>
                                <td>
                                    <div class="tableActions">
                                        <a href="?event_id=<?= h((string) $selectedEventId) ?>&edit_category=<?= h((string) $category['id']) ?>">Edytuj</a>
                                        <form method="post" onsubmit="return confirm('Usunąć tę kategorię wiekową?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="category_delete">
                                            <input type="hidden" name="event_id" value="<?= h((string) $selectedEventId) ?>">
                                            <input type="hidden" name="category_id" value="<?= h((string) $category['id']) ?>">
                                            <button type="submit">Usuń</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Brak kategorii wiekowych dla tego wydarzenia.</p>
                <?php endif; ?>
            <?php else: ?>
                <p>Najpierw wybierz lub dodaj wydarzenie.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <p>Brak zapisanych wydarzeń. Dodaj pierwsze wydarzenie powyżej.</p>
    <?php endif; ?>
</main>
</body>
</html>
