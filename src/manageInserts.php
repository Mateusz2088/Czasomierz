<?php
session_start();
include_once __DIR__ . '/DB_CLASS.php';
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
    <header>
        <h1>Panel zarządzania wpisami</h1>
    </header>
    <? include __DIR__ . '/nav.php'; ?>
    <main>
    <? if (isset($_SESSION['current_event'])): ?>
        <h2>Aktualne wydarzenie: <?php echo htmlspecialchars($_SESSION['current_event']); ?></h2>
    
        <h2>Wpisy</h2>
        <table class="wpisyTable">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Numer startowy</th>
                    <th>Imie i nazwisko</th>
                    <th>Dystans</th>
                    <th>Ilość max okrążeń</th>
                    <th>Czas UTC</th>
                    <th>Czas lokalny</th>
                    <?php
                    $maxLap = 0;
                    $trackers = DB::getLapDistance($_SESSION['current_event']);
                    foreach ($trackers as $tracker) {
                        $maxLap = max($maxLap, htmlspecialchars($tracker['KmPerLap']));
                    }
                    for ($i=0; $i <= $maxLap; $i++) { 
                       # echo "<th>" . htmlspecialchars($i) . "</th>";
                    }
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $trackers = DB::getLapList($_SESSION['current_event']);
                foreach ($trackers as $tracker) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($tracker['tag_code']) . "</td>";
                    echo "<td>" . htmlspecialchars($tracker['start_number']) . "</td>";
                    echo "<td>" . htmlspecialchars($tracker['first_name']) . " " . htmlspecialchars($tracker['last_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($tracker['distance']) . "</td>";
                    echo "<td>" . htmlspecialchars($tracker['KmPerLap']) . "</td>";
                    echo "<td>" . htmlspecialchars($tracker['observation_time']) . "</td>";
                    $date = DateTime::createFromFormat(
                        'U.u',
                        sprintf('%.6f', $tracker['observation_time'])
                    );
                    echo "<td>" . htmlspecialchars($date->format('H:i:s:v')) . "</td>";
                    echo "</tr>";
                }
                ?>
        </table>
        <? else: ?>
            <p>Brak aktywnego wydarzenia. Ustaw aktualne wydarzenie, aby zobaczyć wpisy.</p>
        <? endif; ?>
    </main>
</body>
</html>