<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'app_db';
$user = getenv('DB_USER') ?: 'app_user';
$pass = getenv('DB_PASSWORD') ?: 'app_password';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS tag_observations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sequence_number INT NOT NULL,
        tag_code VARCHAR(20) NOT NULL,
        timer_code VARCHAR(10) NOT NULL,
        event_code VARCHAR(10) NOT NULL,
        observation_time DECIMAL(15,2) NOT NULL,
        reader_id VARCHAR(20) NOT NULL,
        port_number INT NOT NULL,
        rssi INT NOT NULL,
        reader_sequence INT NOT NULL,
        transaction_type CHAR(1) NOT NULL,
        connection_id VARCHAR(64) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_unique_obs (sequence_number, tag_code, reader_id, observation_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chips = ['12345678', '89123456'];
    $rowsToInsert = [];

    foreach ($chips as $chipIndex => $chip) {
        for ($i = 1; $i <= 3; $i++) {
            $seq = ($chipIndex * 100) + $i;
            $rowsToInsert[] = [
                ':seq' => $seq,
                ':tag' => $chip,
                ':timer' => 'T1',
                ':event' => 'IN',
                ':time' => 1700000000.00 + ($chipIndex * 10) + $i,
                ':reader' => 'R' . ($chipIndex + 1),
                ':port' => 1,
                ':rssi' => -60 - $i,
                ':rseq' => $i,
                ':type' => 'N',
                ':conn' => 'test-' . $chip,
            ];
        }
    }

    $deleteSql = 'DELETE FROM tag_observations WHERE tag_code IN (:chip1, :chip2)';
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([
        ':chip1' => '12345678',
        ':chip2' => '89123456',
    ]);

    $insertSql = 'INSERT INTO tag_observations
        (sequence_number, tag_code, timer_code, event_code, observation_time, reader_id, port_number, rssi, reader_sequence, transaction_type, connection_id)
        VALUES
        (:seq, :tag, :timer, :event, :time, :reader, :port, :rssi, :rseq, :type, :conn)';

    $insertStmt = $pdo->prepare($insertSql);
    foreach ($rowsToInsert as $row) {
        $insertStmt->execute($row);
    }

    $result = $pdo->query("SELECT tag_code, COUNT(*) as cnt
        FROM tag_observations
        WHERE tag_code IN ('12345678', '89123456')
        GROUP BY tag_code
        ORDER BY tag_code");
    $counts = $result->fetchAll();

    $actual = [];
    foreach ($counts as $row) {
        $actual[$row['tag_code']] = (int) $row['cnt'];
    }

    $expected = [
        '12345678' => 3,
        '89123456' => 3,
    ];

    if ($actual !== $expected) {
        fwrite(STDERR, "Test failed. Expected: " . json_encode($expected) . " Actual: " . json_encode($actual) . PHP_EOL);
        exit(1);
    }

    echo "PASS: dodano po 3 wpisy dla chipów 12345678 i 89123456." . PHP_EOL;
    echo json_encode($actual, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Test failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
