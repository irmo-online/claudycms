<?php
/**
 * End-to-End-Tests für den Entwurfs-Flow und "Passwort vergessen" in admin.php
 *
 * Aufruf:  php tests/admin.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Deckt den Weg ab, den die KI-Anbindung braucht: mcp.php legt eine Seite als
 * Entwurf ab, im Admin-Backend wird sie freigegeben — und lässt sich wieder
 * zurückziehen. Geprüft wird über echte HTTP-Requests inklusive Login,
 * Session-Cookie und CSRF-Token; anders ist ein Admin-Flow nicht prüfbar.
 *
 * Dazu "Passwort vergessen": Anfrage-Formular, Enumeration-Schutz,
 * Rate-Limit, Reset-Formular mit Auto-Login. Die Test-Konfiguration zeigt mit
 * smtp_host auf einen Port ohne Listener — send_user_email() schlägt darüber
 * absichtlich und schnell fehl (Connection refused statt PHPMailer-Timeout).
 * Ein echter Mailversand lässt sich ohne Postfach im Test ohnehin nicht
 * prüfen, gleiche Grenze wie beim Kontaktformular in index.php. Genau dieser
 * Fehlschlag deckt aber den Fail-Closed-Pfad ab und beweist nebenbei, dass eine scheiternde
 * Bestätigungsmail den bereits erfolgreichen Reset nicht rückgängig macht.
 * Für den erfolgreichen Reset-Schritt selbst wird der Token direkt in
 * config/reset/<user_id>.json geschrieben (write_user_token()) statt über die
 * Anfrage-Route erzeugt — sonst käme man wegen des Fail-Closed-Verhaltens nie
 * an einen gültigen Token. Seit Einführung der Benutzerverwaltung pro
 * Nutzer statt einer einzelnen globalen Datei — bedient
 * denselben Mechanismus auch für
 * Einladungen an neue Nutzer (Tab "Benutzer").
 *
 * Gleiches Wegwerf-Webroot-Verfahren wie tests/api.php und tests/mcp.php: Kopie
 * von admin.php, index.php und lib/ in sys_get_temp_dir(), eigenes content/ und
 * eine eigene config/config.php mit bekanntem Passwort. Das echte content/ und
 * die echte Konfiguration werden nicht angefasst.
 *
 * Diese Datei wird nicht deployed — siehe exclude-Liste in
 * .github/workflows/deploy.yml.
 */

$repo_root = dirname(__DIR__);
$tmp_root  = sys_get_temp_dir() . '/cms-admin-test-' . getmypid();
$password  = 'test-passwort-' . bin2hex(random_bytes(8));

// 1x1-Pixel-PNG als Bild-Fixture für die Logo-/Favicon-Tests — dieselben
// Bytes wie in tests/mcp.php, reicht als
// echter Bildinhalt, weil getimagesize() nur den Header liest.
$png_bytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
);

// ─── Mini-Testhelfer (gleiche Form wie tests/roundtrip.php) ──────────────────

$tests_run    = 0;
$tests_failed = 0;

function group(string $name): void {
    echo "\n" . $name . "\n";
}

function ok(bool $condition, string $what): void {
    global $tests_run, $tests_failed;
    $tests_run++;
    if ($condition) {
        echo "  ok    " . $what . "\n";
    } else {
        $tests_failed++;
        echo "  FEHL  " . $what . "\n";
    }
}

function is_same($actual, $expected, string $what): void {
    global $tests_run, $tests_failed;
    $tests_run++;
    if ($actual === $expected) {
        echo "  ok    " . $what . "\n";
    } else {
        $tests_failed++;
        echo "  FEHL  " . $what . "\n";
        echo "        erwartet: " . var_export($expected, true) . "\n";
        echo "        bekommen: " . var_export($actual, true) . "\n";
    }
}

/** Räumt ein Verzeichnis rekursiv ab. */
function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// ─── Wegwerf-Webroot aufbauen ───────────────────────────────────────────────

/** Schreibt eine Datei und legt fehlende Ordner an. */
function put(string $path, string $contents): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $contents);
}

/**
 * admin.php und index.php müssen kopiert werden, nicht verlinkt: __DIR__ löst
 * Symlinks auf, die Dateien würden sonst content/ und config/ des echten Repos
 * benutzen — ein Test würde in den Live-Inhalten schreiben.
 */
function build_webroot(string $repo_root, string $tmp_root, string $password, int $dead_smtp_port): void {
    cleanup($tmp_root);
    mkdir($tmp_root . '/lib', 0777, true);
    mkdir($tmp_root . '/content', 0777, true);

    copy($repo_root . '/admin.php', $tmp_root . '/admin.php');
    copy($repo_root . '/index.php', $tmp_root . '/index.php');
    copy($repo_root . '/lib/security.php', $tmp_root . '/lib/security.php');
    copy($repo_root . '/lib/content.php', $tmp_root . '/lib/content.php');
    copy($repo_root . '/lib/media.php', $tmp_root . '/lib/media.php');
    copy($repo_root . '/lib/styles.php', $tmp_root . '/lib/styles.php');
    // Nutzerverwaltung — admin.php bindet lib/users.php seither
    // zwingend ein und migriert beim ersten Request nach users.json.
    copy($repo_root . '/lib/users.php', $tmp_root . '/lib/users.php');
    // 301-Redirect-Verwaltung — admin.php und index.php binden
    // lib/redirects.php seither zwingend ein. Dritter Fall desselben
    // Musters (nach lib/media.php, lib/styles.php): ohne die Datei im
    // Wegwerf-Webroot bricht das kopierte admin.php/index.php mit Fatal
    // Error ab.
    copy($repo_root . '/lib/redirects.php', $tmp_root . '/lib/redirects.php');
    // save_config() — aus admin.php nach lib/config.php verschoben, geteilt
    // mit install.php. Vierter Fall desselben Musters: ohne die Datei im
    // Wegwerf-Webroot bricht das kopierte admin.php mit Fatal Error ab.
    copy($repo_root . '/lib/config.php', $tmp_root . '/lib/config.php');
    // Logo/Favicon-Verwaltung — admin.php bindet lib/branding.php seither
    // zwingend ein. Sechster Fall desselben Musters: ohne die Datei im
    // Wegwerf-Webroot bricht das kopierte admin.php mit Fatal Error ab.
    copy($repo_root . '/lib/branding.php', $tmp_root . '/lib/branding.php');
    // Kategorienliste — admin.php bindet lib/categories.php zwingend ein.
    copy($repo_root . '/lib/categories.php', $tmp_root . '/lib/categories.php');
    // Erweiterungs-Haken — index.php bindet lib/extensions.php zwingend ein.
    copy($repo_root . '/lib/extensions.php', $tmp_root . '/lib/extensions.php');
    // Handler-Helfer (guard(), post_*(), cfg_update()) — admin.php bindet
    // lib/admin/helpers.php zwingend ein. Achter Fall desselben Musters.
    mkdir($tmp_root . '/lib/admin', 0777, true);
    // Seither auch HandlerResult und die thematisch gruppierten POST-Handler,
    // deshalb das ganze Verzeichnis statt Datei für Datei.
    foreach (glob($repo_root . '/lib/admin/*.php') as $admin_file) {
        copy($admin_file, $tmp_root . '/lib/admin/' . basename($admin_file));
    }
    // Templates: Layouts, Views und Auth-Seiten. admin.php gibt ohne sie
    // keine einzige Seite aus, auch nicht das Login-Formular.
    foreach (['', '/admin', '/auth'] as $views_sub) {
        mkdir($tmp_root . '/lib/views' . $views_sub, 0777, true);
        foreach (glob($repo_root . '/lib/views' . $views_sub . '/*.php') as $view_file) {
            copy($view_file, $tmp_root . '/lib/views' . $views_sub . '/' . basename($view_file));
        }
    }
    // Enums für Rollen, Status und Einstellungs-Werte — admin.php bindet
    // lib/enums.php zwingend ein. Neunter Fall desselben Musters.
    copy($repo_root . '/lib/enums.php', $tmp_root . '/lib/enums.php');
    // index.php bindet lib/styles.php zwingend ein (cms_get_custom_css()
    // für den optionalen <link>-Tag) — ohne die Datei im Wegwerf-Webroot
    // bräche index.php mit Fatal Error ab, genau wie schon bei
    // lib/media.php. Die echte assets/style.css wird mitkopiert, weil
    // künftige Style-Tests hier ansetzen könnten.
    mkdir($tmp_root . '/assets', 0777, true);
    copy($repo_root . '/assets/style.css', $tmp_root . '/assets/style.css');
    // assets/admin.css: das Admin-Stylesheet liegt nicht als
    // Heredoc in admin.php, sondern als eigene Datei, die per <link> geladen
    // wird. Ohne sie im Wegwerf-Webroot liefe der Abruf des Stylesheets ins
    // Leere und die Auslieferungs-Prüfung unten könnte nichts belegen.
    copy($repo_root . '/assets/admin.css', $tmp_root . '/assets/admin.css');

    // PHPMailer wird für send_admin_email() im Passwort-vergessen-Flow gebraucht.
    mkdir($tmp_root . '/lib/phpmailer', 0777, true);
    foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
        copy($repo_root . '/lib/phpmailer/' . $file, $tmp_root . '/lib/phpmailer/' . $file);
    }

    put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export([
        'site_title'          => 'Testseite',
        'site_domain'         => '',
        'default_slug'        => 'home',
        'admin_password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'max_upload_size'     => 5242880,
        'max_login_attempts'  => 5,
        'login_lockout_time'  => 900,
        'contact_enabled'     => false,
        'ai_connector_token'      => '',
        'ai_publish_immediately'  => false,
        // Absichtlich unerreichbar (kein Listener) — siehe Dateikopf.
        'smtp_host'            => '127.0.0.1',
        'smtp_port'            => $dead_smtp_port,
        'smtp_secure'          => '',
        'smtp_user'            => 'user',
        'smtp_pass'            => 'pass',
        'smtp_from_email'      => 'admin@example.test',
        'smtp_from_name'       => 'Test Admin',
    ], true) . ";\n");

}

/** Legt die Testinhalte an: ein KI-Entwurf und eine veröffentlichte Seite. */
function build_fixtures(string $tmp_root): void {
    put($tmp_root . '/content/ki-entwurf.md',
        "---\ntitle: KI-Entwurf\nslug: ki-entwurf\ndraft: true\n"
        . "lastEditedBy: ai\nlastEditedAt: \"2026-09-06T22:21:31+02:00\"\n---\n\nVon der KI geschrieben.\n");
    put($tmp_root . '/content/home.md',
        "---\ntitle: Startseite\nslug: home\nnavLabel: Start\n---\n\nHallo.\n");

    // Eine Vorversion von home.md, als hätte die KI sie einmal geändert —
    // für den Undo-Test im Tab "KI-Anbindung". Der Dateiname ist die
    // Sortierordnung (cms_history_backup()), das Datum liegt bewusst in der
    // Vergangenheit, damit der Test nicht von der Uhrzeit des Testlaufs abhängt.
    put($tmp_root . '/content/.history/home/2026-01-01_090000.md',
        "---\ntitle: Startseite\nslug: home\nnavLabel: Start\n---\n\nAlte Version vor der KI-Änderung.\n");
}

// ─── HTTP-Server steuern ────────────────────────────────────────────────────

/** Einen freien Port vom Betriebssystem geben lassen. */
function free_port(): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        fwrite(STDERR, "Kein freier Port zu bekommen: {$errstr}\n");
        exit(1);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
}

$server_handle = null;

function start_server(string $docroot, int $port): void {
    global $server_handle;
    // opcache.enable=0: admin.php schreibt config/config.php (save_config())
    // und liest sie oft im selben oder im nächsten Request sofort wieder
    // (load_config()) — Opcache validiert per Default nur alle 2 Sekunden neu
    // (opcache.revalidate_freq), und dieser Test feuert Requests deutlich
    // schneller. Ohne den Schalter liest load_config() dann gelegentlich noch
    // den alten Stand, obwohl die Datei längst geschrieben ist — ein reines
    // Artefakt des schnellen automatisierten Tests, kein reales Nutzungsmuster
    // (ein Mensch klickt nie im Sub-Sekunden-Takt "Token neu generieren").
    $cmd = sprintf(
        '%s -d opcache.enable=0 -S 127.0.0.1:%d -t %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($docroot)
    );
    $server_handle = proc_open(
        $cmd,
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $docroot . '/server.log', 'a']],
        $pipes
    );
    if (!is_resource($server_handle)) {
        fwrite(STDERR, "php -S liess sich nicht starten.\n");
        exit(1);
    }

    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe) {
            fclose($probe);
            return;
        }
        usleep(50000);
    }
    fwrite(STDERR, "php -S auf Port {$port} war nach 5 Sekunden nicht erreichbar.\n");
    exit(1);
}

function stop_server(): void {
    global $server_handle;
    if (is_resource($server_handle)) {
        proc_terminate($server_handle);
        proc_close($server_handle);
        $server_handle = null;
    }
}

// ─── HTTP-Helfer mit Session-Cookie ─────────────────────────────────────────

$base_url = '';
$cookie   = '';   // PHPSESSID=…; wird aus Set-Cookie mitgeführt

/**
 * Ein Request gegen die Test-Instanz.
 *
 * Redirects werden bewusst NICHT verfolgt: admin.php antwortet nach dem Login
 * mit "Location: admin.php", und der PHP-HTTP-Wrapper würde dabei die Methode
 * mitschleppen. Der Folge-GET steht im Test selbst.
 *
 * @param bool $with_cookie false = ohne Session, für die Zugriffsschutz-Prüfung
 * @return array{status:int, body:string}
 */
function request(string $method, string $path, ?array $form = null, bool $with_cookie = true): array {
    global $base_url, $cookie;

    $headers = [];
    if ($with_cookie && $cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $body = null;
    if ($form !== null) {
        $body = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $context = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 10,
    ]]);

    $raw = @file_get_contents($base_url . $path, false, $context);
    $raw = $raw === false ? '' : $raw;

    $status = 0;
    $location = null;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
        // session_regenerate_id() beim Login schickt ein neues Cookie — ohne
        // das Mitführen wäre die Session nach dem Login sofort wieder weg.
        if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $line, $m)) {
            $cookie = $m[1];
        }
        // Location-Header für Redirect-Tests —
        // follow_location ist oben bewusst 0, sonst gäbe es hier nichts zu prüfen.
        if (preg_match('/^Location:\s*(.+)$/i', $line, $m)) {
            $location = trim($m[1]);
        }
    }

    // headers: für die CSP-Prüfungen, siehe header_value().
    return ['status' => $status, 'body' => $raw, 'location' => $location, 'headers' => $http_response_header ?? []];
}

/**
 * Multipart-POST für Datei-Uploads — request() oben baut nur
 * application/x-www-form-urlencoded, das reicht für Logo-/Favicon-Uploads
 * nicht. Baut den Multipart-Body von Hand, ohne PHP-Erweiterung wie cURL
 * (gleiches Vorgehen wie der frühere Wegwerf-Smoketest, hier fest im Repo).
 *
 * @param array<string,string> $fields Normale Formularfelder (inkl. csrf_token)
 * @param array<string,array{name:string,type:string,data:string}> $files Feldname => Datei
 * @return array{status:int, body:string}
 */
function upload_file(string $path, array $fields, array $files): array {
    global $base_url, $cookie;

    $boundary = '----TestBoundary' . bin2hex(random_bytes(8));
    $body     = '';
    foreach ($fields as $key => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
        $body .= $value . "\r\n";
    }
    foreach ($files as $field => $file) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$field}\"; filename=\"{$file['name']}\"\r\n";
        $body .= "Content-Type: {$file['type']}\r\n\r\n";
        $body .= $file['data'] . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $context = stream_context_create(['http' => [
        'method'          => 'POST',
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 10,
    ]]);

    $raw = @file_get_contents($base_url . $path, false, $context);
    $raw = $raw === false ? '' : $raw;

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => $raw];
}

/** Das CSRF-Token aus einer gerenderten Seite ziehen. */
/** Wert eines Antwort-Headers aus request(), oder null. Letzter gewinnt, wie im Browser. */
function header_value(array $response, string $name): ?string {
    $value = null;
    foreach ($response['headers'] ?? [] as $line) {
        if (stripos($line, $name . ':') === 0) {
            $value = trim(substr($line, strlen($name) + 1));
        }
    }
    return $value;
}

function csrf_from(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** Rohinhalt einer Content-Datei im Wegwerf-Webroot. */
function content_file(string $rel): string|false {
    global $tmp_root;
    $path = $tmp_root . '/content/' . $rel;
    // Der Server legt und löscht Dateien in einem anderen Prozess, der
    // Stat-Cache dieses Prozesses darf das Ergebnis nicht verfälschen.
    clearstatcache(true, $path);
    return is_file($path) ? (string) file_get_contents($path) : false;
}

/** Alle Nutzer aus der geschriebenen config/users.json. */
function load_test_users(string $tmp_root): array {
    $raw  = (string) @file_get_contents($tmp_root . '/config/users.json');
    $data = json_decode($raw, true);
    return is_array($data['users'] ?? null) ? $data['users'] : [];
}

/** Alle Redirects aus der geschriebenen config/redirects.json. */
function load_test_redirects(string $tmp_root): array {
    $raw  = (string) @file_get_contents($tmp_root . '/config/redirects.json');
    $data = json_decode($raw, true);
    return is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
}

/** Ein Nutzer nach E-Mail aus config/users.json, oder null. */
function find_test_user(string $tmp_root, string $email): ?array {
    foreach (load_test_users($tmp_root) as $user) {
        if (strtolower((string)($user['email'] ?? '')) === strtolower($email)) {
            return $user;
        }
    }
    return null;
}

/** Den password_hash eines Nutzers aus der geschriebenen config/users.json ziehen. */
function user_password_hash(string $tmp_root, string $email): string {
    return (string)(find_test_user($tmp_root, $email)['password_hash'] ?? '');
}

/**
 * Pfad der Einladungs-/Reset-Token-Datei eines Nutzers
 * — gleiches Verzeichnis wie RESET_TOKEN_FILE, aber pro Nutzer-id statt der
 * einen globalen token.json.
 */
function user_token_file(string $tmp_root, string $user_id): string {
    return $tmp_root . '/config/reset/' . $user_id . '.json';
}

/**
 * Legt einen Nutzer-Token direkt an, im gleichen Format wie
 * create_user_token() in admin.php — ohne den Mailversand zu durchlaufen
 * (analog write_reset_token() für den globalen Admin-Token).
 */
function write_user_token(string $tmp_root, string $user_id, int $expires_in = 3600): string {
    $token = bin2hex(random_bytes(32));
    put(user_token_file($tmp_root, $user_id), json_encode([
        'token_hash' => hash('sha256', $token),
        'expires_at' => time() + $expires_in,
    ]));
    return $token;
}

/**
 * Pfad der Konto-Lockout-Zählerdatei einer E-Mail —
 * gleiche Hash-Logik wie cms_rate_limit_file() in lib/security.php, mit der
 * account_rate_limit_file() in admin.php denselben Dateinamen bildet.
 */
function account_rate_limit_file(string $tmp_root, string $email): string {
    return $tmp_root . '/config/ratelimit/login_account_' . hash('sha256', strtolower(trim($email))) . '.json';
}

// ─── Testlauf ───────────────────────────────────────────────────────────────

register_shutdown_function('stop_server');
register_shutdown_function(static function () use (&$tmp_root) { cleanup($tmp_root); });

$dead_smtp_port = free_port(); // niemand hört hier zu — send_admin_email() muss daran scheitern, siehe Dateikopf

build_webroot($repo_root, $tmp_root, $password, $dead_smtp_port);
build_fixtures($tmp_root);

$port     = free_port();
$base_url = 'http://127.0.0.1:' . $port;
start_server($tmp_root, $port);

echo "admin.php-Tests gegen {$base_url}\n";

// ── Login ──

group('Login (E-Mail + Passwort statt nur Passwort)');

$r = request('GET', '/admin.php');
ok(str_contains($r['body'], 'admin_password'), 'ohne Session kommt das Login-Formular');
ok(str_contains($r['body'], 'name="admin_email"'), 'Login-Formular hat jetzt ein E-Mail-Feld');
$login_csrf = csrf_from($r['body']);
ok(!str_contains(header_value($r, 'Content-Security-Policy') ?? "'unsafe-inline'", "'unsafe-inline'"), "Login-Seite: CSP ohne 'unsafe-inline'");

// Admin-Stylesheet: liegt als
// eigene Datei und wird per <link> geladen. Geprüft wird die ganze Kette —
// Markup, erreichbare URL, richtiger Content-Type, echter Inhalt. Ein
// fehlender Whitelist-Eintrag oder ein falscher Pfad führte sonst zu einer
// unformatierten Oberfläche, die kein Test bemerkt: HTTP 200 liefert die
// Seite ja trotzdem. Schon auf der Login-Seite, nicht erst nach dem Login.
ok(!str_contains($r['body'], '--text-light:'), 'CSS steckt nicht mehr inline in der Seite');
ok(
    (bool)preg_match('#<link rel="stylesheet" href="(assets/admin\.css\?v=\d+)">#', $r['body'], $css_m),
    'Login-Seite bindet assets/admin.css per <link> mit Cache-Buster ein'
);
$css_res = request('GET', '/' . ($css_m[1] ?? 'assets/admin.css'), null, false);
is_same($css_res['status'], 200, 'das verlinkte Stylesheet ist unter genau dieser URL abrufbar');
ok(str_contains($css_res['body'], '.admin-sidebar'), 'ausgeliefertes Stylesheet enthält die Admin-Regeln');
is_same(
    $css_res['body'],
    file_get_contents($repo_root . '/assets/admin.css'),
    'ausgeliefertes Stylesheet ist byte-identisch mit assets/admin.css im Repo'
);

$r = request('POST', '/admin.php', ['admin_email' => 'admin@example.test', 'admin_password' => 'falsch', 'csrf_token' => $login_csrf]);
ok(str_contains($r['body'], 'E-Mail oder Passwort falsch'), 'falsches Passwort liefert die generische Meldung');

$r = request('POST', '/admin.php', ['admin_email' => 'unbekannt@example.test', 'admin_password' => $password, 'csrf_token' => $login_csrf]);
ok(str_contains($r['body'], 'E-Mail oder Passwort falsch'), 'unbekannte E-Mail liefert dieselbe generische Meldung (Enumeration-Schutz, Spec 6.4)');

// Der Enumeration-Schutz oben hält den Meldungstext gleich; gleich lang
// dauern muss der Versuch aber auch, sonst ist die Adresse an der Antwortzeit
// erkennbar. Das hängt allein daran, dass der Dummy-Hash denselben
// bcrypt-Kostenfaktor trägt wie echte Passwörter — über HTTP wäre das nur als
// Zeitmessung prüfbar und damit unzuverlässig, deshalb hier direkt an der
// Eigenschaft. Ein hart eingetragener Dummy mit abweichendem Cost (so war es
// früher) fällt damit sofort auf, statt still die Lücke offenzulassen.
// CMS_ENTRY ist der Guard, mit dem jede lib-Datei einen direkten
// HTTP-Aufruf abweist — im Testprozess muss er gesetzt sein, damit der
// require durchläuft. CMS_USERS_FILE zeigt bewusst ins Wegwerf-Webroot,
// obwohl hier nur gerechnet und keine Datei gelesen wird.
define('CMS_ENTRY', true);
define('CMS_USERS_FILE', $tmp_root . '/config/users.json');
require_once $tmp_root . '/lib/enums.php';
require_once $tmp_root . '/lib/users.php';

// Rollen-Rechte-Tabelle (Role in lib/enums.php) direkt, ohne HTTP-Umweg.
ok(cms_role_can('admin', 'users') && cms_role_can('admin', 'redirects'), 'admin hat users und redirects');
ok(cms_role_can('content_creator', 'content') && !cms_role_can('content_creator', 'settings'), 'content_creator hat content, aber nicht settings');
ok(!cms_role_can('superadmin', 'content') && !cms_role_can('', 'content'), 'unbekannte oder leere Rolle hat gar kein Recht (fail-closed)');
is_same(cms_known_roles(), ['admin', 'content_creator'], 'cms_known_roles() liefert genau die bestehenden Rollen-Strings');
is_same(ContentType::fromMeta([])->value, 'markdown', 'Seite ohne contentType gilt als Markdown');
is_same(ContentType::fromMeta(['contentType' => ''])->value, 'markdown', 'leerer contentType gilt als Markdown');
is_same(ContentType::fromMeta(['contentType' => 'html']), ContentType::Html, 'contentType html wird erkannt');

$dummy_info = password_get_info(cms_dummy_password_hash());
is_same($dummy_info['algoName'], 'bcrypt', 'Dummy-Hash ist ein gültiger bcrypt-Hash (richtige Länge)');
is_same(
    $dummy_info['options']['cost'] ?? null,
    CMS_BCRYPT_COST,
    'Dummy-Hash trägt denselben Kostenfaktor wie echte Passwörter — kein messbarer Zeitunterschied'
);
is_same(
    password_get_info(cms_hash_password('beliebig'))['options']['cost'] ?? null,
    CMS_BCRYPT_COST,
    'echte Passwörter werden mit genau diesem Kostenfaktor gehasht, nicht mit PASSWORD_DEFAULT'
);

$r = request('POST', '/admin.php', ['admin_email' => 'admin@example.test', 'admin_password' => 'egal', 'csrf_token' => 'falsches-token']);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Login ohne gültiges CSRF-Token schlägt fehl, auch mit richtigem Passwort probiert');

$r = request('POST', '/admin.php', ['admin_email' => 'admin@example.test', 'admin_password' => $password, 'csrf_token' => $login_csrf]);
is_same($r['status'], 302, 'richtige E-Mail + richtiges Passwort loggt ein (Redirect)');
$list = request('GET', '/admin.php')['body'];
ok(str_contains($list, 'Seiten (2)'), 'das Listing zeigt danach 2 Seiten');

// ── Konto-Lockout ──

group('Konto-Lockout');

// Erst ausloggen: eine bereits eingeloggte Session ignoriert einen
// Login-POST einfach (zeigt weiter das normale Backend, wie schon immer,
// $login_error bleibt ungenutzt) — die Sperre lässt sich nur "von außen"
// beobachten, also ohne aktive Session.
request('GET', '/admin.php?action=logout');
$r = request('GET', '/admin.php');
$login_csrf = csrf_from($r['body']); // logout regeneriert die Session, neues Token holen

// Eine ausgeschöpfte Sperre direkt simulieren (wie write_reset_token() beim
// Passwort-Reset), statt fünf echte Fehlversuche zu schicken — sonst würde
// das IP-Lockout (an dieselben Grenzwerte gekoppelt, Spec 8/6.3 v0.3)
// gleichzeitig greifen und den account-spezifischen Pfad verdecken.
$account_lock_file = account_rate_limit_file($tmp_root, 'admin@example.test');
put($account_lock_file, json_encode(['attempts' => 5, 'lockout_until' => time() + 900]));

$r = request('POST', '/admin.php', [
    'admin_email'    => 'admin@example.test',
    'admin_password' => $password, // korrektes Passwort
    'csrf_token'     => $login_csrf,
]);
ok(str_contains($r['body'], 'E-Mail oder Passwort falsch'), 'gesperrtes Konto lehnt auch das korrekte Passwort ab');
ok(!str_contains($r['body'], 'Zu viele Fehlversuche'), 'keine eigene Lockout-Meldung — dieselbe generische Meldung wie bei falschem Passwort (Enumeration-Schutz, Spec 6.4)');
ok($r['status'] !== 302, 'kein Redirect, also kein Login trotz korrektem Passwort');

@unlink($account_lock_file);
$r = request('POST', '/admin.php', [
    'admin_email'    => 'admin@example.test',
    'admin_password' => $password,
    'csrf_token'     => $login_csrf,
]);
is_same($r['status'], 302, 'nach Entsperren funktioniert der Login mit korrektem Passwort wieder');
$list = request('GET', '/admin.php')['body']; // für die folgenden Gruppen neu einlesen (neue Session seit dem Logout)

// ── Listing: Entwurfs- und KI-Kennzeichnung ──

group('Seiten-Listing');

is_same(substr_count($list, 'badge badge-draft'), 1, 'Entwurf-Badge steht nur bei der Entwurfsseite');
is_same(substr_count($list, 'badge badge-ai'), 1, 'KI-Badge steht nur bei der von der KI geänderten Seite');
is_same(substr_count($list, 'name="publish_file"'), 1, 'Veröffentlichen-Button nur beim Entwurf');
is_same(substr_count($list, 'name="unpublish_file"'), 1, 'Auf-Entwurf-Button nur bei der veröffentlichten Seite');

// Der Zeitstempel bringt seinen UTC-Offset mit und muss so angezeigt werden,
// wie er aufgezeichnet wurde — nicht in die Zeitzone des Servers umgerechnet.
ok(
    str_contains($list, '06.09.2026 um 22:21'),
    'der KI-Zeitstempel erscheint in seiner eigenen Zeitzone (22:21, nicht umgerechnet)'
);

// ── Speichern im Backend: lastEditedBy für Menschen ──

group('Seite speichern: lastEditedBy für Menschen');

$save_csrf = csrf_from($list);
$r = request('POST', '/admin.php', [
    'csrf_token'    => $save_csrf,
    'filepath'      => 'home.md',
    'meta_title'    => 'Startseite',
    'meta_slug'     => 'home', // wie im echten Formular per Hidden-/vorbelegtem Feld mitgeschickt
    'meta_navLabel' => 'Start',
    'body'          => 'Hallo, geändert im Backend.',
    'save_file'     => '1',
]);
ok(str_contains($r['body'], 'Datei gespeichert'), 'Speichern im Backend meldet Erfolg');

$home_file = (string) content_file('home.md');
// E-Mail enthält "@" -> cms_build_frontmatter() quotet den Wert wie jeden
// anderen Sonderzeichen-Wert (siehe dortige Zeichenliste).
ok(str_contains($home_file, 'lastEditedBy: "admin@example.test"'), 'lastEditedBy trägt die E-Mail des speichernden Admins, nicht "ai"');
ok(str_contains($home_file, 'lastEditedAt:'), 'lastEditedAt wird mitgeschrieben, wie bei der bestehenden KI-Kennzeichnung');

$list_after_save = request('GET', '/admin.php')['body'];
is_same(substr_count($list_after_save, 'badge badge-ai'), 2, 'zwei Badges jetzt: KI-Seite und die von einem Menschen geänderte Seite teilen sich dieselbe Badge-Klasse');
ok(str_contains($list_after_save, 'von admin@example.test'), 'Badge zeigt "von <E-Mail>" statt "von KI"');
$list = $list_after_save; // für die folgenden Gruppen weiterreichen

// ── Der Entwurf ist öffentlich unsichtbar ──

group('Entwurf vor der Freigabe');

is_same(request('GET', '/index.php?slug=ki-entwurf')['status'], 404, 'Entwurf liefert im Frontend 404');
is_same(request('GET', '/index.php?raw=ki-entwurf.md')['status'], 404, 'Entwurf liefert auch als Roh-Markdown 404');

// ── Veröffentlichen ──

group('Veröffentlichen');

$token = csrf_from($list);
ok($token !== '', 'CSRF-Token im Listing vorhanden');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $token,
    'filepath'     => 'ki-entwurf.md',
    'publish_file' => '1',
]);
ok(str_contains($r['body'], 'Seite veröffentlicht'), 'Erfolgsmeldung nach dem Veröffentlichen');

$file = (string) content_file('ki-entwurf.md');
ok(!str_contains($file, 'draft:'), 'die draft-Zeile ist aus der Datei raus');
ok(str_contains($file, 'lastEditedBy: ai'), 'die KI-Kennzeichnung bleibt erhalten');
ok(str_contains($file, 'Von der KI geschrieben.'), 'der Inhalt ist unverändert');

is_same(request('GET', '/index.php?slug=ki-entwurf')['status'], 200, 'die Seite ist jetzt öffentlich erreichbar');
is_same(request('GET', '/index.php?raw=ki-entwurf.md')['status'], 200, 'das Roh-Markdown ist jetzt auch erreichbar');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $token,
    'filepath'     => 'ki-entwurf.md',
    'publish_file' => '1',
]);
ok(str_contains($r['body'], 'bereits veröffentlicht'), 'ein zweites Veröffentlichen wird abgewiesen');

// ── Zurück auf Entwurf ──

group('Zurück auf Entwurf');

$r = request('POST', '/admin.php', [
    'csrf_token'     => $token,
    'filepath'       => 'ki-entwurf.md',
    'unpublish_file' => '1',
]);
ok(str_contains($r['body'], 'auf Entwurf gesetzt'), 'Erfolgsmeldung nach dem Zurückziehen');
ok(str_contains((string) content_file('ki-entwurf.md'), 'draft: true'), 'draft: true steht wieder in der Datei');
is_same(request('GET', '/index.php?slug=ki-entwurf')['status'], 404, 'die Seite ist wieder öffentlich unsichtbar');

$r = request('POST', '/admin.php', [
    'csrf_token'     => $token,
    'filepath'       => 'ki-entwurf.md',
    'unpublish_file' => '1',
]);
ok(str_contains($r['body'], 'bereits ein Entwurf'), 'ein zweites Zurückziehen wird abgewiesen');

// ── Tab "KI-Anbindung" ──

group('Tab "KI-Anbindung"');

$ai = request('GET', '/admin.php?action=ai')['body'];
// Der globale Connector-Token (ein Token für die ganze Installation) ist mit
// der Benutzerverwaltung entfallen: mcp.php prüft seither nur noch
// personengebundene Tokens aus users.json. Der eigene Token-Test dafür
// steht bei der entsprechenden Unteraufgabe (Login/Tab "Benutzer"/"Mein
// Profil"), nicht hier.
ok(!str_contains($ai, 'name="regenerate_ai_token"'), 'kein Button für einen globalen Connector-Token mehr');
ok(str_contains($ai, 'action=users'), 'Tab verweist stattdessen auf den Tab "Benutzer"');

$ai_token = csrf_from($ai);

// Toggle "sofort veröffentlichen"
$r = request('POST', '/admin.php', [
    'csrf_token'              => $ai_token,
    'ai_publish_immediately'  => '1',
    'save_ai_settings'        => '1',
]);
ok(str_contains($r['body'], 'Einstellung gespeichert'), 'Toggle-Speichern meldet Erfolg');
ok(
    str_contains((string) file_get_contents($GLOBALS['tmp_root'] . '/config/config.php'), "'ai_publish_immediately' => true"),
    'ai_publish_immediately steht jetzt auf true in der Konfiguration'
);

$r = request('POST', '/admin.php', [
    'csrf_token'        => $ai_token,
    'save_ai_settings'  => '1',
]);
ok(
    str_contains((string) file_get_contents($GLOBALS['tmp_root'] . '/config/config.php'), "'ai_publish_immediately' => false"),
    'ohne Häkchen im Formular steht ai_publish_immediately wieder auf false (unchecked Checkbox schickt keinen Wert)'
);

// Undo-Liste: home.md hat eine Vorversion aus den Fixtures, ki-entwurf.md nicht
$ai = request('GET', '/admin.php?action=ai')['body'];
ok(str_contains($ai, 'Startseite'), 'die Seite mit Vorversion erscheint in der Undo-Liste');
is_same(substr_count($ai, 'name="restore_history"'), 1, 'genau ein Wiederherstellen-Button — nur für die Seite mit Vorversion');
// Entwurfszähler: rendert aus demselben Content-Scan wie die Undo-Liste und
// wird erst im Lizenz-else-Zweig berechnet. Ohne eigene Prüfung fiele ein
// nicht gesetzter Zähler nur als leere Stelle im Text auf, nicht als Fehler.
ok(
    (bool)preg_match('/Entw(ü|&uuml;)rfe.*?>[1-9]\d* wartet/s', $ai),
    'Entwurfszähler im Tab zeigt eine Zahl größer null (ki-entwurf.md aus den Fixtures)'
);

$r = request('POST', '/admin.php', [
    'csrf_token'      => $ai_token,
    'restore_slug'    => 'home',
    'restore_history' => '1',
]);
ok(str_contains($r['body'], 'Vorversion wiederhergestellt'), 'Wiederherstellen meldet Erfolg');
ok(
    str_contains((string) content_file('home.md'), 'Alte Version vor der KI-Änderung.'),
    'home.md hat wieder den Inhalt der Vorversion'
);
// cms_history_restore() sichert den überschriebenen Stand selbst — Undo bleibt umkehrbar
$history_dir = $tmp_root . '/content/.history/home';
ok(is_dir($history_dir) && count(glob($history_dir . '/*.md')) >= 2, 'der überschriebene Stand wurde vor dem Zurücksetzen selbst gesichert');

$r = request('POST', '/admin.php', [
    'csrf_token'      => 'falsches-token',
    'restore_slug'    => 'home',
    'restore_history' => '1',
]);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Wiederherstellen ohne gültiges CSRF-Token wird abgewiesen');

// ── Einstellungen: Logo & Favicon ──
//
// Systematische Testabdeckung bewusst erst hier gebündelt, nicht einzeln
// aufgeteilt. Deckt Abschnitt 10 der Spec (Website-Logo) ab: beide
// Uploads, beide Positionen zusätzlich
// zum Default, beide Anzeige-Modi, unabhängiges Löschen, Rollenprüfung
// (letztere unten bei "Mein Profil", mit der schon laufenden
// Content-Creator-Session).

group('Website & SEO: Logo & Favicon — Ausgangszustand ohne Upload');

// Seit IRM-57 unter action=site (Reiter "Website", Default), nicht mehr
// action=settings — die reduzierte "Einstellungen" hat diese Sektion gar
// nicht mehr (geprüft weiter unten bei "Mein Profil").
$settings_page = request('GET', '/admin.php?action=site')['body'];
ok(str_contains($settings_page, 'Logo &amp; Favicon'), 'neue Sektion "Logo & Favicon" ist im Reiter "Website" sichtbar');
ok(str_contains($settings_page, 'Noch kein Logo hochgeladen'), 'Hinweistext statt Vorschau, solange kein Logo existiert');
ok(str_contains($settings_page, 'Noch kein Favicon hochgeladen'), 'Hinweistext statt Vorschau, solange kein Favicon existiert');
ok(!str_contains($settings_page, 'name="delete_logo"'), 'kein Löschen-Button ohne Logo');
ok(!str_contains($settings_page, 'name="site_logo_position"'), 'Positions-/Anzeige-Dropdown erscheinen erst nach dem ersten Upload');
ok(str_contains($settings_page, 'Empfohlene Größe: zwischen 100×100 und 300×300 px.'), 'Größenhinweis fürs Logo steht im Upload-Feld');
ok(str_contains($settings_page, 'Empfohlene Größe: 48×48 px.'), 'Größenhinweis fürs Favicon steht im Upload-Feld');
$branding_csrf = csrf_from($settings_page);

$front_before = request('GET', '/index.php?slug=home', null, false)['body'];
ok(str_contains($front_before, 'class="site-name"') && !str_contains($front_before, 'site-logo'), 'Frontend zeigt ohne Logo unverändert den Text-Titel');
ok(!str_contains($front_before, 'rel="icon"'), 'kein <link rel="icon"> ohne Favicon');

group('Einstellungen: Logo hochladen (gültiges Bild)');

$r = upload_file('/admin.php', [
    'csrf_token'  => $branding_csrf,
    'upload_logo' => '1',
], ['logo' => ['name' => 'logo.png', 'type' => 'image/png', 'data' => $png_bytes]]);
ok(str_contains($r['body'], 'Logo hochgeladen: logo.png'), 'Logo-Upload meldet Erfolg');
ok(is_file($tmp_root . '/assets/branding/logo.png'), 'Datei landet in assets/branding/, nicht im normalen Bilder-Pool');
ok(!is_file($tmp_root . '/assets/images/logo.png'), 'Datei landet nicht in assets/images/');
ok(str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'site_logo' => 'logo.png'"), 'config.php trägt site_logo ein');
ok(str_contains($r['body'], 'name="delete_logo"'), 'Löschen-Button erscheint jetzt');
ok(str_contains($r['body'], 'name="site_logo_position"'), 'Positions-Dropdown erscheint jetzt');
ok((bool) preg_match('/value="links"\s+selected/', $r['body']), 'Default-Position "links" vorausgewählt');
ok((bool) preg_match('/value="logo_only"\s+selected/', $r['body']), 'Default-Anzeige "Nur Logo" direkt nach dem ersten Upload vorausgewählt');
$branding_csrf = csrf_from($r['body']);

group('Frontend: Logo vorhanden, Modus "Nur Logo", Position "links" (Default)');

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(str_contains($front, 'class="site-logo"'), '<img class="site-logo"> ist da');
ok(!str_contains($front, 'site-name'), 'kein Text-Titel neben dem Logo im Modus "Nur Logo"');
ok(str_contains($front, 'site-header--links'), 'Header trägt die Positions-Klasse "links"');
ok(str_contains($front, 'alt="Testseite"'), 'alt-Attribut trägt den Website-Titel, auch ohne sichtbaren Text-Titel');

group('Einstellungen: Favicon hochladen (unabhängig vom Logo)');

$r = upload_file('/admin.php', [
    'csrf_token'     => $branding_csrf,
    'upload_favicon' => '1',
], ['favicon' => ['name' => 'favicon.png', 'type' => 'image/png', 'data' => $png_bytes]]);
ok(str_contains($r['body'], 'Favicon hochgeladen: favicon.png'), 'Favicon-Upload meldet Erfolg');
ok(is_file($tmp_root . '/assets/branding/favicon.png'), 'Favicon-Datei landet in assets/branding/');
ok(is_file($tmp_root . '/assets/branding/logo.png'), 'Logo-Datei bleibt vom Favicon-Upload unangetastet');
$branding_csrf = csrf_from($r['body']);

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(str_contains($front, 'rel="icon"') && str_contains($front, 'assets/branding/favicon.png'), 'Favicon-Link erscheint jetzt im <head>');

group('Einstellungen: Position & Anzeige-Modus ändern');

$r = request('POST', '/admin.php', [
    'csrf_token'             => $branding_csrf,
    'site_logo_position'     => 'mittig',
    'site_logo_display'      => 'logo_und_titel',
    'save_branding_settings' => '1',
]);
ok(str_contains($r['body'], 'Branding-Einstellungen gespeichert'), 'Speichern meldet Erfolg');
ok((bool) preg_match('/value="mittig"\s+selected/', $r['body']), 'Position "mittig" jetzt vorausgewählt');
ok((bool) preg_match('/value="logo_und_titel"\s+selected/', $r['body']), 'Anzeige "Logo + Titel" jetzt vorausgewählt');
$branding_csrf = csrf_from($r['body']);

group('Frontend: Logo vorhanden, Modus "Logo + Titel", Position "mittig"');

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(str_contains($front, 'site-header--mittig'), 'Header trägt die Positions-Klasse "mittig"');
ok(str_contains($front, 'class="site-logo"') && str_contains($front, 'class="site-name"'), 'Logo UND Text-Titel sichtbar im Modus "Logo + Titel"');

$r = request('POST', '/admin.php', [
    'csrf_token'             => $branding_csrf,
    'site_logo_position'     => 'rechts',
    'site_logo_display'      => 'logo_only',
    'save_branding_settings' => '1',
]);
$branding_csrf = csrf_from($r['body']);

group('Frontend: Position "rechts"');

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(str_contains($front, 'site-header--rechts'), 'Header trägt die Positions-Klasse "rechts"');

group('Einstellungen: ungültige Position/Anzeige werden server-seitig abgewiesen');

$r = upload_file('/admin.php', [
    'csrf_token'  => $branding_csrf,
    'upload_logo' => '1',
], ['logo' => ['name' => 'nicht-echt.txt', 'type' => 'text/plain', 'data' => 'Das ist kein Bild.']]);
ok(str_contains($r['body'], 'Ungültige Dateiendung'), 'Upload mit unzulässiger Endung wird abgelehnt (cms_validate_image_upload(), wiederverwendet aus lib/media.php)');
ok(str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'site_logo' => 'logo.png'"), 'config.php weiterhin unverändert — noch logo.png, kein Fehlschlag hat den Wert überschrieben');
$branding_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', [
    'csrf_token'             => $branding_csrf,
    'site_logo_position'     => 'oben-links-diagonal',
    'site_logo_display'      => 'logo_only',
    'save_branding_settings' => '1',
]);
ok(str_contains($r['body'], 'Ungültige Logo-Position'), 'ungültiger Positions-Wert wird server-seitig abgewiesen, nicht blind aus dem <select> übernommen');
$branding_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', [
    'csrf_token'             => $branding_csrf,
    'site_logo_position'     => 'links',
    'site_logo_display'      => 'irgendwas',
    'save_branding_settings' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Anzeige-Modus'), 'ungültiger Anzeige-Modus wird server-seitig abgewiesen');
$branding_csrf = csrf_from($r['body']);

$r = upload_file('/admin.php', [
    'csrf_token'  => 'falsches-token',
    'upload_logo' => '1',
], ['logo' => ['name' => 'logo2.png', 'type' => 'image/png', 'data' => $png_bytes]]);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Logo-Upload ohne gültiges CSRF-Token wird abgewiesen');

// Für die folgenden Lösch-Tests auf den überschaubaren Ausgangszustand
// zurücksetzen (Default-Position/-Anzeige, siehe oben).
$r = request('POST', '/admin.php', [
    'csrf_token'             => $branding_csrf,
    'site_logo_position'     => 'links',
    'site_logo_display'      => 'logo_only',
    'save_branding_settings' => '1',
]);
$branding_csrf = csrf_from($r['body']);

group('Einstellungen: Logo und Favicon unabhängig voneinander löschen');

$r = request('POST', '/admin.php', ['csrf_token' => $branding_csrf, 'delete_logo' => '1']);
ok(str_contains($r['body'], 'Logo gelöscht'), 'Logo-Löschen meldet Erfolg');
clearstatcache(true, $tmp_root . '/assets/branding/logo.png'); // is_file() cachet sonst den Stand von der Prüfung nach dem Upload weiter oben
ok(!is_file($tmp_root . '/assets/branding/logo.png'), 'Logo-Datei ist wirklich weg');
ok(is_file($tmp_root . '/assets/branding/favicon.png'), 'Favicon-Datei bleibt vom Logo-Löschen unangetastet');
ok(!str_contains($r['body'], 'name="delete_logo"'), 'Löschen-Button für das Logo verschwindet wieder');
ok(!str_contains($r['body'], 'name="site_logo_position"'), 'Positions-/Anzeige-Dropdown verschwinden wieder ohne Logo');
$branding_csrf = csrf_from($r['body']);

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(!str_contains($front, 'site-logo'), 'Frontend zeigt nach dem Löschen wieder den Text-Titel');
ok(str_contains($front, 'rel="icon"'), 'Favicon bleibt weiterhin eingebunden — unabhängig vom gelöschten Logo');

$r = request('POST', '/admin.php', ['csrf_token' => $branding_csrf, 'delete_favicon' => '1']);
ok(str_contains($r['body'], 'Favicon gelöscht'), 'Favicon-Löschen meldet Erfolg, unabhängig vom bereits gelöschten Logo');
clearstatcache(true, $tmp_root . '/assets/branding/favicon.png'); // is_file() cachet sonst den Stand von der Prüfung weiter oben
ok(!is_file($tmp_root . '/assets/branding/favicon.png'), 'Favicon-Datei ist wirklich weg');
$branding_csrf = csrf_from($r['body']);

$front = request('GET', '/index.php?slug=home', null, false)['body'];
ok(!str_contains($front, 'rel="icon"'), 'kein Favicon-Link mehr nach dem Löschen — Frontend wieder im Ausgangszustand');

$r = request('POST', '/admin.php', ['csrf_token' => 'falsches-token', 'delete_logo' => '1']);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Löschen ohne gültiges CSRF-Token wird abgewiesen');

// ── SMTP-Verschlüsselung ──

group('Einstellungen: SMTP-Verschlüsselung (alle drei Werte, Unbekanntes fällt auf tls)');

// Speichern setzt smtp_port auf einen der erlaubten Standard-Ports und damit
// weg vom absichtlich toten Port aus build_webroot(). Die Mail-Tests weiter
// unten verlassen sich auf diesen toten Port, deshalb wird die Config danach
// wieder exakt zurückgeschrieben.
$config_before_smtp = (string) file_get_contents($tmp_root . '/config/config.php');
$smtp_csrf = csrf_from(request('GET', '/admin.php?action=settings')['body']);
foreach (['ssl' => 'ssl', '' => '', 'tls' => 'tls', 'quatsch' => 'tls'] as $sent => $expected) {
    $r = request('POST', '/admin.php', [
        'csrf_token'            => $smtp_csrf,
        'contact_to_email'      => 'admin@example.test',
        'smtp_from_email'       => 'admin@example.test',
        'smtp_from_name'        => 'Test Admin',
        'smtp_host'             => '127.0.0.1',
        'smtp_user'             => 'user',
        'smtp_port'             => '587',
        'smtp_secure'           => $sent,
        'save_contact_settings' => '1',
    ]);
    ok(str_contains($r['body'], 'E-Mail-Einstellungen gespeichert'), "smtp_secure '{$sent}' speichert ohne Fehler");
    ok(
        str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'smtp_secure' => '{$expected}'"),
        "smtp_secure '{$sent}' landet als '{$expected}' in config.php"
    );
    $smtp_csrf = csrf_from($r['body']);
}
put($tmp_root . '/config/config.php', $config_before_smtp);

// ── Tab "Benutzer" ──

group('Tab "Benutzer": Aussperr-Schutz');

$users_page  = request('GET', '/admin.php?action=users')['body'];
ok(str_contains($users_page, 'Nutzer einladen'), 'Admin sieht den Tab "Benutzer"');
ok(str_contains($users_page, 'admin@example.test'), 'der migrierte Admin steht in der Liste');
$users_csrf = csrf_from($users_page);
$admin_id   = find_test_user($tmp_root, 'admin@example.test')['id'];

$r = request('POST', '/admin.php', [
    'csrf_token'         => $users_csrf,
    'user_id'            => $admin_id,
    'new_status'         => 'gesperrt',
    'toggle_user_status' => '1',
]);
ok(str_contains($r['body'], 'letzte verbleibende Admin-Account kann nicht gesperrt werden'), 'letzten Admin sperren wird abgewiesen');
is_same(find_test_user($tmp_root, 'admin@example.test')['status'], 'aktiv', 'Status unverändert');

$r = request('POST', '/admin.php', [
    'csrf_token'  => $users_csrf,
    'user_id'     => $admin_id,
    'delete_user' => '1',
]);
ok(str_contains($r['body'], 'letzte verbleibende Admin-Account kann nicht gelöscht werden'), 'letzten Admin löschen wird abgewiesen');
ok(find_test_user($tmp_root, 'admin@example.test') !== null, 'Admin weiterhin vorhanden');

group('Tab "Benutzer": Content Creator einladen');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $users_csrf,
    'invite_email' => 'kollegin@example.test',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
ok(str_contains($r['body'], 'Einladungs-Mail konnte nicht verschickt werden'), 'Account bleibt trotz SMTP-Fehlschlag bestehen (v0.3: kein Fail-Closed wie beim ursprünglichen Passwort-Reset)');
$creator = find_test_user($tmp_root, 'kollegin@example.test');
ok($creator !== null, 'Content Creator wurde angelegt');
is_same($creator['role'] ?? null, 'content_creator', 'Rolle korrekt gesetzt');
is_same($creator['status'] ?? null, 'aktiv', 'Status aktiv');
ok($creator['password_hash'] === null, 'noch kein Passwort gesetzt');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $users_csrf,
    'invite_email' => 'chef@example.test',
    'invite_role'  => 'superadmin',
    'invite_user'  => '1',
]);
ok(str_contains($r['body'], 'Unbekannte Rolle'), 'Einladen mit unbekannter Rolle wird abgewiesen');
ok(find_test_user($tmp_root, 'chef@example.test') === null, 'kein Nutzer mit unbekannter Rolle angelegt');
ok($creator['mcp_token_hash'] !== null, 'MCP-Token wurde sofort erzeugt');
// Anders als beim ursprünglichen Passwort-Reset kein Fail-Closed für den
// Token selbst: send_invite_mail() schreibt ihn *vor* dem Versandversuch
// und räumt ihn bei einem Fehlschlag
// nicht wieder weg (siehe invite_user()-Kommentar) — unkritisch, weil ihn
// mangels verschickter Mail niemand kennt, und "Einladung erneut senden"
// überschreibt ihn ohnehin mit einem frischen.
ok(is_file(user_token_file($tmp_root, $creator['id'])), 'Token-Datei existiert (auch wenn die Mail scheiterte — niemand kennt den Wert, also unkritisch)');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $users_csrf,
    'invite_email' => 'kollegin@example.test',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
ok(str_contains($r['body'], 'bereits vergeben'), 'doppelte E-Mail beim Einladen abgewiesen');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $users_csrf,
    'invite_email' => 'keine-email',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
ok(str_contains($r['body'], 'Ungültige E-Mail'), 'ungültige E-Mail beim Einladen abgewiesen');

group('Tab "Benutzer": Einladung erneut senden / Passwort festlegen');

// send_invite_mail() scheitert wie oben am toten SMTP-Port, schreibt aber
// den Token *vor* dem Versandversuch (anders als beim ursprünglichen
// Passwort-Reset kein Fail-Closed, siehe invite_user()-Kommentar in
// admin.php) — deshalb hier
// direkt den Token selbst schreiben, wie write_reset_token() es für den
// globalen Admin-Token tut, statt über die Route zu gehen.
$creator_token = write_user_token($tmp_root, $creator['id']);
$admin_cookie  = $cookie; // Admin-Session merken, gleich wechseln wir die Identität

$cookie = ''; // frische, anonyme Sicht — wie ein Klick auf den Mail-Link
$invite_page = request('GET', '/admin.php?action=reset_password&token=' . $creator_token, null, false)['body'];
ok(str_contains($invite_page, 'Passwort festlegen'), 'Einladungs-Link zeigt "Passwort festlegen", nicht "zurücksetzen"');
$invite_csrf = csrf_from($invite_page);

$r = request('POST', '/admin.php?action=reset_password&token=' . $creator_token, [
    'csrf_token'           => $invite_csrf,
    'token'                => $creator_token,
    'new_password'         => 'passwort-der-kollegin-123',
    'new_password_confirm' => 'passwort-der-kollegin-123',
]);
is_same($r['status'], 302, 'Passwort festlegen loggt automatisch ein, wie beim normalen Reset');
ok(!is_file(user_token_file($tmp_root, $creator['id'])), 'Einladungs-Token ist verbraucht (Single-Use)');
ok(password_verify('passwort-der-kollegin-123', user_password_hash($tmp_root, 'kollegin@example.test')), 'Passwort wirklich gesetzt');

$creator_home = request('GET', '/admin.php')['body'];
ok(str_contains($creator_home, 'Seiten ('), 'Content Creator ist eingeloggt und sieht das Seiten-Listing');
ok(!str_contains($creator_home, '>Benutzer<'), 'Content Creator sieht den Tab "Benutzer" nicht in der Navigation');

$forbidden = request('GET', '/admin.php?action=users');
is_same($forbidden['status'], 403, 'Direktaufruf von action=users durch Content Creator liefert 403');

$forbidden_post = request('POST', '/admin.php', [
    'csrf_token'   => csrf_from($creator_home),
    'invite_email' => 'zweite-person@example.test',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
is_same($forbidden_post['status'], 403, 'auch der POST-Handler selbst weist einen Content Creator ab, nicht nur die Anzeige');
ok(find_test_user($tmp_root, 'zweite-person@example.test') === null, 'kein Nutzer über den abgewiesenen Versuch angelegt');

$creator_session_cookie = $cookie; // aktive Session merken — Spec 5.2/6.7: eine Sperrung soll sie beim nächsten Request noch treffen

$cookie = $admin_cookie; // zurück zur Admin-Session für die restlichen Tests

group('Tab "Redirects"');

$redirects_page = request('GET', '/admin.php?action=redirects')['body'];
ok(str_contains($redirects_page, 'Redirect anlegen'), 'Admin sieht den Tab "Redirects"');
ok(str_contains($redirects_page, 'Noch keine Redirects angelegt'), 'Liste ist anfangs leer');
$redirects_csrf = csrf_from($redirects_page);

// Content Creator: weder Tab in der Nav noch Zugriff.
// Nutzt die noch aktive Session von oben — der Content Creator wird erst im
// nächsten Testblock gesperrt.
$cookie = $creator_session_cookie;
$creator_home_check = request('GET', '/admin.php')['body'];
ok(!str_contains($creator_home_check, '>Redirects<'), 'Content Creator sieht den Tab "Redirects" nicht in der Navigation');
$creator_forbidden = request('GET', '/admin.php?action=redirects');
is_same($creator_forbidden['status'], 403, 'Direktaufruf von action=redirects durch Content Creator liefert 403');
$creator_forbidden_post = request('POST', '/admin.php', [
    'csrf_token'       => csrf_from($creator_home_check),
    'redirect_from'    => '/von-content-creator',
    'redirect_to'      => '/ziel',
    'create_redirect'  => '1',
]);
is_same($creator_forbidden_post['status'], 403, 'auch der POST-Handler weist einen Content Creator ab, nicht nur die Anzeige');
$cookie = $admin_cookie; // zurück zur Admin-Session

// ── Kategorien ──

group('Kategorien: anlegen, umbenennen, löschen');

$cfg_before_categories = (string) file_get_contents($tmp_root . '/config/config.php');
$settings_page = request('GET', '/admin.php?action=settings')['body'];
ok(str_contains($settings_page, 'Noch keine Kategorien angelegt'), 'Liste ist anfangs leer');
$cat_csrf = csrf_from($settings_page);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_name' => 'Über SEO & Mehr', 'create_category' => '1']);
ok(str_contains($r['body'], 'angelegt'), 'Anlegen meldet Erfolg');
ok(str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'slug' => 'ueber-seo-mehr'"), 'Slug wird aus dem Namen erzeugt (Umlaut aufgelöst, Sonderzeichen entfernt)');
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_name' => 'ueber seo mehr', 'create_category' => '1']);
ok(str_contains($r['body'], 'gibt es schon'), 'Doppelter Slug wird abgewiesen');
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_name' => '  !!  ', 'create_category' => '1']);
ok(str_contains($r['body'], 'Namen mit Buchstaben'), 'Name ohne Buchstaben/Ziffern wird abgewiesen');
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_name' => 'WordPress', 'create_category' => '1']);
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_slug' => 'wordpress', 'category_name' => 'WP & Co', 'rename_category' => '1']);
ok(str_contains($r['body'], 'umbenannt'), 'Umbenennen meldet Erfolg');
$cfg_now = (string) file_get_contents($tmp_root . '/config/config.php');
ok(str_contains($cfg_now, "'slug' => 'wordpress'") && str_contains($cfg_now, "'name' => 'WP & Co'"), 'Umbenennen ändert nur den Namen, der Slug bleibt');
ok(str_contains($r['body'], 'WP &amp; Co'), 'Name wird im Formular escaped ausgegeben');
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_slug' => 'gibt-es-nicht', 'category_name' => 'X', 'rename_category' => '1']);
ok(str_contains($r['body'], 'nicht gefunden'), 'Umbenennen einer unbekannten Kategorie wird abgewiesen');
$cat_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', ['csrf_token' => 'falsch', 'category_name' => 'Nope', 'create_category' => '1']);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Anlegen ohne gültiges CSRF-Token wird abgewiesen');

$cookie = $creator_session_cookie;
$creator_page = request('GET', '/admin.php')['body'];
foreach (['create_category' => ['category_name' => 'Hack'], 'rename_category' => ['category_slug' => 'wordpress', 'category_name' => 'Hack'], 'delete_category' => ['category_slug' => 'wordpress']] as $handler => $fields) {
    $r = request('POST', '/admin.php', ['csrf_token' => csrf_from($creator_page), $handler => '1'] + $fields);
    is_same($r['status'], 403, "Content Creator wird bei {$handler} mit 403 abgewiesen");
}
$cookie = $admin_cookie;
ok(!str_contains((string) file_get_contents($tmp_root . '/config/config.php'), 'Hack'), 'Content Creator hat nichts verändert');

group('Kategorien: Zuordnung im Editor');

$cat_save = static fn(array $extra): array => request('POST', '/admin.php', [
    'csrf_token' => $cat_csrf, 'filepath' => 'kategorie-test.md', 'meta_title' => 'Kategorie-Test',
    'meta_slug' => 'kategorie-test', 'body' => 'Text', 'save_file' => '1',
] + $extra);

$r = $cat_save(['meta_categories' => ['wordpress', 'ueber-seo-mehr', 'alt-und-geloescht', 'Ungültig!', '']]);
ok(str_contains($r['body'], 'Datei gespeichert'), 'Seite mit Kategorien speichert');
ok(str_contains((string) content_file('kategorie-test.md'), 'categories: "wordpress, ueber-seo-mehr, alt-und-geloescht"'), 'Frontmatter trägt die gültigen Slugs kommagetrennt, Müll fehlt');
$cat_csrf = csrf_from($r['body']);

$edit = request('GET', '/admin.php?action=edit&file=kategorie-test.md')['body'];
ok(preg_match('/name="meta_categories\[\]" value="wordpress"\s+checked/', $edit) === 1, 'Editor markiert zugeordnete Kategorie nach dem erneuten Öffnen');
ok(preg_match('/name="meta_categories\[\]" value="alt-und-geloescht" checked>\s*alt-und-geloescht <span[^>]*>\(unbekannt\)/', $edit) === 1, 'Unbekannter Slug erscheint als "unbekannt" und bleibt angehakt');
$cat_csrf = csrf_from($edit);

$r = $cat_save(['meta_categories' => 'kein-array']);
ok(!str_contains((string) content_file('kategorie-test.md'), 'categories:'), 'Skalarer Wert statt Array wirft nichts und entfernt die Zuordnung');
$cat_csrf = csrf_from($r['body']);

$r = $cat_save(['meta_categories' => ['wordpress']]);
$cat_csrf = csrf_from($r['body']);
$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'category_slug' => 'wordpress', 'delete_category' => '1']);
ok(str_contains($r['body'], 'Kategorie gelöscht'), 'Löschen meldet Erfolg');
ok(str_contains((string) content_file('kategorie-test.md'), 'categories: wordpress'), 'Löschen schreibt keine Seite um');
$cat_csrf = csrf_from($r['body']);

$settings_page = request('GET', '/admin.php?action=settings')['body'];
ok(!str_contains($settings_page, 'value="wordpress"'), 'gelöschte Kategorie steht nicht mehr in der Liste');

// Aufräumen: Config und Testseite zurück, spätere Gruppen erwarten den alten Stand.
put($tmp_root . '/config/config.php', $cfg_before_categories);
@unlink($tmp_root . '/content/kategorie-test.md');

group('Kategorien: Shortcode [category-list] im Frontend');

$cat_cfg = require $tmp_root . '/config/config.php';
$cat_cfg['categories'] = [
    ['slug' => 'seo', 'name' => 'SEO & Mehr'],
    ['slug' => 'wordpress', 'name' => 'WordPress'],
];
put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export($cat_cfg, true) . ";\n");

$cat_fixtures = [
    'kat-a.md'        => "title: A-Title\nslug: kat-a\nheading: Tom & <b>Jerry</b>\norder: 2\ncategories: seo\n"
                       . "description: Text mit <i>Markup</i>\nheaderImage: assets/images/a.jpg\n---\n\nA.\n",
    'kat-b.md'        => "title: Beta\nslug: kat-b\norder: 1\ncategories: \"seo, wordpress, geloescht\"\n---\n\nB.\n",
    'kat-c.md'        => "title: Zeta\nslug: kat-c\ncategories: seo\nheaderImage: javascript:alert(1)\n---\n\nC.\n",
    'kat-d.md'        => "title: alpha\nslug: kat-d\ncategories: seo\n---\n\nD.\n",
    'kat-fremd.md'    => "title: Fremd\nslug: kat-fremd\ncategories: wordpress\n---\n\nFremd.\n",
    'kat-entwurf.md'  => "title: Geheimer Entwurf\nslug: kat-entwurf\ndraft: true\ncategories: seo\n---\n\nEntwurf.\n",
    'kat-liste.md'    => "title: Liste\nslug: kat-liste\ncategories: seo\n---\n\nVorher\n\n[category-list category=\"seo\"]\n\nNachher\n",
    'kat-karten.md'   => "title: Karten\nslug: kat-karten\n---\n\n[category-list category=\"seo\" style=\"cards\"]\n",
    'kat-fallback.md' => "title: Fallback\nslug: kat-fallback\n---\n\n[category-list style=\"quatsch\" category=\"seo\"]\n",
    'kat-leer.md'     => "title: Leer\nslug: kat-leer\n---\n\nVorher\n\n[category-list category=\"gibt-es-nicht\"]\n\n"
                       . "[category-list]\n\n[category-list category=\"geloescht\" style=\"cards\"]\n\nNachher\n",
    'kat-landing.md'  => "title: Landing\nslug: kat-landing\ncontentType: html\ncategories: seo\n---\n\n"
                       . "<section>[category-list category=\"wordpress\" style=\"cards\"]</section>\n",
];
foreach ($cat_fixtures as $cat_file => $cat_source) {
    put($tmp_root . '/content/' . $cat_file, "---\n" . $cat_source);
}

$page = request('GET', '/index.php?slug=kat-liste')['body'];
ok(preg_match('~Vorher</p>\s*<ul class="category-list">.*</ul>\s*<p>Nachher~s', $page) === 1, 'Liste ersetzt den Shortcode samt <p>-Wrapper');
ok(preg_match('~<li><a href="http://[^"]+/kat-b">Beta</a></li>~', $page) === 1, 'Listeneintrag ist der Titel als Link auf die Seite');
$cat_pos = array_map(static fn(string $slug): int|false => strpos($page, '/' . $slug . '">'), ['kat-b', 'kat-a', 'kat-d', 'kat-c']);
$cat_sorted = $cat_pos;
sort($cat_sorted);
ok(!in_array(false, $cat_pos, true) && $cat_pos === $cat_sorted, 'Sortierung: order zuerst, dann Titel ohne Rücksicht auf Gross-/Kleinschreibung');
ok(str_contains($page, 'Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;</a>') && !str_contains($page, '<b>Jerry'), 'Sonderzeichen im Titel sind escaped, die Überschrift geht vor dem HTML-Title');
ok(!str_contains($page, 'kat-entwurf') && !str_contains($page, 'Geheimer Entwurf'), 'Entwurf bleibt draussen');
ok(!str_contains($page, '/kat-liste">Liste</a>'), 'die Seite selbst steht nicht in ihrer eigenen Liste');
ok(!str_contains($page, '/kat-fremd">'), 'Seite einer anderen Kategorie steht nicht in der Liste');
ok(!str_contains($page, 'page-categories'), 'Kategorien-Zeile ist ohne Einstellung aus');

$page = request('GET', '/index.php?slug=kat-karten')['body'];
ok(str_contains($page, '<div class="category-cards">') && substr_count($page, 'class="category-card"') === 6, 'Karten: ein Element je Seite der Kategorie, auch für eine Landingpage');
ok(preg_match('~<img class="category-card-image" src="http://[^"]+/assets/images/a\.jpg" alt="" loading="lazy">~', $page) === 1, 'Kartenbild mit absoluter URL und lazy');
ok(str_contains($page, '<span class="category-card-text">Text mit &lt;i&gt;Markup&lt;/i&gt;</span>'), 'Beschreibung steht escaped in der Karte');
ok(substr_count($page, 'category-card-image') === 1 && !str_contains($page, 'javascript:'), 'Bild mit fremdem Schema wird nicht ausgegeben');
ok(!str_contains($page, '<p><div'), 'kein <div> im <p>');

$page = request('GET', '/index.php?slug=kat-fallback')['body'];
ok(str_contains($page, '<ul class="category-list">') && !str_contains($page, 'category-cards'), 'ungültiger style fällt auf die Liste zurück, Reihenfolge der Parameter ist egal');

$page = request('GET', '/index.php?slug=kat-leer')['body'];
ok(!str_contains($page, '[category-list') && !str_contains($page, 'class="category-'), 'unbekannte, gelöschte und fehlende Kategorie: keine Ausgabe, kein Shortcode-Rest');
ok(preg_match('~<p>Vorher</p>\s*<p>Nachher</p>~', $page) === 1, 'vom Shortcode bleibt kein leerer Absatz stehen');

$page = request('GET', '/index.php?slug=kat-landing')['body'];
ok(preg_match('~<section><div class="category-cards">.*/kat-fremd">.*</div></section>~s', $page) === 1, 'Shortcode wirkt auch im rohen HTML einer Landingpage');

group('Kategorien: Anzeige unter dem Seiteninhalt');

$settings_page = request('GET', '/admin.php?action=settings')['body'];
ok(preg_match('/name="categories_show_on_page" value="1"\s*>/', $settings_page) === 1, 'Schalter ist anfangs aus');
$cat_csrf = csrf_from($settings_page);

$cookie = $creator_session_cookie;
$r = request('POST', '/admin.php', ['csrf_token' => csrf_from(request('GET', '/admin.php')['body']), 'categories_show_on_page' => '1', 'save_category_settings' => '1']);
is_same($r['status'], 403, 'Content Creator wird bei save_category_settings mit 403 abgewiesen');
$cookie = $admin_cookie;

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'categories_show_on_page' => '1', 'save_category_settings' => '1']);
ok(str_contains($r['body'], 'Kategorien-Einstellungen gespeichert'), 'Einschalten meldet Erfolg');
ok(preg_match('/name="categories_show_on_page" value="1"\s+checked/', $r['body']) === 1, 'Schalter ist danach angehakt');
$cat_csrf = csrf_from($r['body']);

ok(str_contains(request('GET', '/index.php?slug=kat-b')['body'], '<p class="page-categories">Kategorien: SEO &amp; Mehr, WordPress</p>'), 'Seite zeigt ihre Kategorien als Text, escaped, unbekannter Slug fehlt');
ok(str_contains(request('GET', '/index.php?slug=kat-a')['body'], '<p class="page-categories">Kategorie: SEO &amp; Mehr</p>'), 'eine einzelne Kategorie steht in der Einzahl');
ok(!str_contains(request('GET', '/index.php?slug=kat-karten')['body'], 'page-categories'), 'Seite ohne Kategorie bekommt keine Zeile');
ok(!str_contains(request('GET', '/index.php?slug=kat-landing')['body'], 'page-categories'), 'Landingpage bekommt keine Zeile');

$r = request('POST', '/admin.php', ['csrf_token' => $cat_csrf, 'save_category_settings' => '1']);
ok(!str_contains(request('GET', '/index.php?slug=kat-b')['body'], 'page-categories'), 'Ausschalten entfernt die Zeile wieder');

put($tmp_root . '/config/config.php', $cfg_before_categories);
foreach (array_keys($cat_fixtures) as $cat_file) {
    @unlink($tmp_root . '/content/' . $cat_file);
}

// ── Seitenübersicht: Datum, Kategorien, Sortierung, Filter, Löschen ──

group('Seitenübersicht: Änderungsdatum und Spalten');

$list = request('GET', '/admin.php')['body'];
ok(str_contains($list, '<table class="page-table">'), 'die Übersicht ist eine Tabelle');
ok(!str_contains($list, 'page_filter_category') && !str_contains($list, '>Kategorien</th>'), 'ohne angelegte Kategorien gibt es weder Filter noch Kategorien-Spalte');

$list_cfg = require $tmp_root . '/config/config.php';
$list_cfg['categories'] = [
    ['slug' => 'seo', 'name' => 'SEO & Mehr'],
    ['slug' => 'wordpress', 'name' => 'WordPress'],
];
put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export($list_cfg, true) . ";\n");

// Titel und Dateinamen laufen bewusst gegeneinander, damit die Sortierung
// nach Seitenname nicht zufällig der Reihenfolge der Dateipfade entspricht.
$list_fixtures = [
    'liste-a.md' => "title: Zebra\nslug: liste-a\ncategories: seo\nlastEditedBy: ai\nlastEditedAt: \"2026-03-01T10:00:00+02:00\"\n",
    'liste-b.md' => "title: apfel\nslug: liste-b\ncategories: \"seo, wordpress, geloescht\"\nlastEditedBy: ai\nlastEditedAt: \"2026-05-01T08:30:00+02:00\"\n",
    'liste-c.md' => "title: Mango\nslug: liste-c\n",
    'liste-d.md' => "title: Kaputt\nslug: liste-d\nlastEditedAt: kein-datum\n",
];
foreach ($list_fixtures as $list_file => $list_source) {
    put($tmp_root . '/content/' . $list_file, "---\n" . $list_source . "---\n\nText.\n");
}
// Seiten ohne (lesbares) lastEditedAt zeigen die Dateizeit.
$list_mtime_c = mktime(12, 0, 0, 1, 15, 2026);
$list_mtime_d = mktime(9, 45, 0, 2, 20, 2026);
touch($tmp_root . '/content/liste-c.md', $list_mtime_c);
touch($tmp_root . '/content/liste-d.md', $list_mtime_d);

// Reihenfolge der Testseiten in der Tabelle, z.B. "abcd". Die übrigen Seiten
// des Wegwerf-Webroots bleiben aussen vor.
$list_order = static function (string $html): string {
    preg_match_all('~action=edit&file=liste-([a-d])\.md~', $html, $m);
    return implode('', $m[1]);
};

$list = request('GET', '/admin.php')['body'];
is_same($list_order($list), 'abcd', 'ohne sort bleibt die Reihenfolge der Dateipfade');
ok(str_contains($list, '01.05.2026 08:30'), 'lastEditedAt aus dem Frontmatter steht in der Spalte, in seiner eigenen Zeitzone');
ok(
    preg_match('~<span title="Dateizeit\.[^"]*">' . preg_quote(date('d.m.Y H:i', $list_mtime_c), '~') . '</span>~', $list) === 1,
    'ohne lastEditedAt erscheint die Dateizeit, am Tooltip als solche erkennbar'
);
ok(str_contains($list, date('d.m.Y H:i', $list_mtime_d)), 'ein unlesbares lastEditedAt fällt auf die Dateizeit zurück');
ok(str_contains($list, 'data-label="Kategorien">SEO &amp; Mehr, WordPress</td>'), 'Kategorien stehen mit Anzeigenamen in der Zeile, escaped, unbekannter Slug fehlt');
is_same(substr_count($list, 'name="delete_file"'), substr_count($list, 'name="filepath"'), 'jede Zeile hat einen Löschen-Button');
ok(preg_match('~name="delete_file"[^>]*data-confirm="Seite &quot;Zebra&quot; wirklich löschen\?~', $list) === 1, 'Löschen fragt vorher nach und nennt die Seite');
ok(!str_contains($list, 'aria-sort'), 'ohne sort ist keine Spalte als sortiert markiert');

group('Seitenübersicht: Sortierung');

$list = request('GET', '/admin.php?sort=name')['body'];
is_same($list_order($list), 'bdca', 'sort=name sortiert von A nach Z, ohne Rücksicht auf Gross-/Kleinschreibung');
ok(str_contains($list, 'aria-sort="ascending"'), 'die aktive Spalte trägt aria-sort');
ok(str_contains($list, 'href="admin.php?sort=name&amp;dir=desc"'), 'ein Klick auf die aktive Spalte dreht die Richtung');
ok(str_contains($list, 'href="admin.php?sort=date&amp;dir=desc"'), 'die andere Spalte beginnt mit ihrer Standardrichtung');

is_same($list_order(request('GET', '/admin.php?sort=name&dir=desc')['body']), 'acdb', 'sort=name&dir=desc sortiert von Z nach A');

$list = request('GET', '/admin.php?sort=date')['body'];
is_same($list_order($list), 'badc', 'sort=date zeigt ohne dir das Neueste zuerst');
ok(str_contains($list, 'aria-sort="descending"') && str_contains($list, 'href="admin.php?sort=date&amp;dir=asc"'), 'Datum absteigend ist markiert, der Link dreht auf aufsteigend');
is_same($list_order(request('GET', '/admin.php?sort=date&dir=asc')['body']), 'cdab', 'sort=date&dir=asc zeigt das Älteste zuerst');

$r = request('GET', '/admin.php?sort%5B%5D=name&dir%5B%5D=desc&category%5B%5D=seo');
is_same($r['status'], 200, 'Arrays als sort/dir/category ergeben keinen Serverfehler');
is_same($list_order($r['body']), 'abcd', 'Arrays als Parameter fallen auf die Standardansicht zurück');
ok(!str_contains($r['body'], 'Warning') && !str_contains($r['body'], 'Fatal error'), 'keine PHP-Meldung im Markup');
is_same($list_order(request('GET', '/admin.php?sort=quatsch&dir=seitwaerts')['body']), 'abcd', 'unbekannte Werte für sort/dir fallen auf die Standardansicht zurück');
is_same($list_order(request('GET', '/admin.php?sort=name&dir=seitwaerts')['body']), 'bdca', 'unbekanntes dir fällt auf die Standardrichtung der Spalte zurück');

group('Seitenübersicht: Filter nach Kategorie');

$list = request('GET', '/admin.php?category=wordpress')['body'];
is_same($list_order($list), 'b', 'category=wordpress zeigt nur die Seite dieser Kategorie');
ok(preg_match('~<h2>Seiten \(1 von \d+\)</h2>~', $list) === 1, 'die Überschrift nennt Treffer und Gesamtzahl');
ok(preg_match('~<option value="wordpress" selected>WordPress</option>~', $list) === 1, 'die gewählte Kategorie ist im Filter vorausgewählt');
ok(str_contains($list, 'href="admin.php?sort=name&amp;dir=asc&amp;category=wordpress"'), 'die Sortier-Links behalten den Filter');

$list = request('GET', '/admin.php?category=seo&sort=name&dir=desc')['body'];
is_same($list_order($list), 'ab', 'Filter und Sortierung wirken zusammen');
ok(
    str_contains($list, '<input type="hidden" name="sort" value="name">') && str_contains($list, '<input type="hidden" name="dir" value="desc">'),
    'das Filter-Formular behält die Sortierung'
);

$list = request('GET', '/admin.php?category=gibt-es-nicht')['body'];
is_same($list_order($list), 'abcd', 'eine unbekannte Kategorie filtert nicht');
ok(preg_match('~<h2>Seiten \(\d+\)</h2>~', $list) === 1, 'und die Überschrift zeigt nur die Gesamtzahl');

@unlink($tmp_root . '/content/liste-b.md');
$list = request('GET', '/admin.php?category=wordpress&sort=date')['body'];
ok(str_contains($list, 'Keine Seiten in dieser Kategorie.'), 'eine Kategorie ohne Seiten zeigt einen Hinweis statt einer leeren Tabelle');
ok(str_contains($list, 'href="admin.php?sort=date&amp;dir=desc"'), 'der Hinweis führt zurück zur ungefilterten Liste, die Sortierung bleibt');

group('Seitenübersicht: Löschen aus der Liste');

$r = request('POST', '/admin.php?category=seo&sort=name&dir=desc', ['csrf_token' => 'falsch', 'filepath' => 'liste-a.md', 'delete_file' => '1']);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'Löschen ohne gültiges CSRF-Token wird abgewiesen');
ok(content_file('liste-a.md') !== false, 'die Seite ist danach noch da');

$r = request('POST', '/admin.php?sort=name&dir=desc', ['csrf_token' => csrf_from($r['body']), 'filepath' => 'liste-a.md', 'delete_file' => '1']);
ok(str_contains($r['body'], 'Datei gelöscht: liste-a.md'), 'Löschen aus der Liste meldet Erfolg');
ok(content_file('liste-a.md') === false, 'die Datei ist weg');
is_same($list_order($r['body']), 'cd', 'die Liste erscheint danach wieder, ohne die gelöschte Seite und weiter sortiert');

// Aufräumen: Config und Testseiten zurück, spätere Gruppen erwarten den alten Stand.
put($tmp_root . '/config/config.php', $cfg_before_categories);
foreach (array_keys($list_fixtures) as $list_file) {
    @unlink($tmp_root . '/content/' . $list_file);
}

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_from'   => 'ohne-slash',
    'redirect_to'     => '/ziel',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'interner Pfad'), 'ungültige alte URL (ohne führenden "/") wird abgewiesen');
is_same(load_test_redirects($tmp_root), [], 'nichts wurde angelegt');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_from'   => '/home',
    'redirect_to'     => '/neu',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'verdecken'), 'Redirect auf eine noch existierende Seite ("home") wird abgewiesen');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_from'   => '/ki-entwurf',
    'redirect_to'     => '/neu',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'verdecken'), 'Kollisionsprüfung erfasst auch eine noch unveröffentlichte Seite ("ki-entwurf")');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_from'   => '/alte-kampagne',
    'redirect_to'     => '/home',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'Redirect angelegt'), 'gültiger Redirect wird angelegt');
$redirect_id = load_test_redirects($tmp_root)[0]['id'] ?? null;
ok($redirect_id !== null, 'Redirect steht in config/redirects.json');
is_same(find_test_user($tmp_root, 'admin@example.test')['email'] ?? null, 'admin@example.test', 'Sanity-Check: Admin-Fixture weiterhin unverändert'); // keine Seiteneffekte auf users.json

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_from'   => '/alte-kampagne',
    'redirect_to'     => '/anderes-ziel',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'Erst löschen'), 'zweiter Redirect mit derselben Quelle wird abgewiesen (kein Überschreiben)');
is_same(count(load_test_redirects($tmp_root)), 1, 'weiterhin nur ein Redirect vorhanden');

$redirects_page_after = request('GET', '/admin.php?action=redirects')['body'];
ok(str_contains($redirects_page_after, '/alte-kampagne') && str_contains($redirects_page_after, '/home'), 'die Tabelle zeigt den angelegten Redirect');

// Frontend-Routing (index.php): die alte URL liefert jetzt 301 statt 404.
$front_redirect = request('GET', '/index.php?slug=alte-kampagne', null, false);
is_same($front_redirect['status'], 301, 'index.php liefert 301 für die Redirect-Quelle');
is_same($front_redirect['location'], '/home', 'Location-Header zeigt auf das Redirect-Ziel');

$front_live_page = request('GET', '/index.php?slug=home', null, false);
is_same($front_live_page['status'], 200, 'eine lebende Seite bleibt von Redirects unberührt (Vorrang bestätigt)');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_id'     => $redirect_id,
    'delete_redirect' => '1',
]);
ok(str_contains($r['body'], 'Redirect gelöscht'), 'Löschen meldet Erfolg');
is_same(load_test_redirects($tmp_root), [], 'Redirect ist wirklich weg');

$front_after_delete = request('GET', '/index.php?slug=alte-kampagne', null, false);
is_same($front_after_delete['status'], 404, 'nach dem Löschen liefert die alte URL wieder 404');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $redirects_csrf,
    'redirect_id'     => $redirect_id,
    'delete_redirect' => '1',
]);
ok(str_contains($r['body'], 'nicht gefunden'), 'zweiter Löschversuch auf dieselbe id schlägt fehl');

$r = request('POST', '/admin.php', [
    'redirect_from'   => '/ohne-csrf',
    'redirect_to'     => '/ziel',
    'create_redirect' => '1',
]);
ok(str_contains($r['body'], 'Sicherheits-Token'), 'Anlegen ohne gültiges CSRF-Token wird abgewiesen');
is_same(load_test_redirects($tmp_root), [], 'kein Redirect ohne CSRF-Token angelegt');

group('Tab "Benutzer": Sperren/Entsperren, Löschen');

$creator_id = $creator['id'];
$r = request('POST', '/admin.php', [
    'csrf_token'         => $users_csrf,
    'user_id'            => $creator_id,
    'new_status'         => 'pausiert',
    'toggle_user_status' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Status'), 'unbekannter Status wird abgewiesen');
is_same(find_test_user($tmp_root, 'kollegin@example.test')['status'], 'aktiv', 'Status unverändert aktiv');

$r = request('POST', '/admin.php', [
    'csrf_token'         => $users_csrf,
    'user_id'            => $creator_id,
    'new_status'         => 'gesperrt',
    'toggle_user_status' => '1',
]);
ok(str_contains($r['body'], 'Status geändert'), 'Content Creator sperren meldet Erfolg');
is_same(find_test_user($tmp_root, 'kollegin@example.test')['status'], 'gesperrt', 'Status wirklich gesperrt');

// Die ANTWORT AUF DAS SPERREN SELBST muss den neuen Status zeigen: das
// Nutzer-Listing darunter rendert aus cms_load_users(), der Handler hat
// vorher geschrieben. Seit cms_load_users() memoisiert ist, hängt das an der
// Cache-Invalidierung in cms_users_write_locked() — ohne sie stünde in der
// Liste weiter "Sperren" statt "Entsperren" und kein "Gesperrt"-Badge.
ok(
    str_contains($r['body'], 'badge-draft">Gesperrt</span>') && str_contains($r['body'], 'Entsperren'),
    'Nutzer-Liste zeigt im selben Response schon den neuen Status (User-Cache invalidiert)'
);

// Spec 5.2/6.7: keine serverseitige Liste aktiver Sessions, die sich gezielt
// beenden ließe — aber die schon laufende Session wird spätestens bei ihrem
// nächsten Request abgewiesen, weil is_logged_in() Rolle/Status pro Request
// frisch nachschlägt statt nur beim Login.
$cookie = $creator_session_cookie;
$still_logged_in = request('GET', '/admin.php');
ok(
    str_contains($still_logged_in['body'], 'admin_password') && !str_contains($still_logged_in['body'], 'Seiten ('),
    'die schon laufende Session des gesperrten Content Creators wird beim nächsten Request abgewiesen (zurück zum Login-Formular)'
);
$cookie = $admin_cookie;

// Gesperrter Login: eigene, frische Session (sonst würde die bereits
// eingeloggte Session des Content Creators von oben weiterlaufen, siehe
// Konto-Lockout-Gruppe weiter oben zum selben Punkt).
$cookie = '';
$login_page  = request('GET', '/admin.php')['body'];
$login_csrf2 = csrf_from($login_page);
$blocked = request('POST', '/admin.php', [
    'admin_email'    => 'kollegin@example.test',
    'admin_password' => 'passwort-der-kollegin-123',
    'csrf_token'     => $login_csrf2,
]);
ok(str_contains($blocked['body'], 'E-Mail oder Passwort falsch'), 'gesperrter Content Creator kann sich nicht mehr einloggen — trotz korrektem Passwort, generische Meldung');
$cookie = $admin_cookie;

$r = request('POST', '/admin.php', [
    'csrf_token'         => $users_csrf,
    'user_id'            => $creator_id,
    'new_status'         => 'aktiv',
    'toggle_user_status' => '1',
]);
ok(str_contains($r['body'], 'Status geändert'), 'entsperren meldet Erfolg');

$r = request('POST', '/admin.php', [
    'csrf_token'  => $users_csrf,
    'user_id'     => $creator_id,
    'delete_user' => '1',
]);
ok(str_contains($r['body'], 'Nutzer gelöscht'), 'löschen meldet Erfolg');
ok(find_test_user($tmp_root, 'kollegin@example.test') === null, 'Content Creator wirklich weg');

$r = request('POST', '/admin.php', [
    'csrf_token'  => $users_csrf,
    'user_id'     => 'usr_existiertnicht',
    'delete_user' => '1',
]);
ok(str_contains($r['body'], 'nicht gefunden'), 'löschen einer unbekannten id liefert einen eigenen Fehler');

group('Tab "Benutzer": MCP-Token regenerieren');

$old_admin_hash = find_test_user($tmp_root, 'admin@example.test')['mcp_token_hash'];
$r = request('POST', '/admin.php', [
    'csrf_token'             => $users_csrf,
    'user_id'                => $admin_id,
    'regenerate_user_token'  => '1',
]);
ok(str_contains($r['body'], 'Neuer MCP-Token für admin@example.test'), 'Regenerieren meldet Erfolg mit E-Mail-Adresse');
ok(preg_match('#/mcp\.php/([0-9a-f]{64})#', $r['body']) === 1, 'Antwort enthält einen 32-Byte-Hex-Token als Link');
$new_admin_hash = find_test_user($tmp_root, 'admin@example.test')['mcp_token_hash'];
ok($new_admin_hash !== $old_admin_hash, 'mcp_token_hash hat sich wirklich geändert');

group('"Mein Profil": reduzierte Einstellungen-Ansicht für Content Creator');

request('POST', '/admin.php', [
    'csrf_token'   => $users_csrf,
    'invite_email' => 'profil-test@example.test',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
$profile_creator = find_test_user($tmp_root, 'profil-test@example.test');
ok($profile_creator !== null, 'zweiter Content Creator eigens für den Profil-Test angelegt');

$profile_admin_cookie = $cookie; // Admin-Session merken, gleich wechseln wir die Identität
$profile_token = write_user_token($tmp_root, $profile_creator['id']);

$cookie = ''; // frische, anonyme Sicht — wie ein Klick auf den Einladungs-Link
$set_pw_csrf = csrf_from(request('GET', '/admin.php?action=reset_password&token=' . $profile_token, null, false)['body']);
request('POST', '/admin.php?action=reset_password&token=' . $profile_token, [
    'csrf_token'           => $set_pw_csrf,
    'token'                => $profile_token,
    'new_password'         => 'profil-test-passwort-1',
    'new_password_confirm' => 'profil-test-passwort-1',
]);
$profile_password = 'profil-test-passwort-1'; // "Passwort festlegen" loggt automatisch ein, wie beim Reset

$nav = request('GET', '/admin.php')['body'];
ok(!str_contains($nav, 'action=settings'), 'kein Link "Einstellungen" (voller Zugriff) in der Nav');
ok(!str_contains($nav, 'action=ai'), 'kein Link "KI-Anbindung" in der Nav');
// Seit IRM-57 kein Sidebar-Link mehr (der ist admin-only), sondern das
// Profil-Icon oben in der Topbar — für jede Rolle gleich vorhanden.
ok(str_contains($nav, 'action=profile') && str_contains($nav, 'Mein Profil'), 'Profil-Icon/Link in der Topbar vorhanden');

$profile_page = request('GET', '/admin.php?action=profile')['body'];
ok(str_contains($profile_page, 'Mein Profil'), '"Mein Profil"-Seite zeigt sich selbst (seit IRM-57 für jede Rolle dieselbe Seite, nicht mehr nur reduziert)');
ok(str_contains($profile_page, 'profil-test@example.test'), 'eigene E-Mail-Adresse ist im Formular vorausgefüllt');
ok(!str_contains($profile_page, 'site_title'), 'Website-Felder tauchen auf "Mein Profil" gar nicht erst auf');
ok(!str_contains($profile_page, 'smtp_host'), 'SMTP-Felder tauchen auf "Mein Profil" gar nicht erst auf');
ok(!str_contains($profile_page, 'Logo &amp; Favicon'), 'die Sektion "Logo & Favicon" taucht auf "Mein Profil" ebenfalls gar nicht erst auf');
$profile_csrf = csrf_from($profile_page);

// Direktaufruf admin-only Routen durch den Content Creator -> 403,
// nicht nur die ausgeblendete Navigation.
is_same(request('GET', '/admin.php?action=ai')['status'], 403, 'Direktaufruf von action=ai durch Content Creator liefert 403');

// regenerate_ai_token gibt es seit der Benutzerverwaltung nicht mehr (siehe Tab
// "KI-Anbindung" oben) — kein rohes-POST-Angriffsziel mehr zu testen.

// Seit IRM-57 fünf getrennte Handler statt einem save_settings — jeder
// prüft require_role('settings') selbst zuerst (s.o.), hier alle fünf
// einzeln gegen den Content Creator durchgespielt.
$cfg_before_attack = (string) file_get_contents($tmp_root . '/config/config.php');
foreach ([
    ['save_website_settings', ['site_title' => 'Gehackt']],
    ['save_seo_settings', ['robots_txt' => 'Gehackt']],
    ['save_upload_settings', ['max_upload_mb' => '50']],
    ['save_security_settings', ['max_login_attempts' => '3']],
    ['save_contact_settings', ['contact_to_email' => 'gehackt@example.test']],
] as [$field, $extra]) {
    $r = request('POST', '/admin.php', $extra + [
        'csrf_token' => $profile_csrf,
        $field       => '1',
    ]);
    is_same($r['status'], 403, $field . ' per rohem POST wird abgewiesen, obwohl der Content Creator nur "Mein Profil" zu sehen bekommt');
}
is_same((string) file_get_contents($tmp_root . '/config/config.php'), $cfg_before_attack, 'config.php wirklich unverändert nach allen fünf Angriffsversuchen');

// Dieselbe Rollenprüfung für die vier Logo-/Favicon-Handler — ein
// Content Creator hat kein "settings"-Recht, gleiches
// Muster wie bei den übrigen Rollenprüfungen oben.
$r = upload_file('/admin.php', [
    'csrf_token'  => $profile_csrf,
    'upload_logo' => '1',
], ['logo' => ['name' => 'logo.png', 'type' => 'image/png', 'data' => $png_bytes]]);
is_same($r['status'], 403, 'upload_logo per rohem POST wird abgewiesen (Content Creator hat kein settings-Recht)');
ok(!is_file($tmp_root . '/assets/branding/logo.png'), 'kein Logo durch den abgewiesenen Upload gespeichert');

$r = request('POST', '/admin.php', ['csrf_token' => $profile_csrf, 'delete_logo' => '1']);
is_same($r['status'], 403, 'delete_logo per rohem POST wird abgewiesen');

$r = request('POST', '/admin.php', [
    'csrf_token'             => $profile_csrf,
    'site_logo_position'     => 'mittig',
    'site_logo_display'      => 'logo_only',
    'save_branding_settings' => '1',
]);
is_same($r['status'], 403, 'save_branding_settings per rohem POST wird abgewiesen');

// E-Mail ändern: verlangt das aktuelle Passwort zur Bestätigung
$r = request('POST', '/admin.php', [
    'csrf_token'        => $profile_csrf,
    'profile_email'     => 'neue-adresse@example.test',
    'current_password'  => 'falsches-passwort',
    'save_profile'      => '1',
]);
ok(str_contains($r['body'], 'Aktuelles Passwort ist falsch'), 'E-Mail-Änderung mit falschem aktuellem Passwort wird abgewiesen');
ok(find_test_user($tmp_root, 'profil-test@example.test') !== null, 'E-Mail in users.json unverändert');

$r = request('POST', '/admin.php', [
    'csrf_token'        => $profile_csrf,
    'profile_email'     => 'admin@example.test', // bereits vergeben
    'current_password'  => $profile_password,
    'save_profile'      => '1',
]);
ok(str_contains($r['body'], 'bereits vergeben'), 'E-Mail-Änderung auf eine bereits vergebene Adresse wird abgewiesen');

$r = request('POST', '/admin.php', [
    'csrf_token'        => $profile_csrf,
    'profile_email'     => 'neue-adresse@example.test',
    'current_password'  => $profile_password,
    'save_profile'      => '1',
]);
ok(str_contains($r['body'], 'Profil aktualisiert'), 'E-Mail-Änderung mit korrektem Passwort meldet Erfolg');
ok(find_test_user($tmp_root, 'neue-adresse@example.test') !== null, 'neue E-Mail-Adresse in users.json angekommen');
ok(find_test_user($tmp_root, 'profil-test@example.test') === null, 'alte E-Mail-Adresse ist weg');

// Passwort ändern — dieselben Regeln wie im vollen Einstellungen-Formular
$profile_page2 = request('GET', '/admin.php?action=profile')['body'];
$profile_csrf2 = csrf_from($profile_page2);
$r = request('POST', '/admin.php', [
    'csrf_token'            => $profile_csrf2,
    'current_password'      => $profile_password,
    'new_password'          => 'zu-kurz',
    'new_password_confirm'  => 'zu-kurz',
    'save_profile'          => '1',
]);
ok(str_contains($r['body'], 'mindestens 12 Zeichen'), 'zu kurzes neues Passwort wird abgewiesen');

$r = request('POST', '/admin.php', [
    'csrf_token'            => $profile_csrf2,
    'current_password'      => $profile_password,
    'new_password'          => 'neues-profil-passwort-1',
    'new_password_confirm'  => 'neues-profil-passwort-1',
    'save_profile'          => '1',
]);
ok(str_contains($r['body'], 'Profil aktualisiert'), 'Passwort ändern meldet Erfolg');
ok(password_verify('neues-profil-passwort-1', user_password_hash($tmp_root, 'neue-adresse@example.test')), 'neues Passwort wirklich gesetzt');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $profile_csrf2,
    'save_profile' => '1',
]);
ok(str_contains($r['body'], 'Keine Änderung eingegeben'), 'Speichern ganz ohne Eingabe meldet einen eigenen Fehler, kein stiller Erfolg');

// Eigenen MCP-Token regenerieren
$profile_page3 = request('GET', '/admin.php?action=profile')['body'];
ok(str_contains($profile_page3, 'Mein KI-Zugang'), 'reduzierte Ansicht zeigt den eigenen MCP-Zugang');
$old_profile_hash = $profile_creator['mcp_token_hash'];
$r = request('POST', '/admin.php', [
    'csrf_token'           => csrf_from($profile_page3),
    'regenerate_own_token' => '1',
]);
ok(str_contains($r['body'], 'Neuer MCP-Token: '), 'Regenerieren des eigenen Tokens meldet Erfolg mit Link');
ok(preg_match('#/mcp\.php/([0-9a-f]{64})#', $r['body']) === 1, 'Antwort enthält einen 32-Byte-Hex-Token als Link');
$new_profile_user = find_test_user($tmp_root, 'neue-adresse@example.test');
ok($new_profile_user['mcp_token_hash'] !== $old_profile_hash, 'mcp_token_hash des Content Creators hat sich wirklich geändert');

// Seite speichern als Content Creator: eigene E-Mail statt die
// des Admins, und überhaupt möglich — Entscheidung 6 kennt kein
// Ownership-Modell, alle Nutzer bearbeiten alle Seiten.
$cc_list = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'    => csrf_from($cc_list),
    'filepath'      => 'home.md',
    'meta_title'    => 'Startseite',
    'meta_slug'     => 'home',
    'meta_navLabel' => 'Start',
    'body'          => 'Von der Content Creator geändert.',
    'save_file'     => '1',
]);
ok(str_contains($r['body'], 'Datei gespeichert'), 'Content Creator kann Seiten speichern (kein Ownership-Modell, Entscheidung 6)');
ok(str_contains((string) content_file('home.md'), 'lastEditedBy: "neue-adresse@example.test"'), 'lastEditedBy trägt die E-Mail des Content Creators, nicht die des Admins');

group('Content Creator: Ordner, neue Seite, Bild wie der Admin (Entscheidung 6/7, Spec 3.1)');
// Kein require_role() in den Handlern für create_folder/save_file/delete_file/
// delete_image — Seiten, Ordner und Bilder sind laut Spec für beide Rollen
// identisch nutzbar. Hier wird das mit dem tatsächlichen Datenmodell bewiesen,
// nicht nur am Code abgelesen. home.md bleibt dabei unangetastet — spätere
// Tests brauchen die Fixture noch unverändert.

$r = request('POST', '/admin.php', [
    'csrf_token'    => csrf_from($cc_list),
    'folder_name'   => 'cc-ordner',
    'create_folder' => '1',
]);
ok(str_contains($r['body'], 'Ordner erstellt'), 'Content Creator kann einen Ordner anlegen');
ok(is_dir($tmp_root . '/content/cc-ordner'), 'Ordner liegt wirklich im Dateisystem');

$r = request('POST', '/admin.php', [
    'csrf_token' => csrf_from($cc_list),
    'filepath'   => 'cc-seite.md',
    'meta_title' => 'Von der Content Creator angelegt',
    'meta_slug'  => 'cc-seite',
    'body'       => 'Testinhalt.',
    'save_file'  => '1',
]);
ok(str_contains($r['body'], 'Datei gespeichert'), 'Content Creator kann eine neue Seite anlegen (nicht nur bestehende ändern)');
ok(is_file($tmp_root . '/content/cc-seite.md'), 'Datei liegt wirklich im Dateisystem');

$r = request('POST', '/admin.php', [
    'csrf_token'  => csrf_from($cc_list),
    'filepath'    => 'cc-seite.md',
    'delete_file' => '1',
]);
ok(str_contains($r['body'], 'Datei gelöscht'), 'Content Creator kann eine Seite wieder löschen');
clearstatcache(true, $tmp_root . '/content/cc-seite.md'); // is_file() cachet sonst den Stand von der Prüfung zwei Zeilen darüber
ok(!is_file($tmp_root . '/content/cc-seite.md'), 'Datei ist wirklich weg');

// Bild-Fixture direkt im Dateisystem angelegt (analog build_image_fixtures()
// in tests/mcp.php) — der Upload selbst braucht Multipart-Formulardaten, die
// request() hier nicht kennt; das ist eine eigene Baustelle. Fürs Löschen
// reicht ein normaler POST.
@mkdir($tmp_root . '/assets/images', 0777, true);
put($tmp_root . '/assets/images/cc-bild.png', base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
));
$r = request('POST', '/admin.php', [
    'csrf_token'   => csrf_from($cc_list),
    'image_name'   => 'cc-bild.png',
    'delete_image' => '1',
]);
ok(str_contains($r['body'], 'Bild gelöscht'), 'Content Creator kann ein Bild löschen (Entscheidung 5)');
ok(!is_file($tmp_root . '/assets/images/cc-bild.png'), 'Bilddatei ist wirklich weg');

$cookie = $profile_admin_cookie; // zurück zur Admin-Session für die restlichen Tests

group('Landingpage speichern: Whitelist-Fix in $frontmatter_keys');
// Ohne den Fix würden contentType/pageStyle bei jedem Backend-Speichern
// stillschweigend verloren gehen — das Admin-Formular schickt beide
// als eigene POST-Felder, $frontmatter_keys muss sie kennen.

$lp_list = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'       => csrf_from($lp_list),
    'file_folder'      => '',
    'file_name'        => 'admin-kampagne.md',
    'meta_contentType' => 'html',
    'meta_title'       => 'Admin-Kampagne',
    'meta_pageStyle'   => '.hero{background:#eee}',
    'body'             => '<section class="hero"><h1>Admin-HTML</h1></section>',
    'save_file'        => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-kampagne.md&created=1', 'Landingpage anlegen im Backend meldet Erfolg');

$lp_file = (string) content_file('admin-kampagne.md');
ok(str_contains($lp_file, 'contentType: html'), 'contentType: html steht im Frontmatter');
ok(str_contains($lp_file, 'pageStyle:'), 'pageStyle steht im Frontmatter');
ok(str_contains($lp_file, '<section class="hero"><h1>Admin-HTML</h1></section>'), 'der rohe HTML-Body landet unverändert in der Datei');

// Editor erneut öffnen: das versteckte Feld muss den bestehenden Wert tragen,
// sonst geht er beim nächsten Speichern verloren (genau der Bug aus Spec 0, v1.1).
$edit_page = request('GET', '/admin.php?action=edit&file=admin-kampagne.md')['body'];
ok(str_contains($edit_page, 'data-content-type="html"'), 'der Editor erkennt den Seitentyp beim erneuten Öffnen');
ok(str_contains($edit_page, 'name="meta_contentType" value="html"'), 'das versteckte Feld trägt den bestehenden Wert');
ok(str_contains($edit_page, '.hero{background:#eee}'), 'die pageStyle-Textarea ist mit dem bestehenden CSS vorbelegt');

// Erneutes Speichern (wie es das Formular mit dem versteckten Feld tatsächlich
// senden würde) darf contentType/pageStyle NICHT verlieren — das ist der
// eigentliche Beweis für den Whitelist-Fix.
$r = request('POST', '/admin.php', [
    'csrf_token'       => csrf_from($edit_page),
    'filepath'         => 'admin-kampagne.md',
    'meta_contentType' => 'html',
    'meta_title'       => 'Admin-Kampagne (bearbeitet)',
    'meta_pageStyle'   => '.hero{background:#eee}',
    'body'             => '<section class="hero"><h1>Admin-HTML bearbeitet</h1></section>',
    'save_file'        => '1',
]);
ok(str_contains($r['body'], 'Datei gespeichert'), 'erneutes Speichern meldet Erfolg');
$lp_file = (string) content_file('admin-kampagne.md');
ok(str_contains($lp_file, 'contentType: html'), 'contentType bleibt nach erneutem Speichern erhalten (Whitelist-Fix)');
ok(str_contains($lp_file, 'pageStyle:'), 'pageStyle bleibt nach erneutem Speichern erhalten (Whitelist-Fix)');

// Badge in der Seitenliste.
$list_after_lp = request('GET', '/admin.php')['body'];
ok(str_contains($list_after_lp, 'badge-landingpage'), 'die Landingpage trägt das Landingpage-Badge in der Liste');

// Frontend-Regression: kein page-content/content-text-Wrapper, Body roh.
$front = request('GET', '/index.php?slug=admin-kampagne');
is_same($front['status'], 200, 'die Landingpage ist im Frontend erreichbar');
ok(str_contains($front['body'], 'landingpage-content'), 'index.php rendert ohne den Markdown-Wrapper');
// Als CSS-Klasse geprüft, nicht als reiner Teilstring — "page-content" steckt
// als Substring in "landingpage-content" mit drin, "content-text" im
// erklärenden HTML-Kommentar von index.php selbst.
ok(!str_contains($front['body'], 'class="page-content'), 'kein page-content-Wrapper im Markup');
ok(!str_contains($front['body'], 'class="content-text'), 'kein content-text-Wrapper im Markup');
ok(str_contains($front['body'], '<section class="hero"><h1>Admin-HTML bearbeitet</h1></section>'), 'der HTML-Body erscheint unverändert im Frontend');

// Regressions-Gegenprobe: eine normale Markdown-Seite bekommt weiterhin kein
// contentType im Frontmatter (kein Rauschen für den unveränderten Fall).
$r = request('POST', '/admin.php', [
    'csrf_token'       => csrf_from($list_after_lp),
    'file_folder'      => '',
    'file_name'        => 'admin-normal.md',
    'meta_contentType' => '',
    'meta_title'       => 'Normale Seite',
    'body'             => 'Hallo Welt.',
    'save_file'        => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-normal.md&created=1', 'eine normale Markdown-Seite speichert weiterhin');
ok(!str_contains((string) content_file('admin-normal.md'), 'contentType'), 'contentType bleibt bei Markdown-Seiten unerwähnt');

group("CSP: Admin ohne 'unsafe-inline' in style-src");
// Die Admin-CSP erlaubt keine Inline-Styles. Jedes style-Attribut im Markup
// wäre im Browser blockiert und die Oberfläche an der Stelle kaputt, ohne
// dass ein HTTP-Status das zeigt. Deshalb jede Admin-Seite einzeln prüfen.
$csp_pages = [
    '/admin.php', '/admin.php?action=images', '/admin.php?action=new',
    '/admin.php?action=edit&file=admin-kampagne.md', '/admin.php?action=site',
    '/admin.php?action=site&tab=seo', '/admin.php?action=redirects', '/admin.php?action=settings',
    '/admin.php?action=profile', '/admin.php?action=users', '/admin.php?action=ai',
    '/admin.php?action=updates',
];
foreach ($csp_pages as $csp_path) {
    $r   = request('GET', $csp_path);
    $csp = (string) header_value($r, 'Content-Security-Policy');
    ok(
        str_contains($r['body'], 'admin-shell')
            && preg_match("/style-src [^;]*'nonce-/", $csp) === 1
            && !str_contains($csp, "'unsafe-inline'")
            && !str_contains($r['body'], 'style="'),
        "{$csp_path}: CSP ohne 'unsafe-inline', kein style-Attribut im Markup"
    );
}
$r = request('GET', '/index.php?slug=home');
ok(
    str_contains((string) header_value($r, 'Content-Security-Policy'), "'unsafe-inline'"),
    "Frontend behält 'unsafe-inline' (pageStyle, Landingpage-HTML)"
);

group('Landingpage-Vorschau im Editor');
// Die Vorschau kommt per POST vom Server statt als srcdoc: ein srcdoc-Dokument
// erbt die Admin-CSP, und die erlaubt keine Inline-Styles. Geprüft wird, dass
// die Antwort ihre eigene, enge CSP trägt und nur mit gültigem Token kommt.

$edit_page = request('GET', '/admin.php?action=edit&file=admin-kampagne.md')['body'];
ok(str_contains($edit_page, 'id="landingpage-preview-form"'), 'der Editor enthält das Vorschau-Formular');
ok(!str_contains($edit_page, '.srcdoc ='), 'der Editor baut die Vorschau nicht mehr als srcdoc');

$r = request('POST', '/admin.php?action=preview_landingpage', [
    'csrf_token' => csrf_from($edit_page),
    'body'       => '<div class="hero" style="color:red">Vorschau</div><script>alert(1)</script>',
    'page_style' => '.hero{padding:2rem}</style><b>',
]);
is_same($r['status'], 200, 'die Vorschau antwortet mit 200');
ok(str_contains($r['body'], '<div class="hero" style="color:red">Vorschau</div>'), 'der HTML-Body steht unverändert in der Vorschau');
ok(str_contains($r['body'], '<style>.hero{padding:2rem}<\/style><b></style>'), 'ein </style> im pageStyle wird entschärft');
ok(!str_contains($r['body'], 'admin-shell'), 'die Vorschau ist ein eigenes Dokument, nicht die Admin-Seite');
$csp = (string) header_value($r, 'Content-Security-Policy');
ok(str_contains($csp, "style-src 'self'") && str_contains($csp, "'unsafe-inline'"), 'Vorschau-CSP erlaubt Inline-Styles wie live');
ok(str_contains($csp, "default-src 'none'") && !str_contains($csp, 'script-src'), 'Vorschau-CSP erlaubt keine Skripte');
ok(str_contains($csp, 'sandbox'), 'Vorschau-CSP setzt sandbox');
ok(str_contains($csp, "frame-ancestors 'self'"), 'Vorschau darf nur in der eigenen Seite eingebettet werden');
is_same(header_value($r, 'X-Frame-Options'), 'SAMEORIGIN', 'X-Frame-Options passt zu frame-ancestors');

is_same(request('GET', '/admin.php?action=preview_landingpage')['status'], 405, 'GET auf die Vorschau wird abgewiesen');
$r = request('POST', '/admin.php?action=preview_landingpage', ['csrf_token' => 'falsch', 'body' => '<p>x</p>']);
is_same($r['status'], 403, 'ohne gültiges Token keine Vorschau');
ok(!str_contains($r['body'], '<p>x</p>'), 'ohne gültiges Token wird der Body nicht ausgegeben');
// Die Antwort ohne Session setzt ein neues Session-Cookie, request() würde es
// übernehmen und die folgenden Tests liefen ausgeloggt.
$cookie_before = $cookie;
$r = request('POST', '/admin.php?action=preview_landingpage', ['body' => '<p>x</p>'], false);
$cookie = $cookie_before;
ok(!str_contains($r['body'], '<p>x</p>') && str_contains($r['body'], 'admin_password'), 'ohne Session kommt das Login-Formular statt der Vorschau');

group('robots.txt');
// Der Testwebroot lässt den Config-Schlüssel robots_txt bewusst weg (siehe
// build_webroot()) — dieser erste Aufruf prüft also den Fallback in
// index.php, bevor überhaupt einmal gespeichert wurde.
$r = request('GET', '/index.php?slug=robots.txt', null, false);
is_same($r['status'], 200, '/robots.txt liefert 200, auch ohne gesetzten Config-Schlüssel');
is_same($r['body'], "User-agent: *\nAllow: /\n", 'Fallback-Inhalt entspricht dem Default aus config.example.php');

group('Canonical-Feld im Editor');
// admin-normal.md wurde oben ohne meta_canonical angelegt — Default bleibt
// automatisch aus SITE_URL + Slug, kein Override im Frontmatter.
ok(!str_contains((string) content_file('admin-normal.md'), 'canonical'), 'ohne Eingabe landet kein canonical-Schlüssel im Frontmatter');
$front_auto = request('GET', '/index.php?slug=admin-normal')['body'];
ok(str_contains($front_auto, '<link rel="canonical" href="') && str_contains($front_auto, '/admin-normal">'), 'ohne Override zeigt die Canonical-URL weiterhin automatisch auf SITE_URL + Slug');

// Jetzt mit gesetztem Override speichern — muss den automatischen Wert
// überschreiben, nicht nur ergänzen.
$list_before_canonical = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'     => csrf_from($list_before_canonical),
    'file_folder'    => '',
    'file_name'      => 'admin-canonical.md',
    'meta_title'     => 'Seite mit Canonical-Override',
    'meta_canonical' => 'https://example.com/mein-canonical-ziel',
    'body'           => 'Hallo Welt.',
    'save_file'      => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-canonical.md&created=1', 'Seite mit Canonical-Override speichert erfolgreich');
// Der Doppelpunkt in der URL löst cms_build_frontmatter()s Quoting-Regel aus
// (":" gehört zu den Sonderzeichen) — der Wert landet deshalb in Anführungszeichen.
ok(str_contains((string) content_file('admin-canonical.md'), 'canonical: "https://example.com/mein-canonical-ziel"'), 'der Override steht unverändert (gequotet) im Frontmatter');

$front_override = request('GET', '/index.php?slug=admin-canonical')['body'];
ok(str_contains($front_override, '<link rel="canonical" href="https://example.com/mein-canonical-ziel">'), 'das Frontend übernimmt den Override statt der automatischen Canonical-URL');

// Editor erneut öffnen: das Feld muss mit dem bestehenden Override vorbelegt
// sein, sonst geht er beim nächsten Speichern verloren (analog title/description).
$edit_canonical = request('GET', '/admin.php?action=edit&file=admin-canonical.md')['body'];
ok(str_contains($edit_canonical, 'name="meta_canonical" value="https://example.com/mein-canonical-ziel"'), 'der Editor zeigt den bestehenden Override beim erneuten Öffnen');

group('Open Graph / Twitter Card');
// Fallback: weder ogTitle/ogDescription/ogImage gesetzt, aber description und
// headerImage vorhanden — og:title/og:description/og:image müssen automatisch
// auf title/description/headerImage zurückfallen.
$list_before_og = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'        => csrf_from($list_before_og),
    'file_folder'       => '',
    'file_name'         => 'admin-og-fallback.md',
    'meta_title'        => 'Fallback-Seite',
    'meta_description'  => 'Fallback-Beschreibung',
    'meta_headerImage'  => 'assets/images/fallback.jpg',
    'body'              => 'Hallo Welt.',
    'save_file'         => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-og-fallback.md&created=1', 'Seite ohne og-Felder speichert erfolgreich');
$og_fallback_file = (string) content_file('admin-og-fallback.md');
ok(!str_contains($og_fallback_file, 'ogTitle') && !str_contains($og_fallback_file, 'ogDescription') && !str_contains($og_fallback_file, 'ogImage'), 'ohne Eingabe landet kein og*-Schlüssel im Frontmatter');

$front_og_fallback = request('GET', '/index.php?slug=admin-og-fallback')['body'];
ok(str_contains($front_og_fallback, '<meta property="og:title" content="Fallback-Seite">'), 'og:title fällt auf title zurück');
ok(str_contains($front_og_fallback, '<meta property="og:description" content="Fallback-Beschreibung">'), 'og:description fällt auf description zurück');
ok(str_contains($front_og_fallback, '<meta property="og:image" content="' . $base_url . '/assets/images/fallback.jpg">'), 'og:image fällt auf headerImage zurück, als absolute URL');
ok(str_contains($front_og_fallback, '<meta property="og:url" content="' . $base_url . '/admin-og-fallback">'), 'og:url entspricht der Canonical-URL');
ok(str_contains($front_og_fallback, '<meta property="og:type" content="website">'), 'og:type ist gesetzt');
ok(str_contains($front_og_fallback, '<meta name="twitter:card" content="summary_large_image">'), 'twitter:card ist gesetzt, ohne eigene twitter:title/description/image-Tags');
ok(!str_contains($front_og_fallback, 'twitter:title') && !str_contains($front_og_fallback, 'twitter:image'), 'keine eigenen Twitter-Felder — Twitter/X liest og:* mit');

// Override: alle drei og-Felder gesetzt, weichen bewusst von title/description/
// headerImage ab — das Frontend muss die og-Werte nutzen, nicht die Fallbacks.
$list_before_og2 = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'         => csrf_from($list_before_og2),
    'file_folder'        => '',
    'file_name'          => 'admin-og-override.md',
    'meta_title'         => 'Normaler Titel',
    'meta_description'   => 'Normale Beschreibung',
    'meta_headerImage'   => 'assets/images/normal.jpg',
    'meta_ogTitle'       => 'OG-Titel abweichend',
    'meta_ogDescription' => 'OG-Beschreibung abweichend',
    'meta_ogImage'       => 'assets/images/og-abweichend.jpg',
    'body'               => 'Hallo Welt.',
    'save_file'          => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-og-override.md&created=1', 'Seite mit og-Override speichert erfolgreich');

$front_og_override = request('GET', '/index.php?slug=admin-og-override')['body'];
ok(str_contains($front_og_override, '<meta property="og:title" content="OG-Titel abweichend">'), 'og:title nutzt den Override, nicht title');
ok(str_contains($front_og_override, '<meta property="og:description" content="OG-Beschreibung abweichend">'), 'og:description nutzt den Override, nicht description');
ok(str_contains($front_og_override, '<meta property="og:image" content="' . $base_url . '/assets/images/og-abweichend.jpg">'), 'og:image nutzt den Override, nicht headerImage');

// Editor erneut öffnen: die og-Felder müssen vorbelegt sein, sonst gehen sie
// beim nächsten Speichern verloren (analog canonical).
$edit_og = request('GET', '/admin.php?action=edit&file=admin-og-override.md')['body'];
ok(str_contains($edit_og, 'name="meta_ogTitle" value="OG-Titel abweichend"'), 'der Editor zeigt den bestehenden ogTitle beim erneuten Öffnen');
ok(str_contains($edit_og, 'name="meta_ogDescription" value="OG-Beschreibung abweichend"'), 'der Editor zeigt die bestehende ogDescription beim erneuten Öffnen');
ok(str_contains($edit_og, 'name="meta_ogImage" value="assets/images/og-abweichend.jpg"'), 'der Editor zeigt das bestehende ogImage beim erneuten Öffnen');

// Editor-Aufbau: Seitentitel und Inhalt stehen vor den Einstellungen, die
// Einstellungen liegen in zwei Akkordeon-Bereichen, nur der erste ist offen.
$new_page = request('GET', '/admin.php?action=new')['body'];
foreach ([$new_page, $edit_og] as $i => $html) {
    $label = $i === 0 ? 'Neue Seite' : 'Seite bearbeiten';
    $pos_title    = strpos($html, 'name="meta_heading"');
    $pos_body     = strpos($html, 'name="body"');
    $pos_settings = strpos($html, 'class="settings-accordion"');
    ok($pos_title !== false && $pos_body !== false && $pos_settings !== false, "$label: Titel, Inhalt und Einstellungen sind vorhanden");
    ok($pos_title < $pos_body && $pos_body < $pos_settings, "$label: Reihenfolge Seitentitel, Inhalt, Einstellungen");
    ok(str_contains($html, '<label for="meta_heading">Seitentitel</label>'), "$label: das H1-Feld heisst Seitentitel");
    ok(substr_count($html, '<details class="settings-accordion"') === 2, "$label: zwei Akkordeon-Bereiche");
    ok(preg_match('~<details class="settings-accordion" open>\s*<summary>Navigation &amp; Bild</summary>~', $html) === 1, "$label: Navigation & Bild ist ausgeklappt");
    ok(preg_match('~<details class="settings-accordion">\s*<summary>SEO &amp; Metadaten</summary>~', $html) === 1, "$label: SEO & Metadaten ist eingeklappt");
}
ok(str_contains($new_page, 'name="file_name"') && str_contains($new_page, 'name="meta_slug"') && str_contains($new_page, 'name="meta_title"'), 'Neue Seite: Dateiname, Slug und HTML-Title bleiben als Felder erhalten');

// Seite ganz ohne description/headerImage/og-Felder (admin-normal.md aus der
// Landingpage-Gruppe weiter oben, $front_auto aus der Canonical-Gruppe): dann
// müssen og:description/og:image komplett fehlen statt leerer content=""-Tags.
// Auf das konkrete Meta-Tag geprüft, nicht auf den bloßen Teilstring — der
// erklärende HTML-Kommentar zu Twitter/X in index.php enthält "og:description"
// und "og:image" ebenfalls als Text.
ok(!str_contains($front_auto, '<meta property="og:description"') && !str_contains($front_auto, '<meta property="og:image"'), 'ohne description/headerImage bleiben og:description/og:image ganz weg');
ok(str_contains($front_auto, '<meta property="og:title" content="Normale Seite">'), 'og:title fällt auch hier auf title zurück');

group('Lazy Loading für Bilder');
// Header-Bild vorhanden: es bleibt selbst eager (kein loading-Attribut) — alle
// Bilder im Fliesstext, auch das erste, bekommen loading="lazy", weil das
// Header-Bild bereits die Rolle "erstes sichtbares Bild der Seite" übernimmt.
$list_before_lazy = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'       => csrf_from($list_before_lazy),
    'file_folder'      => '',
    'file_name'        => 'admin-lazy-mit-header.md',
    'meta_title'       => 'Lazy mit Header',
    'meta_headerImage' => 'assets/images/lazy-header.jpg',
    'body'             => "![Erstes Textbild](assets/images/lazy1.jpg)\n\nText.\n\n![Zweites Textbild](assets/images/lazy2.jpg)",
    'save_file'        => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-lazy-mit-header.md&created=1', 'Seite mit Header-Bild und zwei Textbildern speichert erfolgreich');

$front_lazy_header = request('GET', '/index.php?slug=admin-lazy-mit-header')['body'];
ok(str_contains($front_lazy_header, '<img src="assets/images/lazy-header.jpg" alt="Lazy mit Header">'), 'das Header-Bild selbst bleibt ohne loading-Attribut (eager)');
ok(str_contains($front_lazy_header, '<img src="assets/images/lazy1.jpg" alt="Erstes Textbild" loading="lazy">'), 'erstes Textbild ist trotzdem lazy, das Header-Bild deckt die Eager-Rolle bereits ab');
ok(str_contains($front_lazy_header, '<img src="assets/images/lazy2.jpg" alt="Zweites Textbild" loading="lazy">'), 'zweites Textbild ist ebenfalls lazy');

// Kein Header-Bild: das erste tatsächlich gerenderte Bild im Fliesstext
// übernimmt die Eager-Rolle, jedes weitere ist lazy. Ein vorangestelltes Bild
// mit unsicherem Schema darf die Rolle nicht verbrauchen — es wird ja gar
// nicht als <img> gerendert (bestehendes Verhalten, siehe is_safe_url()).
$list_before_lazy2 = request('GET', '/admin.php')['body'];
$r = request('POST', '/admin.php', [
    'csrf_token'  => csrf_from($list_before_lazy2),
    'file_folder' => '',
    'file_name'   => 'admin-lazy-ohne-header.md',
    'meta_title'  => 'Lazy ohne Header',
    'body'        => "![Unsicher](javascript:alert(1))\n\nText.\n\n![Erstes Textbild](assets/images/lazy1.jpg)\n\nText.\n\n![Zweites Textbild](assets/images/lazy2.jpg)",
    'save_file'   => '1',
]);
is_same($r['location'], 'admin.php?action=edit&file=admin-lazy-ohne-header.md&created=1', 'Seite ohne Header-Bild speichert erfolgreich');

$front_lazy_no_header = request('GET', '/index.php?slug=admin-lazy-ohne-header')['body'];
ok(!str_contains($front_lazy_no_header, '<img src="javascript:alert'), 'das unsichere Schema wird gar nicht erst als <img> gerendert');
ok(str_contains($front_lazy_no_header, '<img src="assets/images/lazy1.jpg" alt="Erstes Textbild">'), 'ohne Header-Bild bleibt das erste echte Textbild eager (kein loading-Attribut)');
ok(str_contains($front_lazy_no_header, '<img src="assets/images/lazy2.jpg" alt="Zweites Textbild" loading="lazy">'), 'das zweite Textbild ist lazy');

group('Sitemap: HTTP-Ausgabe');
// cms_build_sitemap_xml() selbst ist bereits als reine Funktion in
// tests/roundtrip.php (Gruppe 8) abgedeckt (noindex-Ausschluss, Default ohne
// robots-Feld, lastmod, XML-Escaping). Hier nur der HTTP-Weg über index.php:
// dass /sitemap.xml wirklich mit echten, im Wegwerf-Webroot angelegten Seiten
// antwortet.
$sitemap_resp = request('GET', '/index.php?slug=sitemap.xml', null, false);
is_same($sitemap_resp['status'], 200, '/sitemap.xml liefert 200');
ok(str_starts_with($sitemap_resp['body'], '<?xml version="1.0" encoding="UTF-8"?>'), 'Antwort ist eine XML-Sitemap');
ok(str_contains($sitemap_resp['body'], '<loc>' . $base_url . '/admin-normal</loc>'), 'enthält eine zuvor angelegte, veröffentlichte Seite');

group('Custom-404-Seite');
// Ohne content/404.md: unbekannter Slug liefert den bisherigen generischen
// Text, und "404" selbst ist als Slug schon jetzt reserviert (kein passender
// Content-Index-Eintrag existiert ja ohnehin).
$unknown_before = request('GET', '/index.php?slug=voellig-unbekannte-seite-xyz', null, false);
is_same($unknown_before['status'], 404, 'unbekannter Slug liefert Status 404 (ohne content/404.md)');
ok(str_contains($unknown_before['body'], 'Die angeforderte Seite existiert leider nicht.'), 'generischer Fallback-Text ohne content/404.md');
$direct_404_before = request('GET', '/index.php?slug=404', null, false);
is_same($direct_404_before['status'], 404, 'Direktaufruf von /404 liefert Status 404 (ohne content/404.md)');

// content/404.md direkt ins Dateisystem geschrieben, nicht über den Editor —
// analog build_image_fixtures() in tests/mcp.php: ein Fehler im
// Editor-Speicherpfad soll diese Tests nicht mitreissen. Bewusst mit
// draft: true, weil genau das die Spec-Vorgabe ist ("unabhängig vom
// Frontmatter-Inhalt, z. B. draft").
put($tmp_root . '/content/404.md', <<<MD
---
title: "Individuelle 404-Seite"
description: "Eigene Beschreibung für die 404-Seite"
draft: true
---
Dieser Text kommt aus content/404.md.
MD);

$unknown_after = request('GET', '/index.php?slug=voellig-unbekannte-seite-xyz', null, false);
is_same($unknown_after['status'], 404, 'unbekannter Slug bleibt Status 404 (mit content/404.md)');
ok(str_contains($unknown_after['body'], '<title>Individuelle 404-Seite</title>'), 'individueller Titel erscheint, obwohl content/404.md als Entwurf markiert ist');
ok(str_contains($unknown_after['body'], 'Dieser Text kommt aus content/404.md.'), 'individueller Body erscheint');

// Der reservierte Slug ist der eigentliche Kern der Aufgabe: ein Direktaufruf
// von /404 darf NIE 200 liefern, auch wenn content/404.md (wäre sie kein
// Entwurf) sonst ganz normal im Content-Index stünde.
$direct_404_after = request('GET', '/index.php?slug=404', null, false);
is_same($direct_404_after['status'], 404, 'Direktaufruf von /404 bleibt Status 404, trotz vorhandener content/404.md');
ok(str_contains($direct_404_after['body'], 'Individuelle 404-Seite'), 'zeigt denselben individuellen Inhalt wie der Fallback-Fall');

// Sitemap-Ausschluss: content/404.md hat kein robots-Feld (Default
// index/follow) und ist damit der Beweis, dass der Ausschluss am reservierten
// Slug hängt, nicht am robots-Wert.
$sitemap_after_404 = request('GET', '/index.php?slug=sitemap.xml', null, false);
ok(!str_contains($sitemap_after_404['body'], '/404</loc>'), 'content/404.md erscheint trotz index/follow-Default nicht in der Sitemap');

group('Mein Profil: eigenes Passwort ändern (Regressionstest)');
// Vor der Benutzerverwaltung landete ein hier geändertes Passwort in
// config/config.php ('admin_password_hash') — seit der Migration wird das
// Feld für den Login nicht mehr gelesen, die Änderung wäre also
// stillschweigend wirkungslos gewesen. Läuft seit IRM-57 über "Mein Profil"
// (save_profile), nicht mehr über die Einstellungen — die fünf seither
// getrennten Handler (save_website_settings usw.) berühren einander nicht
// mehr, ein gemeinsames "alle anderen Felder mitschicken" wie früher ist
// deshalb nicht mehr nötig.

$profile_csrf = csrf_from(request('GET', '/admin.php?action=profile')['body']);

$r = request('POST', '/admin.php', [
    'csrf_token'            => $profile_csrf,
    'profile_email'         => '',
    'current_password'      => 'falsches-passwort',
    'new_password'          => 'neues-settings-passwort-1',
    'new_password_confirm'  => 'neues-settings-passwort-1',
    'save_profile'          => '1',
]);
ok(str_contains($r['body'], 'Aktuelles Passwort ist falsch'), 'falsches aktuelles Passwort wird abgewiesen');
ok(password_verify($password, user_password_hash($tmp_root, 'admin@example.test')), 'Passwort in users.json unverändert');
$profile_csrf = csrf_from($r['body']);

$r = request('POST', '/admin.php', [
    'csrf_token'            => $profile_csrf,
    'profile_email'         => '',
    'current_password'      => $password,
    'new_password'          => 'neues-settings-passwort-1',
    'new_password_confirm'  => 'neues-settings-passwort-1',
    'save_profile'          => '1',
]);
ok(str_contains($r['body'], 'Profil aktualisiert'), 'Passwort ändern über "Mein Profil" meldet Erfolg');
ok(
    password_verify('neues-settings-passwort-1', user_password_hash($tmp_root, 'admin@example.test')),
    'neues Passwort landet in users.json (vor dem Fix landete es fälschlich in config.php)'
);
ok(!password_verify($password, user_password_hash($tmp_root, 'admin@example.test')), 'altes Passwort greift nicht mehr');
$password = 'neues-settings-passwort-1'; // ab hier die aktuell gültige Wahrheit für alle folgenden Tests

group('Website & SEO: robots.txt (Reiter "SEO")');
// Seit IRM-57 ein eigener Handler (save_seo_settings) auf einer eigenen
// Seite (action=site&tab=seo) — anders als früher reicht es, nur die
// SEO-eigenen Felder zu schicken, Website-/Upload-/Sicherheits-/Kontakt-
// Einstellungen bleiben davon unberührt.
$seo_csrf = csrf_from(request('GET', '/admin.php?action=site&tab=seo')['body']);
$seo_base = [
    'csrf_token'                   => $seo_csrf,
    'robots_txt'                   => "User-agent: *\nDisallow: /privat\n",
    'search_console_verification'  => 'abcDEF123-_.=456',
    'save_seo_settings'            => '1',
];

// Ziel-URL mit ?action=site&tab=seo statt nur /admin.php: $site_tab kommt aus
// $_GET, und das Formular im Browser postet ohne action="" auf genau die URL
// zurück, auf der es steht. Nur so rendert die Antwort wieder den SEO-Reiter
// und nicht den Website-Reiter.
$r = request('POST', '/admin.php?action=site&tab=seo', $seo_base);
ok(str_contains($r['body'], 'SEO-Einstellungen gespeichert'), 'SEO-Einstellungen speichern meldet Erfolg');
$seo_base['csrf_token'] = csrf_from($r['body']);

// Die ANTWORT AUF DAS SPEICHERN SELBST muss den neuen Wert zeigen, nicht den
// vorherigen. Handler und Formular lesen beide load_config(), letzteres erst
// nach save_config() — seit load_config() memoisiert ist, hängt genau das an
// der Cache-Invalidierung in save_config(). Ohne sie stünde hier noch der
// alte Stand im Textfeld, obwohl "gespeichert" darüber steht.
ok(
    str_contains($r['body'], "User-agent: *\nDisallow: /privat</textarea>"),
    'Formular zeigt im selben Response schon den frisch gespeicherten Wert (Config-Cache invalidiert)'
);

// robots.txt aus demselben Aufruf: jetzt über /robots.txt abrufbar. trim()
// im Handler entfernt eine abschließende Leerzeile bewusst mit — erwarteter
// Wert deshalb ohne trailing \n.
$r = request('GET', '/index.php?slug=robots.txt', null, false);
is_same($r['body'], "User-agent: *\nDisallow: /privat", 'geänderte robots.txt wird sofort unter /robots.txt ausgeliefert');

// Längenlimit: wird abgewiesen, die zuletzt gespeicherte robots.txt bleibt stehen.
$r = request('POST', '/admin.php', array_merge($seo_base, [
    'robots_txt' => str_repeat('a', 5001),
]));
ok(str_contains($r['body'], 'höchstens 5000 Zeichen'), 'zu lange robots.txt wird abgewiesen');
$seo_base['csrf_token'] = csrf_from($r['body']);
$r = request('GET', '/index.php?slug=robots.txt', null, false);
is_same($r['body'], "User-agent: *\nDisallow: /privat", 'nach der Ablehnung liefert /robots.txt weiter den vorherigen Inhalt');

group('Search-Console-Verifizierungscode');
// $seo_base trägt seit dem letzten save_seo_settings-Aufruf oben bereits
// 'abcDEF123-_.=456' — hier zuerst geprüft, dass der Wert tatsächlich
// als Meta-Tag im <head> ankommt (index.php liest ihn vor dem eigenen
// Content-Zweig, deshalb reicht eine beliebige existierende Seite).
$front_sc = request('GET', '/index.php?slug=admin-normal', null, false)['body'];
ok(
    str_contains($front_sc, '<meta name="google-site-verification" content="abcDEF123-_.=456">'),
    'gespeicherter Code erscheint als Meta-Tag im <head>'
);

// Komfort-Fall: der komplette, von Google zum Kopieren angebotene <meta>-Tag
// wird eingefügt statt nur des Codes — der Handler extrahiert automatisch
// den content-Wert.
$r = request('POST', '/admin.php', array_merge($seo_base, [
    'search_console_verification' => '<meta name="google-site-verification" content="ganzer-tag-code-999" />',
]));
ok(str_contains($r['body'], 'SEO-Einstellungen gespeichert'), 'ein kompletter <meta>-Tag als Eingabe wird angenommen');
$seo_base['csrf_token'] = csrf_from($r['body']);
$front_sc = request('GET', '/index.php?slug=admin-normal', null, false)['body'];
ok(
    str_contains($front_sc, '<meta name="google-site-verification" content="ganzer-tag-code-999">'),
    'nur der extrahierte content-Wert landet im ausgegebenen Meta-Tag, nicht der ganze eingefügte Tag'
);

// Ungültige Zeichen (z.B. Leerzeichen/Anführungszeichen ohne erkennbares
// content=-Muster) werden abgewiesen, der zuletzt gespeicherte Wert bleibt
// unverändert stehen — gleiches Muster wie beim robots.txt-Längenlimit oben.
$r = request('POST', '/admin.php', array_merge($seo_base, [
    'search_console_verification' => 'ungültiger code mit leerzeichen',
]));
ok(str_contains($r['body'], 'Ungültiger Search-Console-Verifizierungscode'), 'Code mit unerlaubten Zeichen wird abgewiesen');
$seo_base['csrf_token'] = csrf_from($r['body']);
$front_sc = request('GET', '/index.php?slug=admin-normal', null, false)['body'];
ok(
    str_contains($front_sc, '<meta name="google-site-verification" content="ganzer-tag-code-999">'),
    'nach der Ablehnung bleibt weiter der zuvor gespeicherte Code im Meta-Tag stehen'
);

// Leerer Wert entfernt das Meta-Tag wieder komplett, statt ein leeres
// content="" auszugeben.
$r = request('POST', '/admin.php', array_merge($seo_base, [
    'search_console_verification' => '',
]));
ok(str_contains($r['body'], 'SEO-Einstellungen gespeichert'), 'ein leerer Code wird als gültige Eingabe akzeptiert (Feld leeren)');
$seo_base['csrf_token'] = csrf_from($r['body']);
$front_sc = request('GET', '/index.php?slug=admin-normal', null, false)['body'];
ok(!str_contains($front_sc, 'google-site-verification'), 'ohne gesetzten Code fehlt das Meta-Tag ganz, statt leer auszugeben');

// $seo_base wieder auf den ursprünglichen Testwert zurückstellen, damit
// nachfolgende Gruppen (z.B. "Fehlerhafte und bösartige Eingaben" gleich im
// Anschluss) einen bekannten Ausgangszustand vorfinden.
$r = request('POST', '/admin.php', array_merge($seo_base, [
    'search_console_verification' => 'abcDEF123-_.=456',
]));
ok(str_contains($r['body'], 'SEO-Einstellungen gespeichert'), 'Search-Console-Code wieder auf den Testwert zurückgesetzt');

group('POST-Handler: Ziel-Tab nach dem Absenden');
// Jeder Handler legt fest, welcher Tab nach dem Absenden gerendert wird. Ein
// falsches CSRF-Token reicht, um das für alle durchzuspielen: der Handler
// bricht vor jeder Wirkung ab, setzt den Tab aber trotzdem. Gepostet wird
// bewusst auf /admin.php ohne action, der Tab muss also vom Handler kommen.
// Die Content-Handler fehlen hier, sie lassen den Tab aus der URL stehen.
foreach ([
    'save_website_settings'  => 'site',
    'save_seo_settings'      => 'site',
    'upload_logo'            => 'site',
    'delete_logo'            => 'site',
    'upload_favicon'         => 'site',
    'delete_favicon'         => 'site',
    'save_branding_settings' => 'site',
    'save_upload_settings'   => 'settings',
    'save_security_settings' => 'settings',
    'save_contact_settings'  => 'settings',
    'save_profile'           => 'profile',
    'regenerate_own_token'   => 'profile',
    'invite_user'            => 'users',
    'send_user_token'        => 'users',
    'regenerate_user_token'  => 'users',
    'toggle_user_status'     => 'users',
    'delete_user'            => 'users',
    'create_redirect'        => 'redirects',
    'delete_redirect'        => 'redirects',
    'save_ai_settings'       => 'ai',
    'restore_history'        => 'ai',
] as $handler_key => $expected_tab) {
    $r = request('POST', '/admin.php', ['csrf_token' => 'falsch', $handler_key => '1']);
    ok(
        str_contains($r['body'], 'Ungültiges Sicherheits-Token')
            && str_contains($r['body'], 'href="admin.php?action=' . $expected_tab . '" class="active"'),
        $handler_key . ' rendert danach den Tab "' . $expected_tab . '"'
    );
}

$r = request('POST', '/admin.php?action=images', ['csrf_token' => 'falsch', 'delete_image' => '1']);
ok(str_contains($r['body'], 'href="admin.php?action=images" class="active"'), 'Content-Handler lassen den Tab aus der URL stehen');

group('POST-Handler: bisher ohne Erfolgsprüfung');
// Website, Upload und Sicherheit waren bisher nur mit dem 403 für den
// Content Creator abgedeckt, send_user_token und upload_image gar nicht.
// Die Config wird vorher gesichert und am Ende zurückgeschrieben, damit die
// folgenden Gruppen denselben Ausgangszustand vorfinden.
$cfg_snapshot = (string) file_get_contents($tmp_root . '/config/config.php');
$handler_csrf = csrf_from(request('GET', '/admin.php?action=settings')['body']);

$r = request('POST', '/admin.php', [
    'csrf_token'            => $handler_csrf,
    'site_title'            => 'Handler-Testseite',
    'site_domain'           => 'https://www.example.test',
    'default_slug'          => 'home',
    'save_website_settings' => '1',
]);
ok(str_contains($r['body'], 'Website-Einstellungen gespeichert'), 'save_website_settings meldet Erfolg');
ok(str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'Handler-Testseite'"), 'neuer Seitentitel steht in config.php');

$r = request('POST', '/admin.php', [
    'csrf_token'            => $handler_csrf,
    'site_title'            => 'Handler-Testseite',
    'site_domain'           => 'keine domain',
    'save_website_settings' => '1',
]);
ok(str_contains($r['body'], 'Ungültige Domain'), 'save_website_settings weist eine ungültige Domain ab');
ok(!str_contains($r['body'], 'Website-Einstellungen gespeichert'), 'und meldet dabei keinen Erfolg');

$r = request('POST', '/admin.php', [
    'csrf_token'           => $handler_csrf,
    'max_upload_mb'        => '7',
    'save_upload_settings' => '1',
]);
ok(str_contains($r['body'], 'Upload-Einstellungen gespeichert'), 'save_upload_settings meldet Erfolg');
ok(str_contains((string) file_get_contents($tmp_root . '/config/config.php'), "'max_upload_size' => 7340032"), '7 MB landen als Bytes in config.php');

$r = request('POST', '/admin.php', [
    'csrf_token'             => $handler_csrf,
    'max_login_attempts'     => '8',
    'login_lockout_minutes'  => '20',
    'save_security_settings' => '1',
]);
ok(str_contains($r['body'], 'Sicherheits-Einstellungen gespeichert'), 'save_security_settings meldet Erfolg');
$cfg_now = (string) file_get_contents($tmp_root . '/config/config.php');
ok(str_contains($cfg_now, "'max_login_attempts' => 8") && str_contains($cfg_now, "'login_lockout_time' => 1200"), 'Fehlversuche und Sperrzeit stehen in config.php');

// Zwei Handler-Keys in einem POST: früher liefen beide, und die Meldung des
// zweiten überschrieb die des ersten. Jetzt läuft nur der erste aus der
// Handler-Map in admin.php (Upload steht dort vor Sicherheit).
$r = request('POST', '/admin.php', [
    'csrf_token'             => $handler_csrf,
    'max_upload_mb'          => '9',
    'max_login_attempts'     => '12',
    'save_upload_settings'   => '1',
    'save_security_settings' => '1',
]);
$cfg_now = (string) file_get_contents($tmp_root . '/config/config.php');
ok(str_contains($r['body'], 'Upload-Einstellungen gespeichert'), 'POST mit zwei Handler-Keys: der erste Handler läuft');
ok(!str_contains($r['body'], 'Sicherheits-Einstellungen gespeichert'), 'POST mit zwei Handler-Keys: der zweite meldet nichts');
ok(str_contains($cfg_now, "'max_upload_size' => 9437184"), 'POST mit zwei Handler-Keys: Wirkung des ersten steht in config.php');
ok(str_contains($cfg_now, "'max_login_attempts' => 8"), 'POST mit zwei Handler-Keys: der zweite hat config.php nicht angefasst');

file_put_contents($tmp_root . '/config/config.php', $cfg_snapshot);

$r = request('POST', '/admin.php', [
    'csrf_token'      => $handler_csrf,
    'user_id'         => 'gibt-es-nicht',
    'send_user_token' => '1',
]);
ok(str_contains($r['body'], 'Nutzer nicht gefunden'), 'send_user_token mit unbekannter ID meldet "Nutzer nicht gefunden"');

$r = request('POST', '/admin.php', [
    'csrf_token'      => $handler_csrf,
    'user_id'         => find_test_user($tmp_root, 'neue-adresse@example.test')['id'],
    'send_user_token' => '1',
]);
ok(str_contains($r['body'], 'E-Mail-Versand ist aktuell nicht möglich'), 'send_user_token meldet den SMTP-Fehlschlag, statt Erfolg vorzutäuschen');

$r = upload_file('/admin.php?action=images', [
    'csrf_token'   => $handler_csrf,
    'upload_image' => '1',
], ['image' => ['name' => 'handler-test.png', 'type' => 'image/png', 'data' => $png_bytes]]);
ok(str_contains($r['body'], 'Bild hochgeladen: handler-test.png'), 'upload_image meldet Erfolg mit Dateinamen');
ok(is_file($tmp_root . '/assets/images/handler-test.png'), 'Bild liegt wirklich im Upload-Ordner');

$r = upload_file('/admin.php?action=images', [
    'csrf_token'   => $handler_csrf,
    'upload_image' => '1',
], ['image' => ['name' => 'kein-bild.png', 'type' => 'image/png', 'data' => 'nur Text']]);
ok(!str_contains($r['body'], 'Bild hochgeladen'), 'upload_image weist eine Datei ohne Bildinhalt ab');
ok(!is_file($tmp_root . '/assets/images/kein-bild.png'), 'abgewiesene Datei wird nicht gespeichert');

$r = request('POST', '/admin.php', [
    'csrf_token'    => $handler_csrf,
    'folder_name'   => 'Ungültig!',
    'create_folder' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Ordnername'), 'create_folder weist einen ungültigen Namen ab');

$r = request('POST', '/admin.php', [
    'csrf_token'    => $handler_csrf,
    'folder_name'   => 'cc-ordner',
    'create_folder' => '1',
]);
ok(str_contains($r['body'], 'Ordner existiert bereits'), 'create_folder meldet einen bestehenden Ordner');

$r = request('POST', '/admin.php?action=images', [
    'csrf_token'   => $handler_csrf,
    'image_name'   => '../config/config.php',
    'delete_image' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Dateiname'), 'delete_image weist einen Pfad außerhalb des Upload-Ordners ab');

// Ein rein numerischer Slug wird als Array-Key in cms_list_pages() zum int.
// create_redirect baut daraus die Kollisionsliste — in einer Datei mit
// strict_types darf das keinen TypeError (HTTP 500) auslösen.
put($tmp_root . '/content/handler-2024.md', "---\ntitle: Jahresrückblick\nslug: \"2024\"\n---\nText.\n");
$r = request('POST', '/admin.php', [
    'csrf_token'      => $handler_csrf,
    'redirect_from'   => '/handler-alt',
    'redirect_to'     => '/handler-neu',
    'create_redirect' => '1',
]);
ok($r['status'] === 200 && !str_contains($r['body'], 'TypeError'), 'create_redirect übersteht eine Seite mit numerischem Slug');
ok(str_contains($r['body'], 'Redirect angelegt'), 'Redirect wird trotz numerischem Slug einer anderen Seite angelegt');
$r = request('POST', '/admin.php', [
    'csrf_token'      => $handler_csrf,
    'redirect_from'   => '/2024',
    'redirect_to'     => '/handler-neu',
    'create_redirect' => '1',
]);
ok(!str_contains($r['body'], 'Redirect angelegt'), 'ein Redirect auf den numerischen Slug selbst wird weiter als Kollision abgewiesen');
// Dieselbe Seite mit Vorversion in der Undo-Liste des Tabs "KI-Anbindung":
// das Template reicht den Slug an cms_history_list(string) weiter.
put($tmp_root . '/content/.history/2024/2026-01-01_120000.md', "---\ntitle: Jahresrückblick alt\n---\nAlt.\n");
$r = request('GET', '/admin.php?action=ai');
ok($r['status'] === 200 && !str_contains($r['body'], 'TypeError'), 'Tab "KI-Anbindung" übersteht eine Vorversion mit numerischem Slug');
ok(str_contains($r['body'], 'name="restore_slug" value="2024"'), 'die Seite erscheint in der Undo-Liste');
cleanup($tmp_root . '/content/.history/2024');
@unlink($tmp_root . '/content/handler-2024.md');
foreach (load_test_redirects($tmp_root) as $redirect) {
    if ($redirect['from'] === '/handler-alt') {
        request('POST', '/admin.php', ['csrf_token' => $handler_csrf, 'redirect_id' => $redirect['id'], 'delete_redirect' => '1']);
    }
}

put($tmp_root . '/content/handler-loeschen.md', "---\ntitle: Löschtest\n---\nText.\n");
$r = request('POST', '/admin.php?action=edit&file=handler-loeschen.md', [
    'csrf_token'  => $handler_csrf,
    'filepath'    => 'handler-loeschen.md',
    'delete_file' => '1',
]);
ok(str_contains($r['body'], 'Datei gelöscht'), 'delete_file meldet Erfolg');
ok(str_contains($r['body'], 'href="admin.php" class="active"'), 'nach dem Löschen geht es zurück zur Seitenliste, nicht in den Editor der gelöschten Seite');

// ── Was danebengehen soll ──

group('Fehlerhafte und bösartige Eingaben');

$r = request('POST', '/admin.php', [
    'csrf_token'   => $token,
    'filepath'     => '../config/config.php',
    'publish_file' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Dateipfad'), 'Traversal im Dateipfad wird abgewiesen');
ok(
    str_contains((string) file_get_contents($GLOBALS['tmp_root'] . '/config/config.php'), 'admin_password_hash'),
    'die Konfiguration ist unangetastet'
);

$r = request('POST', '/admin.php', [
    'csrf_token'   => $token,
    'filepath'     => '.history/ki-entwurf/2026-01-01_120000.md',
    'publish_file' => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Dateipfad'), 'der Historien-Ordner ist über diesen Weg nicht erreichbar');

$before = (string) content_file('home.md');
$r = request('POST', '/admin.php', [
    'csrf_token'     => 'falsches-token',
    'filepath'       => 'home.md',
    'unpublish_file' => '1',
]);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'ohne gültiges CSRF-Token passiert nichts');
ok(str_contains($r['body'], 'Bitte lade die Seite neu'), 'jeder Handler liefert denselben CSRF-Text mit Handlungshinweis');
is_same(content_file('home.md'), $before, 'home.md wurde dabei nicht angefasst');

// Formularfelder als Array (feld[]=x): früher ein ungefangener TypeError,
// also HTTP 500 — die Helfer behandeln sie wie ein fehlendes Feld. Der
// PHP-Dev-Server liefert bei einem Fatal Error mit display_errors trotzdem
// 200, deshalb prüft der Test zusätzlich den Body.
$r = request('POST', '/admin.php', [
    'csrf_token' => ['x'],
    'filepath'   => 'home.md',
    'unpublish_file' => '1',
]);
ok($r['status'] === 200 && !str_contains($r['body'], 'TypeError'), 'csrf_token als Array liefert keinen 500');
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'csrf_token als Array wird als ungültiges Token abgewiesen');
is_same(content_file('home.md'), $before, 'home.md bleibt dabei unverändert');

$r = request('POST', '/admin.php', [
    'csrf_token' => $token,
    'filepath'   => 'array-body.md',
    'meta_title' => ['x'],
    'body'       => ['x'],
    'save_file'  => '1',
]);
ok($r['status'] === 200 && !str_contains($r['body'], 'TypeError'), 'body und meta_title als Array liefern im Editor keinen 500');
ok(str_contains($r['body'], 'Datei gespeichert'), 'die Seite wird trotzdem gespeichert, die Array-Felder zählen als leer');
ok(!str_contains((string) content_file('array-body.md'), 'title:'), 'ein Array-Feld landet nicht im Frontmatter');

$r = request('POST', '/admin.php', [
    'csrf_token'     => $token,
    'admin_email'    => ['x'],
    'admin_password' => ['x'],
], false);
ok($r['status'] === 200 && !str_contains($r['body'], 'TypeError'), 'Login mit Array-Feldern liefert keinen 500');

$r = request('POST', '/admin.php', [
    'csrf_token'     => $token,
    'filepath'       => 'home.md',
    'unpublish_file' => '1',
], false);
ok(str_contains($r['body'], 'admin_password'), 'ohne Session kommt statt der Aktion das Login-Formular');
is_same(content_file('home.md'), $before, 'ohne Session bleibt home.md unverändert');

// ── Passwort vergessen: Anfrage-Formular ──
// Der einleitende Check läuft mit $with_cookie=false (echt anonyme Sicht auf
// die Login-Seite). Alles danach läuft MIT Cookie: GET und POST müssen in
// derselben Session laufen, sonst passt das dabei geladene CSRF-Token nicht
// zum serverseitig gespeicherten — is_logged_in() spielt für diese beiden
// Routen ohnehin keine Rolle, sie laufen vor dem Auth-Gate.

group('Passwort vergessen: Anfrage-Formular');

$login_page = request('GET', '/admin.php', null, false)['body'];
ok(str_contains($login_page, 'action=forgot_password'), 'Login-Seite verlinkt "Passwort vergessen?"');

$forgot_form = request('GET', '/admin.php?action=forgot_password')['body'];
ok(str_contains($forgot_form, 'name="forgot_email"'), 'Formular zeigt das E-Mail-Feld');
$forgot_csrf = csrf_from($forgot_form);
ok($forgot_csrf !== '', 'CSRF-Token im Formular vorhanden');

$generic_text = 'Falls die Adresse hinterlegt ist, wurde ein Link zum Zurücksetzen verschickt.';

$r = request('POST', '/admin.php?action=forgot_password', ['forgot_email' => 'admin@example.test']);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'ohne CSRF-Token wird die Anfrage abgewiesen');

$r = request('POST', '/admin.php?action=forgot_password', [
    'csrf_token'   => $forgot_csrf,
    'forgot_email' => 'jemand-anderes@example.test',
]);
ok(str_contains($r['body'], $generic_text), 'Nicht-Treffer liefert die generische Antwort (Enumeration-Schutz, Spec 6.2)');
ok(!is_file(user_token_file($tmp_root, $admin_id)), 'bei Nicht-Treffer wird kein Token angelegt');

$r = request('POST', '/admin.php?action=forgot_password', [
    'csrf_token'   => $forgot_csrf,
    'forgot_email' => 'admin@example.test',
]);
ok(str_contains($r['body'], 'E-Mail-Versand ist aktuell nicht möglich'), 'Treffer, aber SMTP unerreichbar: eigene technische Meldung');
ok(!is_file(user_token_file($tmp_root, $admin_id)), 'fail-closed: der Token wird nach gescheitertem Versand wieder verworfen');

// "Passwort vergessen" gilt seit Einführung der Benutzerverwaltung für
// alle Nutzer, nicht mehr nur den einen Admin
// — hier mit einem frisch
// eingeladenen Content Creator geprüft, nicht nur zufällig über den Admin.
// Die technische Meldung (statt der generischen "Nicht-Treffer"-Antwort)
// beweist, dass cms_find_user_by_email() den Content Creator wirklich
// gefunden hat.
//
// $cookie ist an dieser Stelle durch die anonymen $with_cookie=false-Aufrufe
// oben bereits eine andere, nicht eingeloggte Session (jeder solche Aufruf
// bekommt sein eigenes Set-Cookie, das request() trotzdem übernimmt) — für
// den admin-only Einladen-Endpunkt kurz auf $admin_cookie wechseln, danach
// zurück, damit die anonyme Sicht für die restlichen Tests dieser Gruppe
// erhalten bleibt.
$anon_cookie = $cookie;
$cookie      = $admin_cookie;
request('POST', '/admin.php', [
    'csrf_token'   => csrf_from(request('GET', '/admin.php?action=users')['body']),
    'invite_email' => 'noch-eine-kollegin@example.test',
    'invite_role'  => 'content_creator',
    'invite_user'  => '1',
]);
ok(find_test_user($tmp_root, 'noch-eine-kollegin@example.test') !== null, 'zweiter Content Creator zum Testen angelegt');
$cookie = $anon_cookie;

$r = request('POST', '/admin.php?action=forgot_password', [
    'csrf_token'   => $forgot_csrf,
    'forgot_email' => 'noch-eine-kollegin@example.test',
]);
ok(str_contains($r['body'], 'E-Mail-Versand ist aktuell nicht möglich'), '"Passwort vergessen" findet auch einen Content Creator, nicht nur den Admin');

// Rate-Limit: fest 5 Anfragen/Stunde/IP. Drei Treffer-Anfragen
// liefen oben schon durch (CSRF-Fehlversuch zählt nicht mit, siehe
// handle_forgot_password_request()), macht 8 nach dieser Schleife.
for ($i = 0; $i < 5; $i++) {
    request('POST', '/admin.php?action=forgot_password', [
        'csrf_token'   => $forgot_csrf,
        'forgot_email' => 'jemand-anderes@example.test',
    ]);
}
$rl_files = glob($tmp_root . '/config/ratelimit/password_reset_*.json') ?: [];
is_same(count($rl_files), 1, 'genau ein Rate-Limit-Zähler für den Bucket password_reset');
$rl_state = $rl_files === [] ? [] : (json_decode((string) file_get_contents($rl_files[0]), true) ?: []);
ok(($rl_state['count'] ?? 0) > 5, 'der Zähler steht nach 7 Anfragen über der Grenze von 5');

$r = request('POST', '/admin.php?action=forgot_password', [
    'csrf_token'   => $forgot_csrf,
    'forgot_email' => 'admin@example.test', // Treffer — trotzdem generische Antwort
]);
ok(str_contains($r['body'], $generic_text), 'über dem Limit bleibt die Antwort generisch, auch bei einem Treffer');
ok(!is_file(user_token_file($tmp_root, $admin_id)), 'über dem Limit wird kein Token mehr angelegt, selbst bei einem Treffer');

// ── Passwort vergessen: Reset-Formular ──

group('Passwort vergessen: Reset-Formular');

$invalid = request('GET', '/admin.php?action=reset_password&token=nie-vergeben', null, false)['body'];
ok(str_contains($invalid, 'ungültig oder abgelaufen'), 'unbekannter Token zeigt die Fehlerseite');
ok(!str_contains($invalid, 'name="new_password"'), 'kein Passwort-Formular bei ungültigem Token');

$expired_token = write_user_token($tmp_root, $admin_id, -1); // liegt schon in der Vergangenheit
$expired = request('GET', '/admin.php?action=reset_password&token=' . $expired_token, null, false)['body'];
ok(str_contains($expired, 'ungültig oder abgelaufen'), 'abgelaufener Token zeigt ebenfalls die Fehlerseite');

// Eine neue Anfrage überschreibt eine bestehende — es kann nur ein Link pro
// Nutzer gleichzeitig aktiv sein.
$superseded_token = write_user_token($tmp_root, $admin_id);
$current_token    = write_user_token($tmp_root, $admin_id);
$superseded = request('GET', '/admin.php?action=reset_password&token=' . $superseded_token, null, false)['body'];
ok(str_contains($superseded, 'ungültig oder abgelaufen'), 'ein älterer Token wird durch eine neuere Anfrage sofort ungültig');
$current = request('GET', '/admin.php?action=reset_password&token=' . $current_token, null, false)['body'];
ok(str_contains($current, 'name="new_password"'), 'der zuletzt erzeugte Token bleibt gültig');
$reset_csrf = csrf_from($current);

// Ab hier bewusst mit Cookie (Default): der Token-Check ist unabhängig von
// der Session, aber GET (liefert das CSRF-Token) und POST müssen dieselbe
// Session teilen, sonst passt csrf_token() serverseitig nicht mehr zusammen.
$r = request('POST', '/admin.php?action=reset_password&token=' . $current_token, [
    'csrf_token'           => $reset_csrf,
    'token'                => $current_token,
    'new_password'         => 'kurz',
    'new_password_confirm' => 'kurz',
]);
ok(str_contains($r['body'], 'mindestens 12 Zeichen'), 'zu kurzes Passwort wird abgewiesen');

$r = request('POST', '/admin.php?action=reset_password&token=' . $current_token, [
    'csrf_token'           => $reset_csrf,
    'token'                => $current_token,
    'new_password'         => 'ganz-neues-passwort-1',
    'new_password_confirm' => 'abweichend-bestaetigt-1',
]);
ok(str_contains($r['body'], 'stimmen nicht überein'), 'abweichende Bestätigung wird abgewiesen');

$old_hash = user_password_hash($tmp_root, 'admin@example.test');
$new_password = 'ganz-neues-passwort-999';
$r = request('POST', '/admin.php?action=reset_password&token=' . $current_token, [
    'csrf_token'           => $reset_csrf,
    'token'                => $current_token,
    'new_password'         => $new_password,
    'new_password_confirm' => $new_password,
]);
is_same($r['status'], 302, 'erfolgreicher Reset leitet weiter wie ein normaler Login (auch wenn die Bestätigungsmail scheitert)');
ok(!is_file(user_token_file($tmp_root, $admin_id)), 'der Token ist nach dem Reset verbraucht (Single-Use)');

// Das neue Passwort landet in config/users.json, nicht mehr in
// config/config.php (dessen admin_password_hash bleibt seit der Migration
// unangetastet stehen).
$new_hash = user_password_hash($tmp_root, 'admin@example.test');
ok($new_hash !== '' && $new_hash !== $old_hash, 'password_hash in config/users.json hat sich geändert');
ok(password_verify($new_password, $new_hash), 'der gespeicherte Hash passt zum neuen Passwort');
ok(!password_verify($password, $new_hash), 'das alte Passwort greift nicht mehr');

$after_reset = request('GET', '/admin.php')['body']; // mit Cookie: session_regenerate_id() aus dem Reset
ok(str_contains($after_reset, 'Seiten ('), 'nach dem Reset ist die Session eingeloggt, ohne erneuten Login');

$reuse = request('GET', '/admin.php?action=reset_password&token=' . $current_token, null, false)['body'];
ok(str_contains($reuse, 'ungültig oder abgelaufen'), 'derselbe Token ist nach dem Reset verbraucht — ein zweiter Aufruf schlägt fehl');

group('Fehlende Config-Schlüssel: Defaults aus lib/config.php');
// Zustand nach einem Update, dessen Config-Migration nicht gelaufen ist: die
// Schlüssel, die admin.php und index.php ohne Fallback lesen, fehlen ganz.
// Ohne Defaults wäre MAX_LOGIN_ATTEMPTS 0 und damit jeder Login gesperrt, die
// Formulare zeigten PHP-Warnings, und / fände keine Startseite. Warnings
// werden im Body UND im Server-Log gesucht, weil display_errors am Hoster
// aus sein kann — dann stünden sie nur im Log.
$cfg_snapshot = (string) file_get_contents($tmp_root . '/config/config.php');
$cfg_reduced  = require $tmp_root . '/config/config.php';
foreach (['site_title', 'site_domain', 'default_slug', 'max_upload_size', 'max_login_attempts', 'login_lockout_time'] as $key) {
    unset($cfg_reduced[$key]);
}
file_put_contents($tmp_root . '/config/config.php', "<?php\nreturn " . var_export($cfg_reduced, true) . ";\n");
$cfg_reduced_raw = (string) file_get_contents($tmp_root . '/config/config.php');
$log_offset = (int) @filesize($tmp_root . '/server.log');
$php_noise  = '/(Warning|Deprecated|Notice|Fatal error)\b.*?(Undefined array key|Passing null)/';

$cookie = '';
$login_page = request('GET', '/admin.php');
$r = request('POST', '/admin.php', [
    'admin_email'    => 'admin@example.test',
    'admin_password' => $new_password,
    'csrf_token'     => csrf_from($login_page['body']),
]);
is_same($r['status'], 302, 'Login klappt ohne max_login_attempts/login_lockout_time in config.php');

$site_page     = request('GET', '/admin.php?action=site')['body'];
$settings_page = request('GET', '/admin.php?action=settings')['body'];
ok(str_contains($site_page, 'id="default_slug"') && str_contains($site_page, 'value="home"'), 'Tab "Website & SEO" rendert, Startseiten-Slug zeigt den Default home');
ok(str_contains($settings_page, 'id="max_upload_mb"') && str_contains($settings_page, 'Aktuell: 5 MB'), 'Tab "Einstellungen" rendert, Upload-Limit zeigt den Default 5 MB');
ok(str_contains($settings_page, 'id="max_login_attempts"') && str_contains($settings_page, 'value="5"'), 'Fehlversuche zeigen den Default 5');
ok(str_contains($settings_page, 'Aktuell: 15 Minuten'), 'Sperrzeit zeigt den Default 15 Minuten');
ok(!preg_match($php_noise, $site_page . $settings_page), 'keine PHP-Warning im Admin-Markup');

$front = request('GET', '/', null, false);
is_same($front['status'], 200, 'Frontend: / liefert ohne default_slug HTTP 200');
// Gegen die explizit aufgerufene Seite home, nicht gegen festen Text: der
// Undo-Test weiter oben hat den Inhalt von home.md schon ausgetauscht.
$home = request('GET', '/index.php?slug=home', null, false);
preg_match('#<title>(.*?)</title>#s', $front['body'], $front_title);
preg_match('#<title>(.*?)</title>#s', $home['body'], $home_title);
ok(($front_title[1] ?? '') !== '' && ($front_title[1] ?? '') === ($home_title[1] ?? null), 'Frontend: / zeigt die Seite home');
ok(!preg_match($php_noise, $front['body']), 'Frontend: keine PHP-Warning im Markup');

$log_tail = (string) @file_get_contents($tmp_root . '/server.log', false, null, $log_offset);
ok(!preg_match($php_noise, $log_tail), 'keine Warning zu fehlenden Schlüsseln im Server-Log');
is_same((string) file_get_contents($tmp_root . '/config/config.php'), $cfg_reduced_raw, 'reines Lesen schreibt die Defaults nicht nach config.php');

// Gespeicherte Werte gewinnen gegen den Default, auch wenn sie davon abweichen.
file_put_contents($tmp_root . '/config/config.php', "<?php\nreturn " . var_export(['max_upload_size' => 2097152] + $cfg_reduced, true) . ";\n");
ok(str_contains(request('GET', '/admin.php?action=settings')['body'], 'Aktuell: 2 MB'), 'vorhandener Wert in config.php wird nicht vom Default überschrieben');

file_put_contents($tmp_root . '/config/config.php', $cfg_snapshot);

group('Neue Seite: Wechsel in den Bearbeitungsmodus, kein Überschreiben');
// Nach dem Anlegen soll der Editor der neuen Seite erscheinen, nicht wieder
// die leere Maske. Ein vergebener Dateiname darf nichts überschreiben, und
// nach einem Fehler bleiben die Eingaben stehen.

$np_form = request('GET', '/admin.php?action=new')['body'];
$np_csrf = csrf_from($np_form);
@mkdir($tmp_root . '/content/np-ordner', 0777, true);

$r = request('POST', '/admin.php?action=new', [
    'csrf_token'     => $np_csrf,
    'file_folder'    => 'np-ordner',
    'file_name'      => 'np-seite.md',
    'meta_heading'   => 'Neue Seite Eins',
    'meta_slug'      => 'np-seite',
    'meta_showInNav' => '1',
    'body'           => 'Erster Inhalt.',
    'save_file'      => '1',
]);
is_same($r['status'], 303, 'Anlegen antwortet mit 303');
is_same($r['location'], 'admin.php?action=edit&file=np-ordner%2Fnp-seite.md&created=1', 'Redirect zeigt auf den Editor der neuen Seite, auch im Unterordner');
ok(str_contains((string) content_file('np-ordner/np-seite.md'), 'Erster Inhalt.'), 'die Datei ist angelegt');

$np_edit = request('GET', '/' . $r['location']);
is_same($np_edit['status'], 200, 'die Zielseite lädt');
ok(str_contains($np_edit['body'], 'Seite bearbeiten'), 'Zielseite ist der Bearbeitungsmodus');
ok(!str_contains($np_edit['body'], 'Neue Seite erstellen'), 'nicht mehr die Maske "Neue Seite"');
ok(str_contains($np_edit['body'], 'Seite erstellt: np-ordner/np-seite.md'), 'Zielseite meldet die erstellte Seite');
ok(str_contains($np_edit['body'], 'name="filepath" value="np-ordner/np-seite.md"'), 'das Formular zeigt auf die neue Datei');
ok(str_contains($np_edit['body'], 'value="Neue Seite Eins"'), 'der Seitentitel steht im Formular');
ok(str_contains($np_edit['body'], '>Erster Inhalt.</textarea>'), 'der Inhalt steht im Formular');

// Erneutes Speichern auf der Zielseite bearbeitet dieselbe Datei, ohne Redirect.
$r = request('POST', '/' . $r['location'], [
    'csrf_token'   => csrf_from($np_edit['body']),
    'filepath'     => 'np-ordner/np-seite.md',
    'meta_heading' => 'Neue Seite Eins',
    'body'         => 'Zweiter Inhalt.',
    'save_file'    => '1',
]);
is_same($r['status'], 200, 'Speichern einer bestehenden Seite leitet nicht um');
ok(str_contains($r['body'], 'Datei gespeichert: np-ordner/np-seite.md'), 'und meldet wie bisher "Datei gespeichert"');
ok(!str_contains($r['body'], 'Seite erstellt'), 'die Erstellt-Meldung erscheint dabei nicht noch einmal');
ok(str_contains((string) content_file('np-ordner/np-seite.md'), 'Zweiter Inhalt.'), 'die Änderung steht in der Datei');

// Das Flag allein erzeugt keine Meldung für eine Datei, die es nicht gibt.
$r = request('GET', '/admin.php?action=edit&file=gibt-es-nicht.md&created=1');
ok(!str_contains($r['body'], 'Seite erstellt'), 'created=1 ohne vorhandene Datei zeigt keine Meldung');
$r = request('GET', '/admin.php?action=edit&file[]=x&created[]=1');
is_same($r['status'], 200, 'Arrays als file/created ergeben keinen Serverfehler');

// Vergebener Dateiname: nichts überschreiben, Eingaben behalten.
$np_before = (string) content_file('np-ordner/np-seite.md');
$r = request('POST', '/admin.php?action=new', [
    'csrf_token'       => $np_csrf,
    'file_folder'      => 'np-ordner',
    'file_name'        => 'np-seite.md',
    'meta_heading'     => 'Zweiter Versuch',
    'meta_description' => 'Beschreibung <bleibt>',
    'meta_contentType' => 'html',
    'meta_pageStyle'   => '.np{color:red}',
    'meta_robots'      => 'noindex, follow',
    'meta_showInFooter' => '1',
    'body'             => '<p>Darf nicht überschreiben</p>',
    'save_file'        => '1',
]);
is_same($r['status'], 200, 'vergebener Dateiname leitet nicht um');
ok(str_contains($r['body'], 'Diese Datei existiert bereits'), 'und meldet den Fehler');
is_same((string) content_file('np-ordner/np-seite.md'), $np_before, 'die bestehende Datei bleibt unverändert');
ok(str_contains($r['body'], 'Neue Seite erstellen'), 'die Maske "Neue Seite" bleibt stehen');
ok(str_contains($r['body'], 'value="Zweiter Versuch"'), 'Seitentitel bleibt erhalten');
ok(str_contains($r['body'], 'value="Beschreibung &lt;bleibt&gt;"'), 'Meta-Description bleibt erhalten und ist escaped');
ok(str_contains($r['body'], '&lt;p&gt;Darf nicht überschreiben&lt;/p&gt;</textarea>'), 'Inhalt bleibt erhalten');
ok(str_contains($r['body'], '.np{color:red}</textarea>'), 'pageStyle bleibt erhalten');
ok(str_contains($r['body'], 'name="file_name" value="np-seite.md"'), 'Dateiname bleibt erhalten');
ok((bool) preg_match('~<option value="np-ordner" selected~', $r['body']), 'Ordner bleibt ausgewählt');
ok((bool) preg_match('~name="meta_contentType" value="html" checked~', $r['body']), 'Seitentyp HTML-Landingpage bleibt ausgewählt');
ok(str_contains($r['body'], 'data-content-type="html"'), 'der Editor startet im HTML-Modus');
ok((bool) preg_match('~<option value="noindex, follow" selected~', $r['body']), 'Robots-Auswahl bleibt erhalten');
ok((bool) preg_match('~name="meta_showInFooter" value="1"\s+checked~', $r['body']), 'Footer-Checkbox bleibt gesetzt');
ok(!preg_match('~name="meta_showInNav" value="1"\s+checked~', $r['body']), 'abgewählte Navigations-Checkbox bleibt abgewählt');

// Ungültiger Dateiname: gleiche Behandlung, auch ohne action in der URL.
$r = request('POST', '/admin.php', [
    'csrf_token'   => $np_csrf,
    'file_folder'  => '',
    'file_name'    => '../np-ausbruch.txt',
    'meta_heading' => 'Dritter Versuch',
    'body'         => 'Bleibt stehen.',
    'save_file'    => '1',
]);
ok(str_contains($r['body'], 'Ungültiger Dateipfad'), 'ungültiger Dateiname meldet den Fehler');
ok(str_contains($r['body'], 'Neue Seite erstellen'), 'und führt in die Maske "Neue Seite"');
ok(str_contains($r['body'], 'value="Dritter Versuch"') && str_contains($r['body'], '>Bleibt stehen.</textarea>'), 'Eingaben bleiben erhalten');

// Die leere Maske bleibt leer, solange nichts gesendet wurde.
ok((bool) preg_match('~name="meta_contentType" value="" checked~', $np_form), 'frische Maske: Markdown-Seite ist vorausgewählt');
ok(str_contains($np_form, 'name="file_name" value=""'), 'frische Maske: Dateiname ist leer');

@unlink($tmp_root . '/content/np-ordner/np-seite.md');
@rmdir($tmp_root . '/content/np-ordner');

// ─── Ergebnis ────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 60) . "\n";
if ($tests_failed === 0) {
    echo "Alle {$tests_run} Pruefungen bestanden.\n";
    exit(0);
}
echo "{$tests_failed} von {$tests_run} Pruefungen fehlgeschlagen.\n";
exit(1);
