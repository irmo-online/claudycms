<?php
/**
 * Gemeinsame Bildverwaltungs-Bibliothek für alle Einstiegspunkte.
 *
 * Analog zu lib/content.php: Bündelt die Validierungs- und Dateisystem-Logik
 * für assets/images/, die bisher inline in admin.php (action=images) steckte,
 * damit mcp.php sie ohne Doppelarbeit für die KI-Bildwerkzeuge nutzen kann
 * (Abschnitt 4.1).
 *
 *   - cms_list_images()              Auflisten
 *   - cms_validate_image_upload()    MIME-/Größen-/Inhaltsprüfung
 *   - cms_sanitize_image_filename()  Dateinamen-Sanitizing
 *   - cms_store_image()              Speichern (Kollisionsschutz per Suffix)
 *   - cms_delete_image()             Löschen
 *   - cms_resolve_image_path()       Whitelist + realpath-Check (Defense in
 *                                    Depth, analog cms_safe_path() in
 *                                    lib/content.php)
 *
 * Anders als lib/content.php braucht diese Bibliothek keine Frontmatter- oder
 * Seiten-Logik und bindet lib/content.php deshalb bewusst nicht ein — beide
 * Dateien stehen unabhängig nebeneinander.
 *
 * Kein Backup/Undo für Bilder (Abschnitt 0 Punkt 7) — anders als
 * bei Seiten gibt es hier keine content/.history/-Entsprechung.
 *
 * HTTP-Zugriff auf diesen Ordner ist per lib/.htaccess gesperrt.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Konfiguration ────────────────────────────────────────────────────────────

// admin.php definiert diese Konstanten bereits selbst (vor dem Einbinden
// dieser Datei) — die Fallbacks greifen nur für Aufrufer wie mcp.php, die
// bisher keine eigene Bildkonfiguration kennen. Werte sind identisch zu
// admin.php, damit sich am Browser-Weg nichts ändert.
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', dirname(__DIR__) . '/assets/images');
}
if (!defined('ALLOWED_IMAGE_TYPES')) {
    define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
}
if (!defined('ALLOWED_IMAGE_EXT')) {
    define('ALLOWED_IMAGE_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
}

// Fallback, falls MAX_UPLOAD_SIZE (admin.php, aus config/config.php) nicht
// gesetzt ist — Standardwert identisch zu config/config.example.php (5 MB).
if (!defined('CMS_MEDIA_DEFAULT_MAX_UPLOAD')) {
    define('CMS_MEDIA_DEFAULT_MAX_UPLOAD', 5 * 1024 * 1024);
}

/** Endung → MIME-Typ, nur als Rückfallebene, wenn getimagesize() einmal scheitert. */
if (!defined('CMS_IMAGE_MIME_BY_EXT')) {
    define('CMS_IMAGE_MIME_BY_EXT', [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ]);
}

/**
 * Einheitliches Fehlerformat, analog cms_crud_error() in lib/content.php.
 * Eigene, kleine Kopie statt eines require auf lib/content.php — media.php
 * bleibt damit unabhängig ladbar.
 */
function cms_media_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

// ─── Pfad-Prüfung ─────────────────────────────────────────────────────────────

/**
 * Prüft einen Dateinamen gegen die Bild-Whitelist und löst ihn zum absoluten
 * Pfad in UPLOAD_DIR auf, oder gibt false zurück.
 *
 * Zwei Ebenen (Abschnitt 4.2): die Regex lässt nur unverdächtige
 * Zeichen und erlaubte Endungen durch, der realpath()-Vergleich fängt
 * zusätzlich Symlinks, die aus UPLOAD_DIR hinausführen — analog
 * cms_safe_path() in lib/content.php. Der Dateiname kommt nie ungeprüft aus
 * einem Tool-Argument in einen Dateipfad.
 */
function cms_resolve_image_path(string $filename): string|false {
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$/', $filename)) {
        return false;
    }

    $full = UPLOAD_DIR . '/' . $filename;
    if (file_exists($full)) {
        $real      = realpath($full);
        $real_base = realpath(UPLOAD_DIR);
        if ($real === false || $real_base === false) {
            return false;
        }
        if ($real !== $real_base && strpos($real, $real_base . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
    }

    return $full;
}

// ─── Auflisten ────────────────────────────────────────────────────────────────

/** Liest den MIME-Typ aus dem echten Bildinhalt, mit Endungs-Fallback. */
function cms_image_mime_type(string $path): string {
    $info = @getimagesize($path);
    if ($info !== false && !empty($info['mime'])) {
        return $info['mime'];
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return CMS_IMAGE_MIME_BY_EXT[$ext] ?? 'application/octet-stream';
}

/**
 * Alle hochgeladenen Bilder in assets/images/, alphabetisch nach Dateiname.
 * Entspricht list_uploaded_images() aus admin.php, um Metadaten ergänzt, die
 * das MCP-Tool list_images braucht (Abschnitt 5.1).
 *
 * url ist bewusst der relative Pfad ("assets/images/…"), wie ihn auch
 * headerImage im Frontmatter erwartet — dieselbe Konvention wie im Rest des
 * CMS. Ein Aufrufer, der eine absolute URL braucht (z.B. mcp.php für Claude),
 * stellt SITE_URL selbst voran.
 *
 * @return list<array{filename: string, url: string, size_bytes: int, mime_type: string, modified_at: string}>
 */
function cms_list_images(): array {
    $images = [];
    if (!is_dir(UPLOAD_DIR)) {
        return $images;
    }
    $items = scandir(UPLOAD_DIR);
    if ($items === false) {
        return $images;
    }

    foreach ($items as $item) {
        if ($item === '' || $item[0] === '.') continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXT)) continue;

        $full     = UPLOAD_DIR . '/' . $item;
        $images[] = [
            'filename'    => $item,
            'url'         => 'assets/images/' . $item,
            'size_bytes'  => (int) (@filesize($full) ?: 0),
            'mime_type'   => cms_image_mime_type($full),
            'modified_at' => date('c', (int) (@filemtime($full) ?: 0)),
        ];
    }

    usort($images, static fn(array $a, array $b): int => strcmp($a['filename'], $b['filename']));
    return $images;
}

// ─── Validierung ──────────────────────────────────────────────────────────────

/**
 * Prüft, ob Bilddaten mit dem für ihr Format zwingenden Dateiende abschliessen —
 * ein zuverlässiger, deckoder-unabhängiger Hinweis auf Truncation (siehe
 * cms_validate_image_upload()). Unbekannte MIME-Typen kommen hier nicht an
 * (die Whitelist läuft vorher), gelten also als unbedenklich.
 */
function cms_image_end_marker_ok(string $data, string $mime): bool {
    return match ($mime) {
        // JPEG: End-of-Image-Marker.
        'image/jpeg' => substr($data, -2) === "\xFF\xD9",
        // GIF: Trailer-Byte ';'.
        'image/gif'  => substr($data, -1) === ';',
        // PNG: IEND-Chunk — Typ + CRC sind fix, weil IEND keine Nutzdaten hat.
        'image/png'  => substr($data, -8) === "\x49\x45\x4E\x44\xAE\x42\x60\x82",
        // WebP: RIFF-Container, Bytes 4-7 sind die kleinendian-Länge der
        // restlichen Datei (= Gesamtlänge - 8). Weicht sie ab, fehlen Bytes.
        'image/webp' => strlen($data) >= 12
            && substr($data, 0, 4) === 'RIFF'
            && unpack('V', substr($data, 4, 4))[1] === strlen($data) - 8,
        default => true,
    };
}

/**
 * Prüft hochzuladende Bilddaten: Endungs-Whitelist, Größenlimit, echter
 * Bildinhalt. Identisch zu den Regeln, die bisher inline im Admin-Upload
 * steckten (Abschnitt 6.3) — nur der Transport ist neu: hier kommen die
 * Bytes bereits dekodiert an (Base64-Decoding ist Sache des Aufrufers,
 * siehe Abschnitt 5.3, Schritt 2).
 *
 * $max_size fehlt → MAX_UPLOAD_SIZE (admin.php, aus config/config.php), sonst
 * der Standardwert. mcp.php kann stattdessen den Wert aus seiner eigenen
 * config-Funktion durchreichen, ohne eine globale Konstante zu brauchen.
 *
 * @return array{ok: true, ext: string, mime_type: string, size_bytes: int}|array{ok: false, code: string, error: string}
 */
function cms_validate_image_upload(string $filename, string $binary_data, ?int $max_size = null): array {
    $max_size ??= defined('MAX_UPLOAD_SIZE') ? (int) MAX_UPLOAD_SIZE : CMS_MEDIA_DEFAULT_MAX_UPLOAD;

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGE_EXT)) {
        return cms_media_error('invalid_extension', 'Ungültige Dateiendung. Erlaubt: JPG, PNG, GIF, WebP.');
    }

    $size = strlen($binary_data);
    if ($size > $max_size) {
        return cms_media_error('too_large', 'Datei zu groß. Maximum: ' . (int) round($max_size / 1024 / 1024) . ' MB.');
    }

    // getimagesize() prüft den tatsächlichen Bildinhalt statt sich auf die
    // Endung zu verlassen — verhindert z.B. eine .php-Datei mit .jpg-Endung.
    // Der data://-Stream-Wrapper erspart ein Temp-File: Der "Mime-Typ" in der
    // URI ist frei erfunden und wird von getimagesize() ignoriert, geprüft
    // wird ausschliesslich die echte Signatur der Bytes dahinter.
    $info = @getimagesize('data://application/octet-stream;base64,' . base64_encode($binary_data));
    if ($info === false || empty($info['mime'])) {
        return cms_media_error('invalid_image', 'Die Datei ist kein gültiges Bild.');
    }

    // Der alte Admin-Upload hat zusätzlich den vom Browser gesendeten
    // Content-Type geprüft ($_FILES['type']) — frei fälschbar und für
    // MCP-Uploads ohnehin nicht vorhanden (Claude schickt keinen Browser-
    // Header). Der Ersatz hier prüft stattdessen den von getimagesize()
    // erkannten *echten* Typ gegen dieselbe Whitelist: strenger, weil er
    // z.B. eine als .png umbenannte BMP-Datei abweist, die die Endungs-
    // Whitelist allein durchliesse.
    if (!in_array($info['mime'], ALLOWED_IMAGE_TYPES, true)) {
        return cms_media_error('invalid_mime', 'Ungültiger Dateityp. Erlaubt: JPG, PNG, GIF, WebP.');
    }

    // Vollständigkeits-Check über getimagesize() hinaus (live aufgefallen,
    // 2026-09-18): getimagesize() liest nur den Datei-Header und erkennt ein
    // mitten in den Bilddaten abgeschnittenes Bild oft nicht — ein per MCP
    // hochgeladenes Foto kam nur zu einem Drittel an (das Modell hat die sehr
    // lange Base64-Zeichenkette nicht fehlerfrei reproduziert), wurde aber
    // als "gültiges Bild" akzeptiert und live gespeichert.
    //
    // Weder getimagesize() noch imagecreatefromstring() allein sind hier
    // verlässlich: libjpeg (JPEG) und teils auch die GIF/LZW-Dekodierung
    // liefern bei Truncation je nach Abschneide-Position mal ein (falsches)
    // Ergebnis, mal einen Fehler — ausprobiert und beobachtet, nicht bloss
    // vermutet. cms_image_end_marker_ok() prüft stattdessen deterministisch
    // das Dateiende jedes Formats (JPEG-EOI, PNG-IEND-Chunk, GIF-Trailer,
    // WebP-RIFF-Längenfeld) — das hängt nicht von Decoder-Nachsicht ab.
    $incomplete = 'Die Datei lässt sich nicht vollständig als gültiges Bild lesen — '
        . 'vermutlich unvollständig oder beschädigt übertragen.';

    if (!cms_image_end_marker_ok($binary_data, $info['mime'])) {
        return cms_media_error('invalid_image', $incomplete);
    }

    // Zweite Ebene, wo verfügbar: ein voller Dekodierversuch fängt auch
    // Beschädigungen ab, die zufällig einen korrekten Endmarker haben.
    if (extension_loaded('gd') && @imagecreatefromstring($binary_data) === false) {
        return cms_media_error('invalid_image', $incomplete);
    }

    return ['ok' => true, 'ext' => $ext, 'mime_type' => $info['mime'], 'size_bytes' => $size];
}

// ─── Dateiname-Sanitizing ───────────────────────────────────────────────────────

/**
 * Macht aus einem beliebigen Dateinamen einen zulässigen: Basisname auf
 * [a-zA-Z0-9_-] reduziert, mehrfache Bindestriche zusammengefasst, Endung
 * kleingeschrieben unverändert angehängt. Identische Regel wie bisher im
 * Admin-Upload (Abschnitt 4.1).
 */
function cms_sanitize_image_filename(string $original): string {
    $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $basename = pathinfo($original, PATHINFO_FILENAME);
    $basename = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $basename);
    $basename = preg_replace('/-+/', '-', trim($basename, '-'));

    return $ext !== '' ? $basename . '.' . $ext : $basename;
}

// ─── Speichern & Löschen ────────────────────────────────────────────────────────

/**
 * Schreibt Bilddaten nach UPLOAD_DIR und gibt den tatsächlich verwendeten
 * Dateinamen zurück. Kollisionen werden wie bisher im Admin-Upload per
 * angehängtem Zähler gelöst (-1, -2, …), nie mit einem Fehler quittiert
 * (Abschnitt 5.3, Schritt 6).
 *
 * Leerer Rückgabewert ('') bedeutet Fehlschlag (Verzeichnis nicht anlegbar
 * oder Schreibfehler) — der Aufrufer muss darauf prüfen.
 */
function cms_store_image(string $sanitized_name, string $binary_data): string {
    $ext      = pathinfo($sanitized_name, PATHINFO_EXTENSION);
    $basename = pathinfo($sanitized_name, PATHINFO_FILENAME);

    $target_name = $sanitized_name;
    $counter     = 1;
    while (file_exists(UPLOAD_DIR . '/' . $target_name)) {
        $target_name = $basename . '-' . $counter . ($ext !== '' ? '.' . $ext : '');
        $counter++;
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return '';
    }

    // LOCK_EX wie cms_write_md_file() in lib/content.php — verhindert, dass
    // ein gleichzeitiger zweiter Schreibzugriff eine halb geschriebene Datei
    // hinterlässt.
    if (file_put_contents(UPLOAD_DIR . '/' . $target_name, $binary_data, LOCK_EX) === false) {
        return '';
    }

    return $target_name;
}

/**
 * Löscht ein Bild unwiderruflich — kein Backup, siehe Dateikopf.
 * Whitelist- und realpath-Prüfung über cms_resolve_image_path(); ein
 * Dateiname, der nicht existiert oder das Muster verfehlt, liefert false.
 */
function cms_delete_image(string $filename): bool {
    $full = cms_resolve_image_path($filename);
    if ($full === false || !file_exists($full)) {
        return false;
    }
    return @unlink($full);
}
