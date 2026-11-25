<?php
/**
 * Gemeinsame Content-Bibliothek für alle Einstiegspunkte.
 *
 * Bündelt die Logik, die vorher doppelt in index.php und admin.php lag und
 * ein drittes Mal in mcp.php entstanden wäre:
 *   - cms_parse_frontmatter()  / cms_parse_md_file()    Lesen
 *   - cms_build_frontmatter()  / cms_serialize_md_file() Schreiben
 *   - cms_safe_path()          / cms_resolve_content_path()  Pfad-Prüfung
 *   - cms_scan_content_dir()   / cms_build_content_index()   Index
 *   - cms_build_sitemap_xml()  XML-Sitemap
 *   - Seiten-CRUD: cms_list_pages, cms_get_page, cms_create_page,
 *     cms_update_page, cms_delete_page
 *   - cms_history_*()          Vorversionen für den KI-Schreibpfad
 *
 * Abgesichert durch tests/roundtrip.php — nach jeder Änderung hier laufen
 * lassen: php tests/roundtrip.php
 *
 * HTTP-Zugriff auf diesen Ordner ist zusätzlich per lib/.htaccess gesperrt.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// index.php und admin.php definieren CONTENT_DIR selbst und identisch. Fehlt
// die Konstante (api.php, mcp.php, Testskript), wird sie hier nachgezogen.
if (!defined('CONTENT_DIR')) {
    define('CONTENT_DIR', dirname(__DIR__) . '/content');
}

// Aufbewahrung der Vorversionen (Feature-PRD 6.3): pro Seite die letzten 20
// behalten UND alles älter als 90 Tage entfernen — was zuerst greift.
if (!defined('CMS_HISTORY_KEEP')) {
    define('CMS_HISTORY_KEEP', 20);
}
if (!defined('CMS_HISTORY_MAX_AGE')) {
    define('CMS_HISTORY_MAX_AGE', 90 * 86400);
}

// ─── Frontmatter lesen ───────────────────────────────────────────────────────

/**
 * Entfernt ein zusammengehöriges Paar umschliessender Anführungszeichen und
 * löst die Escapes von cms_build_frontmatter() wieder auf.
 *
 * Ein einfaches trim($val, '"\'') würde bei Werten wie "Sag "Hallo""
 * das letzte Zeichen abschneiden.
 */
function cms_unquote_frontmatter_value(string $val): string {
    $val = trim($val);
    if (strlen($val) >= 2) {
        $first = $val[0];
        $last  = $val[strlen($val) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
            if ($first === '"') {
                $val = str_replace(['\\"', '\\\\'], ['"', '\\'], $val);
            }
        }
    }
    return $val;
}

/**
 * Wandelt einen Frontmatter-Wert in int/bool um, wo das eindeutig ist.
 *
 * Nur das Frontend braucht das: get_navigation() vergleicht showInNav mit
 * false und sortiert nach order. Das Admin-Formular dagegen erwartet
 * durchgehend Strings, sonst landen bools in den Eingabefeldern.
 */
function cms_cast_frontmatter_value(string $val): string|int|bool {
    if (is_numeric($val))   return (int)$val;
    if ($val === 'true')    return true;
    if ($val === 'false')   return false;
    return $val;
}

/**
 * Zerlegt einen Markdown-Text in Frontmatter (Array) und Body (String).
 *
 * $cast_scalars steuert das Verhalten, in dem sich die beiden alten Parser
 * unterschieden haben (Fund U8): index.php castete zu int/bool,
 * admin.php liess alles als String. Beide Aufrufer brauchen ihre Variante.
 *
 * @return array{meta: array<string, mixed>, body: string}
 */
function cms_parse_frontmatter(string $raw, bool $cast_scalars = false): array {
    $meta = [];
    $body = $raw;

    if (preg_match('/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $raw, $m)) {
        $body = $m[2];
        foreach (explode("\n", trim($m[1])) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $val) = array_map('trim', explode(':', $line, 2));
                $val = cms_unquote_frontmatter_value($val);
                $meta[$key] = $cast_scalars ? cms_cast_frontmatter_value($val) : $val;
            }
        }
    }

    return ['meta' => $meta, 'body' => $body];
}

/**
 * Wie cms_parse_frontmatter(), liest den Text aber aus einer Datei.
 * Gibt false zurück, wenn die Datei nicht lesbar ist.
 *
 * @return array{meta: array<string, mixed>, body: string}|false
 */
function cms_parse_md_file(string $filepath, bool $cast_scalars = false): array|false {
    if (!is_file($filepath) || !is_readable($filepath)) {
        return false;
    }
    $raw = file_get_contents($filepath);
    if ($raw === false) {
        return false;
    }
    return cms_parse_frontmatter($raw, $cast_scalars);
}

// ─── Frontmatter schreiben ───────────────────────────────────────────────────

/**
 * Baut den Frontmatter-Block aus einem Metadaten-Array.
 *
 * Gegenstück zu cms_unquote_frontmatter_value(). Wer eine der beiden
 * Funktionen ändert, muss die andere mitziehen — tests/roundtrip.php prüft
 * genau dieses Paar.
 *
 * Leere Werte und null fallen raus, damit keine sinnlosen Schlüssel
 * entstehen. bool wird zu true/false, nicht zu "1"/"" — sonst ginge
 * showInFooter: false beim Speichern verloren, weil (string)false = "" ist.
 */
function cms_build_frontmatter(array $meta): string {
    $lines = ["---"];
    foreach ($meta as $key => $val) {
        // Schlüsselnamen, die nicht wie ein YAML-Schlüssel aussehen, würden
        // den Block zerlegen. Betrifft vor allem Schreibzugriffe von aussen
        // (api.php, mcp.php), die keine Whitelist durchlaufen haben.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', (string)$key)) {
            continue;
        }

        if ($val === null) continue;
        if (is_bool($val)) {
            $val = $val ? 'true' : 'false';
        } else {
            $val = (string)$val;
        }

        // Zeilenumbrüche und Steuerzeichen raus. Ohne das kann ein einzelnes
        // Feld weitere Frontmatter-Schlüssel unterschieben ("Titel\nrobots:
        // noindex") oder mit einem eingebetteten "---" den Block vorzeitig
        // schliessen. Ein Tabulator wird zum Leerzeichen, damit nichts wegfällt.
        $val = str_replace("\t", ' ', $val);
        $val = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $val);
        $val = trim($val);

        if ($val === '') continue;

        if (preg_match('/[:#\[\]{},"\'|>&*!%@`]/', $val) || is_numeric($val)) {
            // In doppelten Anführungszeichen müssen \ und " escaped werden,
            // sonst endet der Wert am ersten inneren " (kaputtes YAML).
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $val);
            $lines[] = "{$key}: \"{$escaped}\"";
        } else {
            $lines[] = "{$key}: {$val}";
        }
    }
    $lines[] = "---";
    return implode("\n", $lines);
}

/**
 * Setzt eine vollständige Markdown-Datei aus Metadaten und Body zusammen.
 * Der Body wird auf LF normalisiert (Formulare liefern CRLF).
 */
function cms_serialize_md_file(array $meta, string $body): string {
    $body = str_replace("\r\n", "\n", $body);
    return cms_build_frontmatter($meta) . "\n\n" . $body;
}

/**
 * Schreibt eine Markdown-Datei und legt fehlende Ordner an.
 * LOCK_EX, damit zwei gleichzeitige Schreibzugriffe (Admin und KI) sich
 * nicht gegenseitig eine halb geschriebene Datei hinterlassen.
 */
function cms_write_md_file(string $full_path, array $meta, string $body): bool {
    $dir = dirname($full_path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    return file_put_contents($full_path, cms_serialize_md_file($meta, $body), LOCK_EX) !== false;
}

// ─── Pfad-Prüfung ────────────────────────────────────────────────────────────

/**
 * Prüft einen relativen Pfad gegen ein Basisverzeichnis und gibt ihn
 * normalisiert zurück, oder false wenn er nicht zulässig ist.
 *
 * Anders als die Vorgängerversion in admin.php werden ".."-Segmente
 * abgewiesen statt stillschweigend entfernt. Das Entfernen war zwar sicher
 * (der Pfad blieb innerhalb des Basisverzeichnisses), aber überraschend:
 * aus "../config/config.php" wurde "config/config.php" — ein gültiger Pfad,
 * der nur zufällig ins Leere zeigte. Abweisen ist vorhersagbarer.
 *
 * Segmente mit führendem Punkt sind ebenfalls raus. Damit ist content/.history
 * (die Backups vor KI-Schreibzugriffen) über diesen Weg nicht erreichbar.
 */
function cms_safe_path(string $relative, string $base_dir): string|false {
    // Ein Null-Byte kann in C-Bibliotheken den Pfad vorzeitig beenden.
    if (strpos($relative, "\0") !== false) {
        return false;
    }

    $clean = str_replace('\\', '/', $relative);
    $clean = preg_replace('/\/+/', '/', $clean);
    $clean = trim($clean, '/');

    if ($clean === '') {
        return false;
    }

    foreach (explode('/', $clean) as $part) {
        if ($part === '' || $part[0] === '.') {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+(\.[a-zA-Z0-9]+)?$/', $part)) {
            return false;
        }
    }

    // Existiert die Datei bereits, entscheidet realpath — das fängt auch
    // Symlinks, die aus dem Basisverzeichnis hinausführen.
    $full = $base_dir . '/' . $clean;
    if (file_exists($full)) {
        $real      = realpath($full);
        $real_base = realpath($base_dir);
        if ($real === false || $real_base === false) {
            return false;
        }
        if ($real !== $real_base && strpos($real, $real_base . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
    }

    return $clean;
}

/**
 * Prüft, ob ein Dateiname als Content-Datei zulässig ist.
 *
 * Fund L2: delete_file in admin.php hat diese Prüfung nicht durchlaufen,
 * save_file schon. Über den Löschweg liessen sich damit auch Dateien
 * entfernen, die keine Seite sind.
 */
function cms_is_valid_content_file(string $filename): bool {
    return preg_match('/^[a-z0-9_\-]+\.md$/i', basename($filename)) === 1;
}

/**
 * Löst einen relativen Content-Pfad zum absoluten Dateipfad auf.
 * Gibt false zurück, wenn der Pfad unzulässig ist oder keine .md-Datei meint.
 * Die Datei muss nicht existieren — das ist der Weg für neue Seiten.
 */
function cms_resolve_content_path(string $relative, ?string $base_dir = null): string|false {
    $base = $base_dir ?? CONTENT_DIR;
    $safe = cms_safe_path($relative, $base);
    if ($safe === false || !cms_is_valid_content_file($safe)) {
        return false;
    }
    return $base . '/' . $safe;
}

// ─── Verzeichnis-Scan und Index ──────────────────────────────────────────────

/**
 * Sammelt alle .md-Dateien unterhalb von $dir als relative Pfade.
 *
 * Reihenfolge: erst die Dateien des aktuellen Ordners, dann die Unterordner.
 * Das entspricht dem alten build_content_index() in index.php (glob() auf
 * *.md vor glob() auf Ordner) und hält damit die Navigation bei gleichem
 * order-Wert in derselben Reihenfolge wie vorher.
 *
 * Punkt-Einträge werden übersprungen — dazu gehört content/.history.
 */
function cms_scan_content_dir(?string $dir = null, string $prefix = ''): array {
    $dir = $dir ?? CONTENT_DIR;
    if (!is_dir($dir)) {
        return [];
    }

    $items = scandir($dir);
    if ($items === false) {
        return [];
    }
    sort($items);

    $files = [];
    $dirs  = [];
    foreach ($items as $item) {
        if ($item === '' || $item[0] === '.') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $dirs[] = $item;
        } elseif (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'md') {
            $files[] = $prefix ? $prefix . '/' . $item : $item;
        }
    }

    foreach ($dirs as $sub) {
        $sub_prefix = $prefix ? $prefix . '/' . $sub : $sub;
        $files = array_merge($files, cms_scan_content_dir($dir . '/' . $sub, $sub_prefix));
    }

    return $files;
}

/**
 * Flache, alphabetisch sortierte Liste aller Content-Dateien.
 * Das ist die Reihenfolge, die das Admin-Listing erwartet.
 */
function cms_list_content_files(?string $dir = null): array {
    $files = cms_scan_content_dir($dir);
    sort($files);
    return $files;
}

/** Unterordner direkt unterhalb von $dir, alphabetisch. */
function cms_list_subdirs(?string $dir = null): array {
    $dir = $dir ?? CONTENT_DIR;
    $dirs = [];
    if (!is_dir($dir)) {
        return $dirs;
    }
    $items = scandir($dir);
    if ($items === false) {
        return $dirs;
    }
    foreach ($items as $item) {
        if ($item === '' || $item[0] === '.') continue;
        if (is_dir($dir . '/' . $item)) {
            $dirs[] = $item;
        }
    }
    sort($dirs);
    return $dirs;
}

/**
 * Ist die Seite ein Entwurf?
 *
 * Verträgt beide Parser-Modi: im Cast-Modus steht ein bool im Frontmatter,
 * im String-Modus der Text "true".
 */
function cms_is_draft(array $meta): bool {
    if (!array_key_exists('draft', $meta)) {
        return false;
    }
    $val = $meta['draft'];
    if (is_bool($val)) return $val;
    return in_array(strtolower(trim((string)$val)), ['true', '1', 'yes'], true);
}

/**
 * Baut den nach Slug indizierten Seitenkatalog.
 *
 * Der Slug kommt aus dem Frontmatter; fehlt er, wird er aus Ordnerpfad und
 * Dateiname gebildet. Die Metadaten werden gecastet, weil der Index für
 * Navigation und Auslieferung gebraucht wird.
 *
 * $include_drafts = false wirft Seiten mit draft: true heraus. Das ist der
 * Modus für alles, was nach aussen sichtbar ist — Frontend und Navigation.
 */
function cms_build_content_index(?string $dir = null, string $prefix = '', bool $include_drafts = true): array {
    $dir = $dir ?? CONTENT_DIR;
    $index = [];

    foreach (cms_scan_content_dir($dir, $prefix) as $rel) {
        $file = $dir . '/' . $rel;
        $data = cms_parse_md_file($file, true);
        if ($data === false) {
            continue;
        }
        $meta = $data['meta'];

        if (!$include_drafts && cms_is_draft($meta)) {
            continue;
        }

        if (!empty($meta['slug'])) {
            $slug = (string)$meta['slug'];
        } else {
            $slug = preg_replace('/\.md$/i', '', $rel);
        }

        $parent = strpos($rel, '/') !== false ? dirname($rel) : null;

        $index[$slug] = [
            'file'   => $file,
            'rel'    => $rel,
            'meta'   => $meta,
            'parent' => $parent,
        ];
    }

    return $index;
}

// ─── Sitemap ───────────────────────────────────────────────────────────────────

/**
 * Baut die XML-Sitemap aus einem bereits gebauten Content-Index.
 *
 * Erwartet den Index ohne Entwürfe ($include_drafts = false bei
 * cms_build_content_index()) — Entwürfe sind hier schon draussen, genau wie
 * bei Navigation/Footer im Frontend. Zusätzlich ausgeschlossen: Seiten mit
 * "noindex" im robots-Feld — sie
 * dort zu listen wäre widersprüchlich zur eigenen Robots-Anweisung. Kein
 * Cache (bindende Entscheidung, s. Vault-Spec): der Index wird ohnehin bei
 * jedem Seitenaufruf frisch gebaut.
 *
 * $site_url ohne abschliessenden Slash, wie SITE_URL in index.php.
 */
function cms_build_sitemap_xml(array $content_index, string $site_url): string {
    $site_url = rtrim($site_url, '/');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($content_index as $slug => $entry) {
        // "404" ist reservierter Slug: ein
        // Direktaufruf liefert in index.php immer Status 404, egal was in
        // content/404.md steht — die Seite gehört deshalb nie in die
        // Sitemap, unabhängig vom robots-Feld. Cast auf String nötig: PHP
        // wandelt einen rein numerischen Array-Schlüssel wie "404" beim
        // foreach automatisch in den int 404 um, ein "===" gegen den
        // String-Literal '404' würde sonst nie greifen.
        if ((string)$slug === '404') {
            continue;
        }

        // Gleicher Default wie im Frontend (index.php): fehlt das Feld, ist
        // die Seite index/follow.
        $robots = trim((string)($entry['meta']['robots'] ?? 'index, follow'));
        if (strncasecmp($robots, 'noindex', 7) === 0) {
            continue;
        }

        $loc = $site_url . '/' . $slug;
        $mtime = @filemtime($entry['file']);

        $xml .= "    <url>\n";
        $xml .= '        <loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</loc>\n";
        if ($mtime !== false) {
            $xml .= '        <lastmod>' . date(DATE_W3C, $mtime) . "</lastmod>\n";
        }
        $xml .= "    </url>\n";
    }

    $xml .= '</urlset>' . "\n";
    return $xml;
}

// ─── Seiten-CRUD ─────────────────────────────────────────────────────────────
//
// Grundlage für die neue api.php und für die MCP-Tools in mcp.php. Die
// Funktionen arbeiten über den Slug, nicht über den Dateipfad — das ist die
// Adresse, die ein Aufrufer von aussen kennt.
//
// $opts steuert die Sonderwege des KI-Schreibpfads:
//   draft     bool    Entwurfs-Flag setzen (true) oder entfernen (false)
//   backup    bool    Vorversion nach content/.history/ sichern (siehe unten)
//   edited_by string  Urheber für lastEditedBy/lastEditedAt ('ai')
// Das Schreib-Rate-Limit bleibt bewusst draussen: es zählt Tool-Calls, nicht
// Dateischreibvorgänge, und gehört damit in den Transport (mcp.php).

/**
 * Macht aus beliebigem Text einen zulässigen Slug.
 * Schrägstriche bleiben erhalten, weil Slugs verschachtelt sein können
 * ("work/projekt-alpha").
 */
function cms_sanitize_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\/_-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = preg_replace('/\/+/', '/', $slug);
    $slug = trim($slug, '-/');
    // Bindestriche an den Rändern der einzelnen Segmente ebenfalls weg.
    $parts = array_filter(array_map(fn($p) => trim($p, '-'), explode('/', $slug)), fn($p) => $p !== '');
    return implode('/', $parts);
}

/**
 * Antwortformat aller schreibenden CRUD-Funktionen.
 * code ist maschinenlesbar (not_found, exists, invalid_slug, io_error),
 * error die deutsche Meldung für Admin-UI und Claude.
 */
function cms_crud_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

/**
 * Trägt die Kennzeichnung "zuletzt geändert von" ins Frontmatter ein.
 *
 * Nur wenn $opts['edited_by'] gesetzt ist — normale Admin-Änderungen bleiben
 * ungekennzeichnet, damit "lastEditedBy: ai" im Admin-Listing tatsächlich
 * bedeutet, dass die KI zuletzt geschrieben hat (Feature-PRD 5.2).
 * Der Zeitstempel ist ISO-8601 (date('c')); cms_build_frontmatter() setzt ihn
 * wegen des Doppelpunkts in Anführungszeichen.
 */
function cms_apply_edit_marks(array $meta, array $opts): array {
    $by = trim((string)($opts['edited_by'] ?? ''));
    if ($by === '') {
        return $meta;
    }
    $meta['lastEditedBy'] = $by;
    $meta['lastEditedAt'] = (string)($opts['edited_at'] ?? date('c'));
    return $meta;
}

/**
 * Sichert die Vorversion, wenn $opts['backup'] gesetzt ist.
 *
 * Schlägt die Sicherung fehl, bricht der Aufrufer ab statt weiterzuschreiben:
 * ein KI-Schreibzugriff ohne Vorversion nimmt dem Betreiber genau die
 * Rückfalloption, für die das Backup da ist (Feature-PRD 6.3).
 *
 * @return array{ok: bool}|array{ok: false, code: string, error: string}
 */
function cms_guard_backup(array $opts, string $slug, string $file, ?string $dir): array {
    if (empty($opts['backup'])) {
        return ['ok' => true];
    }
    if (cms_history_backup($slug, $file, $dir) === false) {
        return cms_crud_error(
            'backup_failed',
            'Die Vorversion liess sich nicht sichern. Die Änderung wurde deshalb nicht ausgeführt.'
        );
    }
    cms_history_prune($slug, $dir);
    return ['ok' => true];
}

/**
 * Alle Seiten mit den Feldern, die eine Übersicht braucht.
 */
function cms_list_pages(bool $include_drafts = true, ?string $dir = null): array {
    $pages = [];
    foreach (cms_build_content_index($dir, '', $include_drafts) as $slug => $entry) {
        $meta = $entry['meta'];
        $pages[] = [
            // (string): PHP macht aus einem rein numerischen Array-Key
            // ("2024", "404") einen int. Aufrufer mit strict_types würden
            // daran mit einem TypeError scheitern.
            'slug'      => (string)$slug,
            'title'     => (string)($meta['title'] ?? ''),
            'navLabel'  => (string)($meta['navLabel'] ?? ''),
            'showInNav' => !empty($meta['navLabel']) && ($meta['showInNav'] ?? true) !== false,
            'draft'     => cms_is_draft($meta),
            'file'      => $entry['rel'],
        ];
    }
    return $pages;
}

/**
 * Liest eine Seite über ihren Slug.
 *
 * Metadaten kommen als Strings zurück (kein Cast) — das ist das Format, in
 * dem sie auch wieder geschrieben werden.
 *
 * @return array{slug: string, file: string, rel: string, meta: array, body: string}|false
 */
function cms_get_page(string $slug, bool $include_drafts = true, ?string $dir = null): array|false {
    $index = cms_build_content_index($dir, '', $include_drafts);
    if (!isset($index[$slug])) {
        return false;
    }
    $entry = $index[$slug];
    $data  = cms_parse_md_file($entry['file'], false);
    if ($data === false) {
        return false;
    }
    return [
        'slug' => $slug,
        'file' => $entry['file'],
        'rel'  => $entry['rel'],
        'meta' => $data['meta'],
        'body' => $data['body'],
    ];
}

/**
 * Legt eine neue Seite an. Der Dateipfad ergibt sich aus dem Slug.
 * $opts: draft, edited_by (backup entfällt — es gibt keine Vorversion).
 */
function cms_create_page(string $slug, array $meta, string $body, array $opts = [], ?string $dir = null): array {
    $base = $dir ?? CONTENT_DIR;
    $slug = cms_sanitize_slug($slug);
    if ($slug === '') {
        return cms_crud_error('invalid_slug', 'Der Slug ist leer oder enthält nur unzulässige Zeichen.');
    }

    $full = cms_resolve_content_path($slug . '.md', $base);
    if ($full === false) {
        return cms_crud_error('invalid_slug', 'Aus dem Slug lässt sich kein zulässiger Dateipfad bilden.');
    }
    if (file_exists($full)) {
        return cms_crud_error('exists', 'Es gibt bereits eine Datei unter diesem Pfad.');
    }
    if (isset(cms_build_content_index($base)[$slug])) {
        return cms_crud_error('exists', 'Der Slug ist bereits vergeben.');
    }

    $meta['slug'] = $slug;
    if (array_key_exists('draft', $opts)) {
        if ($opts['draft']) {
            $meta['draft'] = 'true';
        } else {
            unset($meta['draft']);
        }
    }
    $meta = cms_apply_edit_marks($meta, $opts);

    if (!cms_write_md_file($full, $meta, $body)) {
        return cms_crud_error('io_error', 'Die Datei konnte nicht geschrieben werden.');
    }

    return ['ok' => true, 'slug' => $slug, 'file' => $full, 'draft' => cms_is_draft($meta)];
}

/**
 * Ändert eine bestehende Seite.
 *
 * Übergebene Metadaten überschreiben die vorhandenen feldweise; nicht
 * übergebene Felder bleiben stehen. $body === null lässt den Inhalt unberührt.
 * $opts: draft, backup, edited_by
 */
function cms_update_page(string $slug, array $meta, ?string $body = null, array $opts = [], ?string $dir = null): array {
    $page = cms_get_page($slug, true, $dir);
    if ($page === false) {
        return cms_crud_error('not_found', 'Es gibt keine Seite mit diesem Slug.');
    }

    $backup = cms_guard_backup($opts, $slug, $page['file'], $dir);
    if (!$backup['ok']) {
        return $backup;
    }

    $new_meta = array_merge($page['meta'], $meta);
    if (array_key_exists('draft', $opts)) {
        if ($opts['draft']) {
            $new_meta['draft'] = 'true';
        } else {
            unset($new_meta['draft']);
        }
    }
    $new_meta = cms_apply_edit_marks($new_meta, $opts);

    $new_body = $body ?? $page['body'];

    if (!cms_write_md_file($page['file'], $new_meta, $new_body)) {
        return cms_crud_error('io_error', 'Die Datei konnte nicht geschrieben werden.');
    }

    return [
        'ok'    => true,
        'slug'  => (string)($new_meta['slug'] ?? $slug),
        'file'  => $page['file'],
        'draft' => cms_is_draft($new_meta),
    ];
}

/**
 * Löscht eine Seite über ihren Slug.
 * $opts: backup (edited_by ist hier ohne Wirkung — die Datei ist danach weg).
 */
function cms_delete_page(string $slug, array $opts = [], ?string $dir = null): array {
    $page = cms_get_page($slug, true, $dir);
    if ($page === false) {
        return cms_crud_error('not_found', 'Es gibt keine Seite mit diesem Slug.');
    }

    $backup = cms_guard_backup($opts, $slug, $page['file'], $dir);
    if (!$backup['ok']) {
        return $backup;
    }

    return cms_delete_content_file($page['rel'], $dir);
}

/**
 * Löscht eine Content-Datei über ihren relativen Pfad.
 *
 * Getrennt von cms_delete_page(), weil das Admin-Backend mit Dateipfaden
 * arbeitet und nicht mit Slugs. Die Endungsprüfung läuft hier — das ist der
 * Fix für Fund L2.
 */
function cms_delete_content_file(string $relative, ?string $dir = null): array {
    $base = $dir ?? CONTENT_DIR;
    $full = cms_resolve_content_path($relative, $base);
    if ($full === false) {
        return cms_crud_error('invalid_path', 'Ungültiger Dateipfad. Nur .md-Dateien in erlaubten Ordnern sind zulässig.');
    }
    if (!file_exists($full)) {
        return cms_crud_error('not_found', 'Die Datei existiert nicht.');
    }
    if (!unlink($full)) {
        return cms_crud_error('io_error', 'Die Datei konnte nicht gelöscht werden.');
    }
    return ['ok' => true, 'file' => $full];
}

/**
 * Schreibt eine Content-Datei über ihren relativen Pfad.
 * Gegenstück zu cms_delete_content_file() für das Admin-Backend.
 */
function cms_save_content_file(string $relative, array $meta, string $body, ?string $dir = null): array {
    $base = $dir ?? CONTENT_DIR;
    $full = cms_resolve_content_path($relative, $base);
    if ($full === false) {
        return cms_crud_error('invalid_path', 'Ungültiger Dateipfad. Nur .md-Dateien in erlaubten Ordnern sind zulässig.');
    }
    if (!cms_write_md_file($full, $meta, $body)) {
        return cms_crud_error('io_error', 'Die Datei konnte nicht geschrieben werden.');
    }
    return ['ok' => true, 'file' => $full];
}

// ─── Versionshistorie ────────────────────────────────────────────────────────
//
// Vorversionen liegen unter content/.history/{slug}/{zeitstempel}.md. Der
// Ordner beginnt mit einem Punkt und ist damit gleich dreifach abgeschirmt:
// cms_scan_content_dir() überspringt ihn, cms_safe_path() weist Segmente mit
// führendem Punkt ab, und die .htaccess sperrt den HTTP-Zugriff.
//
// Gesichert wird ausschliesslich vor Schreibzugriffen mit $opts['backup'] —
// also über die KI-Anbindung und die API, nicht im Admin-Backend
// (Feature-PRD 6.3).

/** Wurzelverzeichnis der Versionshistorie. */
function cms_history_root(?string $dir = null): string {
    return ($dir ?? CONTENT_DIR) . '/.history';
}

/**
 * Verzeichnis der Vorversionen einer Seite, oder false bei unbrauchbarem Slug.
 * Der Slug wird durch cms_sanitize_slug() geschickt und besteht danach nur noch
 * aus [a-z0-9/_-] — verschachtelte Slugs werden zu verschachtelten Ordnern.
 */
function cms_history_slug_dir(string $slug, ?string $dir = null): string|false {
    $safe = cms_sanitize_slug($slug);
    if ($safe === '') {
        return false;
    }
    return cms_history_root($dir) . '/' . $safe;
}

/**
 * Legt die .htaccess im Historien-Ordner an, falls sie fehlt.
 *
 * Im Repo liegt sie bereits, aber auf einer frisch aufgesetzten Installation
 * entsteht .history/ erst zur Laufzeit — ohne diese Zeilen stünden die
 * Vorversionen dort offen, sobald ein Server mod_rewrite nicht anbietet.
 */
function cms_history_write_guard(?string $dir = null): void {
    $file = cms_history_root($dir) . '/.htaccess';
    if (is_file($file)) {
        return;
    }
    @file_put_contents($file, implode("\n", [
        '# Versionsstaende der Seiten (Backups vor jedem KI-Schreibzugriff).',
        '# Kein HTTP-Zugriff — weder auf den Ordner noch auf die Dateien darin.',
        '<IfModule mod_authz_core.c>',
        '    Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '    Order deny,allow',
        '    Deny from all',
        '</IfModule>',
        '',
    ]));
}

/**
 * Sichert den aktuellen Stand einer Seite als Vorversion.
 *
 * @return string|false Pfad der Sicherung, oder false wenn sie misslungen ist.
 */
function cms_history_backup(string $slug, string $file, ?string $dir = null): string|false {
    if (!is_file($file) || !is_readable($file)) {
        return false;
    }

    $target_dir = cms_history_slug_dir($slug, $dir);
    if ($target_dir === false) {
        return false;
    }
    if (!is_dir($target_dir) && !@mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
        return false;
    }
    cms_history_write_guard($dir);

    // Sekundengenauer Stempel. Zwei Sicherungen in derselben Sekunde bekommen
    // ein Suffix — der Stempel sortiert dann weiterhin chronologisch, weil
    // "…_120000-1" lexikografisch hinter "…_120000" steht.
    $stamp  = date('Y-m-d_His');
    $target = $target_dir . '/' . $stamp . '.md';
    for ($i = 1; $i < 100 && file_exists($target); $i++) {
        $target = $target_dir . '/' . $stamp . '-' . $i . '.md';
    }
    if (file_exists($target) || !@copy($file, $target)) {
        return false;
    }

    return $target;
}

/**
 * Vorversionen einer Seite, neueste zuerst.
 *
 * @return list<array{stamp: string, file: string, time: int, size: int}>
 */
function cms_history_list(string $slug, ?string $dir = null): array {
    $target_dir = cms_history_slug_dir($slug, $dir);
    if ($target_dir === false || !is_dir($target_dir)) {
        return [];
    }

    $versions = [];
    foreach (glob($target_dir . '/*.md') ?: [] as $file) {
        $versions[] = [
            'stamp' => basename($file, '.md'),
            'file'  => $file,
            'time'  => (int)(@filemtime($file) ?: 0),
            'size'  => (int)(@filesize($file) ?: 0),
        ];
    }

    usort($versions, static fn(array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));
    return $versions;
}

/**
 * Räumt die Vorversionen einer Seite auf: die neuesten $keep behalten UND
 * alles älter als $max_age entfernen — was zuerst greift (Feature-PRD 6.3).
 *
 * Läuft bei jedem gesicherten Schreibzugriff, nicht zeitgesteuert: auf
 * Shared-Hosting gibt es keinen Cron, auf den man sich verlassen könnte.
 *
 * @return int Anzahl gelöschter Dateien.
 */
function cms_history_prune(string $slug, ?string $dir = null, ?int $keep = null, ?int $max_age = null): int {
    $keep    = $keep    ?? CMS_HISTORY_KEEP;
    $max_age = $max_age ?? CMS_HISTORY_MAX_AGE;
    $cutoff  = time() - $max_age;

    $removed = 0;
    foreach (cms_history_list($slug, $dir) as $i => $version) {
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
 * Stellt eine Vorversion wieder her (Undo im Admin-Backend).
 *
 * Ohne $stamp die neueste. Der aktuelle Stand wird vorher selbst gesichert,
 * damit auch ein versehentliches Zurücksetzen umkehrbar bleibt.
 */
function cms_history_restore(string $slug, ?string $stamp = null, ?string $dir = null): array {
    $page = cms_get_page($slug, true, $dir);
    if ($page === false) {
        return cms_crud_error('not_found', 'Es gibt keine Seite mit diesem Slug.');
    }

    $versions = cms_history_list($slug, $dir);
    if ($versions === []) {
        return cms_crud_error('not_found', 'Für diese Seite ist keine Vorversion gesichert.');
    }

    $pick = null;
    if ($stamp === null) {
        $pick = $versions[0];
    } else {
        foreach ($versions as $version) {
            if ($version['stamp'] === $stamp) {
                $pick = $version;
                break;
            }
        }
    }
    if ($pick === null) {
        return cms_crud_error('not_found', 'Diese Vorversion gibt es nicht.');
    }

    $raw = @file_get_contents($pick['file']);
    if ($raw === false) {
        return cms_crud_error('io_error', 'Die Vorversion konnte nicht gelesen werden.');
    }

    if (cms_history_backup($slug, $page['file'], $dir) === false) {
        return cms_crud_error(
            'backup_failed',
            'Der aktuelle Stand liess sich nicht sichern. Es wurde nichts zurückgesetzt.'
        );
    }
    if (file_put_contents($page['file'], $raw, LOCK_EX) === false) {
        return cms_crud_error('io_error', 'Die Seite konnte nicht zurückgesetzt werden.');
    }
    cms_history_prune($slug, $dir);

    return ['ok' => true, 'slug' => $slug, 'file' => $page['file'], 'stamp' => $pick['stamp']];
}

// ─── Landingpage-HTML ────────────────────────────────────────────────────────

/**
 * Basis-Validierung für Landingpage-HTML vor dem Speichern (Abschnitt 6.3).
 * Kein vollständiger Sanitizer/Parser — nur eine Tag-Blockliste passend zur
 * v1-Entscheidung "kein JS, keine externen Nachladevorgänge" (analog der
 * @import/url()-Blockliste bei cms_custom_css_safe()) plus eine grobe
 * Wohlgeformtheits-Prüfung,
 * damit ein nicht geschlossenes Tag nicht die restliche Seite (inkl. Footer)
 * mitreisst.
 *
 * Gibt bei einem Verstoß eine deutsche Fehlermeldung zurück, sonst null.
 */
function cms_validate_landingpage_html(string $html): ?string {
    if (preg_match('/<\s*(script|iframe|object|embed|link)\b/i', $html, $m)) {
        return 'Das Tag <' . strtolower($m[1]) . '> ist in Landingpages nicht erlaubt.';
    }
    // on*-Attribute auch ohne Leerzeichen davor abfangen (z. B. direkt nach
    // dem schliessenden Anführungszeichen des vorherigen Attributwerts —
    // Browser werten das trotzdem als neues Attribut).
    if (preg_match('/[\s"\']on[a-z]+\s*=/i', $html)) {
        return 'Inline-Event-Handler (on*-Attribute wie onclick, onerror) sind in Landingpages nicht erlaubt.';
    }

    // Ein nicht geschlossenes <style> verschluckt alles danach. Neuere
    // libxml-Versionen (ab 2.14, HTML5-Tokenizer) melden das nicht mehr als
    // Fehler, die Prüfung unten würde es dort durchwinken. Deshalb vorab
    // unabhängig von libxml: jedes öffnende Tag braucht ein schließendes.
    if (preg_match_all('/<\s*style\b/i', $html) !== preg_match_all('/<\s*\/\s*style\s*>/i', $html)) {
        return 'Das HTML ist nicht wohlgeformt (ein <style>-Block ist nicht geschlossen).';
    }

    // Grobe Wohlgeformtheits-Prüfung über DOMDocument::loadHTML() statt eines
    // vollständigen HTML5-Parsers (Spec 6.3: keine neue Abhängigkeit,
    // DOMDocument ist Bordmittel). libxmls interner Parser kennt nur HTML4 —
    // moderne Tags wie section/article/svg meldet er als "Tag X invalid"
    // (Code 801), ein unescapetes "&" als Code 23. Beides ist in echtem,
    // harmlosem Landingpage-HTML alltäglich und wird bewusst ignoriert.
    // Übrig bleiben Fälle wie nicht geschlossene Tags oder unterbrochene
    // Kommentare, die tatsächlich nachfolgendes Markup verschlucken können.
    $ignored_codes = [801, 23];
    $prev_setting = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev_setting);

    foreach ($errors as $error) {
        if ($error->level >= LIBXML_ERR_ERROR && !in_array($error->code, $ignored_codes, true)) {
            return 'Das HTML ist nicht wohlgeformt (z. B. ein nicht geschlossenes oder falsch verschachteltes Tag).';
        }
    }

    return null;
}
