# Architektura systemu Czasomierz

## 1. Przegląd

System Czasomierz składa się z kontenerów Docker uruchamiających:

- Apache + PHP,
- MySQL,
- phpMyAdmin,
- serwer TCP odbierający dane z urządzeń pomiarowych.

W praktyce głównym modułem odpowiedzialnym za integrację z urządzeniem jest `src/receiver.php`.

## 2. Strumień danych

1. Urządzenie ChronoTrack wysyła dane do portu TCP 61611.
2. `receiver.php` przyjmuje połączenie i odczytuje linie protokołu CTP01.
3. Po wykryciu odczytu `TO~...` dane są przekazywane do `persistObservation()`.
4. Dane są zapisane w WAL na dysku.
5. Następnie następuje próba zapisu do MySQL.
6. Jeśli baza nie jest dostępna, dane trafiają do pamięciowego bufora i są ponawiane później.

## 3. Obsługa awarii

System ma 4 warstwy odporności:

### 3.1 WAL

Każdy rekord jest zapisywany do pliku na dysku przed próbą zapisu do MySQL. Używany jest `fopen(..., 'ab')`, `flock()`, `fflush()`, oraz `fsync()`.

### 3.2 Bufor pamięciowy

Jeżeli baza jest niedostępna, obiekt `memoryBuffer` przechowuje rekordy. Po odzyskaniu połączenia następuje ich próba wysłania do bazy.

### 3.3 Odtworzenie po restarcie

Po starcie `recoverFromWAL()` sprawdza plik i próbuje wstawić brakujące rekordy do bazy, wykorzystując `INSERT IGNORE`.

### 3.4 Komunikacja z MySQL

Połączenie PDO jest utrzymywane w cache, a przy błędzie wykonywany jest nowy retry. Dodatkowo ustawione są parametry:

- `innodb_flush_log_at_trx_commit = 1`
- `sync_binlog = 1`

## 4. Tabele bazy danych

### `tag_observations`

Tabela główna dla danych pomiarowych. Zawiera odczyty czujników i jest zoptymalizowana pod operacje filtrowania po tagu, czasie i czytniku.

### `events`

Definiuje wydarzenia sportowe takie jak konkursy, bieg, zawody.

### `distances`

Przechowuje dystanse przypisane do danego eventu.

### `participants`

Rejestracje zawodników wraz z danymi osobowymi i powiązaniami z eventem oraz dystansem.

### `chip`

Mapowanie numeru chipa na zawodnika.

## 5. Kontenery

### `php`

Uruchamia:

- Apache,
- aplikację PHP,
- odbiornik CTP01 przez supervisor.

### `mysql`

Działa w trybie MySQL 8.0 i ma skrypty inicjalizacji z katalogu `docker/mysql/init`.

### `phpmyadmin`

Pozwala na łatwe sprawdzanie danych w bazie bezpośrednio z przeglądarki.

## 6. Konfiguracja środowiska

Plik `.env` zawiera dane konfiguracyjne dla bazy. Najważniejsze wartości:

- `MYSQL_ROOT_PASSWORD`
- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `DB_HOST`
- `DB_PORT`

## 7. Uruchamianie i utrzymanie

Przykładowe polecenia:

```bash
docker compose up --build -d
docker compose logs -f php
docker compose ps
docker compose down
```

## 8. Rozszerzenia

W przyszłości można rozszerzyć system o:

- panel administracyjny,
- API REST,
- automatyczne raportowanie wyników,
- eksport do CSV / JSON,
- integrację z systemami live timing.
