<?php
/**
 * Branding-Verwaltung (Logo & Favicon).
 *
 * Eigenes Verzeichnis assets/branding/, bewusst getrennt vom Content-
 * Bilder-Pool assets/images/ (lib/media.php): ein versehentlich über die
 * normale Medienbibliothek gelöschtes Logo/Favicon wiegt anders als ein
 * gelöschtes Content-Bild (Spec-Abschnitt zum Website-Logo).
 *
 * Nutzt dieselbe Validierung wie lib/media.php (cms_validate_image_upload(),
 * ALLOWED_IMAGE_TYPES/ALLOWED_IMAGE_EXT) — kein neuer Prüfpfad, nur ein
 * eigener Speicherort. lib/media.php muss vor dieser Datei eingebunden sein.
 *
 * Anders als bei normalen Bildern gibt es je Slot ("logo"/"favicon") immer
 * nur eine aktive Datei (config/config.php hält genau einen Dateinamen je
 * Slot, Spec Abschnitt 4) — deshalb kein Kollisionsschutz per Zähler-Suffix
 * wie cms_store_image(), sondern ein fester Dateiname je Slot
 * ("logo.<ext>"/"favicon.<ext>"). Ein Ersetzen räumt eine bestehende Datei
 * mit anderer Endung automatisch ab, statt Leichen im Verzeichnis
 * anzusammeln — es gibt ohnehin kein Backup/Undo für Bilder (siehe
 * lib/media.php, Dateikopf).
 *
 * HTTP-Zugriff auf diesen Ordner ist per assets/branding/.htaccess gesperrt
 * (PHP-Ausführung aus, analog assets/images/.htaccess).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Konfiguration ────────────────────────────────────────────────────────────

// admin.php definiert diese Konstante bereits selbst (vor dem Einbinden
// dieser Datei) — der Fallback greift nur für Aufrufer, die bisher keine
// eigene Branding-Konfiguration kennen (analog UPLOAD_DIR in lib/media.php).
if (!defined('BRANDING_DIR')) {
    define('BRANDING_DIR', dirname(__DIR__) . '/assets/branding');
}

/** Erlaubte Slots — die beiden einzigen Branding-Elemente. */
if (!defined('CMS_BRANDING_SLOTS')) {
    define('CMS_BRANDING_SLOTS', ['logo', 'favicon']);
}

// ─── Pfad-Prüfung ─────────────────────────────────────────────────────────────

/**
 * Prüft einen Dateinamen gegen die Bild-Whitelist und löst ihn zum absoluten
 * Pfad in BRANDING_DIR auf, oder gibt false zurück. Identisches Muster zu
 * cms_resolve_image_path() in lib/media.php, nur mit anderem Basisverzeichnis
 * (und ohne die dortige Beschränkung auf hochgeladene Dateinamen aus
 * cms_store_image() — hier sind wegen der festen Slot-Namen ohnehin nur
 * "logo.<ext>"/"favicon.<ext>" gültige Treffer).
 */
function cms_resolve_branding_path(string $filename): string|false {
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$/', $filename)) {
        return false;
    }

    $full = BRANDING_DIR . '/' . $filename;
    if (file_exists($full)) {
        $real      = realpath($full);
        $real_base = realpath(BRANDING_DIR);
        if ($real === false || $real_base === false) {
            return false;
        }
        if ($real !== $real_base && strpos($real, $real_base . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
    }

    return $full;
}

// ─── Speichern & Löschen ────────────────────────────────────────────────────────

/**
 * Entfernt eine bestehende Slot-Datei unabhängig von ihrer Endung — z.B.
 * "logo.png" beim Ersetzen durch "logo.webp". Interner Helfer für
 * cms_store_branding_file(), damit beim Formatwechsel keine alte Datei liegen
 * bleibt (siehe Dateikopf).
 */
function cms_clear_branding_slot(string $slot): void {
    foreach (ALLOWED_IMAGE_EXT as $ext) {
        $stale = BRANDING_DIR . '/' . $slot . '.' . $ext;
        if (file_exists($stale)) {
            @unlink($stale);
        }
    }
}

/**
 * Schreibt Logo- oder Favicon-Bilddaten nach BRANDING_DIR unter dem festen
 * Namen "<slot>.<ext>" und gibt den Dateinamen zurück. $slot muss einer der
 * CMS_BRANDING_SLOTS sein, $ext einer der ALLOWED_IMAGE_EXT (beides prüft
 * der Aufrufer bereits über cms_validate_image_upload(); hier zusätzlich
 * defensiv abgesichert).
 *
 * Leerer Rückgabewert ('') bedeutet Fehlschlag (ungültiger Slot,
 * Verzeichnis nicht anlegbar oder Schreibfehler) — der Aufrufer muss darauf
 * prüfen, analog cms_store_image() in lib/media.php.
 */
function cms_store_branding_file(string $slot, string $ext, string $binary_data): string {
    if (!in_array($slot, CMS_BRANDING_SLOTS, true) || !in_array($ext, ALLOWED_IMAGE_EXT, true)) {
        return '';
    }

    if (!is_dir(BRANDING_DIR) && !@mkdir(BRANDING_DIR, 0755, true) && !is_dir(BRANDING_DIR)) {
        return '';
    }

    // Alte Datei desselben Slots zuerst abräumen (auch bei Endungswechsel),
    // dann erst neu schreiben — sonst läge kurzzeitig eine falsche Datei mit
    // dem Zielnamen im Weg, falls ext unverändert ist.
    cms_clear_branding_slot($slot);

    $target_name = $slot . '.' . $ext;

    // LOCK_EX wie cms_store_image() — verhindert eine halb geschriebene Datei
    // bei einem gleichzeitigen zweiten Schreibzugriff.
    if (file_put_contents(BRANDING_DIR . '/' . $target_name, $binary_data, LOCK_EX) === false) {
        return '';
    }

    return $target_name;
}

/**
 * Löscht eine Branding-Datei (Logo oder Favicon) unwiderruflich — kein
 * Backup, siehe Dateikopf. Whitelist- und realpath-Prüfung über
 * cms_resolve_branding_path(); ein Dateiname, der nicht existiert oder das
 * Muster verfehlt, liefert false.
 */
function cms_delete_branding_file(string $filename): bool {
    $full = cms_resolve_branding_path($filename);
    if ($full === false || !file_exists($full)) {
        return false;
    }
    return @unlink($full);
}
