<?php
declare(strict_types=1);

/**
 * POST-Handler für Logo & Favicon (Tab "Website & SEO").
 *
 * Datei-Uploads laufen über dieselbe Validierung wie der bestehende
 * Bilder-Upload (lib/media.php, cms_validate_image_upload()) — nur der
 * Speicherort ist ein anderer (lib/branding.php, BRANDING_DIR statt
 * UPLOAD_DIR, siehe dortiger Dateikopf). Logo und Favicon sind unabhängig
 * voneinander hoch- und löschbar.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Gemeinsamer Ablauf für Logo- und Favicon-Upload: Datei aus $_FILES lesen,
 * validieren, speichern und den Dateinamen in die Config schreiben.
 *
 * Keine eigene Maßprüfung fürs Favicon.
 *
 * @param string $kind      'logo' oder 'favicon' — Formularfeld und Dateiname
 * @param string $cfg_key   Config-Schlüssel für den gespeicherten Dateinamen
 * @param string $label     "Logo"/"Favicon" für die Meldungen
 */
function admin_upload_branding_file(string $kind, string $cfg_key, string $label): HandlerResult {
    $error = guard('settings');
    if ($error !== null) {
        return HandlerResult::failure($error, 'site');
    }
    if (!isset($_FILES[$kind]) || $_FILES[$kind]['error'] !== UPLOAD_ERR_OK) {
        return HandlerResult::failure('Fehler beim Upload. Bitte versuche es erneut.', 'site');
    }
    $file = $_FILES[$kind];
    $binary_data = file_get_contents($file['tmp_name']);
    if ($binary_data === false) {
        return HandlerResult::failure('Fehler beim Upload. Bitte versuche es erneut.', 'site');
    }

    $validation = cms_validate_image_upload($file['name'], $binary_data);
    if (!$validation['ok']) {
        return HandlerResult::failure($validation['error'], 'site');
    }
    $stored_name = cms_store_branding_file($kind, $validation['ext'], $binary_data);
    if ($stored_name === '') {
        return HandlerResult::failure('Fehler beim Speichern des ' . $label . 's.', 'site');
    }

    $error = cfg_update(static function (array $cfg) use ($cfg_key, $stored_name): array {
        $cfg[$cfg_key] = $stored_name;
        return $cfg;
    });
    return HandlerResult::fromError($error, $label . ' hochgeladen: ' . $stored_name, 'site');
}

/** Gegenstück zu admin_upload_branding_file(): Datei löschen, Config leeren. */
function admin_delete_branding_file(string $cfg_key, string $label): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg) use ($cfg_key, $label): array|string {
        $filename = trim((string)($cfg[$cfg_key] ?? ''));
        if ($filename !== '' && !cms_delete_branding_file($filename)) {
            return 'Fehler beim Löschen des ' . $label . 's.';
        }
        $cfg[$cfg_key] = '';
        return $cfg;
    });
    return HandlerResult::fromError($error, $label . ' gelöscht.', 'site');
}

function handle_upload_logo(): HandlerResult {
    return admin_upload_branding_file('logo', 'site_logo', 'Logo');
}

function handle_delete_logo(): HandlerResult {
    return admin_delete_branding_file('site_logo', 'Logo');
}

function handle_upload_favicon(): HandlerResult {
    return admin_upload_branding_file('favicon', 'site_favicon', 'Favicon');
}

function handle_delete_favicon(): HandlerResult {
    return admin_delete_branding_file('site_favicon', 'Favicon');
}

// Position + Anzeige-Modus — nur relevant, solange ein Logo hochgeladen ist
// (im UI entsprechend nur dann sichtbar), serverseitig trotzdem gegen eine
// feste Werte-Whitelist geprüft statt dem <select> blind zu vertrauen.
function handle_save_branding_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array|string {
        $position = LogoPosition::tryFrom(post_str('site_logo_position', 'links'));
        $display  = LogoDisplay::tryFrom(post_str('site_logo_display', 'logo_only'));
        if ($position === null) {
            return 'Ungültige Logo-Position.';
        }
        if ($display === null) {
            return 'Ungültiger Anzeige-Modus.';
        }
        $cfg['site_logo_position'] = $position->value;
        $cfg['site_logo_display']  = $display->value;
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Branding-Einstellungen gespeichert.', 'site');
}
