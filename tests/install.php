<?php
/**
 * End-to-End-Tests für die Installationsroutine (install.php)
 *
 * Aufruf:  php tests/install.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Deckt Spec Abschnitt 10 ab: Voraussetzungsprüfung, Formular-Validierung,
 * erfolgreiche Installation (config.php + users.json korrekt geschrieben,
 * Login-Account also sofort funktionsfähig), Self-Lock nach Abschluss und
 * Race-Condition-Schutz bei parallelen Requests (Spec 6.4). Bisher nur durch
 * Ad-hoc-Smoketests geprüft, keine automatisierte Testsuite.
 *
 * Gleiches Wegwerf-Webroot-Verfahren wie tests/admin.php/tests/api.php/
 * tests/mcp.php: Kopie von install.php und den benötigten lib/-Dateien nach
 * sys_get_temp_dir(). Anders als bei den anderen drei Testsuiten OHNE
 * config/config.php — genau der Zustand, den install.php voraussetzt (frisch
 * hochgeladen, unkonfiguriert). Das echte content/ und die echte
 * Konfiguration werden nicht angefasst.
 *
 * Die Race-Condition-Gruppe braucht echte Nebenläufigkeit, die der
 * eingebaute php -S-Server nur mit mehreren Worker-Prozessen liefert
 * (PHP_CLI_SERVER_WORKERS, benötigt die pcntl-Extension). Ohne pcntl
 * verarbeitet der Server die Requests sequentiell — dann prüft der Test
 * weiterhin "genau ein Erfolg, Rest kollidiert", aber ohne echte
 * Gleichzeitigkeit im Schreibvorgang selbst.
 *
 * Diese Datei wird nicht deployed — siehe exclude-Liste in
 * .github/workflows/main.yml (der gesamte tests/-Ordner ist ausgeschlossen).
 */

// config/config.php verlangt CMS_ENTRY (Spec 5.3, cms_render_config_php()) —
// load_test_config() liest sie unten per require im eigenen Testprozess,
// nicht über den php -S-Server, deshalb hier wie in tests/roundtrip.php und
// tests/redirects.php direkt gesetzt.
define('CMS_ENTRY', true);

$repo_root = dirname(__DIR__);

// ─── Mini-Testhelfer (gleiche Form wie tests/admin.php) ──────────────────────

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
 * install.php muss kopiert werden, nicht verlinkt: __DIR__ löst Symlinks auf,
 * die Datei würde sonst config/config.example.php des echten Repos aus
 * ihrem eigenen __DIR__ lesen, aber in dieses hier schreiben wollen — ein Test
 * würde in der echten Installation landen. Bewusst OHNE config/config.php
 * (anders als tests/admin.php & Co.) — das ist genau der Zustand, den
 * install.php voraussetzt.
 */
function build_webroot(string $repo_root, string $tmp_root): void {
    cleanup($tmp_root);
    mkdir($tmp_root . '/lib', 0777, true);
    mkdir($tmp_root . '/config', 0777, true);
    mkdir($tmp_root . '/content', 0777, true);
    mkdir($tmp_root . '/assets/images', 0777, true);

    copy($repo_root . '/install.php', $tmp_root . '/install.php');
    copy($repo_root . '/lib/security.php', $tmp_root . '/lib/security.php');
    copy($repo_root . '/lib/enums.php', $tmp_root . '/lib/enums.php');
    copy($repo_root . '/lib/config.php', $tmp_root . '/lib/config.php');
    copy($repo_root . '/lib/users.php', $tmp_root . '/lib/users.php');
    copy($repo_root . '/config/config.example.php', $tmp_root . '/config/config.example.php');
}

// ─── HTTP-Server steuern ────────────────────────────────────────────────────

/**
 * Einen freien Port vom Betriebssystem geben lassen.
 */
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

$server_handles = [];

/**
 * Startet einen php -S für einen Wegwerf-Webroot. $workers > 1 aktiviert
 * echte Nebenläufigkeit (PHP_CLI_SERVER_WORKERS, braucht pcntl) — nur für
 * die Race-Condition-Gruppe gebraucht, alle anderen Requests laufen ohnehin
 * nacheinander aus demselben Testskript.
 */
function start_server(string $docroot, int $port, int $workers = 1): void {
    global $server_handles;
    // putenv() statt eines eigenen $env-Arrays für proc_open — ein explizites
    // $env würde das geerbte Environment (PATH usw.) komplett ersetzen statt
    // nur PHP_CLI_SERVER_WORKERS zu ergänzen. Der Kindprozess erbt das
    // Environment des Elternprozesses zum Zeitpunkt von proc_open() ohnehin,
    // wenn kein $env übergeben wird.
    putenv('PHP_CLI_SERVER_WORKERS=' . $workers);
    // Als Array statt als String: ohne Shell dazwischen ist die PID aus
    // proc_get_status() die des Servers selbst, stop_servers() findet darüber
    // dessen Worker.
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot];
    $handle = proc_open(
        $cmd,
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $docroot . '/server.log', 'a']],
        $pipes
    );
    if (!is_resource($handle)) {
        fwrite(STDERR, "php -S liess sich nicht starten.\n");
        exit(1);
    }
    $server_handles[] = $handle;

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

/**
 * Beendet alle gestarteten Server samt ihrer Worker.
 *
 * Mit PHP_CLI_SERVER_WORKERS forkt php -S Kindprozesse. Die überleben das
 * Ende des Hauptprozesses (ausser unter Linux, wo PHP ihnen ein Signal beim
 * Tod des Elternprozesses mitgibt) und blieben früher nach jedem Testlauf auf
 * ihrem Port stehen. Deshalb vor dem Beenden die Kind-PIDs einsammeln und
 * danach einzeln beenden.
 */
function stop_servers(): void {
    global $server_handles;
    foreach ($server_handles as $handle) {
        if (!is_resource($handle)) {
            continue;
        }
        $pid     = (int) (proc_get_status($handle)['pid'] ?? 0);
        $workers = [];
        if ($pid > 0) {
            exec('pgrep -P ' . $pid . ' 2>/dev/null', $workers);
        }
        proc_terminate($handle);
        proc_close($handle);
        foreach ($workers as $worker_pid) {
            if (ctype_digit($worker_pid)) {
                exec('kill ' . $worker_pid . ' 2>/dev/null');
            }
        }
    }
    $server_handles = [];
}

// ─── HTTP-Helfer mit Session-Cookie ─────────────────────────────────────────
//
// Anders als tests/admin.php gibt es hier keinen Login — das Cookie trägt
// nur die Session, in der csrf_token() sein Token ablegt (Spec 6.3).

/**
 * Ein Request gegen eine Test-Instanz.
 *
 * @return array{status:int, body:string}
 */
function request(string $base_url, string &$cookie, string $method, string $path, ?array $form = null): array {
    $headers = [];
    if ($cookie !== '') {
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
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
        if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $line, $m)) {
            $cookie = $m[1];
        }
    }

    return ['status' => $status, 'body' => $raw, 'headers' => $http_response_header ?? []];
}

/** Das CSRF-Token aus einer gerenderten Seite ziehen. */
function csrf_from(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** Die geschriebene config/config.php als Array laden. */
function load_test_config(string $tmp_root): array {
    $file = $tmp_root . '/config/config.php';
    if (!is_file($file)) return [];
    // require statt json_decode: config.php liefert ein PHP-Array zurück,
    // wie save_config()/cms_install_write_config() es auch schreiben.
    return (array) (require $file);
}

/** Alle Nutzer aus der geschriebenen config/users.json. */
function load_test_users(string $tmp_root): array {
    $raw  = (string) @file_get_contents($tmp_root . '/config/users.json');
    $data = json_decode($raw, true);
    return is_array($data['users'] ?? null) ? $data['users'] : [];
}

// ─── Testlauf ───────────────────────────────────────────────────────────────

register_shutdown_function('stop_servers');

$tmp_root = sys_get_temp_dir() . '/cms-install-test-' . getmypid();
register_shutdown_function(static function () use (&$tmp_root) { cleanup($tmp_root); });

build_webroot($repo_root, $tmp_root);

$port     = free_port();
$base_url = 'http://127.0.0.1:' . $port;
start_server($tmp_root, $port);

$cookie = '';

echo "install.php-Tests gegen {$base_url}\n";

// ── Voraussetzungsprüfung (Spec 5.5, 6.2) ──

group('Voraussetzungsprüfung');

// content/ ohne Schreibrecht simulieren — dieselbe Prüfung wie beim
// früheren Smoketest, per chmod statt eines PHP-Version-Mocks (die
// PHP-Version selbst lässt sich zur Laufzeit nicht verstellen).
chmod($tmp_root . '/content', 0500);
$r = request($base_url, $cookie, 'GET', '/install.php');
ok(str_contains($r['body'], 'Installation nicht möglich'), 'nicht beschreibbares content/ zeigt die Fehlerseite');
ok(str_contains($r['body'], 'content/') && str_contains($r['body'], 'nicht beschreibbar'), 'die Meldung nennt das betroffene Verzeichnis konkret');
ok(!str_contains($r['body'], 'name="csrf_token"'), 'kein Formular, solange die Voraussetzungen nicht erfüllt sind');
chmod($tmp_root . '/content', 0755);

$r = request($base_url, $cookie, 'GET', '/install.php');
ok(preg_match('/(md-website-cms|ClaudyCMS) einrichten/', $r['body']) === 1,'nach dem Beheben zeigt ein Reload wieder das Formular');
ok(!is_file($tmp_root . '/config/config.php'), 'die fehlgeschlagene Voraussetzungsprüfung hat nichts geschrieben (kein Teil-Zustand, Spec 5.5)');

group('CSP: Inline-Styles nur mit Nonce');
// Der Installer läuft mit der Admin-CSP ohne 'unsafe-inline' in style-src.
// Sein CSS steht als <style>-Block in der Seite und braucht deshalb denselben
// Nonce wie der Header, sonst sieht der Installer unformatiert aus.
$csp = '';
foreach ($r['headers'] as $line) {
    if (stripos($line, 'Content-Security-Policy:') === 0) {
        $csp = $line;
    }
}
ok(preg_match("/style-src [^;]*'nonce-([^']+)'/", $csp, $csp_m) === 1, 'style-src trägt einen Nonce');
ok(!preg_match("/style-src [^;]*'unsafe-inline'/", $csp), "style-src ohne 'unsafe-inline'");
ok(
    isset($csp_m[1]) && str_contains($r['body'], '<style nonce="' . $csp_m[1] . '">'),
    'der <style>-Block trägt genau den Nonce aus dem Header'
);

// ── Formular-Validierung (Spec 5.2) ──

group('Formular-Validierung');

$form_page = request($base_url, $cookie, 'GET', '/install.php')['body'];
ok(str_contains($form_page, 'name="site_title"'), 'Formular zeigt das Site-Titel-Feld');
ok(str_contains($form_page, 'name="site_domain"'), 'Formular zeigt das Domain-Feld (optional)');
$csrf = csrf_from($form_page);
ok($csrf !== '', 'CSRF-Token im Formular vorhanden');

$valid = [
    'site_title'              => 'Testinstallation',
    'site_domain'             => 'https://www.beispiel-install.test',
    'admin_email'             => 'admin@beispiel-install.test',
    'admin_password'          => 'ein-sehr-sicheres-passwort',
    'admin_password_confirm'  => 'ein-sehr-sicheres-passwort',
    'csrf_token'              => $csrf,
];

$r = request($base_url, $cookie, 'POST', '/install.php', ['csrf_token' => 'falsches-token'] + $valid);
ok(str_contains($r['body'], 'Ungültiges Sicherheits-Token'), 'POST ohne gültiges CSRF-Token wird abgewiesen');

$r = request($base_url, $cookie, 'POST', '/install.php', ['site_title' => ''] + $valid);
ok(str_contains($r['body'], 'Site-Titel angeben'), 'leerer Site-Titel wird abgewiesen');

$r = request($base_url, $cookie, 'POST', '/install.php', ['site_domain' => 'nicht-http-und-kein-slash'] + $valid);
ok(str_contains($r['body'], 'Ungültige Domain'), 'ungültiges Domain-Format wird abgewiesen');

// Kombiniert mit einer ungültigen E-Mail, damit dieser Request nicht aus
// Versehen vollständig durchläuft (eine leere Domain allein wäre zusammen mit
// den übrigen $valid-Werten eine tatsächlich erfolgreiche Installation).
$r = request($base_url, $cookie, 'POST', '/install.php', ['site_domain' => '', 'admin_email' => 'keine-email'] + $valid);
ok(!str_contains($r['body'], 'Ungültige Domain'), 'eine leere Domain selbst ist gültig (Spec 5.2: optional, "automatisch")');
ok(str_contains($r['body'], 'Ungültige E-Mail-Adresse'), 'die Validierung läuft mit leerer Domain trotzdem weiter bis zum nächsten Fehler');

$r = request($base_url, $cookie, 'POST', '/install.php', ['admin_email' => 'keine-email'] + $valid);
ok(str_contains($r['body'], 'Ungültige E-Mail-Adresse'), 'ungültige E-Mail wird abgewiesen');

$r = request($base_url, $cookie, 'POST', '/install.php', ['admin_password' => 'zu-kurz-11', 'admin_password_confirm' => 'zu-kurz-11'] + $valid);
ok(str_contains($r['body'], 'mindestens 12 Zeichen'), 'zu kurzes Passwort (< 12 Zeichen) wird abgewiesen');

$r = request($base_url, $cookie, 'POST', '/install.php', ['admin_password_confirm' => 'etwas-anderes-123456'] + $valid);
ok(str_contains($r['body'], 'nicht überein'), 'Passwort und Bestätigung unterschiedlich wird abgewiesen');

ok(!is_file($tmp_root . '/config/config.php'), 'keiner der Validierungsfehler hat config.php geschrieben');
ok(load_test_users($tmp_root) === [], 'keiner der Validierungsfehler hat einen Nutzer angelegt');

// ── Erfolgreiche Installation (Spec 5.3, 5.4) ──

group('Erfolgreiche Installation');

$r = request($base_url, $cookie, 'POST', '/install.php', $valid);
ok(str_contains($r['body'], 'Installation abgeschlossen'), 'gültige Eingaben führen zur Erfolgsseite');

$config = load_test_config($tmp_root);
is_same($config['site_title'] ?? null, 'Testinstallation', 'config.php enthält den eingegebenen Site-Titel');
is_same($config['site_domain'] ?? null, 'https://www.beispiel-install.test', 'config.php enthält die eingegebene Domain');
is_same($config['admin_password_hash'] ?? null, 'REPLACE_WITH_PASSWORD_HASH', 'admin_password_hash bleibt der Platzhalter aus der Vorlage (Spec 5.3) — das Passwort landet nur in users.json');

$users = load_test_users($tmp_root);
is_same(count($users), 1, 'genau ein Nutzer wurde angelegt');
$admin = $users[0] ?? [];
is_same($admin['email'] ?? null, 'admin@beispiel-install.test', 'Admin-E-Mail korrekt übernommen');
is_same($admin['role'] ?? null, 'admin', 'Rolle ist admin');
is_same($admin['status'] ?? null, 'aktiv', 'Status ist aktiv');
ok($admin['mcp_token_hash'] === null, 'noch kein MCP-Token (entsteht erst über den Tab "KI-Anbindung", Spec 5.4)');
ok(password_verify('ein-sehr-sicheres-passwort', (string) ($admin['password_hash'] ?? '')), 'Login funktioniert sofort: das eingegebene Passwort passt zum gespeicherten Hash');

// ── Self-Lock nach Abschluss (Spec 5.1, 6.1) ──

group('Self-Lock nach Abschluss');

$config_before = (string) file_get_contents($tmp_root . '/config/config.php');
$users_before  = (string) file_get_contents($tmp_root . '/config/users.json');

$r = request($base_url, $cookie, 'GET', '/install.php');
ok(str_contains($r['body'], 'Bereits installiert'), 'ein GET nach Abschluss zeigt "Bereits installiert" statt des Formulars');
ok(!str_contains($r['body'], 'name="csrf_token"'), 'kein Formular mehr erreichbar');

$r = request($base_url, $cookie, 'POST', '/install.php', ['site_title' => 'Übernommene Installation'] + $valid);
ok(str_contains($r['body'], 'Bereits installiert'), 'ein POST mit neuen Daten nach Abschluss wird ebenfalls abgewiesen, ohne die Formularverarbeitung überhaupt zu erreichen');

is_same((string) file_get_contents($tmp_root . '/config/config.php'), $config_before, 'config.php bleibt byteidentisch unangetastet');
is_same((string) file_get_contents($tmp_root . '/config/users.json'), $users_before, 'users.json bleibt byteidentisch unangetastet — kein zweiter Admin-Account');

// ── Race-Condition-Schutz (Spec 6.4) ──
//
// Eigener, frischer Wegwerf-Webroot (die Instanz oben ist bereits
// installiert) plus eigener Server MIT mehreren Worker-Prozessen — nur so
// bearbeitet der eingebaute php -S Requests wirklich gleichzeitig, siehe
// Dateikopf.

group('Race-Condition-Schutz bei parallelen Requests');

$race_root = sys_get_temp_dir() . '/cms-install-race-test-' . getmypid();
register_shutdown_function(static function () use (&$race_root) { cleanup($race_root); });
build_webroot($repo_root, $race_root);

$race_port     = free_port();
$race_base_url = 'http://127.0.0.1:' . $race_port;
start_server($race_root, $race_port, 8);

// Eine gemeinsame Session für alle parallelen Requests — ein einzelner GET
// holt Session-Cookie + CSRF-Token, die dann für jeden Request identisch
// mitgeschickt werden (Spec 6.3 verlangt nur ein gültiges Token, keine
// Ein-Token-pro-Request-Bindung).
$race_cookie = '';
$race_form   = request($race_base_url, $race_cookie, 'GET', '/install.php')['body'];
$race_csrf   = csrf_from($race_form);
ok($race_csrf !== '', 'frische Race-Test-Instanz zeigt ebenfalls ein Formular mit CSRF-Token');

$concurrency = 20;
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < $concurrency; $i++) {
    $ch = curl_init($race_base_url . '/install.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'site_title'             => 'Race-Titel-' . $i,
            'site_domain'            => '',
            'admin_email'            => 'admin@beispiel-install.test',
            'admin_password'         => 'ein-sehr-sicheres-passwort',
            'admin_password_confirm' => 'ein-sehr-sicheres-passwort',
            'csrf_token'             => $race_csrf,
        ]),
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $race_cookie],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$i] = $ch;
}
$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);

$responses = [];
foreach ($handles as $ch) {
    $responses[] = (string) curl_multi_getcontent($ch);
    curl_multi_remove_handle($multi, $ch);
    // curl_close() ist seit PHP 8.0 wirkungslos (CurlHandle wird automatisch
    // aufgeräumt) und seit 8.5 deprecated — bewusst weggelassen.
}
curl_multi_close($multi);

$successes = 0;
$collisions = 0;
foreach ($responses as $body) {
    if (str_contains($body, 'Installation abgeschlossen')) $successes++;
    elseif (str_contains($body, 'Bereits installiert')) $collisions++;
}
is_same($successes, 1, "genau einer von {$concurrency} gleichzeitigen Installationsversuchen gewinnt (Double-Checked-Locking, Spec 6.4)");
is_same($collisions, $concurrency - 1, 'alle übrigen Versuche landen auf "Bereits installiert", kein anderer Fehlerpfad');

is_same(count(load_test_users($race_root)), 1, 'trotz 20 gleichzeitiger Versuche existiert nur ein einziger Admin-Account');
$race_config = load_test_config($race_root);
ok(
    str_starts_with((string) ($race_config['site_title'] ?? ''), 'Race-Titel-'),
    'config.php enthält den Site-Titel genau des einen gewonnenen Requests'
);
ok(is_string((string) file_get_contents($race_root . '/config/config.php')) && trim((string) file_get_contents($race_root . '/config/config.php')) !== '', 'config.php ist kein leerer/halbgeschriebener Zustand');
$lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($race_root . '/config/config.php') . ' 2>&1');
ok(str_contains((string) $lint, 'No syntax errors'), 'config.php ist nach der Parallel-Last syntaktisch gültig');

// ── Ergebnis ──

echo "\n" . $tests_run . " Prüfungen, " . $tests_failed . " fehlgeschlagen.\n";
exit($tests_failed > 0 ? 1 : 0);
