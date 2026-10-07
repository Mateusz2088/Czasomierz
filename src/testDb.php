<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'app_db';
$user = getenv('DB_USER') ?: 'app_user';
$pass = getenv('DB_PASSWORD') ?: '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "<h1>✅ Połączenie z MySQL OK</h1>";
    echo "<p>Wersja serwera: <strong>" . htmlspecialchars((string)$version) . "</strong></p>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS test (
        id INT AUTO_INCREMENT PRIMARY KEY,
        msg VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ✅ Poprawnie dla MySQL – prepared statement zamiast konkatenacji w SQL
    $stmt = $pdo->prepare("INSERT INTO test (msg) VALUES (:msg)");
    $stmt->execute([':msg' => 'Hello from PHP ' . date('Y-m-d H:i:s')]);

    $rows = $pdo->query('SELECT * FROM test ORDER BY id DESC LIMIT 5')->fetchAll();
    echo "<h2>Ostatnie wpisy:</h2><ul>";
    foreach ($rows as $r) {
        echo "<li>#" . (int)$r['id'] . " – "
            . htmlspecialchars($r['msg'])
            . " (" . $r['created_at'] . ")</li>";
    }
    echo "</ul>";
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>❌ Błąd połączenia</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}