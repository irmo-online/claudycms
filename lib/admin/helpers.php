<?php
declare(strict_types=1);

/**
 * Gemeinsame Helfer für die POST-Handler in admin.php.
 *
 * Enthält:
 *   - post_str() / post_bool() / post_int()  typsicheres Lesen aus $_POST
 *   - get_str()                              typsicheres Lesen aus $_GET
 *   - fail_403()                             Abbruch mit 403
 *   - guard()                                Rolle + CSRF in einem Aufruf
 *   - cfg_update()                           Config laden, ändern, speichern
 *
 * Braucht require_role() und verify_csrf() aus lib/admin/auth.php sowie
 * load_config()/save_config() — erst zur Laufzeit, die Reihenfolge der
 * Einbindung spielt also keine Rolle.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

const ADMIN_CSRF_ERROR        = 'Ungültiges Sicherheits-Token. Bitte lade die Seite neu.';
const ADMIN_CONFIG_SAVE_ERROR = 'Fehler beim Speichern. Prüfe die Schreibrechte auf config/config.php.';

// ─── Eingaben ───────────────────────────────────────────────────────────────
//
// Ein Formularfeld kann als Array ankommen (feld[]=x). Direkt in einen
// string-Parameter gereicht, wirft PHP 8 auch ohne strict_types einen
// ungefangenen TypeError, also HTTP 500. Die Helfer liefern in dem Fall den
// Default, als wäre das Feld gar nicht geschickt worden.

function post_str(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? null;
    return is_string($value) ? $value : $default;
}

/** Checkbox-Semantik: gesetzt heißt an, egal mit welchem Wert. */
function post_bool(string $key): bool {
    return isset($_POST[$key]);
}

/** Wie der frühere (int)-Cast: ein nicht-numerischer String wird zu 0. */
function post_int(string $key, int $default = 0): int {
    $value = $_POST[$key] ?? null;
    return is_string($value) ? (int)$value : $default;
}

/** Wie post_str(), für Parameter aus der URL (Sortierung, Filter). */
function get_str(string $key, string $default = ''): string {
    $value = $_GET[$key] ?? null;
    return is_string($value) ? $value : $default;
}

// ─── Zugriffsschutz ─────────────────────────────────────────────────────────

/**
 * Bricht den Request mit 403 ab. `never` sagt dem Leser (und statischer
 * Analyse), dass danach kein Code mehr läuft.
 *
 * Die Guards am Kopf der lib/-Dateien nutzen das bewusst nicht: sie greifen
 * genau dann, wenn eine Datei direkt aufgerufen wird, und dann ist noch keine
 * Funktion geladen.
 */
function fail_403(): never {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * Prüft Rolle und CSRF-Token eines POST-Handlers in einem Aufruf.
 *
 * Fehlt das Recht, bricht require_role() mit 403 ab — die Rolle wird bewusst
 * zuerst geprüft, damit ein fremder POST nicht einmal erfährt, ob sein Token
 * gepasst hätte. $permission === null heißt: Route steht jeder eingeloggten
 * Rolle offen (z. B. "Mein Profil"), nur CSRF wird geprüft.
 *
 * @return string|null null wenn alles passt, sonst der Fehlertext fürs Formular
 */
function guard(?string $permission): ?string {
    if ($permission !== null) {
        require_role($permission);
    }
    return verify_csrf() ? null : ADMIN_CSRF_ERROR;
}

// ─── Config schreiben ───────────────────────────────────────────────────────

/**
 * Lädt die Config frisch, lässt $mutate die eigenen Schlüssel ändern und
 * schreibt das ganze Array zurück. save_config() erhält Fremdschlüssel, ein
 * Handler überschreibt also nie die Werte eines anderen Formulars.
 *
 * $mutate bekommt die Config und gibt sie geändert zurück — oder einen String
 * als Validierungsfehler, dann wird nichts gespeichert.
 *
 * @param callable(array): (array|string) $mutate
 * @return string|null null bei Erfolg, sonst der Fehlertext
 */
function cfg_update(callable $mutate): ?string {
    $result = $mutate(load_config());
    if (is_string($result)) {
        return $result;
    }
    return save_config($result) ? null : ADMIN_CONFIG_SAVE_ERROR;
}
