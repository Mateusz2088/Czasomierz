<?php
session_start();
require_once __DIR__ . '/DB_CLASS.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatResultTime(mixed $seconds): string
{
    $centiseconds = (int) round((float) $seconds * 100);
    $hours = intdiv($centiseconds, 360000);
    $minutes = intdiv($centiseconds % 360000, 6000);
    $wholeSeconds = intdiv($centiseconds % 6000, 100);
    $fraction = $centiseconds % 100;

    return sprintf('%02d:%02d:%02d.%02d', $hours, $minutes, $wholeSeconds, $fraction);
}

$events = [];
$results = [];
$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT) ?: null;
$message = '';

try {
    $events = DB::getManagementEvents();
    $eventIds = array_map(static fn (array $event): int => (int) $event['id'], $events);

    if ($selectedEventId === null || !in_array($selectedEventId, $eventIds, true)) {
        $selectedEventId = $eventIds[0] ?? null;
    }

    if ($selectedEventId !== null) {
        $results = DB::rankedIndividual($selectedEventId);
    }
} catch (Throwable $e) {
    error_log('[scorePanel] ' . $e->getMessage());
    $message = 'Nie udało się pobrać wyników. Sprawdź połączenie z bazą danych.';
}

$ageGroups = array_values(array_unique(array_column($results, 'age_category')));
$genders = array_values(array_unique(array_column($results, 'gender')));
$distances = array_values(array_unique(array_column($results, 'distance_km')));
sort($ageGroups);
sort($genders);
sort($distances, SORT_NUMERIC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel wyników</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header><h1>Panel wyników</h1></header>
<?php include __DIR__ . '/nav.php'; ?>
<main>
    <?php if ($message !== ''): ?>
        <p class="alert" role="alert"><?= h($message) ?></p>
    <?php endif; ?>

    <?php if ($events !== []): ?>
        <form method="get" class="filterForm">
            <label for="eventFilter">Wydarzenie</label>
            <select id="eventFilter" name="event_id" onchange="this.form.submit()">
                <?php foreach ($events as $event): ?>
                    <option value="<?= h($event['id']) ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                        <?= h($event['event_name']) ?> (<?= h($event['date_start']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($results !== []): ?>
            <section class="filterForm" aria-label="Filtrowanie wyników">
                <div class="formGrid">
                    <label for="resultSearch">Szukaj zawodnika, numeru lub klubu
                        <input type="search" id="resultSearch" placeholder="Wpisz frazę">
                    </label>
                    <label for="ageGroupFilter">Grupa wiekowa
                        <select id="ageGroupFilter">
                            <option value="">Wszystkie grupy</option>
                            <?php foreach ($ageGroups as $ageGroup): ?>
                                <option value="<?= h($ageGroup) ?>"><?= h($ageGroup) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="genderFilter">Płeć
                        <select id="genderFilter">
                            <option value="">Wszystkie</option>
                            <?php foreach ($genders as $gender): ?>
                                <option value="<?= h($gender) ?>"><?= $gender === 'F' ? 'Kobiety' : 'Mężczyźni' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="distanceFilter">Dystans
                        <select id="distanceFilter">
                            <option value="">Wszystkie dystanse</option>
                            <?php foreach ($distances as $distance): ?>
                                <option value="<?= h($distance) ?>"><?= h(number_format((float) $distance, 2, ',', ' ')) ?> km</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="maxPlaceFilter">Maksymalne miejsce open
                        <input type="number" id="maxPlaceFilter" min="1" step="1" placeholder="Bez limitu">
                    </label>
                </div>
                <p id="resultCount" aria-live="polite"></p>
            </section>

            <div class="tableScroll">
                <table class="runnerTable" id="resultsTable">
                    <thead>
                    <tr>
                        <th scope="col" data-sort-type="number"><button type="button" class="sortButton">Miejsce open <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="number"><button type="button" class="sortButton">Miejsce w grupie <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="number"><button type="button" class="sortButton">Nr startowy <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="text"><button type="button" class="sortButton">Chip <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="text"><button type="button" class="sortButton">Imię i nazwisko <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="text"><button type="button" class="sortButton">Grupa <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="number"><button type="button" class="sortButton">Dystans <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="text"><button type="button" class="sortButton">Klub <span aria-hidden="true">↕</span></button></th>
                        <th scope="col" data-sort-type="number"><button type="button" class="sortButton">Czas <span aria-hidden="true">↕</span></button></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $result): ?>
                        <?php
                        $searchText = implode(' ', [
                            $result['start_number'],
                            $result['first_name'],
                            $result['last_name'],
                            $result['club'] ?? '',
                            $result['chip_number'],
                        ]);
                        ?>
                        <tr
                            data-search="<?= h($searchText) ?>"
                            data-age-group="<?= h($result['age_category']) ?>"
                            data-gender="<?= h($result['gender']) ?>"
                            data-distance="<?= h($result['distance_km']) ?>"
                            data-place="<?= h($result['place_open']) ?>"
                        >
                            <td data-sort-value="<?= h($result['place_open']) ?>"><?= h($result['place_open']) ?></td>
                            <td data-sort-value="<?= h($result['place_age_gender']) ?>"><?= h($result['place_age_gender']) ?></td>
                            <td data-sort-value="<?= h($result['start_number']) ?>"><?= h($result['start_number']) ?></td>
                            <td data-sort-value="<?= h($result['chip_number']) ?>"><?= h($result['chip_number']) ?></td>
                            <td data-sort-value="<?= h($result['last_name'] . ' ' . $result['first_name']) ?>"><?= h($result['first_name'] . ' ' . $result['last_name']) ?></td>
                            <td data-sort-value="<?= h($result['age_category'] . ' ' . $result['gender']) ?>"><?= h($result['age_category']) ?> / <?= h($result['gender']) ?></td>
                            <td data-sort-value="<?= h($result['distance_km']) ?>"><?= h(number_format((float) $result['distance_km'], 2, ',', ' ')) ?> km</td>
                            <td data-sort-value="<?= h($result['club'] ?? '') ?>"><?= h($result['club'] ?? '') ?></td>
                            <td data-sort-value="<?= h($result['elapsed_s']) ?>"><?= h(formatResultTime($result['elapsed_s'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="noFilteredResults" hidden>Brak wyników pasujących do wybranych filtrów.</p>
        <?php else: ?>
            <p>Brak ukończonych wyników dla tego wydarzenia.</p>
        <?php endif; ?>
    <?php elseif ($message === ''): ?>
        <p>Brak zapisanych wydarzeń.</p>
    <?php endif; ?>
</main>
<?php if ($results !== []): ?>
<script>
    const resultRows = Array.from(document.querySelectorAll('#resultsTable tbody tr'));
    const resultSearch = document.getElementById('resultSearch');
    const ageGroupFilter = document.getElementById('ageGroupFilter');
    const genderFilter = document.getElementById('genderFilter');
    const distanceFilter = document.getElementById('distanceFilter');
    const maxPlaceFilter = document.getElementById('maxPlaceFilter');
    const resultCount = document.getElementById('resultCount');
    const noFilteredResults = document.getElementById('noFilteredResults');
    const resultsBody = document.querySelector('#resultsTable tbody');
    const sortableHeaders = Array.from(document.querySelectorAll('#resultsTable thead th'));
    let activeSortColumn = -1;
    let sortDirection = 1;
    const originalOrder = new Map(resultRows.map((row, index) => [row, index]));

    function sortResults(columnIndex) {
        if (activeSortColumn === columnIndex) {
            sortDirection *= -1;
        } else {
            activeSortColumn = columnIndex;
            sortDirection = 1;
        }

        const sortType = sortableHeaders[columnIndex].dataset.sortType;
        resultRows.sort((rowA, rowB) => {
            const valueA = rowA.cells[columnIndex].dataset.sortValue ?? '';
            const valueB = rowB.cells[columnIndex].dataset.sortValue ?? '';
            const comparison = sortType === 'number'
                ? Number(valueA) - Number(valueB)
                : valueA.localeCompare(valueB, 'pl', {numeric: true, sensitivity: 'base'});

            return comparison === 0
                ? originalOrder.get(rowA) - originalOrder.get(rowB)
                : comparison * sortDirection;
        });

        for (const row of resultRows) {
            resultsBody.appendChild(row);
        }

        sortableHeaders.forEach((header, index) => {
            const button = header.querySelector('.sortButton');
            const active = index === activeSortColumn;
            header.setAttribute('aria-sort', active ? (sortDirection === 1 ? 'ascending' : 'descending') : 'none');
            button.lastElementChild.textContent = active ? (sortDirection === 1 ? '↑' : '↓') : '↕';
        });
    }

    function filterResults() {
        const searchTerm = resultSearch.value.trim().toLocaleLowerCase('pl');
        const maxPlace = Number.parseInt(maxPlaceFilter.value, 10);
        let visibleCount = 0;

        for (const row of resultRows) {
            const matchesSearch = row.dataset.search.toLocaleLowerCase('pl').includes(searchTerm);
            const matchesAgeGroup = !ageGroupFilter.value || row.dataset.ageGroup === ageGroupFilter.value;
            const matchesGender = !genderFilter.value || row.dataset.gender === genderFilter.value;
            const matchesDistance = !distanceFilter.value || row.dataset.distance === distanceFilter.value;
            const matchesPlace = !maxPlaceFilter.value || Number(row.dataset.place) <= maxPlace;
            const visible = matchesSearch && matchesAgeGroup && matchesGender && matchesDistance && matchesPlace;

            row.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        }

        resultCount.textContent = `Wyświetlono ${visibleCount} z ${resultRows.length} wyników.`;
        noFilteredResults.hidden = visibleCount > 0;
    }

    for (const filter of [resultSearch, ageGroupFilter, genderFilter, distanceFilter, maxPlaceFilter]) {
        filter.addEventListener('input', filterResults);
        filter.addEventListener('change', filterResults);
    }

    sortableHeaders.forEach((header, index) => {
        header.querySelector('.sortButton').addEventListener('click', () => sortResults(index));
    });

    filterResults();
</script>
<?php endif; ?>
</body>
</html>
