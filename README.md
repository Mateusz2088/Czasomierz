# Czasomierz
Projekt jest jeszcze niegotowy.

System do zbierania i przechowywania wyników z pomiarów czasu dla zawodów biegowych i eventów sportowych. Projekt wykorzystuje odbiornik ChronoTrack CTP01, który nasłuchuje na porcie TCP, zapisuje dane do bazy MySQL i utrzymuje trwałość odczytów za pomocą Write-Ahead Log (WAL).

## 1. Cel projektu

Celem systemu jest:

- odbieranie odczytów z urządzeń ChronoTrack / SimpleClient,
- walidacja i zapis informacji o tagach i zdarzeniach,
- przechowywanie danych w relacyjnej bazie MySQL,
- zapewnienie odporności na awarie poprzez zapis do pliku WAL,
- uruchamianie całego środowiska w kontenerach Docker.

## 2. Architektura

System składa się z trzech głównych usług:

- `php` – kontener z Apache + PHP 8.2,
- `mysql` – baza danych MySQL 8.0,
- `phpmyadmin` – interfejs do zarządzania bazą.

### Komponenty

- `src/receiver.php` – główny serwer TCP odbierający pakiety od urządzeń CTP01.
- `src/testDb.php` – prosty test połączenia do MySQL oraz demonstracja zapisu danych testowych.
- `docker/mysql/init/*.sql` – skrypty inicjalizacji schematu bazy danych.
- `supervisord.conf` – konfiguracja uruchamiania Apache oraz odbiornika TCP.
- `docker-compose.yml` – definicja całego środowiska Docker.

## 3. Porty i usługi

| Usługa | Adres | Opis |
| --- | --- | --- |
| Apache/PHP | http://localhost:8101 | aplikacja webowa / test połączenia |
| phpMyAdmin | http://localhost:8102 | zarządzanie bazą MySQL |
| TCP receiver | tcp://localhost:61611 | odbiór danych z ChronoTrack CTP01 |
| MySQL | localhost:3306 | baza danych wewnątrz dockerowego sieci |

## 4. Wymagania

- Docker
- Docker Compose
- dostęp do portów 8101, 8102, 61611

## 5. Szybki start

1. Skopiuj plik konfiguracyjny:

   ```bash
   cp .env.example .env
   ```

2. W razie potrzeby zaktualizuj dane uwierzytelniające w pliku `.env`.

3. Uruchom środowisko:

   ```bash
   docker compose up --build -d
   ```

4. Sprawdź stan kontenerów:

   ```bash
   docker compose ps
   ```

5. Zaloguj się do aplikacji webowej:

   - http://localhost:8101
   - http://localhost:8102

## 6. Struktura bazy danych

Skrypty w katalogu `docker/mysql/init` tworzą następujące tabele:

- `tag_observations` – odczyty czujników/tagów z urządzeń pomiarowych,
- `events` – definicje zawodów / eventów,
- `distances` – dystanse przypisane do wydarzenia,
- `participants` – uczestnicy zawodów,
- `chip` – przypisanie chipów do zawodników,
- `event_age_categories` – kategorie wiekowe i ich zakresy przypisane do wydarzeń.

Kategorie wiekowe dodaje się, edytuje i usuwa w panelu wydarzeń. Przedziały są domknięte z obu stron; puste „Wiek do” oznacza brak górnego limitu. Zakresy kategorii w obrębie jednego wydarzenia nie mogą się nakładać.

Skrypty z `docker/mysql/init` są uruchamiane automatycznie tylko przy pierwszym tworzeniu wolumenu MySQL. Aby dodać tabelę kategorii do już działającej bazy, wykonaj z katalogu projektu:

```bash
docker compose exec -T mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < docker/mysql/init/06-event-age-categories.sql
```

Najważniejsza tabela to `tag_observations`, która przechowuje:

- `sequence_number`
- `tag_code`
- `timer_code`
- `event_code`
- `observation_time`
- `reader_id`
- `port_number`
- `rssi`
- `reader_sequence`
- `transaction_type`
- `connection_id`

## 7. Mechanizm odbierania danych

Serwer `receiver.php` działa w trybie TCP i nasłuchuje na porcie 61611. Po nawiązaniu połączenia odczytuje linie z protokołu CTP01 i interpretuje komunikaty, takie jak:

- powitanie i inicjalizacja sesji,
- identyfikacja połączenia,
- potwierdzenia `ack-start`,
- odczyty typu `TO~...`.

Po każdym poprawnym odczycie dane są najpierw zapisane do pliku WAL, a dopiero potem próbowany jest zapis do bazy MySQL.

## 8. Mechanizm trwałości WAL

W katalogu `/var/lib/chronotrack/wal` zapisywane są rekordy w formacie logu. Dzięki temu po awarii serwera lub chwilowego przestoju bazy można odzyskać brakujące dane po restarcie:

- `recoverFromWAL()` odczytuje zapisane linie,
- próbuje wstawić je do bazy,
- po sukcesie czyści plik WAL.

To zwiększa odporność systemu na utratę danych przy nagłych przerwach zasilania lub chwilowej niedostępności MySQL.

## 9. Ochrona przed utratą danych

Projekt przewiduje kilka warstw ochrony:

- zapis do pliku WAL z `fsync()`,
- użycie transakcji i ustawień MySQL `innodb_flush_log_at_trx_commit = 1`,
- ponawianie połączenia z bazą po błędach,
- bufor pamięciowy `memoryBuffer` do przechowywania rekordów w czasie awarii bazy,
- sterowanie tym, aby nie dopuścić do przepełnienia pamięci.

## 10. Logowanie i diagnostyka

Ważne informacje są zapisane do logów PHP / supervisor. Możesz śledzić logi:

```bash
docker compose logs -f php
```

Dodatkowo:

```bash
docker compose logs -f mysql
```

## 11. Rozwiązywanie problemów

### Problem: brak połączenia z bazą

Sprawdź:

- czy kontener `mysql` działa,
- czy w pliku `.env` są poprawne dane dostępu,
- czy MySQL jest gotowe do przyjmowania połączeń.

### Problem: brak danych w bazie mimo odbioru

Sprawdź:

- czy plik WAL został utworzony,
- czy `receiver.php` działa pod supervisord,
- czy port 61611 jest otwarty.

### Problem: aplikacja webowa nie działa

Sprawdź logi Apache i PHP:

```bash
docker compose logs -f php
```

## 12. Dalszy rozwój

W kolejnych etapach można dodać:

- panel administracyjny do przeglądu zarejestrowanych zawodników,
- API REST do pobierania wyników,
- import / eksport danych CSV,
- integrację z systemami wyników live,
- automatyczne raportowanie i analitykę wyników.


---

Dokumentację techniczną szczegółową można znaleźć w katalogu `docs/`.
