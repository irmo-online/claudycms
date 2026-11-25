<?php
declare(strict_types=1);

/**
 * Anmeldung, Sitzung und Passwort-Reset für admin.php.
 *
 * Enthält:
 *   - csrf_token() / csrf_field() / verify_csrf()   CSRF-Schutz der Formulare
 *   - Login-Rate-Limits pro Session, IP und Konto
 *   - Reset-/Einladungs-Token pro Nutzer (config/reset/)
 *   - handle_forgot_password_request() / handle_reset_password_request()
 *   - destroy_session() / is_logged_in() / current_user() / require_role()
 *   - handle_login_request()
 *
 * Braucht lib/users.php beim Einbinden (CMS_DUMMY_PASSWORD_HASH), alles
 * andere (MAX_LOGIN_ATTEMPTS, post_str(), send_user_email()) erst zur Laufzeit.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Security Helpers ───────────────────────────────────────────────────────

/**
 * Generate or validate a CSRF token.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): bool {
    $token = post_str('csrf_token');
    return $token !== '' && hash_equals(csrf_token(), $token);
}

/**
 * Brute-force protection: track login attempts (session-basiert).
 * Achtung: allein reicht das nicht — ein Angreifer ohne Session-Cookie
 * bekommt bei jedem Request eine neue, leere Session und umgeht die Sperre.
 * Siehe check_ip_rate_limit() für den IP-basierten Zusatzschutz.
 */
function check_rate_limit(): bool {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lockout_until = $_SESSION['lockout_until'] ?? 0;

    if ($lockout_until > time()) {
        return false; // Still locked out
    }

    if ($lockout_until > 0 && $lockout_until <= time()) {
        // Lockout expired, reset
        $_SESSION['login_attempts'] = 0;
        $_SESSION['lockout_until'] = 0;
    }

    return $attempts < MAX_LOGIN_ATTEMPTS;
}

function record_failed_login(): void {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $_SESSION['lockout_until'] = time() + LOGIN_LOCKOUT_TIME;
    }
}

function reset_login_attempts(): void {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['lockout_until'] = 0;
}

/**
 * Brute-force protection: track login attempts pro IP-Adresse (dateibasiert).
 * Läuft unabhängig von der Session, damit ein Angreifer die Sperre nicht
 * durch Verwerfen des Session-Cookies umgehen kann.
 *
 * Ablageort ist config/ratelimit/ (siehe lib/security.php) — vorher lagen die
 * Zähler in sys_get_temp_dir(), auf Shared Hosting also im mit fremden
 * Mandanten geteilten /tmp.
 */
function ip_rate_limit_file(): string {
    return cms_rate_limit_file('login', cms_client_ip());
}

function read_ip_rate_limit_data(): array {
    $file = ip_rate_limit_file();
    if (file_exists($file)) {
        $stored = json_decode((string)file_get_contents($file), true);
        if (is_array($stored) && isset($stored['attempts'], $stored['lockout_until'])) {
            return $stored;
        }
    }
    return ['attempts' => 0, 'lockout_until' => 0];
}

function check_ip_rate_limit(): bool {
    $data = read_ip_rate_limit_data();

    if ($data['lockout_until'] > time()) {
        return false; // Still locked out
    }

    if ($data['lockout_until'] > 0 && $data['lockout_until'] <= time()) {
        // Lockout expired, reset
        $data = ['attempts' => 0, 'lockout_until' => 0];
        file_put_contents(ip_rate_limit_file(), json_encode($data), LOCK_EX);
    }

    return (int)$data['attempts'] < MAX_LOGIN_ATTEMPTS;
}

function record_ip_failed_login(): void {
    $data = read_ip_rate_limit_data();
    $data['attempts']++;
    if ($data['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $data['lockout_until'] = time() + LOGIN_LOCKOUT_TIME;
    }
    file_put_contents(ip_rate_limit_file(), json_encode($data), LOCK_EX);
    // Alte Zählerdateien wegräumen — im Projektverzeichnis tut das sonst
    // niemand, /tmp hat früher der Hoster geleert.
    cms_rate_limit_cleanup();
}

function reset_ip_login_attempts(): void {
    $file = ip_rate_limit_file();
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Brute-force protection pro Nutzer-Konto (Spec 6.3), zusätzlich
 * zum IP-Lockout — verhindert, dass ein Angreifer mit vielen IP-Adressen ein
 * einzelnes Konto gezielt durch Passwort-Raten angreift, ohne vom IP-Lockout
 * gebremst zu werden. 1:1 dieselbe Mechanik wie ip_rate_limit_file() & Co.,
 * nur nach E-Mail statt IP geschlüsselt, und bewusst an dieselben Grenzwerte
 * gekoppelt (MAX_LOGIN_ATTEMPTS/LOGIN_LOCKOUT_TIME) statt eigener
 * Einstellungen (Spec 8, Punkt 4, v0.3).
 *
 * Bewusst kein eigener Eintrag in login_lockout_remaining(): eine sichtbare
 * Wartezeit würde verraten, dass die eingegebene E-Mail zu einem echten,
 * gerade gesperrten Konto gehört — Enumeration-Schutz (Spec 6.4) gilt auch
 * hier, nicht nur für den Fehlertext selbst.
 */
function account_rate_limit_file(string $email): string {
    return cms_rate_limit_file('login_account', strtolower(trim($email)));
}

function read_account_rate_limit_data(string $email): array {
    $file = account_rate_limit_file($email);
    if (file_exists($file)) {
        $stored = json_decode((string)file_get_contents($file), true);
        if (is_array($stored) && isset($stored['attempts'], $stored['lockout_until'])) {
            return $stored;
        }
    }
    return ['attempts' => 0, 'lockout_until' => 0];
}

function check_account_rate_limit(string $email): bool {
    $data = read_account_rate_limit_data($email);

    if ($data['lockout_until'] > time()) {
        return false;
    }

    if ($data['lockout_until'] > 0 && $data['lockout_until'] <= time()) {
        $data = ['attempts' => 0, 'lockout_until' => 0];
        file_put_contents(account_rate_limit_file($email), json_encode($data), LOCK_EX);
    }

    return (int)$data['attempts'] < MAX_LOGIN_ATTEMPTS;
}

function record_account_failed_login(string $email): void {
    $data = read_account_rate_limit_data($email);
    $data['attempts']++;
    if ($data['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $data['lockout_until'] = time() + LOGIN_LOCKOUT_TIME;
    }
    file_put_contents(account_rate_limit_file($email), json_encode($data), LOCK_EX);
    cms_rate_limit_cleanup();
}

function reset_account_login_attempts(string $email): void {
    $file = account_rate_limit_file($email);
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Verbleibende Sperrzeit in Sekunden, über beide Mechanismen hinweg.
 */
function login_lockout_remaining(): int {
    $session_remaining = max(0, ($_SESSION['lockout_until'] ?? 0) - time());
    $ip_data = read_ip_rate_limit_data();
    $ip_remaining = max(0, (int)$ip_data['lockout_until'] - time());
    return max($session_remaining, $ip_remaining);
}

// ─── Passwort vergessen/Einladung: Token-Verwaltung pro Nutzer (Spec 4.1/5.4)
// ────────────────────────────────────────────────────────────────────────────
//
// Dateibasiert wie die Rate-Limits, aber eigenes Verzeichnis und eigene
// Lebensdauer: ein Token ist einmalig gültig und wird beim Verbrauch
// gelöscht, ein Rate-Limit-Zähler läuft dagegen über ein Zeitfenster weiter.
// config/reset/ ist bereits über config/.htaccess gesperrt — die Regel dort
// gilt rekursiv für den ganzen config/-Ordner, genau wie bei
// config/ratelimit/, das ebenfalls keine eigene .htaccess braucht.
//
// Eigene Datei pro Nutzer (config/reset/<user_id>.json) statt der einen
// globalen Datei aus der ursprünglichen "Passwort vergessen"-Version: seit
// Einführung der Benutzerverwaltung gibt es mehr als einen Account, mehrere
// Nutzer können gleichzeitig einen offenen Link haben — eine gemeinsame
// Datei würde sich gegenseitig überschreiben. Ursprünglich (nur ein Admin)
// genügte eine einzelne Datei; diese Umstellung ist Spec 8 Punkt 1 (v0.3)
// und bedient jetzt sowohl "Passwort vergessen" als auch Einladungen mit
// demselben Mechanismus (Spec 5.4).
//
// Die Mail-URL trägt nur den Token, keine user_id — find_user_by_token()
// durchsucht deshalb alle Nutzer-Token-Dateien, analog
// cms_find_user_by_mcp_token() in lib/users.php.

define('RESET_TOKEN_DIR', dirname(__DIR__, 2) . '/config/reset');

function user_token_file(string $user_id): string {
    return RESET_TOKEN_DIR . '/' . $user_id . '.json';
}

/**
 * Erzeugt einen neuen Token für einen Nutzer und überschreibt einen evtl.
 * bestehenden — es kann immer nur ein Link pro Nutzer gleichzeitig gültig
 * sein. Gespeichert wird nur der SHA-256-Hash, nicht der Token selbst — ein
 * Leak der Datei allein reicht dann nicht, um den Link nachzubauen.
 *
 * @return string|null Der Klartext-Token für den Mail-Link, oder null, wenn
 *                      das Verzeichnis nicht anlegbar/beschreibbar ist.
 */
function create_user_token(string $user_id): ?string {
    $dir = RESET_TOKEN_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    $data = [
        'token_hash' => hash('sha256', $token),
        'expires_at' => time() + 3600, // 1 Stunde, Spec 0/4.1
    ];
    if (@file_put_contents(user_token_file($user_id), json_encode($data), LOCK_EX) === false) {
        return null;
    }
    return $token;
}

/** Der Nutzer, dem ein Token gehört, oder null (unbekannt/abgelaufen). */
function find_user_by_token(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    foreach (cms_load_users() as $user) {
        $file = user_token_file($user['id']);
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['token_hash'], $data['expires_at'])) {
            continue;
        }
        if ((int)$data['expires_at'] < time()) {
            continue;
        }
        if (hash_equals((string)$data['token_hash'], $hash)) {
            return $user;
        }
    }
    return null;
}

/** Verbraucht den Token eines Nutzers (Single-Use). */
function consume_user_token(string $user_id): void {
    @unlink(user_token_file($user_id));
}

/**
 * Verarbeitet den POST des Formulars "Passwort vergessen" (Spec 5.2).
 * Seit Einführung der Benutzerverwaltung für alle Nutzer, nicht mehr nur
 * den einen Admin (Spec 3.1, Entscheidung 9) — die E-Mail wird gegen
 * users.json geprüft statt fest
 * gegen smtp_from_email.
 *
 * Enumeration-Schutz (Spec 6.2/6.4): Treffer, Nicht-Treffer, gesperrtes
 * Konto und Rate-Limit-Überschreitung (Spec 6.1) liefern bewusst denselben
 * Text. Nur ein technischer Mailversand-Fehler bekommt eine eigene Meldung
 * (Spec 5.4) — der hängt nicht von der eingegebenen Adresse ab, verrät also
 * nichts.
 *
 * @return array{type:string, text:string}
 */
function handle_forgot_password_request(): array {
    $generic = ['type' => 'success', 'text' => 'Falls die Adresse hinterlegt ist, wurde ein Link zum Zurücksetzen verschickt.'];

    if (!verify_csrf()) {
        return ['type' => 'error', 'text' => ADMIN_CSRF_ERROR];
    }

    // Vor dem E-Mail-Abgleich geprüft (nicht erst beim Mailversand weiter
    // unten): sonst würde eine unbekannte Adresse bei fehlender site_domain
    // weiterhin die generische "falls hinterlegt"-Antwort bekommen, eine
    // bekannte aber den Fehlertext — das verriete am Antworttext, welche
    // E-Mails existieren (Enumeration-Schutz, Spec 6.4 gilt auch hier).
    if (!site_url_trustworthy_for_mail()) {
        return ['type' => 'error', 'text' => 'E-Mail-Versand ist aktuell nicht möglich. Bitte versuch es später erneut.'];
    }

    // Fest verdrahtet: 5 Anfragen/Stunde/IP (Spec 6.1). Kein Hinweis im
    // Antworttext, sonst verriete das Limit selbst schon etwas.
    $limit = cms_rate_limit_hit('password_reset', cms_client_ip(), 3600);
    if ($limit['count'] > 5) {
        return $generic;
    }

    $email = trim(post_str('forgot_email'));
    $user  = $email !== '' ? cms_find_user_by_email($email) : null;

    if ($user === null || ($user['status'] ?? '') !== UserStatus::Aktiv->value) {
        return $generic;
    }

    $token = create_user_token($user['id']);
    if ($token === null) {
        return ['type' => 'error', 'text' => 'E-Mail-Versand ist aktuell nicht möglich. Bitte versuch es später erneut.'];
    }

    $cfg     = load_config();
    $link    = SITE_URL . '/admin.php?action=reset_password&token=' . $token;
    $subject = 'Passwort zurücksetzen für ' . ($cfg['site_title'] !== '' ? $cfg['site_title'] : 'deine Website');
    $body    = "Für dein Konto wurde ein Zurücksetzen des Passworts angefordert.\n\n"
        . "Link (1 Stunde gültig): {$link}\n\n"
        . "Hast du das nicht angefordert, ignoriere diese Mail — es passiert nichts von selbst.";

    if (!send_user_email($cfg, $user['email'], $subject, $body)) {
        // Fail-closed wie beim Backup der KI-Anbindung: lieber gar kein Token
        // stehen lassen als einen Link, der nie verschickt wurde (Spec 5.4).
        consume_user_token($user['id']);
        return ['type' => 'error', 'text' => 'E-Mail-Versand ist aktuell nicht möglich. Bitte versuch es später erneut.'];
    }

    return $generic;
}

/**
 * Verarbeitet den POST des Reset-Formulars (Spec 5.3/4). Der Token wurde vom
 * Aufrufer bereits als gültig geprüft — auf jedem POST läuft das über
 * dieselbe Weiche wie beim GET, das deckt die von der Spec verlangte
 * erneute Prüfung ab (kein zweiter Check nötig innerhalb desselben Requests).
 *
 * Bei Erfolg leitet die Funktion direkt weiter wie der normale Login und
 * kehrt nicht zurück. Bei einem Fehler gibt sie den Meldungstext zurück,
 * das Formular wird dann mit dieser Meldung erneut angezeigt.
 */
function handle_reset_password_request(): ?string {
    if (!verify_csrf()) {
        return ADMIN_CSRF_ERROR;
    }

    $new_pw  = post_str('new_password');
    $new_pw2 = post_str('new_password_confirm');
    if (strlen($new_pw) < 12) {
        return 'Das neue Passwort muss mindestens 12 Zeichen lang sein.';
    }
    if ($new_pw !== $new_pw2) {
        return 'Neues Passwort und Bestätigung stimmen nicht überein.';
    }

    // Das Passwort liegt in users.json, der Token in einer Datei
    // pro Nutzer (Spec 5.4) — der Aufrufer (die Route) hat den Token bereits
    // gegen find_user_by_token() geprüft, hier nur die eigentliche Änderung.
    $token = post_str('token');
    $user  = find_user_by_token($token);
    if ($user === null) {
        return 'Dieser Link ist ungültig oder abgelaufen.';
    }
    $is_new_account = $user['password_hash'] === null;

    if (!cms_set_user_password($user['id'], cms_hash_password($new_pw))) {
        return 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.';
    }
    consume_user_token($user['id']); // Single-Use — ein zweiter Aufruf desselben Links schlägt danach fehl

    session_regenerate_id(true); // wie beim normalen Login (Session-Fixation)
    $_SESSION['user_id'] = $user['id'];
    // Session-Lebensdauer (L5): wie beim normalen Login, siehe dort.
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();
    cms_record_user_login($user['id']);

    // Bestätigungsmail ist best-effort: ein Fehlschlag macht den bereits
    // erfolgreichen Reset nicht rückgängig — das Fail-Closed-Prinzip aus
    // Spec 5.4 gilt nur für die Anfrage-Mail, nicht mehr für die Bestätigung
    // danach. Der Nutzer ist ohnehin schon eingeloggt und sieht sein neues
    // Passwort funktionieren.
    $cfg  = load_config();
    $site = $cfg['site_title'] !== '' ? $cfg['site_title'] : 'deine Website';
    if ($is_new_account) {
        $subject = 'Dein Zugang ist eingerichtet';
        $body    = "Dein Passwort für {$site} wurde soeben festgelegt — dein Zugang ist ab sofort nutzbar.";
    } else {
        $subject = 'Dein Passwort wurde geändert';
        $body    = "Dein Passwort für {$site} wurde soeben per Link geändert.\n\n"
            . 'Warst das nicht du, prüfe umgehend den Zugriff auf dein Konto und dein E-Mail-Postfach.';
    }
    send_user_email($cfg, $user['email'], $subject, $body);

    header('Location: admin.php');
    exit;
}

// ─── Authentication ─────────────────────────────────────────────────────────

// Ein bcrypt-Hash ohne zugehöriges Klartext-Passwort — nur damit
// password_verify() unten bei unbekannter/gesperrter E-Mail genauso lange
// läuft wie bei einem echten, falschen Passwortversuch. Ohne das wäre eine
// unbekannte Adresse an der (viel kürzeren) Antwortzeit erkennbar, obwohl die
// Fehlermeldung selbst identisch bleibt — Spec 6.4 redet vom Meldungstext,
// das hier schließt dieselbe Lücke auf Zeitebene (nicht in der Spec, aber im
// Sinne von deren Enumeration-Schutz).
//
// Der Wert kommt aus lib/users.php, wo auch der Kostenfaktor echter Hashes
// festgelegt ist — vorher stand hier ein fest eingetragener Cost-10-Hash,
// während echte Passwörter je nach PHP-Version des Hosters mit Cost 12
// geschrieben wurden. Damit lief der Dummy viermal schneller durch als ein
// echter Hash, und der Zeitschutz war zu vier Fünfteln wirkungslos.
define('CMS_DUMMY_PASSWORD_HASH', cms_dummy_password_hash());

// Session-Lebensdauer (L5): kein Config-Wert, sondern Code-Konstanten wie
// UPLOAD_DIR & Co. in lib/media.php — !defined()-Guard, damit ein Test sie
// per auto_prepend_file auf kleine Werte setzen kann, ohne config/config.php
// anzufassen. 30 Minuten Idle-Timeout, 12 Stunden absolutes Maximum seit
// Login (auch bei durchgehender Aktivität) — beides fehlte bisher komplett.
if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800);
}
if (!defined('SESSION_ABSOLUTE_TIMEOUT')) {
    define('SESSION_ABSOLUTE_TIMEOUT', 43200);
}

/**
 * Beendet die Session vollständig: leert $_SESSION, löscht das Cookie beim
 * Browser und zerstört die Session serverseitig (L5). session_destroy()
 * allein tut nur den letzten Schritt — $_SESSION bliebe im laufenden
 * Request gefüllt, und ohne gelöschtes Cookie schickt der Browser die (auf
 * dem Server bereits ungültige) Session-ID beim nächsten Request weiter mit.
 */
function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

function is_logged_in(): bool {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    // Idle-Timeout und absolutes Maximum (L5) — ohne das blieb eine
    // gestohlene oder vergessene offene Session unbegrenzt lange gültig.
    // Fehlen die Zeitstempel (z.B. eine Session von vor diesem Fix), wird
    // "jetzt" angenommen statt sofort auszuloggen — kein hartes Abmelden
    // mitten in einer laufenden Arbeit direkt nach dem Deploy.
    $now = time();
    $_SESSION['login_time']    = $_SESSION['login_time']    ?? $now;
    $_SESSION['last_activity'] = $_SESSION['last_activity'] ?? $now;
    if (($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT
        || ($now - $_SESSION['login_time']) > SESSION_ABSOLUTE_TIMEOUT) {
        destroy_session();
        return false;
    }
    $_SESSION['last_activity'] = $now;

    // Rolle/Status werden pro Request nachgeschlagen, nicht nur beim Login
    // (Spec 5.2) — eine Sperrung durch den Admin wirkt so spätestens beim
    // nächsten Request dieser Session, nicht erst nach einem erneuten
    // Login-Versuch (Spec 6.7 nennt dieselbe Grenze: erst beim nächsten
    // Request, nicht sofort serverseitig erzwingbar).
    $user = cms_find_user_by_id($_SESSION['user_id']);
    return $user !== null && ($user['status'] ?? '') === UserStatus::Aktiv->value;
}

/** Der eingeloggte Nutzer, oder null. Für Rollen-/Rechteprüfungen (spätere Unteraufgaben). */
function current_user(): ?array {
    return isset($_SESSION['user_id']) ? cms_find_user_by_id($_SESSION['user_id']) : null;
}

/**
 * Bricht mit 403 ab, wenn der eingeloggte Nutzer das angegebene Recht nicht
 * hat (Spec 6.2: jede admin-only Route prüft die Rolle bei jedem Request,
 * nicht nur die Sichtbarkeit im Menü — UI-Ausblendung allein ist kein
 * Zugriffsschutz). Muss nach dem is_logged_in()-Gate weiter unten aufgerufen
 * werden, setzt also einen eingeloggten Nutzer voraus.
 */
function require_role(string $permission): void {
    $me = current_user();
    if ($me === null || !cms_role_can($me['role'], $permission)) {
        fail_403();
    }
}

/**
 * Verarbeitet den POST des Login-Formulars. Bei Erfolg leitet die Funktion
 * weiter und kehrt nicht zurück, sonst gibt sie den Fehlertext für das
 * Formular zurück.
 */
function handle_login_request(): string {
    if (!verify_csrf()) {
        return ADMIN_CSRF_ERROR;
    } elseif (!check_rate_limit() || !check_ip_rate_limit()) {
        // L7: auch ein Versuch waehrend eines aktiven Lockouts geloggt — genau
        // das ist das Muster eines laufenden Angriffs (Brute-Force trotz Sperre).
        error_log('[CMS Login] Versuch waehrend aktivem Lockout, IP=' . cms_client_ip());
        $remaining = login_lockout_remaining();
        return "Zu viele Fehlversuche. Bitte warte " . max(1, (int)ceil($remaining / 60)) . " Minuten.";
    } else {
        $email = trim(post_str('admin_email'));
        $user  = cms_find_user_by_email($email);

        // password_verify() läuft *immer*, notfalls gegen den Dummy-Hash
        // oben — siehe dessen Kommentar.
        $hash_to_check = ($user !== null && $user['password_hash'] !== null)
            ? $user['password_hash']
            : CMS_DUMMY_PASSWORD_HASH;
        $password_ok = password_verify(post_str('admin_password'), $hash_to_check);

        $account_active = $user !== null && ($user['status'] ?? '') === UserStatus::Aktiv->value;

        // Enumeration-Schutz (Spec 6.4): falsche E-Mail, falsches Passwort,
        // gesperrtes Konto und ein durch Fehlversuche ausgeschöpftes
        // Konto-Lockout liefern unten bewusst denselben Text — kein eigener
        // Zweig mit eigener Meldung wie beim IP-/Session-Lockout oben (der
        // verrät nichts Konto-Spezifisches, trifft jede Session gleich).
        $account_ok = $account_active && check_account_rate_limit($email) && $password_ok;

        if ($account_ok) {
            reset_login_attempts();
            reset_ip_login_attempts();
            reset_account_login_attempts($email);
            cms_record_user_login($user['id']);
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['user_id'] = $user['id'];
            // Session-Lebensdauer (L5): Grundlage fuer Idle-Timeout und
            // absolutes Maximum in is_logged_in().
            $_SESSION['login_time']    = time();
            $_SESSION['last_activity'] = time();

            header('Location: admin.php');
            exit;
        } else {
            record_failed_login();
            record_ip_failed_login();
            // Nur ein tatsächlich existierendes, aktives Konto bekommt einen
            // Fehlversuch angerechnet — ein erfundener Adressstring hat kein
            // Konto, das sich sperren ließe.
            if ($account_active) {
                record_account_failed_login($email);
            }
            // L7: Fehlgeschlagene Logins landen im Server-Error-Log — bisher
            // gab es dafuer keinerlei Protokollierung, ein laufender Angriff
            // war nicht erkennbar. Absichtlich ohne Passwort, mit E-Mail (die
            // steht ohnehin schon in den Rate-Limit-Dateien) und IP.
            error_log(sprintf(
                '[CMS Login] Fehlgeschlagener Versuch: E-Mail=%s IP=%s Konto-aktiv=%s',
                $email,
                cms_client_ip(),
                $account_active ? 'ja' : 'nein'
            ));
            return 'E-Mail oder Passwort falsch.';
        }
    }
}
