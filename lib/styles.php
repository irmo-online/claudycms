<?php
/**
 * Gemeinsame Style-Bibliothek für die KI-Anbindung.
 *
 * Analog zu lib/content.php und lib/media.php: Bündelt Parsing/Serialisierung
 * des :root-Blocks in assets/style.css, das freie Override-CSS in
 * assets/custom.css sowie Backup/Historie/Restore für beide — bisher liess
 * sich das Design ausschliesslich per direktem Datei-/Git-Zugriff ändern,
 * es gibt (anders als bei Bildern) keine bestehende Admin-Funktion,
 * auf der aufgebaut werden könnte.
 *
 *   - cms_get_style_tokens()        Lesen der zehn :root-Variablen
 *   - cms_update_style_tokens()     Validieren + Schreiben (nur der :root-Block)
 *   - cms_get_custom_css()          Lesen von assets/custom.css
 *   - cms_update_custom_css()       Validieren + Schreiben (komplette Datei)
 *   - cms_style_history_*()         Vorversionen + Undo für beide Ziele
 *
 * Anders als lib/content.php braucht diese Bibliothek keine Frontmatter- oder
 * Seiten-Logik und bindet lib/content.php deshalb bewusst nicht ein — analog
 * zu lib/media.php stehen alle drei Dateien unabhängig nebeneinander.
 *
 * Style-Änderungen wirken sofort auf die Live-Website, es gibt keinen
 * draft-ähnlichen Vorschau-Modus (Abschnitt 0 Punkt 9) — Backup +
 * Undo (restore_styles) sind hier das komplette Sicherheitsnetz.
 *
 * HTTP-Zugriff auf diesen Ordner ist per lib/.htaccess gesperrt.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Konfiguration ────────────────────────────────────────────────────────────

// Guard analog UPLOAD_DIR in lib/media.php: mcp.php kennt bisher keine eigene
// Assets-Konstante, ein künftiges Admin-UI (Abschnitt 8) könnte sie
// vorher selbst setzen, ohne diese Datei anzufassen.
if (!defined('CMS_STYLE_CSS_FILE')) {
    define('CMS_STYLE_CSS_FILE', dirname(__DIR__) . '/assets/style.css');
}
if (!defined('CMS_CUSTOM_CSS_FILE')) {
    define('CMS_CUSTOM_CSS_FILE', dirname(__DIR__) . '/assets/custom.css');
}

// Die zehn bekannten :root-Variablen (Abschnitt "v1.1" — Liste ist
// bindend). update_styles lehnt jeden anderen Schlüssel ab, es entstehen nie
// neue Variablen.
if (!defined('CMS_STYLE_TOKEN_KEYS')) {
    define('CMS_STYLE_TOKEN_KEYS', [
        '--color-bg',
        '--color-text',
        '--color-text-light',
        '--color-border',
        '--color-link',
        '--color-link-hover',
        '--font-heading',
        '--font-body',
        '--max-width',
        '--header-height',
    ]);
}

// Whitelist bereits selbst gehosteter Font-Stacks (Abschnitt 6.2),
// wörtlich identisch zu den Werten in assets/style.css. Beide Font-Variablen
// teilen sich dieselbe Liste — Claude darf z.B. auch Quicksand für
// --font-heading eintragen, nur ein dritter, nicht selbst gehosteter Font
// waere ein Fehler.
if (!defined('CMS_STYLE_FONT_WHITELIST')) {
    define('CMS_STYLE_FONT_WHITELIST', [
        "'League Spartan', 'Helvetica Neue', Helvetica, Arial, sans-serif",
        "'Quicksand', 'Helvetica Neue', Helvetica, Arial, sans-serif",
    ]);
}

// Aufbewahrung der Style-Vorversionen, identische Regel wie content/.history
// (Abschnitt 6.3): letzte 20 behalten UND alles aelter als 90 Tage
// entfernen — was zuerst greift.
if (!defined('CMS_STYLE_HISTORY_KEEP')) {
    define('CMS_STYLE_HISTORY_KEEP', 20);
}
if (!defined('CMS_STYLE_HISTORY_MAX_AGE')) {
    define('CMS_STYLE_HISTORY_MAX_AGE', 90 * 86400);
}

/** Einheitliches Fehlerformat, analog cms_crud_error() / cms_media_error(). */
function cms_style_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

// ─── Token-Validierung ────────────────────────────────────────────────────────

/**
 * Prüft einen einzelnen Token-Wert auf Zeichen, die eine CSS-Deklaration
 * verlassen könnten (Abschnitt 4.4). Anders als bei
 * Frontmatter-Strings (cms_build_frontmatter()) wird hier abgelehnt statt
 * escaped — ein Farbwert mit Semikolon ist schlicht ungültig, es gibt keinen
 * sinnvollen escapten Fall.
 *
 * Zeilenumbrüche sind zusätzlich verboten, obwohl die Spec sie nicht
 * einzeln aufzählt: der :root-Block wird zeilenweise neu zusammengesetzt
 * (siehe cms_style_render_root_block()), ein eingeschleuster Umbruch würde
 * dort ohne Semikolon eine zusätzliche Deklarationszeile erzeugen.
 */
function cms_style_value_safe(string $value): bool {
    return !preg_match('/[;{}<\r\n]|\/\*/', $value);
}

/**
 * Validiert einen einzelnen Token-Schlüssel/Wert. Font-Variablen zusätzlich
 * gegen CMS_STYLE_FONT_WHITELIST, alle anderen nur gegen den
 * Injection-Zeichen-Check.
 */
function cms_style_validate_token(string $key, string $value): array {
    if (!in_array($key, CMS_STYLE_TOKEN_KEYS, true)) {
        return cms_style_error(
            'unknown_token',
            'Unbekannter Token-Schlüssel: ' . $key . '. Erlaubt sind: ' . implode(', ', CMS_STYLE_TOKEN_KEYS) . '.'
        );
    }

    $value = trim($value);
    if ($value === '') {
        return cms_style_error('invalid_value', 'Der Wert für ' . $key . ' darf nicht leer sein.');
    }

    if (($key === '--font-heading' || $key === '--font-body') && !in_array($value, CMS_STYLE_FONT_WHITELIST, true)) {
        return cms_style_error(
            'invalid_font',
            'Nicht erlaubter Font für ' . $key . '. Erlaubt sind nur bereits selbst gehostete Fonts: '
                . implode(' / ', CMS_STYLE_FONT_WHITELIST) . '.'
        );
    }

    if (!cms_style_value_safe($value)) {
        return cms_style_error('invalid_value', 'Der Wert für ' . $key . ' enthält unzulässige Zeichen (u.a. ; { } < /*).');
    }

    return ['ok' => true, 'key' => $key, 'value' => $value];
}

// ─── Design-Tokens lesen ───────────────────────────────────────────────────────

/**
 * Extrahiert den :root-Block aus assets/style.css als [Startposition, Länge,
 * Inhalt zwischen den geschweiften Klammern], oder false wenn er fehlt.
 *
 * PREG_OFFSET_CAPTURE statt eines globalen preg_replace() beim Schreiben:
 * die exakte Byte-Position erlaubt ein substr_replace() ohne die
 * Backslash-/Dollar-Sonderbehandlung von preg_replace()-Ersetzungsstrings —
 * ein Token-Wert mit "$1" o.ä. würde sonst falsch interpretiert.
 *
 * Kein verschachteltes "{" im Block erwartet (reine Variablendeklarationen),
 * [^}]* bis zur ersten schliessenden Klammer reicht deshalb aus.
 *
 * @return array{start: int, end: int, inner: string}|false
 */
function cms_style_locate_root_block(string $raw): array|false {
    if (!preg_match('/:root\s*\{([^}]*)\}/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    return [
        'start' => $m[0][1],
        'end'   => $m[0][1] + strlen($m[0][0]),
        'inner' => $m[1][0],
    ];
}

/** Parst die "--name: wert;"-Deklarationen innerhalb eines :root-Blockinhalts. */
function cms_style_parse_declarations(string $inner): array {
    $tokens = [];
    if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $inner, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }
    }
    return $tokens;
}

/**
 * Liest die aktuellen Werte der zehn bekannten Design-Tokens aus
 * assets/style.css (Abschnitt 5.1).
 *
 * Fehlt die Datei oder der :root-Block, liefert die Funktion ein leeres
 * Array zurück — im Normalbetrieb sollte das nie vorkommen, style.css ist
 * fester Bestandteil des Repos.
 *
 * @return array<string, string> Nur bekannte Schlüssel, in der Reihenfolge von CMS_STYLE_TOKEN_KEYS.
 */
function cms_get_style_tokens(): array {
    $raw = @file_get_contents(CMS_STYLE_CSS_FILE);
    if ($raw === false) {
        return [];
    }

    $block = cms_style_locate_root_block($raw);
    if ($block === false) {
        return [];
    }
    $parsed = cms_style_parse_declarations($block['inner']);

    $tokens = [];
    foreach (CMS_STYLE_TOKEN_KEYS as $key) {
        if (array_key_exists($key, $parsed)) {
            $tokens[$key] = $parsed[$key];
        }
    }
    return $tokens;
}

// ─── Design-Tokens schreiben ────────────────────────────────────────────────────

/** Baut den kompletten :root-Block (inkl. Klammern) aus einem Token-Array neu auf. */
function cms_style_render_root_block(array $tokens): string {
    $lines = [':root {'];
    foreach (CMS_STYLE_TOKEN_KEYS as $key) {
        if (array_key_exists($key, $tokens)) {
            $lines[] = '    ' . $key . ': ' . $tokens[$key] . ';';
        }
    }
    $lines[] = '}';
    return implode("\n", $lines);
}

/**
 * Ändert einzelne Design-Tokens (Abschnitt 5.2).
 *
 * $changes ist eine Teilmenge der bekannten Schlüssel, z.B.
 * ['--color-link' => '#0033aa']. Validiert wird alles VOR dem ersten
 * Schreibzugriff (kein Teil-Update bei einem ungültigen Schlüssel mittendrin)
 * — anschliessend Backup von style.css, dann wird ausschliesslich der
 * :root-Block ersetzt, der Rest der Datei bleibt Byte für Byte unangetastet.
 *
 * @param array<string, string> $changes
 * @return array{ok: true, changed: array<string, string>}|array{ok: false, code: string, error: string}
 */
function cms_update_style_tokens(array $changes): array {
    if ($changes === []) {
        return cms_style_error('no_changes', 'Es wurden keine Token-Änderungen übergeben.');
    }

    // Erst validieren, dann schreiben — ein ungültiger Schlüssel irgendwo in
    // $changes darf nicht dazu führen, dass die anderen bereits übernommen
    // wurden.
    $validated = [];
    foreach ($changes as $key => $value) {
        $result = cms_style_validate_token((string) $key, (string) $value);
        if ($result['ok'] === false) {
            return $result;
        }
        $validated[$result['key']] = $result['value'];
    }

    $raw = @file_get_contents(CMS_STYLE_CSS_FILE);
    if ($raw === false) {
        return cms_style_error('io_error', 'assets/style.css konnte nicht gelesen werden.');
    }
    $block = cms_style_locate_root_block($raw);
    if ($block === false) {
        return cms_style_error('io_error', 'Der :root-Block in assets/style.css wurde nicht gefunden.');
    }

    if (cms_style_history_backup('tokens', CMS_STYLE_CSS_FILE) === false) {
        return cms_style_error('backup_failed', 'Der aktuelle Stand liess sich nicht sichern. Es wurde nichts geändert.');
    }

    $current   = cms_style_parse_declarations($block['inner']);
    $new_block = cms_style_render_root_block(array_merge($current, $validated));
    $new_raw   = substr_replace($raw, $new_block, $block['start'], $block['end'] - $block['start']);

    if (file_put_contents(CMS_STYLE_CSS_FILE, $new_raw, LOCK_EX) === false) {
        return cms_style_error('io_error', 'assets/style.css konnte nicht geschrieben werden.');
    }
    cms_style_history_prune('tokens');

    return ['ok' => true, 'changed' => $validated];
}

// ─── Freies CSS lesen & schreiben ───────────────────────────────────────────────

/** Aktueller Inhalt von assets/custom.css, leerer String falls (noch) nicht vorhanden. */
function cms_get_custom_css(): string {
    if (!is_file(CMS_CUSTOM_CSS_FILE)) {
        return '';
    }
    return (string) (@file_get_contents(CMS_CUSTOM_CSS_FILE) ?: '');
}

/**
 * Prüft freies CSS auf externe Nachladevorgänge (Abschnitt 4.4):
 * jede Form von @import sowie url(...) mit http://, https:// oder
 * protokollrelativem // davor. data: und relative Pfade (assets/..., wie bei
 * Content wird auch hier ../ abgelehnt) bleiben erlaubt.
 */
function cms_custom_css_safe(string $css): bool {
    if (preg_match('/@import\b/i', $css)) {
        return false;
    }
    if (preg_match('#url\(\s*[\'"]?\s*(https?:)?//#i', $css)) {
        return false;
    }
    if (preg_match('/\.\.\//', $css)) {
        return false;
    }
    return true;
}

/**
 * Überschreibt assets/custom.css vollständig (Abschnitt 5.4).
 *
 * Existiert die Datei noch nicht (erster Schreibzugriff überhaupt), entfällt
 * das Backup — es gibt keinen vorherigen Stand zu sichern, das ist kein
 * Fehler.
 */
function cms_update_custom_css(string $css): array {
    if (!cms_custom_css_safe($css)) {
        return cms_style_error(
            'external_resource',
            'Externe Nachladevorgänge (@import, externe url()) sind nicht erlaubt — nur selbst gehostete/relative Pfade oder data:-URLs.'
        );
    }

    if (is_file(CMS_CUSTOM_CSS_FILE) && cms_style_history_backup('custom_css', CMS_CUSTOM_CSS_FILE) === false) {
        return cms_style_error('backup_failed', 'Der aktuelle Stand liess sich nicht sichern. Es wurde nichts geändert.');
    }

    if (file_put_contents(CMS_CUSTOM_CSS_FILE, $css, LOCK_EX) === false) {
        return cms_style_error('io_error', 'assets/custom.css konnte nicht geschrieben werden.');
    }
    cms_style_history_prune('custom_css');

    return ['ok' => true];
}

// ─── Versionshistorie ────────────────────────────────────────────────────────
//
// Vorversionen liegen unter config/style-history/{tokens|custom-css}/
// {zeitstempel}.css. Bewusst unter config/, nicht assets/ (Abschnitt 4.3):
// assets/ wird öffentlich ausgeliefert, eine Historie dort
// bräuchte eine neue .htaccess-Sperre. config/ ist bereits vollständig
// gesperrt (Require all denied, kein AllowOverride) und schon Vorbild für
// laufzeit-erzeugte Verzeichnisse (config/ratelimit/, config/reset/) — anders
// als bei content/.history/ braucht es hier deshalb KEINE eigene .htaccess.
//
// $target ist 'tokens' oder 'custom_css' (so auch im Tool-Parameter von
// restore_styles, Abschnitt 5.5); der Ordnername selbst heisst
// "custom-css" mit Bindestrich, analog zur Datei assets/custom.css.

/** Wurzelverzeichnis der Style-Versionshistorie. */
function cms_style_history_root(): string {
    return dirname(__DIR__) . '/config/style-history';
}

/** Verzeichnis der Vorversionen eines Ziels, oder false bei unbekanntem $target. */
function cms_style_history_dir(string $target): string|false {
    $map = ['tokens' => 'tokens', 'custom_css' => 'custom-css'];
    if (!isset($map[$target])) {
        return false;
    }
    return cms_style_history_root() . '/' . $map[$target];
}

/**
 * Sichert den aktuellen Stand einer Style-Datei als Vorversion, analog
 * cms_history_backup() in lib/content.php.
 *
 * @return string|false Pfad der Sicherung, oder false wenn sie misslungen ist.
 */
function cms_style_history_backup(string $target, string $file): string|false {
    if (!is_file($file) || !is_readable($file)) {
        return false;
    }

    $dir = cms_style_history_dir($target);
    if ($dir === false) {
        return false;
    }
    // Modus 0700 wie config/ratelimit/ (lib/security.php) — anders als
    // content/.history/ (0755 + eigene .htaccess) liegt dieses Verzeichnis
    // unter config/, das bereits pauschal gesperrt ist.
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    // Sekundengenauer Stempel, Suffix bei Kollision — identisches Muster wie
    // cms_history_backup(), sortiert lexikografisch weiterhin chronologisch.
    $stamp  = date('Y-m-d_His');
    $target_file = $dir . '/' . $stamp . '.css';
    for ($i = 1; $i < 100 && file_exists($target_file); $i++) {
        $target_file = $dir . '/' . $stamp . '-' . $i . '.css';
    }
    if (file_exists($target_file) || !@copy($file, $target_file)) {
        return false;
    }

    return $target_file;
}

/**
 * Vorversionen eines Ziels, neueste zuerst.
 *
 * @return list<array{stamp: string, file: string, time: int, size: int}>
 */
function cms_style_history_list(string $target): array {
    $dir = cms_style_history_dir($target);
    if ($dir === false || !is_dir($dir)) {
        return [];
    }

    $versions = [];
    foreach (glob($dir . '/*.css') ?: [] as $file) {
        $versions[] = [
            'stamp' => basename($file, '.css'),
            'file'  => $file,
            'time'  => (int) (@filemtime($file) ?: 0),
            'size'  => (int) (@filesize($file) ?: 0),
        ];
    }

    usort($versions, static fn(array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));
    return $versions;
}

/**
 * Räumt die Vorversionen eines Ziels auf: die neuesten $keep behalten UND
 * alles älter als $max_age entfernen — was zuerst greift. Identische Regel
 * wie cms_history_prune() in lib/content.php (Abschnitt 6.3).
 *
 * @return int Anzahl gelöschter Dateien.
 */
function cms_style_history_prune(string $target, ?int $keep = null, ?int $max_age = null): int {
    $keep    = $keep    ?? CMS_STYLE_HISTORY_KEEP;
    $max_age = $max_age ?? CMS_STYLE_HISTORY_MAX_AGE;
    $cutoff  = time() - $max_age;

    $removed = 0;
    foreach (cms_style_history_list($target) as $i => $version) {
        if ($i < $keep && $version['time'] >= $cutoff) {
            continue;
        }
        if (@unlink($version['file'])) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Absoluter Pfad der Live-Datei zu einem Historien-Ziel, oder false bei
 * unbekanntem $target.
 */
function cms_style_target_file(string $target): string|false {
    return match ($target) {
        'tokens'     => CMS_STYLE_CSS_FILE,
        'custom_css' => CMS_CUSTOM_CSS_FILE,
        default      => false,
    };
}

/**
 * Stellt die jüngste Vorversion eines Ziels wieder her (Undo, Abschnitt 5.5)
 * — der aktuelle Stand wird vorher selbst gesichert, damit auch ein Restore
 * umkehrbar bleibt, analog cms_history_restore().
 *
 * Bewusst bool statt eines Fehler-Arrays wie bei Seiten (Abschnitt 4.1):
 * der Aufrufer (mcp.php) kennt bereits vorab über
 * cms_style_history_list(), ob überhaupt eine Vorversion existiert, und
 * formuliert die passende Fehlermeldung selbst — die zwei Ziele hier sind
 * fest bekannt, anders als ein beliebiger Seiten-Slug.
 */
function cms_style_history_restore(string $target): bool {
    $file = cms_style_target_file($target);
    if ($file === false) {
        return false;
    }

    $versions = cms_style_history_list($target);
    if ($versions === []) {
        return false;
    }
    $latest = $versions[0];

    $raw = @file_get_contents($latest['file']);
    if ($raw === false) {
        return false;
    }

    if (is_file($file) && cms_style_history_backup($target, $file) === false) {
        return false;
    }
    if (file_put_contents($file, $raw, LOCK_EX) === false) {
        return false;
    }
    cms_style_history_prune($target);

    return true;
}
