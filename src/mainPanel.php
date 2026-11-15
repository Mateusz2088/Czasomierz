<?php
session_start();
include_once __DIR__ . '/DB_CLASS.php';

if (isset($_GET['set_event'])) {
    $_SESSION['current_event'] = htmlspecialchars($_GET['set_event']);
    echo "<p>Ustawiono aktualne wydarzenie na: <strong>" . htmlspecialchars($_SESSION['current_event']) . "</strong></p>";
}

# Panel główny aplikacji – wyświetla panel nawigacyjny i statystyki

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel administratora</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <h1>Panel administratora aplikacji biegowej</h1>
        </header>
        <? include __DIR__ . '/nav.php'; ?>
    <main>
        <h2>Dane serwera</h2>
        <p>PHP: <?php echo phpversion(); ?></p>
        <p>IP: <?php echo $_SERVER['SERVER_ADDR']; ?></p>
        <p>Port: <?php echo $_SERVER['SERVER_PORT']; ?></p>
        <p>Czas pracy: <?php echo shell_exec('uptime'); ?></p>
        <p>Nazwa hosta: <?php echo gethostname(); ?></p>
        <p>Nazwa serwera: <?php echo $_SERVER['SERVER_NAME']; ?></p>
        <h2>Wydarzenie</h2>
        <p>Aktualnie trwające wydarzenie: <?php echo $_SESSION['current_event'] ?? 'Brak aktywnego wydarzenia'; ?></p>
        <h2>Najbliższe wydarzenia</h2>
        <?php
            $events = DB::getEvents();
            foreach ($events as $event) {
                echo "<p>" . htmlspecialchars($event['event_name']) . " (" . htmlspecialchars($event['date_start']) . ")";
                if ($event['date_end']) {
                    echo " – " . htmlspecialchars($event['date_end']);
                }
                if (urlencode($event['EVENT_CODE'])==htmlspecialchars($_SESSION['current_event'])) {
                    echo " <strong>(aktualne)</strong>";
                }else {
                    echo " <a href=\"?set_event=" . urlencode($event['EVENT_CODE']) . "\">Ustaw jako aktualne</a>";
                }
                echo "</p>";
            }
        ?>
        <h2>Statystyki aktualnego wydarzenia</h2>
        <?php
            $naTrasie = 0;
            if (isset($_SESSION['current_event'])) {
                $trackers = DB::getTrackersCount($_SESSION['current_event']);
                $distanceStats = DB::getDistanceRunnerStats($_SESSION['current_event']);
                foreach ($distanceStats as $distanceStat) {
                    $naTrasie += (int) $distanceStat['runners_on_route'];
                }

                echo "<p>Liczba biegaczy: " .DB::getRunnersCount($_SESSION['current_event']). "</p>";
                echo "<p>Liczba wyników: " . count($trackers) . "</p>";
                echo "<p>Liczba biegaczy na trasie: " . $naTrasie . "</p>";
                echo "<h3>Biegacze na trasie według dystansu</h3>";
                echo "<table><thead><tr><th>Dystans</th><th>Na trasie</th><th>Wszyscy biegacze</th><th>Procent na trasie</th></tr></thead><tbody>";
                foreach ($distanceStats as $distanceStat) {
                    $totalRunners = (int) $distanceStat['total_runners'];
                    $runnersOnRoute = (int) $distanceStat['runners_on_route'];
                    $percentage = $totalRunners > 0 ? ($runnersOnRoute / $totalRunners) * 100 : 0;
                    echo "<tr><td>" . htmlspecialchars((string) $distanceStat['distance']) . " km</td>";
                    echo "<td>" . $runnersOnRoute . "</td><td>" . $totalRunners . "</td>";
                    echo "<td>" . number_format($percentage, 1, ',', ' ') . "%</td></tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<p>Brak aktywnego wydarzenia. Ustaw aktualne wydarzenie, aby zobaczyć statystyki.</p>";
            }
        ?>
    </main>
    </body>
    </html> 

