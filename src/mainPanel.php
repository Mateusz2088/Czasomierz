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
    <nav>
        <ul>
            <li><a href="index.php">Strona główna</a></li>
            <li><a href="testDb.php">Test połączenia z bazą danych</a></li>
            <li><a href="manageInserts.php">Zarządzanie wpisami</a></li>
            <li><a href="manageRaces.php">Zarządzanie biegami</a></li>
            <li><a href="manageResults.php">Zarządzanie wynikami</a></li>
            <li><a href="manageUsers.php">Zarządzanie biegaczami</a></li>
        </ul>
    </nav>
    <main>
        <h2>Wydarzenie</h2>
        <p>Aktualnie </p>

        <h2>Statystyki bazy danych</h2>
<?php

$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'app_db';
$user = getenv('DB_USER') ?: 'app_user';
$pass = getenv('DB_PASSWORD') ?: '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

# Panel główny aplikacji – wyświetla panel nawigacyjny i statystyki

?>
    </main>
    </body>
    </html> 

