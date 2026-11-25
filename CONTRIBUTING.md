# Mitmachen

Danke für dein Interesse an ClaudyCMS. Fehlermeldungen, Verbesserungsvorschläge und Pull Requests sind willkommen.

## Fehler und Wünsche

Lege ein Issue an und nutze die Vorlagen. Bei Fehlern helfen PHP-Version, Webserver, Schritte zum Nachstellen und die erwartete Ausgabe. Sicherheitslücken meldest du nicht hier, sondern wie in [SECURITY.md](SECURITY.md).

## Pull Requests

1. Arbeite auf einem eigenen Branch und halte den Pull Request klein und zielgerichtet.
2. Lass vor dem Einreichen alle Tests laufen: `for t in tests/*.php; do php "$t" || exit 1; done`. Neue Logik bekommt einen Test, ein behobener Fehler einen Test, der ihn belegt.
3. Prüfe jede geänderte Datei mit `php -l`.
4. Beschreibe im Pull Request, was sich ändert und warum.

## Leitplanken

- PHP 8.1 oder neuer, kein Framework, kein Composer, keine Datenbank, kein Build-Schritt. Das System läuft auf Shared Hosting per FTP-Upload.
- Neue Dateien beginnen mit `declare(strict_types=1);`.
- Eingaben nie direkt aus `$_POST`/`$_GET`, sondern über `post_str()`, `post_bool()`, `post_int()` und `get_str()`.
- Ausgaben immer über `e()` escapen.
- Feste Wertebereiche (Rollen, Status, Typen) gehören als Enum nach `lib/enums.php`.
- Kein neuer Code in `admin.php`: Handler nach `lib/admin/handlers-*.php`, Markup nach `lib/views/`.
- Neue Dateien unter `lib/` müssen in `build_webroot()` von `tests/admin.php` kopiert werden, sonst fehlen sie im Testlauf.
- Kommentare und Oberflächentexte sind auf Deutsch. Kommentare erklären das Warum, nicht das Was.

Mit einem Beitrag stimmst du zu, dass er unter der MIT-Lizenz des Projekts veröffentlicht wird.
