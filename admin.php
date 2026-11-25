<?php
declare(strict_types=1);

/**
 * Mini-CMS Admin Backend (Secure Edition)
 *
 * Features:
 *   - Password login with bcrypt hash (no plaintext)
 *   - CSRF protection on all forms
 *   - Session fixation prevention
 *   - Brute-force protection (rate limiting)
 *   - Path traversal protection (realpath validation)
 *   - File extension whitelist (.md only for content)
 *   - Image upload with type validation
 *   - Markdown live preview
 */

// Markiert diesen Einstiegspunkt, damit config/config.php geladen werden darf.
define('CMS_ENTRY', true);

require_once __DIR__ . '/lib/security.php';

// Session mit HttpOnly/Secure/SameSite=Lax
cms_start_session();

// ─── Configuration ───────────────────────────────────────────────────────────
define('CONFIG_FILE',        __DIR__ . '/config/config.php');
define('CONTENT_DIR',        __DIR__ . '/content');
define('UPLOAD_DIR',         __DIR__ . '/assets/images');
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_IMAGE_EXT',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
// Logo/Favicon — eigenes Verzeichnis, getrennt vom
// Content-Bilder-Pool UPLOAD_DIR (siehe lib/branding.php, Dateikopf).
define('BRANDING_DIR',       __DIR__ . '/assets/branding');

// save_config() — geteilt mit install.php. Braucht CONFIG_FILE.
require_once __DIR__ . '/lib/config.php';

// Frontmatter-Parser, Pfad-Prüfung, Verzeichnis-Scan und Seiten-CRUD —
// geteilt mit index.php, api.php und mcp.php. Muss nach CONTENT_DIR kommen.
require_once __DIR__ . '/lib/content.php';

// Bildverwaltung (Validierung, Sanitizing, Speichern/Löschen/Auflisten) —
// geteilt mit mcp.php. Muss nach UPLOAD_DIR/ALLOWED_IMAGE_*
// kommen, die oben bereits definiert sind.
require_once __DIR__ . '/lib/media.php';

// Logo/Favicon-Verwaltung — nutzt cms_validate_image_upload()
// aus lib/media.php, muss also danach kommen. Braucht BRANDING_DIR, oben
// bereits definiert.
require_once __DIR__ . '/lib/branding.php';

// Enums für Rollen, Status und Einstellungs-Werte — geteilt mit mcp.php und
// install.php. Muss vor lib/users.php stehen, das die Enums zur Laufzeit
// nutzt.
require_once __DIR__ . '/lib/enums.php';

// Nutzerverwaltung — geteilt mit mcp.php. Braucht nur CMS_ENTRY,
// keine weiteren Konstanten vorher.
require_once __DIR__ . '/lib/users.php';

// 301-Redirect-Verwaltung — geteilt mit mcp.php.
// Braucht nur CMS_ENTRY, keine weiteren Konstanten vorher.
require_once __DIR__ . '/lib/redirects.php';

// Kategorienliste und Slug-Helfer.
require_once __DIR__ . '/lib/categories.php';

// post_*(), guard(), cfg_update() — Helfer für die POST-Handler unten.
require_once __DIR__ . '/lib/admin/helpers.php';

// POST-Handler, nach Thema gruppiert. Jeder gibt ein HandlerResult zurück,
// aufgerufen werden sie über die Handler-Map weiter unten.
require_once __DIR__ . '/lib/admin/result.php';
require_once __DIR__ . '/lib/admin/handlers-content.php';
require_once __DIR__ . '/lib/admin/handlers-settings.php';
require_once __DIR__ . '/lib/admin/handlers-users.php';
require_once __DIR__ . '/lib/admin/handlers-redirects.php';
require_once __DIR__ . '/lib/admin/handlers-categories.php';
require_once __DIR__ . '/lib/admin/handlers-branding.php';

// Zeilen, Sortierung und Filter für den Tab "Alle Seiten".
require_once __DIR__ . '/lib/admin/page-list.php';

// Anmeldung, Sitzung, Passwort-Reset und Mailversand.
require_once __DIR__ . '/lib/admin/auth.php';
require_once __DIR__ . '/lib/admin/mail.php';

// Helfer für die Templates unter lib/views/ (Auth-Renderer, CSS-Link, Icons).
require_once __DIR__ . '/lib/views/helpers.php';

/**
 * Lädt die Konfiguration aus config/config.php.
 * Bricht kontrolliert ab, falls die Datei fehlt — kein Login mit einem
 * fest einprogrammierten Standard-Passwort (Sicherheitsrisiko).
 *
 * Das Ergebnis wird pro Request memoisiert (cms_config_cache() in
 * lib/config.php), sonst wird die Datei bei jedem Aufruf neu eingelesen —
 * ein GET kommt auf drei Aufrufe, ein POST auf vier. Der Cache liegt
 * bewusst nicht als static hier, sondern in lib/config.php: nur so kann
 * save_config() ihn nach dem Schreiben verwerfen, damit die
 * Einstellungsseite den frisch gespeicherten Wert zeigt und nicht den alten.
 */
function load_config(): array {
    $cached = cms_config_cache();
    if ($cached !== null) {
        return $cached;
    }
    if (!file_exists(CONFIG_FILE)) {
        http_response_code(503);
        exit('CMS nicht konfiguriert. Bitte config/config.php aus config/config.example.php anlegen.');
    }
    $cfg = cms_config_with_defaults(require CONFIG_FILE);
    cms_config_cache($cfg);
    return $cfg;
}

$_cms_cfg = load_config();
define('MAX_UPLOAD_SIZE',     (int)$_cms_cfg['max_upload_size']);
define('MAX_LOGIN_ATTEMPTS',  (int)$_cms_cfg['max_login_attempts']);
define('LOGIN_LOCKOUT_TIME',  (int)$_cms_cfg['login_lockout_time']);
define('SITE_DOMAIN',         $_cms_cfg['site_domain']);

// Einmalige Migration ins Nutzermodell (Spec 4.2): läuft bei jedem
// Request, ist aber idempotent (Schnellpfad in cms_migrate_users() selbst) —
// kein separater Installations-Schritt nötig. admin_password_hash wird ab
// hier nicht mehr für den Login gelesen, bleibt aber unangetastet in
// config.php stehen (Rückwärtskompatibilität, falls users.json einmal fehlt).
cms_migrate_users($_cms_cfg);
unset($_cms_cfg);

// Site URL: prefer configured domain, fall back to auto-detect
if (SITE_DOMAIN !== '') {
    define('SITE_URL', rtrim(SITE_DOMAIN, '/'));
} else {
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    define('SITE_URL', $proto . '://' . $host);
}

// Security-Header setzen, bevor irgendetwas ausgegeben wird
cms_send_security_headers(SITE_URL, true);

// Handle login
if (isset($_POST['admin_password']) && isset($_POST['admin_email'])) {
    $login_error = handle_login_request();
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // L5: destroy_session() statt nacktem session_destroy() — leert zusaetzlich
    // $_SESSION und loescht das Cookie beim Browser, siehe deren Kommentar.
    destroy_session();
    header('Location: admin.php');
    exit;
}

// ─── Passwort vergessen: Anfrage-Formular ───────────────────────────────────
// Läuft bewusst VOR dem is_logged_in()-Gate: genau dafür ist der Flow da —
// wer eingeloggt wäre, bräuchte ihn nicht (Spec 4).
if (($_GET['action'] ?? '') === 'forgot_password') {
    $forgot_message = isset($_POST['forgot_email']) ? handle_forgot_password_request() : null;
    admin_render_auth('forgot', 'Passwort vergessen', ['message' => $forgot_message]);
    exit;
}

// ─── Passwort vergessen: Reset-Formular ─────────────────────────────────────
// Ebenfalls vor dem is_logged_in()-Gate. Token kommt bei GET aus der Mail-URL,
// bei POST aus dem versteckten Formularfeld (Spec 5.3).
if (($_GET['action'] ?? '') === 'reset_password') {
    $reset_token = is_string($_GET['token'] ?? null) ? $_GET['token'] : post_str('token');
    $token_user  = find_user_by_token($reset_token);
    if ($token_user === null) {
        admin_render_auth('invalid', 'Link ungültig');
        exit;
    }
    $reset_error = isset($_POST['new_password']) ? handle_reset_password_request() : null;
    // "Passwort festlegen" statt "zurücksetzen", solange der Nutzer noch nie
    // eines hatte (frische Einladung, Spec 5.4) — ein vom Admin ausgelöster
    // manueller Reset für einen bestehenden Nutzer läuft über denselben
    // Mechanismus, zeigt aber den Zurücksetzen-Text, weil password_hash dann
    // bereits gesetzt ist.
    $is_new = $token_user['password_hash'] === null;
    admin_render_auth('reset', $is_new ? 'Passwort festlegen' : 'Neues Passwort vergeben', [
        'token'  => $reset_token,
        'error'  => $reset_error,
        'is_new' => $is_new,
    ]);
    exit;
}

// Show login form if not authenticated
if (!is_logged_in()) {
    admin_render_auth('login', 'Admin Login', ['error' => $login_error ?? null]);
    exit;
}

// ─── Zustand für alle Handler ───────────────────────────────────────────────
// Muss VOR dem ersten Handler stehen: früher wurde hier unten initialisiert,
// wodurch alle Meldungen des Settings-Blocks stillschweigend überschrieben
// wurden (u.a. "Aktuelles Passwort ist falsch" — Fehlschlag sah aus wie Erfolg).
$action  = $_GET['action'] ?? 'list';
// $message enthaelt teils Nutzereingaben (E-Mail, Dateiname, Ordnername) und
// wird zentral beim Rendern escaped (e() am <div class="msg
// success">, nicht hier bei jeder Zuweisung) — L3: ein einzelner Escape-Punkt
// ist sicherer als "jeder Handler escaped selbst", weil eine kuenftige
// Zuweisung sonst leicht vergessen koennte.
$message = '';
$error   = '';

// Direktaufruf eines admin-only Tabs ohne das Recht dafür -> 403 (Spec 5.2/
// 6.2). Deckt die reine Anzeige per GET ab; die einzelnen POST-Handler unten
// prüfen sich zusätzlich selbst (Spec 6.2 verlangt die Prüfung pro
// Route/Aktion, nicht nur einmal zentral über $action, das mehrere
// POST-Handler teilen). "Mein Profil" (action=profile) ist bewusst NICHT
// hier gelistet: die Route ist für jede eingeloggte Rolle legitim
// — jeder sieht dort nur seine eigenen Daten, kein 403, sondern für alle
// dieselben Inhalte.
if ($action === 'users') {
    require_role('users');
}
if ($action === 'redirects') {
    require_role('redirects');
}
if ($action === 'ai') {
    require_role('settings');
}
// "Website & SEO" (Reiter Website/SEO) und die reduzierte "Einstellungen"
// (Uploads/Sicherheit/Kontaktformular) sind inzwischen beide admin-only —
// die frühere Content-Creator-Ansicht unter action=settings ist nach
// action=profile umgezogen (s.o.).
if ($action === 'site') {
    require_role('settings');
}
if ($action === 'settings') {
    require_role('settings');
}

// Landingpage-Vorschau im Editor: eigenes Dokument mit eigener CSP statt der
// Admin-Seite, läuft deshalb vor den POST-Handlern und kehrt nicht zurück.
if ($action === 'preview_landingpage') {
    handle_landingpage_preview();
}

// ─── POST-Handler ───────────────────────────────────────────────────────────
// POST-Key => Handler. Die Keys sind die name-Attribute der Submit-Buttons
// in den Formularen, deshalb nicht umbenennen. Pro Request läuft höchstens
// ein Handler: der erste Key dieser Liste, der im POST steckt. Früher lief
// jeder passende Handler, und der letzte überschrieb still die Meldung der
// vorherigen.
//
// Die Handler selbst liegen thematisch gruppiert in lib/admin/handlers-*.php.
// Jeder prüft Rolle und CSRF-Token über guard() selbst (Spec 6.2) — der
// GET-Gate oben deckt nur die Anzeige ab, ein POST kommt ohne passende
// action in der URL aus.
$post_handlers = [
    // Seiten, Ordner, Bilder
    'save_file'              => handle_save_file(...),
    'delete_file'            => handle_delete_file(...),
    'publish_file'           => handle_publish_file(...),
    'unpublish_file'         => handle_unpublish_file(...),
    'create_folder'          => handle_create_folder(...),
    'upload_image'           => handle_upload_image(...),
    'delete_image'           => handle_delete_image(...),
    // Website & SEO, Einstellungen, KI-Anbindung
    'save_website_settings'  => handle_save_website_settings(...),
    'save_seo_settings'      => handle_save_seo_settings(...),
    'save_upload_settings'   => handle_save_upload_settings(...),
    'save_security_settings' => handle_save_security_settings(...),
    'save_contact_settings'  => handle_save_contact_settings(...),
    'save_ai_settings'       => handle_save_ai_settings(...),
    'restore_history'        => handle_restore_history(...),
    // Logo & Favicon
    'upload_logo'            => handle_upload_logo(...),
    'delete_logo'            => handle_delete_logo(...),
    'upload_favicon'         => handle_upload_favicon(...),
    'delete_favicon'         => handle_delete_favicon(...),
    'save_branding_settings' => handle_save_branding_settings(...),
    // Mein Profil, Benutzer
    'save_profile'           => handle_save_profile(...),
    'regenerate_own_token'   => handle_regenerate_own_token(...),
    'invite_user'            => handle_invite_user(...),
    'send_user_token'        => handle_send_user_token(...),
    'regenerate_user_token'  => handle_regenerate_user_token(...),
    'toggle_user_status'     => handle_toggle_user_status(...),
    'delete_user'            => handle_delete_user(...),
    // Kategorien
    'create_category'        => handle_create_category(...),
    'rename_category'        => handle_rename_category(...),
    'delete_category'        => handle_delete_category(...),
    'save_category_settings' => handle_save_category_settings(...),
    // Redirects
    'create_redirect'        => handle_create_redirect(...),
    'delete_redirect'        => handle_delete_redirect(...),
];

$post_key = array_key_first(array_intersect_key($post_handlers, $_POST));
if ($post_key !== null) {
    $result  = $post_handlers[$post_key]();
    // 303: der Browser holt das Ziel per GET, ein Reload schickt das Formular
    // danach nicht erneut ab.
    if ($result->redirect !== null) {
        header('Location: ' . $result->redirect, true, 303);
        exit;
    }
    $message = $result->message;
    $error   = $result->error;
    $action  = $result->action ?? $action;
    unset($result);
} else {
    $message = admin_page_created_message($action);
}
unset($post_handlers, $post_key);

// ─── Ausgabe ────────────────────────────────────────────────────────────────
// Zuordnung action => View unter lib/views/admin/. Eine unbekannte action
// zeigt nur den Rahmen ohne Inhalt, wie vorher die if/elseif-Kette.
$admin_views = [
    'list'      => 'list',
    'images'    => 'images',
    'new'       => 'editor',
    'edit'      => 'editor',
    'site'      => 'site',
    'settings'  => 'settings',
    'profile'   => 'profile',
    'users'     => 'users',
    'redirects' => 'redirects',
    'ai'        => 'ai',
];
$view = $admin_views[$action] ?? null;
unset($admin_views);

// Für das Logo oben in der Sidebar — dieselben Felder wie im Reiter
// "Website" unter "Website & SEO" (admin.php, Action "site").
$admin_cfg       = load_config();
$admin_site_logo = trim((string)($admin_cfg['site_logo'] ?? ''));

// Der eingeloggte Nutzer für den gesamten Render-Durchlauf: Sidebar,
// Tab "KI-Anbindung" und Tab "Benutzer" haben sich den bisher jeweils
// einzeln geholt. Hier ist der früheste Punkt, an dem das sicher geht —
// alle POST-Handler sind durch, keiner schreibt users.json mehr, der Wert
// kann also für den Rest der Seite nicht mehr veralten. Die Handler
// weiter oben rufen bewusst weiterhin selbst current_user() auf: dort
// liegen Schreibzugriffe (eigene E-Mail, eigenes Passwort, eigener
// MCP-Token) dazwischen, ein vorgeladener Wert wäre danach falsch.
$me         = current_user();
$admin_role = $me['role'] ?? '';

require __DIR__ . '/lib/views/admin/layout.php';
