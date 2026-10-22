<?php

declare(strict_types=1);

/**
 * Prosty wrapper PDO do wykonywania zapytań do bazy danych.
 *
 * Użycie:
 * require_once __DIR__ . '/DB_CLASS.php';
 *
 * $rows = DB::fetchAll('SELECT * FROM events WHERE id = :id', ['id' => 1]);
 * $id = DB::insert('INSERT INTO events (event_name, date_start, date_end) VALUES (:n, :s, :e)', [
 *     'n' => 'Nowy event',
 *     's' => '2026-10-20 10:00:00',
 *     'e' => '2026-10-20 12:00:00',
 * ]);
 */
class DB
{
    private static ?PDO $pdo = null;

    /**
     * Zwraca aktywne połączenie PDO.
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (Throwable) {
                self::$pdo = null;
            }
        }

        $host = getenv('DB_HOST') ?: 'mysql';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_NAME') ?: 'app_db';
        $user = getenv('DB_USER') ?: 'app_user';
        $pass = getenv('DB_PASSWORD') ?: 'app_password';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            self::$pdo->exec("SET NAMES utf8mb4");
            self::$pdo->exec("SET sql_mode = ''");

            return self::$pdo;
        } catch (PDOException $e) {
            error_log('[DB] Nie można nawiązać połączenia: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Wykonuje zapytanie i zwraca statement.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Wykonuje zapytanie bez zwracania wyników.
     */
    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = self::getConnection()->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Zwraca wszystkie wiersze.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Zwraca pierwszy wiersz lub null.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Zwraca wartość z pierwszej kolumny pierwszego wiersza.
     */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return self::query($sql, $params)->fetchColumn($column);
    }

    /**
     * Wstawia rekord i zwraca ID ostatniego wstawionego rekordu.
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);
        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * Rozpoczyna transakcję.
     */
    public static function beginTransaction(): void
    {
        self::getConnection()->beginTransaction();
    }

    /**
     * Zatwierdza transakcję.
     */
    public static function commit(): void
    {
        self::getConnection()->commit();
    }

    /**
     * Wycofuje transakcję.
     */
    public static function rollBack(): void
    {
        self::getConnection()->rollBack();
    }

    public static function getEvents(): array
    {
        return self::fetchAll('SELECT * FROM events WHERE date_start <= NOW() ORDER BY date_start DESC');
    }

    public static function getRunnersCount(string $eventCode): int
    {
        $sql = 'SELECT COUNT(participants.start_number)
                FROM participants
                INNER JOIN events ON participants.event_id = events.id
                WHERE events.EVENT_CODE = :code';

        return (int) self::fetchColumn($sql, ['code' => $eventCode]);
    }

    public static function getTrackersCount(string $eventCode): array
    {
        $sql = 'SELECT p.first_name, p.last_name, COUNT(tg.tag_code) AS lap, d.KmPerLap
                FROM tag_observations AS tg
                JOIN chip AS c ON c.chip_number = tg.tag_code
                JOIN participants AS p ON c.participant_id = p.id
                JOIN distances AS d ON p.distance_id = d.id
                WHERE tg.event_code = :code
                GROUP BY p.id, p.first_name, p.last_name, d.KmPerLap;';

        return self::fetchAll($sql, ['code' => $eventCode]);
    }

    public static function getLapDistance(string $eventCode): array
    {
        $sql = 'SELECT distance, KmPerLap FROM `distances`, events 
                WHERE event_id=events.id AND events.EVENT_CODE=:code ;';
        return self::fetchAll($sql, ['code' => $eventCode]);
    }
    public static function getLapList(string $eventCode): array
    {
        $sql = 'SELECT * FROM `EventListOfLaps` 
        WHERE event_code = :code 
        ORDER by observation_time DESC;';
        return self::fetchAll($sql, ['code' => $eventCode]);
    }
}
