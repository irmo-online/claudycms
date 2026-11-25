<?php
/**
 * Nutzerverwaltung für alle Einstiegspunkte.
 *
 * Löst das bisherige Single-Admin-Modell (ein Passwort-Hash in
 * config/config.php, ein globaler ai_connector_token) durch echte Accounts
 * ab: mehrere Nutzer in config/users.json, unterschieden nach Rolle.
 *
 *   - cms_role_can() / cms_known_roles()     Rollen-Rechte (Tabelle an Role, lib/enums.php)
 *   - cms_load_users() / cms_find_user_by_*() Lesen
 *   - cms_create_user() / cms_delete_user() / cms_set_user_status() /
 *     cms_set_user_password() / cms_update_user_email() /
 *     cms_regenerate_user_mcp_token() / cms_record_user_login()  Schreiben
 *   - cms_migrate_users()                     Einmalige Migration (Spec 4.2)
 *   - cms_create_initial_admin()              Erstinstallation (Spec 5.4)
 *
 * Spec sieht die Rollen-Rechte-Tabelle in einem eigenen lib/auth.php vor
 * (Abschnitt 4.1) — hier bewusst zusammen mit dem Datenmodell in einer Datei,
 * weil die Superlist-Aufgabe beides als eine Unteraufgabe führt und beides
 * ohnehin nur ein paar Dutzend Zeilen sind.
 *
 * Schreibzugriffe sperren config/users.json exklusiv über die gesamte
 * Lese-Ändern-Schreiben-Folge (nicht nur beim Schreiben selbst wie
 * save_config() in admin.php) — Spec 4.1 verlangt das ausdrücklich
 * ("Lockfile-Schutz gegen parallele Schreibvorgänge, mehrere Admin-Tabs
 * offen"). Anders als bei den Einstellungen in config/config.php ginge es
 * hier nicht nur um ein verlorenes Feld, sondern um einen ganzen verlorenen
 * Listeneintrag (z. B. zwei gleichzeitige "Nutzer anlegen"-Aufrufe).
 *
 * HTTP-Zugriff auf diesen Ordner ist per lib/.htaccess gesperrt, die Datei
 * config/users.json zusätzlich über das bereits bestehende, den ganzen
 * config/-Ordner rekursiv sperrende config/.htaccess (kein neuer Eintrag
 * nötig, analog config/ratelimit/ und config/reset/).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Rollen-Rechte ──────────────────────────────────────────────────────────
//
// Die Tabelle selbst hängt an Role (lib/enums.php). Die Rolle kommt hier
// aber als roher String aus users.json, deshalb dieser Wrapper statt
// Role::from() an jedem Aufrufer.

/** Ob eine Rolle ein bestimmtes Recht hat. Unbekannte Rolle → false (fail-closed). */
function cms_role_can(string $role, string $permission): bool {
    return Role::tryFrom($role)?->can($permission) ?? false;
}

/** Bekannte Rollen, z. B. für das Auswahlfeld beim Anlegen im Tab "Benutzer". */
function cms_known_roles(): array {
    return array_column(Role::cases(), 'value');
}

// ─── Datei-Zugriff ──────────────────────────────────────────────────────────

// Überschreibbar per !defined()-Guard, analog UPLOAD_DIR in lib/media.php —
// admin.php/mcp.php brauchen das nicht, Testskripte gegen ein
// Wegwerf-Verzeichnis schon (sonst liefe jeder Test gegen die echte
// config/users.json).
if (!defined('CMS_USERS_FILE')) {
    define('CMS_USERS_FILE', dirname(__DIR__) . '/config/users.json');
}

function cms_users_file(): string {
    return CMS_USERS_FILE;
}

// ─── Passwort-Hashing ───────────────────────────────────────────────────────

// Bcrypt-Kostenfaktor für ALLE Passwort-Hashes dieser Installation — echte
// Nutzer-Passwörter wie auch den Dummy-Hash, gegen den admin.php bei
// unbekannter E-Mail prüft, damit ein Login-Versuch gleich lange dauert.
//
// Bewusst eine eigene Konstante statt PASSWORD_DEFAULT: dessen Wert hängt an
// der PHP-Version des Hosters (bis 8.3 Cost 10, ab 8.4 Cost 12). Echte Hashes
// und Dummy liefen dadurch auseinander, sobald der Hoster PHP aktualisiert —
// und genau diese Differenz ist auf Zeitebene messbar. Gemessen auf PHP 8.5:
// password_verify() gegen einen Cost-10-Hash 62 ms, gegen Cost 12 253 ms. Wer
// den Unterschied misst, weiß, ob eine E-Mail-Adresse existiert, obwohl die
// Fehlermeldung identisch bleibt (Spec 6.4, Enumeration-Schutz).
//
// Erhöhen ist unkritisch: password_verify() liest den Kostenfaktor aus dem
// jeweils gespeicherten Hash, bestehende Passwörter funktionieren also
// weiter und werden beim nächsten Setzen mit dem neuen Wert geschrieben.
// !defined()-Guard wie bei CMS_USERS_FILE, damit ein Testskript mit einem
// kleineren Wert schneller laufen kann.
if (!defined('CMS_BCRYPT_COST')) {
    define('CMS_BCRYPT_COST', 12);
}

/**
 * Einheitliches Passwort-Hashing für alle Einstiegspunkte. Einziger Ort, an
 * dem password_hash() für ein Nutzer-Passwort aufgerufen wird — sonst wäre
 * der Kostenfaktor wieder an drei Stellen einzeln zu pflegen.
 */
function cms_hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => CMS_BCRYPT_COST]);
}

/**
 * Bcrypt-Hash ohne zugehöriges Klartext-Passwort, mit demselben
 * Kostenfaktor wie echte Hashes. admin.php prüft dagegen, wenn die
 * eingegebene E-Mail unbekannt oder das Konto ohne Passwort ist — nur so
 * dauert ein Login-Versuch in beiden Fällen gleich lang.
 *
 * Aus der Konstante zusammengesetzt statt als fertiger String hinterlegt,
 * damit Dummy und echte Hashes nicht wieder auseinanderlaufen können. Der
 * Rumpf sind die 53 Zeichen, die bcrypt hinter '$2y$NN$' erwartet
 * (22 Salt + 31 Hash) — mit 54 erkennt password_get_info() das Format nicht
 * einmal als bcrypt.
 */
function cms_dummy_password_hash(): string {
    return sprintf('$2y$%02d$', CMS_BCRYPT_COST)
        . 'abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
}

/** Einheitliches Fehlerformat, analog cms_media_error() in lib/media.php. */
function cms_users_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

/**
 * Öffnet config/users.json exklusiv gesperrt zum Lesen und Schreiben in
 * einer Folge. 'c+' legt die Datei bei Bedarf leer an, ohne einen
 * bestehenden Inhalt zu verwerfen. Rückgabe false, wenn weder Verzeichnis
 * noch Datei beschreibbar sind — der Aufrufer bricht dann kontrolliert ab.
 *
 * @return resource|false
 */
function cms_users_open_locked() {
    $file = cms_users_file();
    $dir  = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

/** @param resource $handle */
function cms_users_read_locked($handle): array {
    rewind($handle);
    $raw  = stream_get_contents($handle);
    $data = json_decode((string)$raw, true);
    return is_array($data['users'] ?? null) ? $data['users'] : [];
}

/** @param resource $handle */
function cms_users_write_locked($handle, array $users): bool {
    $json = json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false; // nichts geschrieben, der Lesecache bleibt gültig
    }
    rewind($handle);
    if (!ftruncate($handle, 0)) {
        return false; // dito
    }
    $written = fwrite($handle, $json);
    fflush($handle);

    // Jeder Schreibweg dieser Datei läuft hier durch (Anlegen, Sperren,
    // Löschen, Passwort, E-Mail, MCP-Token, Login-Zeitstempel, Migration,
    // Erstinstallation) — deshalb reicht es, den Lesecache genau hier zu
    // verwerfen, statt in jeder einzelnen Schreibfunktion daran zu denken.
    // Auch bei fehlgeschlagenem fwrite(): ftruncate() ist dann schon durch,
    // die Datei also leer, und der Cache wäre weder der alte noch der neue
    // Stand.
    cms_users_cache_clear();

    return $written !== false;
}

/** @param resource $handle */
function cms_users_close_locked($handle): void {
    flock($handle, LOCK_UN);
    fclose($handle);
}

// ─── Lesen ──────────────────────────────────────────────────────────────────

/**
 * Prozess-Cache für die Nutzerliste.
 *
 * Eigene Funktion statt eines static in cms_load_users(), weil ein static
 * dort von cms_users_write_locked() aus nicht erreichbar wäre — und genau
 * das braucht es: nach jedem Schreiben muss der Cache weg, sonst arbeitet
 * der Rest des Requests mit dem Stand von vorher. Gleiches Muster wie
 * cms_config_cache() in lib/config.php.
 *
 * null heißt "nichts gecacht", ein leeres Array ist ein gültiger gecachter
 * Wert (Instanz ohne Nutzer, vor der Migration) — die beiden Fälle dürfen
 * nicht zusammenfallen, sonst läge bei leerer Datei bei jedem Aufruf wieder
 * ein Dateizugriff an.
 *
 * @param array|null $set   Wert ablegen; null heißt "nur lesen"
 * @param bool       $clear Cache verwerfen (hat Vorrang vor $set)
 * @return list<array>|null Die gecachte Liste, oder null wenn nichts gecacht ist
 */
function cms_users_cache(?array $set = null, bool $clear = false): ?array {
    static $cache = null;
    if ($clear) {
        $cache = null;
    } elseif ($set !== null) {
        $cache = $set;
    }
    return $cache;
}

/** Verwirft den Nutzer-Lesecache. Nach jedem Schreibzugriff auf users.json aufrufen. */
function cms_users_cache_clear(): void {
    cms_users_cache(null, true);
}

/**
 * Alle Nutzer, ungefiltert (inkl. password_hash/mcp_token_hash — ein
 * Aufrufer, der sie anzeigt, z. B. der Tab "Benutzer", blendet sie selbst
 * aus). Kein Lock: ein gelegentlich leicht veralteter Lesezugriff ist hier
 * unkritisch, genau wie bei den Rate-Limit-Dateien in lib/security.php.
 *
 * Das Ergebnis wird pro Request memoisiert, weil sonst jeder Aufruf ein
 * file_get_contents() plus json_decode() kostet — ein Aufruf von
 * ?action=users kam damit allein auf sechs Lesevorgänge derselben Datei.
 * Die Memoisierung ist die Fortsetzung derselben Abwägung, die oben schon
 * gegen das Lock spricht: innerhalb eines Requests darf die Liste leicht
 * veraltet sein. Jeder Schreibzugriff dieses Prozesses verwirft den Cache
 * (cms_users_write_locked()).
 *
 * @return list<array>
 */
function cms_load_users(): array {
    $cached = cms_users_cache();
    if ($cached !== null) {
        return $cached;
    }
    $file = cms_users_file();
    if (!is_file($file)) {
        // Bewusst nicht cachen: die Datei entsteht direkt danach durch
        // cms_migrate_users() bzw. die Erstinstallation, und deren
        // Schreibweg räumt zwar den Cache — aber nur, wenn überhaupt
        // geschrieben wurde. Ein gecachtes [] bei fehlender Datei würde den
        // Rest des Requests auf "keine Nutzer" festnageln.
        return [];
    }
    $data  = json_decode((string)@file_get_contents($file), true);
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    cms_users_cache($users);
    return $users;
}

/** E-Mail-Vergleich case-insensitive (übliche Login-Konvention). */
function cms_find_user_by_email(string $email): ?array {
    $needle = strtolower(trim($email));
    if ($needle === '') {
        return null;
    }
    foreach (cms_load_users() as $user) {
        if (strtolower((string)($user['email'] ?? '')) === $needle) {
            return $user;
        }
    }
    return null;
}

function cms_find_user_by_id(string $id): ?array {
    foreach (cms_load_users() as $user) {
        if (($user['id'] ?? '') === $id) {
            return $user;
        }
    }
    return null;
}

/**
 * Nutzer-Lookup über den persönlichen MCP-Connector-Token (Spec 4:
 * mcp.php löst den Token aus der URL gegen users.json auf). hash_equals pro
 * Kandidat, analog dem bisherigen einzelnen ai_connector_token-Vergleich.
 */
function cms_find_user_by_mcp_token(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    foreach (cms_load_users() as $user) {
        if (!empty($user['mcp_token_hash']) && hash_equals((string)$user['mcp_token_hash'], $hash)) {
            return $user;
        }
    }
    return null;
}

/** Anzahl Accounts mit role=admin, unabhängig vom Status (Spec 5.2/6.5: "der letzte Account mit role=admin", nicht nur die aktiven). */
function cms_count_admins(): int {
    return count(array_filter(cms_load_users(), static fn(array $u): bool => ($u['role'] ?? '') === Role::Admin->value));
}

// ─── Token-Erzeugung ────────────────────────────────────────────────────────

/**
 * Neuer Zufalls-Token nach demselben Muster wie der bisherige
 * ai_connector_token: 32 Byte Zufall als Hex, gespeichert wird nur der
 * SHA-256-Hash. Wiederverwendbar für den MCP-Token jedes Nutzers.
 *
 * @return array{token: string, hash: string}
 */
function cms_generate_user_token(): array {
    $token = bin2hex(random_bytes(32));
    return ['token' => $token, 'hash' => hash('sha256', $token)];
}

// ─── Schreiben ──────────────────────────────────────────────────────────────

/**
 * Legt einen neuen Nutzer an (Spec 5.2): nur E-Mail + Rolle, kein Passwort —
 * das setzt der Nutzer selbst über die Einladungs-Mail. Der MCP-Token
 * wird sofort erzeugt, damit der Tab
 * "Benutzer" den Connector-Link direkt zeigen kann, statt einen Nutzer ohne
 * nutzbaren MCP-Zugang zurückzulassen.
 *
 * @return array{ok: true, user: array, mcp_token: string}|array{ok: false, code: string, error: string}
 */
function cms_create_user(string $email, string $role): array {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return cms_users_error('invalid_email', 'Ungültige E-Mail-Adresse.');
    }
    if (Role::tryFrom($role) === null) {
        return cms_users_error('invalid_role', 'Unbekannte Rolle.');
    }

    $handle = cms_users_open_locked();
    if ($handle === false) {
        return cms_users_error('write_failed', 'Nutzerverwaltung ist aktuell nicht beschreibbar.');
    }
    $users = cms_users_read_locked($handle);

    foreach ($users as $existing) {
        if (strtolower((string)($existing['email'] ?? '')) === strtolower($email)) {
            cms_users_close_locked($handle);
            return cms_users_error('email_exists', 'Diese E-Mail-Adresse ist bereits vergeben.');
        }
    }

    $token_pair = cms_generate_user_token();
    $user = [
        'id'             => 'usr_' . bin2hex(random_bytes(16)),
        'email'          => $email,
        'password_hash'  => null, // wird erst über die Einladungs-Mail gesetzt
        'role'           => $role,
        'status'         => UserStatus::Aktiv->value,
        'mcp_token_hash' => $token_pair['hash'],
        'created_at'     => date('c'),
        'last_login_at'  => null,
    ];
    $users[] = $user;

    if (!cms_users_write_locked($handle, $users)) {
        cms_users_close_locked($handle);
        return cms_users_error('write_failed', 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.');
    }
    cms_users_close_locked($handle);

    return ['ok' => true, 'user' => $user, 'mcp_token' => $token_pair['token']];
}

/**
 * Sperrt/entsperrt einen Nutzer. Weigert sich, den letzten verbleibenden
 * Admin-Account zu sperren (Spec 5.2/6.5) — die Prüfung läuft *innerhalb*
 * desselben Locks wie das Schreiben, damit zwei gleichzeitige Sperr-Versuche
 * auf zwei verschiedene Admin-Accounts sich nicht gegenseitig aushebeln
 * können. Verteidigung auch auf dieser Ebene, nicht nur über die im Tab
 * "Benutzer" deaktivierten Buttons (Spec 6.2).
 */
function cms_set_user_status(string $id, string $status): array {
    $new_status = UserStatus::tryFrom($status);
    if ($new_status === null) {
        return cms_users_error('invalid_status', 'Ungültiger Status.');
    }

    $handle = cms_users_open_locked();
    if ($handle === false) {
        return cms_users_error('write_failed', 'Nutzerverwaltung ist aktuell nicht beschreibbar.');
    }
    $users = cms_users_read_locked($handle);

    $target = null;
    foreach ($users as &$user) {
        if (($user['id'] ?? '') === $id) {
            $target = &$user;
            break;
        }
    }
    unset($user);

    if ($target === null) {
        cms_users_close_locked($handle);
        return cms_users_error('not_found', 'Nutzer nicht gefunden.');
    }

    if ($new_status === UserStatus::Gesperrt && ($target['role'] ?? '') === Role::Admin->value) {
        $admin_count = count(array_filter($users, static fn(array $u): bool => ($u['role'] ?? '') === Role::Admin->value));
        if ($admin_count <= 1) {
            cms_users_close_locked($handle);
            return cms_users_error('last_admin', 'Der letzte verbleibende Admin-Account kann nicht gesperrt werden.');
        }
    }

    $target['status'] = $status;
    $ok = cms_users_write_locked($handle, $users);
    cms_users_close_locked($handle);
    return $ok ? ['ok' => true] : cms_users_error('write_failed', 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.');
}

/**
 * Löscht einen Nutzer unwiderruflich. Gleicher Aussperr-Schutz und gleiches
 * Lock-Prinzip wie cms_set_user_status().
 */
function cms_delete_user(string $id): array {
    $handle = cms_users_open_locked();
    if ($handle === false) {
        return cms_users_error('write_failed', 'Nutzerverwaltung ist aktuell nicht beschreibbar.');
    }
    $users = cms_users_read_locked($handle);

    $target = null;
    foreach ($users as $user) {
        if (($user['id'] ?? '') === $id) {
            $target = $user;
            break;
        }
    }

    if ($target === null) {
        cms_users_close_locked($handle);
        return cms_users_error('not_found', 'Nutzer nicht gefunden.');
    }

    if (($target['role'] ?? '') === Role::Admin->value) {
        $admin_count = count(array_filter($users, static fn(array $u): bool => ($u['role'] ?? '') === Role::Admin->value));
        if ($admin_count <= 1) {
            cms_users_close_locked($handle);
            return cms_users_error('last_admin', 'Der letzte verbleibende Admin-Account kann nicht gelöscht werden.');
        }
    }

    $remaining = array_values(array_filter($users, static fn(array $u): bool => ($u['id'] ?? '') !== $id));
    $ok = cms_users_write_locked($handle, $remaining);
    cms_users_close_locked($handle);
    return $ok ? ['ok' => true] : cms_users_error('write_failed', 'Löschen ist gerade nicht möglich. Bitte versuch es erneut.');
}

/**
 * Ändert die E-Mail-Adresse eines Nutzers (Spec 5.3). CSRF- und
 * Passwort-Bestätigung sind Sache des Aufrufers in admin.php — hier wird nur
 * Format und Eindeutigkeit geprüft, innerhalb desselben Locks wie das
 * Schreiben (sonst könnten zwei gleichzeitige Änderungen auf dieselbe neue
 * Adresse beide die Eindeutigkeitsprüfung passieren).
 */
function cms_update_user_email(string $id, string $email): array {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return cms_users_error('invalid_email', 'Ungültige E-Mail-Adresse.');
    }

    $handle = cms_users_open_locked();
    if ($handle === false) {
        return cms_users_error('write_failed', 'Nutzerverwaltung ist aktuell nicht beschreibbar.');
    }
    $users = cms_users_read_locked($handle);

    $found = false;
    foreach ($users as $user) {
        if (($user['id'] ?? '') === $id) {
            $found = true;
            continue;
        }
        if (strtolower((string)($user['email'] ?? '')) === strtolower($email)) {
            cms_users_close_locked($handle);
            return cms_users_error('email_exists', 'Diese E-Mail-Adresse ist bereits vergeben.');
        }
    }

    if (!$found) {
        cms_users_close_locked($handle);
        return cms_users_error('not_found', 'Nutzer nicht gefunden.');
    }

    foreach ($users as &$user) {
        if (($user['id'] ?? '') === $id) {
            $user['email'] = $email;
            break;
        }
    }
    unset($user);

    $ok = cms_users_write_locked($handle, $users);
    cms_users_close_locked($handle);
    return $ok ? ['ok' => true] : cms_users_error('write_failed', 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.');
}

/**
 * Interner Helfer: überschreibt beliebige Felder eines Nutzers per id, ohne
 * Cross-Record-Prüfung. Für Sperren/Löschen und die E-Mail-Änderung reicht
 * das nicht (siehe dort) — die sperren deshalb selbst statt hierüber zu
 * laufen. Für Passwort, MCP-Token und den Login-Zeitstempel genügt es.
 */
function cms_update_user_fields(string $id, array $fields): bool {
    $handle = cms_users_open_locked();
    if ($handle === false) {
        return false;
    }
    $users = cms_users_read_locked($handle);

    $found = false;
    foreach ($users as &$user) {
        if (($user['id'] ?? '') === $id) {
            $user  = array_merge($user, $fields);
            $found = true;
            break;
        }
    }
    unset($user);

    if (!$found) {
        cms_users_close_locked($handle);
        return false;
    }

    $ok = cms_users_write_locked($handle, $users);
    cms_users_close_locked($handle);
    return $ok;
}

function cms_set_user_password(string $id, string $password_hash): bool {
    return cms_update_user_fields($id, ['password_hash' => $password_hash]);
}

/** Erzeugt einen neuen MCP-Token und speichert nur dessen Hash. Rückgabe: der Klartext-Token für den Connector-Link, oder null bei Schreibfehler/unbekannter id. */
function cms_regenerate_user_mcp_token(string $id): ?string {
    $pair = cms_generate_user_token();
    return cms_update_user_fields($id, ['mcp_token_hash' => $pair['hash']]) ? $pair['token'] : null;
}

function cms_record_user_login(string $id): bool {
    return cms_update_user_fields($id, ['last_login_at' => date('c')]);
}

// ─── Migration ──────────────────────────────────────────────────────────────

/**
 * Einmalige Migration (Spec 4.2): Legt config/users.json aus dem bisherigen
 * Single-Admin-Modell an, falls sie noch nicht existiert. Idempotent — ein
 * zweiter Aufruf tut nichts, sobald die Datei einmal Nutzer enthält.
 *
 * Der migrierte Admin übernimmt seinen bisherigen ai_connector_token 1:1 als
 * persönlichen MCP-Token (mit Raphael abgestimmt, 2026-09-18) — sonst würde
 * der bereits eingerichtete Claude-Connector beim Deploy dieses Features
 * ohne Vorwarnung ungültig, bis die Connector-URL in Claude.ai neu gesetzt
 * wird. Genau wie beim Passwort-Hash (kein Zwangs-Reset) ist das die
 * Fortsetzung des bestehenden Zugangs, keine Neuvergabe.
 *
 * Fehlt smtp_from_email (in der Spec als Regelfall vorausgesetzt, hier
 * defensiv trotzdem behandelt statt die Migration abzubrechen), bekommt der
 * Account eine Platzhalter-Adresse aus der Site-Domain — nicht Teil der Spec,
 * aber besser als ein Login, der mangels bekannter E-Mail gar nicht
 * funktioniert. Der Admin merkt das beim ersten Login-Versuch und muss die
 * Adresse dann selbst korrigieren (Profilbereich, spätere Unteraufgabe).
 *
 * @param array $cfg Die geladene config/config.php.
 */
function cms_migrate_users(array $cfg): bool {
    if (is_file(cms_users_file())) {
        return true; // Schnellpfad: Migration ist bereits gelaufen.
    }

    $handle = cms_users_open_locked();
    if ($handle === false) {
        return false;
    }
    // Zwischen dem is_file()-Check oben und dem Lock hier könnte ein
    // paralleler Request bereits migriert haben — nochmal prüfen, bevor
    // geschrieben wird, sonst entstünde ein zweiter Admin-Account.
    if (cms_users_read_locked($handle) !== []) {
        cms_users_close_locked($handle);
        return true;
    }

    $password_hash = (string)($cfg['admin_password_hash'] ?? '');

    $email = trim((string)($cfg['smtp_from_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $domain = parse_url((string)($cfg['site_domain'] ?? ''), PHP_URL_HOST);
        $email  = 'admin@' . ($domain !== null && $domain !== '' ? $domain : 'localhost');
    }

    $legacy_token = trim((string)($cfg['ai_connector_token'] ?? ''));

    $user = [
        'id'             => 'usr_' . bin2hex(random_bytes(16)),
        'email'          => $email,
        'password_hash'  => $password_hash !== '' ? $password_hash : null,
        'role'           => Role::Admin->value,
        'status'         => UserStatus::Aktiv->value,
        'mcp_token_hash' => $legacy_token !== '' ? hash('sha256', $legacy_token) : null,
        'created_at'     => date('c'),
        'last_login_at'  => null,
    ];

    $ok = cms_users_write_locked($handle, [$user]);
    cms_users_close_locked($handle);
    return $ok;
}

// ─── Erstinstallation ───────────────────────────────────────────────────────

/**
 * Legt den allerersten Admin-Account für eine per install.php eingerichtete
 * Instanz an (Spec 5.4). Analog cms_create_user() (Zeile ~236), aber mit
 * direkt gesetztem Passwort statt password_hash: null + Einladungs-Mail-Fluss
 * — für den allerersten Admin gibt es noch kein SMTP, keine bestehende
 * Instanz, die eine Mail verschicken könnte. Deshalb ein eigener, expliziter
 * Weg statt eine Sonderfall-Abzweigung in cms_create_user().
 *
 * Läuft in install.php STATT cms_migrate_users(), nicht zusätzlich dazu —
 * cms_migrate_users() bleibt über ihren eigenen Schnellpfad ("Datei existiert
 * bereits") inaktiv, sobald diese Funktion einmal erfolgreich geschrieben
 * hat.
 *
 * Gleiches Double-Checked-Locking-Muster wie cms_migrate_users(): install.php
 * schützt config/config.php bereits per Self-Lock/cms_install_write_config()
 * gegen einen zweiten parallelen Installationsversuch, aber config/users.json
 * ist eine eigene Datei mit eigenem Lock — dieselbe Prüfung "ist die Datei
 * inzwischen doch nicht mehr leer?" innerhalb desselben Locks verhindert
 * auch hier zuverlässig einen zweiten Admin-Account, statt sich allein auf
 * den Config-Lock zu verlassen.
 *
 * @return array{ok?: bool, user?: array, code?: string, error?: string}
 */
function cms_create_initial_admin(string $email, string $password): array {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return cms_users_error('invalid_email', 'Ungültige E-Mail-Adresse.');
    }
    if (strlen($password) < 12) {
        // Gleicher Text wie die Formular-Validierung in install.php — diese
        // Funktion validiert defensiv mit, statt sich blind auf den Aufrufer
        // zu verlassen (gleiches Prinzip wie die E-Mail-Prüfung in
        // cms_create_user()).
        return cms_users_error('invalid_password', 'Das Admin-Passwort muss mindestens 12 Zeichen lang sein.');
    }

    $handle = cms_users_open_locked();
    if ($handle === false) {
        return cms_users_error('write_failed', 'Nutzerverwaltung ist aktuell nicht beschreibbar.');
    }
    // Analog cms_migrate_users(): zwischen einem vorherigen Check und diesem
    // Lock könnte bereits ein Admin-Account angelegt worden sein — dann
    // nicht überschreiben, sondern abbrechen (kein zweiter Admin-Account).
    if (cms_users_read_locked($handle) !== []) {
        cms_users_close_locked($handle);
        return cms_users_error('already_installed', 'Es existiert bereits ein Benutzer-Konto.');
    }

    $user = [
        'id'             => 'usr_' . bin2hex(random_bytes(16)),
        'email'          => $email,
        'password_hash'  => cms_hash_password($password),
        'role'           => Role::Admin->value,
        'status'         => UserStatus::Aktiv->value,
        // Leer, nicht sofort erzeugt (Spec 5.4) — wie bei jedem neuen Nutzer
        // ohne Connector entsteht der MCP-Token erst über den Tab
        // "KI-Anbindung" nach dem ersten Login.
        'mcp_token_hash' => null,
        'created_at'     => date('c'),
        'last_login_at'  => null,
    ];

    if (!cms_users_write_locked($handle, [$user])) {
        cms_users_close_locked($handle);
        return cms_users_error('write_failed', 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.');
    }
    cms_users_close_locked($handle);

    return ['ok' => true, 'user' => $user];
}
