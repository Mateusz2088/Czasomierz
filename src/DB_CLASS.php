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
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
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
        $sql = 'SELECT tg.id, tg.tag_code, tg.observation_time,
                       p.start_number, p.first_name, p.last_name,
                       d.distance, d.KmPerLap
                FROM tag_observations AS tg
                LEFT JOIN chip AS c ON c.chip_number = tg.tag_code
                LEFT JOIN participants AS p ON p.id = c.participant_id
                LEFT JOIN distances AS d ON d.id = p.distance_id
                WHERE tg.event_code = :code
                ORDER BY tg.observation_time DESC';

        return self::fetchAll($sql, ['code' => $eventCode]);
    }

    public static function getDistancesByEvent(int $eventId): array
    {
        return self::fetchAll(
            'SELECT * FROM distances WHERE event_id = :event_id ORDER BY distance ASC',
            ['event_id' => $eventId]
        );
    }

    public static function getManagementEvents(): array
    {
        return self::fetchAll('SELECT * FROM events ORDER BY date_start DESC');
    }

    public static function getParticipantForManagement(int $participantId): ?array
    {
        return self::fetchOne(
            'SELECT p.*,
                    (SELECT c.chip_number
                     FROM chip AS c
                     WHERE c.participant_id = p.id
                     ORDER BY c.chip_number ASC
                     LIMIT 1) AS chip_number
             FROM participants AS p
             WHERE p.id = :id',
            ['id' => $participantId]
        );
    }

    public static function getParticipantsForManagement(?int $eventId = null): array
    {
        $sql = 'SELECT p.*, e.event_name, d.distance,
                       (SELECT c.chip_number
                        FROM chip AS c
                        WHERE c.participant_id = p.id
                        ORDER BY c.chip_number ASC
                        LIMIT 1) AS chip_number
                FROM participants AS p
                LEFT JOIN events AS e ON e.id = p.event_id
                LEFT JOIN distances AS d ON d.id = p.distance_id';
        $params = [];

        if ($eventId !== null) {
            $sql .= ' WHERE p.event_id = :event_id';
            $params['event_id'] = $eventId;
        }

        $sql .= ' ORDER BY p.start_number ASC, p.last_name ASC, p.first_name ASC';

        return self::fetchAll($sql, $params);
    }

    public static function saveParticipantForManagement(array $params, ?int $participantId, ?string $chipNumber): int
    {
        self::beginTransaction();

        try {
            if ($participantId === null) {
                $participantId = self::insert(
                    'INSERT INTO participants (
                        start_number, first_name, last_name, event_id, distance_id, addictional_text,
                        gender, birth_date, city, country, club, email, phone_number, disability, about_me
                    ) VALUES (
                        :start_number, :first_name, :last_name, :event_id, :distance_id, :addictional_text,
                        :gender, :birth_date, :city, :country, :club, :email, :phone_number, :disability, :about_me
                    )',
                    $params
                );
            } else {
                $params['id'] = $participantId;
                self::execute(
                    'UPDATE participants SET
                        start_number = :start_number,
                        first_name = :first_name,
                        last_name = :last_name,
                        event_id = :event_id,
                        distance_id = :distance_id,
                        addictional_text = :addictional_text,
                        gender = :gender,
                        birth_date = :birth_date,
                        city = :city,
                        country = :country,
                        club = :club,
                        email = :email,
                        phone_number = :phone_number,
                        disability = :disability,
                        about_me = :about_me
                     WHERE id = :id',
                    $params
                );
            }

            if ($participantId < 1 || self::fetchOne(
                'SELECT id FROM participants WHERE id = :id',
                ['id' => $participantId]
            ) === null) {
                throw new RuntimeException('Nie znaleziono zapisanego zawodnika do przypisania chipu.');
            }

            self::execute(
                'DELETE FROM chip WHERE participant_id = :participant_id',
                ['participant_id' => $participantId]
            );
            if ($chipNumber !== null && $chipNumber !== '') {
                self::execute(
                    'INSERT INTO chip (chip_number, participant_id) VALUES (:chip_number, :participant_id)',
                    ['chip_number' => $chipNumber, 'participant_id' => $participantId]
                );
            }

            self::commit();
            return $participantId;
        } catch (Throwable $e) {
            if (self::getConnection()->inTransaction()) {
                self::rollBack();
            }
            throw $e;
        }
    }

    public static function deleteParticipantForManagement(int $participantId): void
    {
        self::beginTransaction();

        try {
            self::execute(
                'DELETE FROM chip WHERE participant_id = :participant_id',
                ['participant_id' => $participantId]
            );
            self::execute('DELETE FROM participants WHERE id = :id', ['id' => $participantId]);
            self::commit();
        } catch (Throwable $e) {
            if (self::getConnection()->inTransaction()) {
                self::rollBack();
            }
            throw $e;
        }
    }
    public static function rankedIndividual(int $eventId): array
    {
        $sql = 'WITH ranked AS (
                    SELECT p.id AS participant_id,
                           p.event_id,
                           t.observation_time AS ts,
                           ROW_NUMBER() OVER (
                               PARTITION BY p.id
                               ORDER BY t.observation_time, t.id
                           ) AS rn
                    FROM tag_observations AS t
                    INNER JOIN chip AS c ON c.chip_number = t.tag_code
                    INNER JOIN participants AS p ON p.id = c.participant_id
                    WHERE p.event_id = :event_id
                ),
                per_runner AS (
                    SELECT r.participant_id,
                           MAX(CASE WHEN r.rn = 1 THEN r.ts END) AS start_time,
                           MAX(
                               CASE
                                   WHEN r.rn = CAST(d.KmPerLap AS UNSIGNED) + 1 THEN r.ts
                               END
                           ) AS finish_time,
                           COUNT(*) AS crossings_cnt,
                           CAST(d.KmPerLap AS UNSIGNED) AS laps_needed
                    FROM ranked AS r
                    INNER JOIN participants AS p ON p.id = r.participant_id
                    INNER JOIN distances AS d ON d.id = p.distance_id
                    WHERE d.KmPerLap > 0
                    GROUP BY r.participant_id, d.distance, d.KmPerLap
                ),
                finishers AS (
                    SELECT p.id AS participant_id,
                           p.start_number,
                           p.first_name,
                           p.last_name,
                           p.gender,
                           p.club,
                           c.chip_number,
                           p.distance_id,
                           d.distance AS distance_km,
                           CASE
                               WHEN TIMESTAMPDIFF(YEAR, p.birth_date, e.date_start) < 18 THEN \'U18\'
                               WHEN TIMESTAMPDIFF(YEAR, p.birth_date, e.date_start) < 30 THEN \'18-29\'
                               WHEN TIMESTAMPDIFF(YEAR, p.birth_date, e.date_start) < 40 THEN \'30-39\'
                               WHEN TIMESTAMPDIFF(YEAR, p.birth_date, e.date_start) < 50 THEN \'40-49\'
                               WHEN TIMESTAMPDIFF(YEAR, p.birth_date, e.date_start) < 60 THEN \'50-59\'
                               ELSE \'60+\'
                           END AS age_category,
                           pr.start_time,
                           pr.finish_time,
                           pr.finish_time - pr.start_time AS elapsed_s
                    FROM per_runner AS pr
                    INNER JOIN participants AS p ON p.id = pr.participant_id
                    INNER JOIN chip AS c ON c.participant_id = p.id
                    INNER JOIN distances AS d ON d.id = p.distance_id
                    INNER JOIN events AS e ON e.id = p.event_id
                    WHERE pr.finish_time IS NOT NULL
                      AND pr.crossings_cnt >= pr.laps_needed + 1
                )
                SELECT f.*,
                       RANK() OVER (
                           PARTITION BY f.distance_id, f.age_category, f.gender
                           ORDER BY f.elapsed_s
                       ) AS place_age_gender,
                       RANK() OVER (
                           PARTITION BY f.distance_id
                           ORDER BY f.elapsed_s
                       ) AS place_open
                FROM finishers AS f
                ORDER BY f.distance_km, f.age_category, f.gender, f.elapsed_s';

        return self::fetchAll($sql, ['event_id' => $eventId]);
    }

}
