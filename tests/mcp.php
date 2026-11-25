<?php
/**
 * End-to-End-Tests für mcp.php
 *
 * Aufruf:  php tests/mcp.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Gleiches Vorgehen wie tests/api.php: ein Wegwerf-Webroot in
 * sys_get_temp_dir() (Kopie von mcp.php und lib/, eigenes content/ und
 * config/config.php), darauf ein php -S, und alles läuft über echte
 * HTTP-Requests. Nur so lassen sich Token-in-URL, Statuscodes und die
 * JSON-RPC-Hülle prüfen. Das echte content/ wird nicht angefasst.
 *
 * Weil jeder Request in einem eigenen Prozess läuft, kann der Test die
 * Konfiguration zwischendurch umschreiben — so wird auch der Schalter
 * ai_publish_immediately in beiden Stellungen geprüft.
 *
 * Der Ordner tests/ gehört nur zum Repository und muss nicht auf den
 * Webserver hochgeladen werden.
 */

$repo_root = dirname(__DIR__);
$tmp_root  = sys_get_temp_dir() . '/cms-mcp-test-' . getmypid();
$token     = 'test-token-' . bin2hex(random_bytes(16));

// 1x1-Pixel-PNG als Bild-Fixture — reicht als echter Bildinhalt,
// weil getimagesize() nur den Header liest, nicht die Bildgroesse braucht.
$png_bytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
);
$png_b64 = base64_encode($png_bytes);

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
 * config/config.php im Wegwerf-Webroot schreiben.
 * Wird im Testlauf mehrfach aufgerufen, um ai_publish_immediately umzustellen.
 */
function write_config(
    bool $publish_immediately = false,
    ?string $token_override = null
): void {
    global $tmp_root, $token;
    put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export([
        'site_title'             => 'Testseite',
        // serverInfo.name/Health-Check leiten sich aus dieser
        // Domain ab (cms_site_domain()) statt aus einem festen Literal.
        'site_domain'            => 'https://testseite.example',
        'ai_connector_token'     => $token_override ?? $token,
        'ai_publish_immediately' => $publish_immediately,
        // Klein gehalten (200 KB statt der 5-MB-Vorgabe aus
        // config.example.php): der "zu gross"-Testfall (Abschnitt 6.3) muss
        // den Grössen-Check in cms_validate_image_upload() treffen,
        // nicht schon vorher an MCP_MAX_BODY (1-MB-Requestgrenze in mcp.php)
        // scheitern — Base64 blaeht die Bytes um ~1/3 auf.
        'max_upload_size'        => 200 * 1024,
        // Kategorienliste für die Kategorien-Testgruppe. Steht in jeder
        // Config-Fassung, damit ein Umschreiben zwischendurch sie nicht verliert.
        'categories'             => [
            ['slug' => 'seo', 'name' => 'SEO'],
            ['slug' => 'wordpress', 'name' => 'WordPress'],
        ],
    ], true) . ";\n");
}

/**
 * mcp.php muss kopiert werden, nicht verlinkt: __DIR__ folgt Symlinks, und die
 * Datei würde sonst content/ und config/ des echten Repos benutzen.
 */
function build_webroot(string $repo_root, string $tmp_root): void {
    cleanup($tmp_root);
    mkdir($tmp_root . '/lib', 0777, true);
    mkdir($tmp_root . '/content', 0777, true);

    copy($repo_root . '/mcp.php', $tmp_root . '/mcp.php');
    copy($repo_root . '/lib/security.php', $tmp_root . '/lib/security.php');
    copy($repo_root . '/lib/enums.php', $tmp_root . '/lib/enums.php');
    copy($repo_root . '/lib/content.php', $tmp_root . '/lib/content.php');
    copy($repo_root . '/lib/media.php', $tmp_root . '/lib/media.php');
    copy($repo_root . '/lib/styles.php', $tmp_root . '/lib/styles.php');
    // Vierter Fall desselben Musters (nach tests/admin.php zweimal und
    // tests/mcp.php selbst): eine neue lib/*.php muss hier eigens ergänzt
    // werden, sonst bricht das kopierte mcp.php mit Fatal Error ab, sobald
    // es die Datei per require einbindet.
    copy($repo_root . '/lib/users.php', $tmp_root . '/lib/users.php');
    // Fünfter Fall desselben Musters: mcp.php bindet lib/redirects.php
    // seither ebenfalls zwingend ein.
    copy($repo_root . '/lib/redirects.php', $tmp_root . '/lib/redirects.php');
    // Sechster Fall desselben Musters: mcp.php bindet lib/config.php
    // seither ebenfalls zwingend ein (cms_site_domain()).
    copy($repo_root . '/lib/config.php', $tmp_root . '/lib/config.php');
    // Achter Fall desselben Musters: mcp.php bindet lib/categories.php
    // seither ebenfalls zwingend ein (Kategorien prüfen, lesen, filtern).
    copy($repo_root . '/lib/categories.php', $tmp_root . '/lib/categories.php');
    mkdir($tmp_root . '/assets/images', 0777, true);
    // Echte assets/style.css kopieren — lib/styles.php parst den
    // dortigen :root-Block, eine leere/erfundene Datei würde die Style-Tools
    // etwas anderes testen lassen als das, was tatsächlich live liegt.
    copy($repo_root . '/assets/style.css', $tmp_root . '/assets/style.css');

    // Router für php -S: simuliert auf Wunsch einen Server ohne PATH_INFO,
    // damit mcp_extract_token() den Rückfallweg über REQUEST_URI nehmen muss.
    // Alle anderen Requests bedient php -S wie ohne Router.
    put($tmp_root . '/router.php', <<<'PHP'
<?php
if (isset($_GET['ohne_path_info'])) {
    unset($_SERVER['PATH_INFO']);
    require __DIR__ . '/mcp.php';
    return true;
}
return false;
PHP);

    write_config(false);

}

/** Legt die Testinhalte an. */
function build_fixtures(string $tmp_root): void {
    put($tmp_root . '/content/home.md',
        "---\ntitle: Startseite\nslug: home\norder: 1\nnavLabel: Start\n---\n\n# Willkommen\n");
    put($tmp_root . '/content/kontakt.md',
        "---\ntitle: Kontakt\nslug: kontakt\norder: 3\n---\n\nSchreib uns.\n");
    put($tmp_root . '/content/entwurf.md',
        "---\ntitle: Entwurf\nslug: entwurf\ndraft: true\n---\n\nNoch nicht fertig.\n");
    put($tmp_root . '/content/work/projekt-alpha.md',
        "---\ntitle: Projekt Alpha\nslug: work/projekt-alpha\n---\n\nVerschachtelt.\n");
}

/**
 * Legt Bild-Fixtures direkt im Dateisystem an — wie
 * build_fixtures() für Seiten, unabhängig von upload_image, damit ein Fehler
 * in upload_image nicht auch die Lese-Tests verdeckt.
 *
 * "grosses-bild.png" ist kein echtes Bild (nur wiederholte Bytes über der
 * 5-MB-Grenze aus MCP_IMAGE_INLINE_LIMIT) — für den Metadaten-Pfad von
 * get_image reicht das, weil dort nur Dateigrösse und Endung zählen, nicht
 * der Bildinhalt (anders als beim Upload, der echte Bilddaten prüft).
 */
function build_image_fixtures(string $tmp_root, string $png_bytes): void {
    put($tmp_root . '/assets/images/vorhanden.png', $png_bytes);
    put($tmp_root . '/assets/images/grosses-bild.png', str_repeat('X', 5 * 1024 * 1024 + 1024));
}

/**
 * Setzt alle Rate-Limit-Zähler zurück.
 *
 * Nötig, weil der Testlauf deutlich mehr Requests stellt als das Limit von
 * 60/Minute erlaubt — ohne das würde die Hälfte der Prüfungen an einem 429
 * scheitern, das nichts mit dem Prüfgegenstand zu tun hat.
 */
function reset_rate_limits(): void {
    global $tmp_root;
    foreach (glob($tmp_root . '/config/ratelimit/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Setzt nur den allgemeinen Anfragen-Zähler pro IP zurück (Bucket "mcp",
 * Feature-PRD 6.6/Sicherheits-Härtung), nicht den Schreib-Zähler ("mcp_write",
 * Feature-PRD 6.6) — nötig, wenn innerhalb der Rate-Limits-Gruppe der
 * ausgeschöpfte Schreib-Zustand für weitere Prüfungen erhalten bleiben soll,
 * die Gruppe aber inzwischen mehr als 60 Requests gestellt hat (die drei
 * Redirect-Tools kamen als letzte hinzu und haben die Gruppe über die
 * 60/Minute-Grenze aus lib/security.php geschoben).
 */
function reset_ip_rate_limit(): void {
    global $tmp_root;
    foreach (glob($tmp_root . '/config/ratelimit/mcp_*.json') ?: [] as $file) {
        if (str_starts_with(basename($file), 'mcp_write_')) {
            continue;
        }
        @unlink($file);
    }
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
    $cmd = sprintf(
        '%s -S 127.0.0.1:%d -t %s %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($docroot),
        escapeshellarg($docroot . '/router.php')
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

    // Warten, bis der Port annimmt — sonst laufen die ersten Requests ins Leere.
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

// ─── HTTP-Helfer ────────────────────────────────────────────────────────────

$base_url = '';

/**
 * Ein roher Request gegen die Test-Instanz.
 *
 * @return array{status:int, headers:array<string,string>, body:string, json:mixed}
 */
function http(string $method, string $path, ?string $body = null): array {
    global $base_url;

    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'ignore_errors' => true,   // 4xx/5xx sollen den Body liefern, keine Warnung
        'timeout'       => 10,
    ]]);

    $raw = @file_get_contents($base_url . $path, false, $context);
    $raw = $raw === false ? '' : $raw;

    $status = 0;
    $head   = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $head[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    return ['status' => $status, 'headers' => $head, 'body' => $raw, 'json' => json_decode($raw, true)];
}

/** Ein JSON-RPC-Aufruf über die Connector-URL. */
function rpc(string $method, array $params = [], mixed $id = 1, ?string $path_token = null): array {
    global $token;
    $payload = ['jsonrpc' => '2.0', 'method' => $method];
    if ($id !== null) {
        $payload['id'] = $id;
    }
    if ($params !== []) {
        $payload['params'] = $params;
    }
    return http(
        'POST',
        '/mcp.php/' . ($path_token ?? $token),
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );
}

/** Ein Tool-Aufruf. */
function call_tool(string $name, array $args = []): array {
    return rpc('tools/call', ['name' => $name, 'arguments' => (object) $args]);
}

/** Der Text aus einem Tool-Ergebnis. */
function tool_text(array $response): string {
    return (string) ($response['json']['result']['content'][0]['text'] ?? '');
}

/** Ist das Tool-Ergebnis ein Fehler? */
function tool_failed(array $response): bool {
    return ($response['json']['result']['isError'] ?? null) === true;
}

/** Rohinhalt einer Content-Datei im Wegwerf-Webroot. */
function content_file(string $rel): string|false {
    global $tmp_root;
    $path = $tmp_root . '/content/' . $rel;
    clearstatcache(true, $path);
    return is_file($path) ? (string) file_get_contents($path) : false;
}

/** Vorversionen einer Seite im Wegwerf-Webroot. */
function history_files(string $slug): array {
    global $tmp_root;
    return glob($tmp_root . '/content/.history/' . $slug . '/*.md') ?: [];
}

/** Rohinhalt einer Bilddatei im Wegwerf-Webroot, oder false wenn sie fehlt. */
function image_file(string $filename): string|false {
    global $tmp_root;
    $path = $tmp_root . '/assets/images/' . $filename;
    clearstatcache(true, $path);
    return is_file($path) ? (string) file_get_contents($path) : false;
}

/** Rohinhalt von assets/style.css im Wegwerf-Webroot. */
function style_css_file(): string {
    global $tmp_root;
    return (string) file_get_contents($tmp_root . '/assets/style.css');
}

/** Rohinhalt von assets/custom.css im Wegwerf-Webroot, oder false wenn sie fehlt. */
function custom_css_file(): string|false {
    global $tmp_root;
    $path = $tmp_root . '/assets/custom.css';
    clearstatcache(true, $path);
    return is_file($path) ? (string) file_get_contents($path) : false;
}

/** Vorversionen eines Style-Ziels ('tokens' oder 'custom_css') im Wegwerf-Webroot. */
function style_history_files(string $target): array {
    global $tmp_root;
    $dir = $target === 'custom_css' ? 'custom-css' : 'tokens';
    return glob($tmp_root . '/config/style-history/' . $dir . '/*.css') ?: [];
}

/** Decodiert den JSON-Anteil einer mcp_tool_json()-Antwort (nach der vorangestellten Notiz). */
function tool_json(array $response): mixed {
    $text = tool_text($response);
    $pos  = strpos($text, "\n\n");
    return json_decode($pos === false ? $text : substr($text, $pos + 2), true);
}

/** Alle Redirects aus der geschriebenen config/redirects.json im Wegwerf-Webroot. */
function redirects_in_file(): array {
    global $tmp_root;
    $raw  = (string) @file_get_contents($tmp_root . '/config/redirects.json');
    $data = json_decode($raw, true);
    return is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
}

/** Alle Nutzer aus dem Wegwerf-users.json lesen. */
function read_users(string $tmp_root): array {
    $data = json_decode((string) @file_get_contents($tmp_root . '/config/users.json'), true);
    return is_array($data['users'] ?? null) ? $data['users'] : [];
}

/** users.json im Wegwerf-Webroot schreiben, gleiches Format wie lib/users.php. */
function write_users(string $tmp_root, array $users): void {
    put(
        $tmp_root . '/config/users.json',
        json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Ergänzt users.json direkt im Dateisystem um einen weiteren Nutzer — analog
 * build_image_fixtures(): am Datenmodell vorbei angelegt, nicht über
 * den Einladungs-Flow im Admin-Backend (der ist Sache von tests/admin.php),
 * damit ein Fehler dort die MCP-Rollen-Tests hier nicht mitreisst.
 * Gibt den Klartext-Token zurück.
 */
function add_test_user(string $tmp_root, string $role, string $status = 'aktiv'): string {
    $users = read_users($tmp_root);
    $token = 'test-token-' . bin2hex(random_bytes(16));
    $users[] = [
        'id'             => 'usr_' . bin2hex(random_bytes(16)),
        'email'          => $role . '-' . bin2hex(random_bytes(4)) . '@example.test',
        'password_hash'  => null,
        'role'           => $role,
        'status'         => $status,
        'mcp_token_hash' => hash('sha256', $token),
        'created_at'     => date('c'),
        'last_login_at'  => null,
    ];
    write_users($tmp_root, $users);
    return $token;
}

/**
 * Ersetzt den mcp_token_hash des ersten Nutzers mit der angegebenen Rolle
 * direkt in users.json — simuliert "Token neu generieren", ohne
 * den Umweg über admin.php (der ist bereits in tests/admin.php geprüft).
 * Gibt den neuen Klartext-Token zurück.
 */
function regenerate_token_in_users_json(string $tmp_root, string $role = 'admin'): string {
    $users     = read_users($tmp_root);
    $new_token = 'test-token-' . bin2hex(random_bytes(16));
    foreach ($users as &$u) {
        if (($u['role'] ?? '') === $role) {
            $u['mcp_token_hash'] = hash('sha256', $new_token);
            break;
        }
    }
    unset($u);
    write_users($tmp_root, $users);
    return $new_token;
}

// ─── Testlauf ───────────────────────────────────────────────────────────────

register_shutdown_function('stop_server');
register_shutdown_function(static function () use (&$tmp_root) { cleanup($tmp_root); });

build_webroot($repo_root, $tmp_root);
build_fixtures($tmp_root);
build_image_fixtures($tmp_root, $png_bytes);

$port     = free_port();
$base_url = 'http://127.0.0.1:' . $port;
start_server($tmp_root, $port);

echo "mcp.php-Tests gegen {$base_url}\n";

// ── Authentifizierung ──

group('Token-Authentifizierung');

$r = http('POST', '/mcp.php', '{"jsonrpc":"2.0","id":1,"method":"ping"}');
is_same($r['status'], 401, 'ohne Token im Pfad -> HTTP 401');
is_same($r['json']['error']['code'] ?? null, -32001, 'JSON-RPC-Fehlercode -32001');

$r = rpc('ping', [], 1, 'falscher-token');
is_same($r['status'], 401, 'falscher Token -> HTTP 401');
is_same(
    $r['json']['error']['message'] ?? null,
    'Ungültiger oder fehlender Connector-Token.',
    'gleiche Meldung wie ohne Token (keine Existenz-Preisgabe)'
);

$r = rpc('ping', [], 1, $token . 'x');
is_same($r['status'], 401, 'Token mit angehaengtem Zeichen -> 401');

$r = rpc('ping');
is_same($r['status'], 200, 'richtiger Token -> HTTP 200');

// Rückfallweg für Server ohne PATH_INFO: der Token muss dann aus
// REQUEST_URI kommen. Der Router (build_webroot()) entfernt PATH_INFO.
$ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
$r = http('POST', '/mcp.php/' . $token . '?ohne_path_info=1', $ping);
is_same($r['status'], 200, 'ohne PATH_INFO: Token aus REQUEST_URI -> HTTP 200');
$r = http('POST', '/mcp.php/falscher-token?ohne_path_info=1', $ping);
is_same($r['status'], 401, 'ohne PATH_INFO: falscher Token -> HTTP 401');
$r = http('POST', '/mcp.php?ohne_path_info=1', $ping);
is_same($r['status'], 401, 'ohne PATH_INFO und ohne Token -> HTTP 401');
$r = http('POST', '/mcp.php?ohne_path_info=1&token=' . $token, $ping);
is_same($r['status'], 200, 'ohne PATH_INFO: ?token= greift weiterhin');
ok(
    !str_contains((string) @file_get_contents($tmp_root . '/server.log'), 'preg_match'),
    'kein preg_match-Warning im Server-Log'
);

// Migration: der allererste Request oben hat bereits
// cms_migrate_users() durchlaufen und aus ai_connector_token (config.php)
// einen Admin-Account in users.json angelegt — mcp.php prüft von hier an nur
// noch gegen users.json, nicht mehr gegen config.php.
$migrated = read_users($tmp_root);
is_same(count($migrated), 1, 'Migration legt genau einen Nutzer an');
is_same($migrated[0]['role'] ?? null, 'admin', 'der migrierte Nutzer ist Admin');
is_same($migrated[0]['status'] ?? null, 'aktiv', 'der migrierte Nutzer ist aktiv');
is_same(
    $migrated[0]['mcp_token_hash'] ?? null,
    hash('sha256', $token),
    'mcp_token_hash ist der Hash des bisherigen globalen ai_connector_token'
);

// Ein neu erzeugter Token macht die alte Connector-URL sofort ungueltig. Der
// Tausch läuft über users.json (Tab "Benutzer"/"Mein Profil" in admin.php,
// dort bereits geprüft) — ein erneutes Schreiben von ai_connector_token nach
// config.php hätte hier keine Wirkung mehr, die Migration lief nur einmal.
$alter_token = $token;
$token       = regenerate_token_in_users_json($tmp_root, 'admin');
$r = rpc('ping', [], 1, $alter_token);
is_same($r['status'], 401, 'nach Token-Wechsel ist die alte URL sofort ungueltig');
$r = rpc('ping');
is_same($r['status'], 200, 'die neue Connector-URL funktioniert');

// ── Transport ──

group('Transport und JSON-RPC-Huelle');

reset_rate_limits();

$r = http('GET', '/mcp.php/' . $token);
is_same($r['status'], 200, 'GET liefert eine Health-Antwort');
is_same($r['json']['status'] ?? null, 'ok', 'Health-Antwort meldet ok');

$r = http('PUT', '/mcp.php/' . $token, '{}');
is_same($r['status'], 405, 'PUT -> HTTP 405');

$r = http('POST', '/mcp.php/' . $token, 'kein json');
is_same($r['json']['error']['code'] ?? null, -32700, 'kaputtes JSON -> Parse error -32700');

$r = http('POST', '/mcp.php/' . $token, '[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
is_same($r['json']['error']['code'] ?? null, -32600, 'Batch-Request wird abgelehnt');

$r = rpc('gibt/es/nicht');
is_same($r['json']['error']['code'] ?? null, -32601, 'unbekannte Methode -> -32601');
is_same($r['json']['id'] ?? null, 1, 'die id des Requests kommt zurueck');

$r = rpc('initialize', ['protocolVersion' => '2025-03-26']);
is_same($r['json']['result']['protocolVersion'] ?? null, '2025-03-26', 'initialize spiegelt die protocolVersion');
is_same($r['json']['result']['serverInfo']['name'] ?? null, 'testseite.example', 'serverInfo traegt die Domain aus site_domain');
ok(isset($r['json']['result']['capabilities']['tools']), 'capabilities meldet tools');
ok(($r['json']['result']['instructions'] ?? '') !== '', 'initialize liefert instructions fuer Claude');

$r = rpc('notifications/initialized', [], null);
is_same($r['status'], 202, 'notifications/initialized -> HTTP 202');
is_same($r['body'], '', 'Notification bekommt keinen Body zurueck');

$r = rpc('notifications/cancelled', [], null);
is_same($r['status'], 202, 'auch andere notifications/* -> HTTP 202');

$r = rpc('ping');
ok(str_contains($r['body'], '"result":{}'), 'ping liefert ein leeres Objekt, kein leeres Array');

// ── Tool-Katalog ──

group('tools/list');

reset_rate_limits();

$r     = rpc('tools/list');
$tools = $r['json']['result']['tools'] ?? [];
$names = array_column($tools, 'name');

// Vier Bild-Tools zusätzlich zu den fünf Seiten-Tools aus Feature-PRD
// Abschnitt 5. Dazu alle fünf Style-Tools, die zwei Landingpage-Tools und
// die drei Redirect-Tools (301-Redirect-Verwaltung) sowie list_categories.
is_same(count($tools), 20, 'zwanzig Tools werden angeboten');
is_same(
    $names,
    [
        'list_pages', 'get_page', 'create_page', 'update_page', 'delete_page',
        'create_landingpage', 'update_landingpage',
        'list_categories',
        'list_images', 'get_image', 'upload_image', 'delete_image',
        'get_styles', 'update_styles', 'get_custom_css', 'update_custom_css', 'restore_styles',
        'list_redirects', 'create_redirect', 'delete_redirect',
    ],
    'die fuenf Seiten-, zwei Landingpage-, vier Bild-, fuenf Style- und drei Redirect-Tools plus list_categories, in dieser Reihenfolge'
);

$by_name = array_combine($names, $tools);
foreach ($names as $name) {
    ok(
        strlen($by_name[$name]['description'] ?? '') > 80,
        "{$name} hat eine ausfuehrliche Beschreibung"
    );
}
ok(
    str_contains($by_name['update_page']['description'], 'ändern')
        && str_contains($by_name['delete_page']['description'], 'Sicherungskopie'),
    'die Beschreibungen sind auf Deutsch formuliert'
);
is_same($by_name['get_page']['inputSchema']['required'] ?? null, ['slug'], 'get_page verlangt slug');
is_same(
    $by_name['create_page']['inputSchema']['required'] ?? null,
    ['slug', 'title', 'body'],
    'create_page verlangt slug, title und body'
);
is_same($by_name['update_page']['inputSchema']['required'] ?? null, ['slug'], 'update_page verlangt nur den slug');

$r = rpc('tools/call', ['name' => 'gibt_es_nicht', 'arguments' => new stdClass()]);
is_same($r['json']['error']['code'] ?? null, -32602, 'unbekanntes Tool -> Protokollfehler -32602');

// ── Lesen ──

group('Lese-Tools');

reset_rate_limits();

$r = call_tool('list_pages');
ok(!tool_failed($r), 'list_pages meldet keinen Fehler');
$text = tool_text($r);
ok(str_contains($text, '"slug": "home"'), 'list_pages enthaelt home');
ok(str_contains($text, '"slug": "work/projekt-alpha"'), 'list_pages enthaelt den verschachtelten Slug');
ok(str_contains($text, 'entwurf'), 'list_pages zeigt Entwuerfe standardmaessig mit');

$r = call_tool('list_pages', ['include_drafts' => false]);
ok(!str_contains(tool_text($r), 'entwurf'), 'list_pages mit include_drafts=false filtert Entwuerfe');

$r = call_tool('get_page', ['slug' => 'kontakt']);
ok(!tool_failed($r), 'get_page meldet keinen Fehler');
ok(str_contains(tool_text($r), 'Schreib uns.'), 'get_page liefert den Body');
ok(str_contains(tool_text($r), '"title": "Kontakt"'), 'get_page liefert die Metadaten');

$r = call_tool('get_page', ['slug' => 'gibt-es-nicht']);
ok(tool_failed($r), 'get_page auf unbekannten Slug -> isError');
ok(str_contains(tool_text($r), 'list_pages'), 'die Fehlermeldung verweist auf list_pages');
is_same($r['status'], 200, 'ein Tool-Fehler bleibt auf HTTP-Ebene ein Erfolg');

$r = call_tool('get_page', []);
ok(tool_failed($r), 'get_page ohne slug -> isError');

// ── Schreiben als Entwurf (Standard) ──

group('Schreiben mit ai_publish_immediately = false');

reset_rate_limits();

$r = call_tool('create_page', [
    'slug'  => 'neue-seite',
    'title' => 'Neue Seite',
    'body'  => 'Erster Inhalt.',
    'meta'  => ['navLabel' => 'Neu', 'order' => 5],
]);
ok(!tool_failed($r), 'create_page legt eine Seite an');
ok(str_contains(tool_text($r), 'Entwurf gespeichert'), 'die Antwort weist auf den Entwurfsstatus hin');
ok(str_contains(tool_text($r), 'Freigabe'), 'die Antwort nennt die noetige Freigabe');

$file = content_file('neue-seite.md');
ok(str_contains((string) $file, 'draft: true'), 'die neue Seite ist als Entwurf gespeichert');
ok(str_contains((string) $file, 'lastEditedBy: ai'), 'lastEditedBy: ai steht im Frontmatter');
ok(
    preg_match('/lastEditedAt: "\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $file) === 1,
    'lastEditedAt steht als ISO-8601-Zeitstempel im Frontmatter'
);
ok(str_contains((string) $file, 'navLabel: Neu'), 'Felder aus meta landen im Frontmatter');
ok(str_contains((string) $file, 'Erster Inhalt.'), 'der Body wurde geschrieben');

$r = call_tool('create_page', ['slug' => 'neue-seite', 'title' => 'Nochmal', 'body' => 'x']);
ok(tool_failed($r), 'create_page auf einen vergebenen Slug -> isError');

$r = call_tool('create_page', ['slug' => 'ohne-titel', 'body' => 'x']);
ok(tool_failed($r), 'create_page ohne Titel -> isError');
is_same(content_file('ohne-titel.md'), false, 'ohne Titel wird keine Datei angelegt');

$r = call_tool('create_page', [
    'slug'  => 'verschachtelte-werte',
    'title' => 'Test',
    'body'  => 'x',
    'meta'  => ['liste' => ['a', 'b']],
]);
ok(tool_failed($r), 'verschachtelte meta-Werte werden abgelehnt');

// ── Aendern, Backup und Kennzeichnung ──

group('update_page, Backup und Historie');

reset_rate_limits();

is_same(history_files('kontakt'), [], 'vor der Aenderung gibt es keine Vorversion');

$r = call_tool('update_page', ['slug' => 'kontakt', 'body' => 'Neuer Kontakttext.']);
ok(!tool_failed($r), 'update_page aendert eine bestehende Seite');
ok(str_contains(tool_text($r), 'nicht mehr'), 'die Antwort sagt, dass die Seite bis zur Freigabe offline ist');

$file = content_file('kontakt.md');
ok(str_contains((string) $file, 'Neuer Kontakttext.'), 'der neue Body steht in der Datei');
ok(str_contains((string) $file, 'title: Kontakt'), 'nicht uebergebene Felder bleiben erhalten');
ok(str_contains((string) $file, 'draft: true'), 'die geaenderte Seite ist ein Entwurf');
ok(str_contains((string) $file, 'lastEditedBy: ai'), 'update_page kennzeichnet die Aenderung');

$versions = history_files('kontakt');
is_same(count($versions), 1, 'update_page legt genau eine Vorversion an');
ok(
    str_contains((string) file_get_contents($versions[0]), 'Schreib uns.'),
    'die Vorversion enthaelt den Stand VOR der Aenderung'
);

$r = call_tool('update_page', ['slug' => 'work/projekt-alpha', 'title' => 'Alpha neu']);
ok(!tool_failed($r), 'update_page funktioniert auch fuer verschachtelte Slugs');
is_same(count(history_files('work/projekt-alpha')), 1, 'auch dort entsteht eine Vorversion');
ok(str_contains((string) content_file('work/projekt-alpha.md'), 'Verschachtelt.'), 'ohne body bleibt der Inhalt unberuehrt');

$r = call_tool('update_page', ['slug' => 'gibt-es-nicht', 'body' => 'x']);
ok(tool_failed($r), 'update_page auf unbekannten Slug -> isError');
ok(str_contains(tool_text($r), 'create_page'), 'die Fehlermeldung verweist auf create_page');

// ── Sofort veroeffentlichen ──

group('Schreiben mit ai_publish_immediately = true');

reset_rate_limits();
write_config(true);

$r = call_tool('create_page', ['slug' => 'sofort-live', 'title' => 'Sofort', 'body' => 'Live.']);
ok(!tool_failed($r), 'create_page mit sofortiger Veroeffentlichung');
ok(str_contains(tool_text($r), 'sofort öffentlich'), 'die Antwort meldet die sofortige Veroeffentlichung');
ok(!str_contains((string) content_file('sofort-live.md'), 'draft:'), 'die Seite traegt kein draft-Flag');

$r = call_tool('update_page', ['slug' => 'home', 'body' => 'Neuer Willkommenstext.']);
ok(!str_contains((string) content_file('home.md'), 'draft:'), 'update_page setzt kein draft-Flag mehr');
ok(str_contains((string) content_file('home.md'), 'lastEditedBy: ai'), 'die Kennzeichnung greift weiterhin');
is_same(count(history_files('home')), 1, 'die Vorversion wird auch bei sofortiger Veroeffentlichung gesichert');

write_config(false);

// ── Loeschen ──

group('delete_page');

reset_rate_limits();

$r = call_tool('delete_page', ['slug' => 'sofort-live']);
ok(!tool_failed($r), 'delete_page loescht eine Seite');
is_same(content_file('sofort-live.md'), false, 'die Datei ist weg');
is_same(count(history_files('sofort-live')), 1, 'vor dem Loeschen wurde gesichert');
ok(str_contains(tool_text($r), 'Sicherungskopie'), 'die Antwort nennt die Sicherungskopie');

$r = call_tool('delete_page', ['slug' => 'sofort-live']);
ok(tool_failed($r), 'delete_page auf eine bereits geloeschte Seite -> isError');

// ── Landingpages ──

group('create_landingpage, update_landingpage');

reset_rate_limits();

$r = call_tool('create_landingpage', [
    'slug'      => 'kampagne',
    'title'     => 'Kampagnenseite',
    'body'      => '<section class="hero"><h1>Willkommen</h1></section>',
    'pageStyle' => '.hero{background:#eee}',
]);
ok(!tool_failed($r), 'create_landingpage legt eine Seite an');
$saved = (string) content_file('kampagne.md');
ok(str_contains($saved, 'contentType: html'), 'contentType: html steht im Frontmatter');
ok(str_contains($saved, 'pageStyle:'), 'pageStyle steht im Frontmatter');
ok(str_contains($saved, '<section class="hero"><h1>Willkommen</h1></section>'), 'der HTML-Body landet unveraendert in der Datei');

$r = call_tool('create_landingpage', ['slug' => 'kampagne', 'title' => 'x', 'body' => '<p>x</p>']);
ok(tool_failed($r), 'create_landingpage auf einen vergebenen Slug -> isError');
ok(str_contains(tool_text($r), 'update_landingpage'), 'die Meldung verweist auf update_landingpage');

$r = call_tool('create_landingpage', ['slug' => 'boese-script', 'title' => 'x', 'body' => '<p>x</p><script>alert(1)</script>']);
ok(tool_failed($r), 'create_landingpage lehnt <script> ab');
is_same(content_file('boese-script.md'), false, 'bei <script> wurde nichts geschrieben');

$r = call_tool('create_landingpage', ['slug' => 'boese-onclick', 'title' => 'x', 'body' => '<button onclick="alert(1)">x</button>']);
ok(tool_failed($r), 'create_landingpage lehnt on*-Attribute ab');

$r = call_tool('create_landingpage', ['slug' => 'boese-kaputt', 'title' => 'x', 'body' => '<style>.x{color:red}<p>Text</p>']);
ok(tool_failed($r), 'create_landingpage lehnt nicht wohlgeformtes HTML ab');

$r = call_tool('create_landingpage', [
    'slug' => 'boese-css', 'title' => 'x', 'body' => '<p>x</p>',
    'pageStyle' => '@import url(https://evil.example/x.css);',
]);
ok(tool_failed($r), 'create_landingpage lehnt externes CSS in pageStyle ab');
is_same(content_file('boese-css.md'), false, 'bei verbotenem CSS wurde nichts geschrieben');

$r = call_tool('create_landingpage', [
    'slug' => 'geschmuggelt', 'title' => 'x', 'body' => '<p>x</p>',
    'meta' => ['pageStyle' => '@import url(https://evil.example/x.css);'],
]);
ok(tool_failed($r), 'ein ueber meta geschmuggeltes pageStyle wird ebenso geprueft');

$r = call_tool('create_landingpage', ['slug' => 'ohne-body', 'title' => 'x']);
ok(tool_failed($r), 'create_landingpage ohne body -> isError');
is_same(content_file('ohne-body.md'), false, 'ohne body wird keine Datei angelegt');

// contentType wird immer erzwungen — auch wenn meta widerspricht.
$r = call_tool('create_landingpage', [
    'slug' => 'erzwungen', 'title' => 'x', 'body' => '<p>x</p>',
    'meta' => ['contentType' => 'markdown'],
]);
ok(!tool_failed($r), 'create_landingpage funktioniert trotz widersprechendem meta.contentType');
ok(str_contains((string) content_file('erzwungen.md'), 'contentType: html'), 'contentType wird dennoch auf html gesetzt');

// list_pages/get_page/delete_page sind slug-basiert und funktionieren fuer
// Landingpages unveraendert mit — kein eigenes get_landingpage noetig.
$r = call_tool('get_page', ['slug' => 'kampagne']);
ok(!tool_failed($r), 'get_page funktioniert auch fuer eine Landingpage');
$page = tool_json($r);
is_same($page['meta']['contentType'] ?? null, 'html', 'get_page liefert contentType mit');
ok(str_contains((string) ($page['body'] ?? ''), '<section class="hero">'), 'get_page liefert den rohen HTML-Body');

$r = call_tool('list_pages');
ok(str_contains(tool_text($r), '"slug": "kampagne"'), 'list_pages enthaelt die Landingpage');

// ── update_landingpage ──

$r = call_tool('update_landingpage', ['slug' => 'kampagne', 'title' => 'Neuer Titel', 'body' => '<p>Neu</p>']);
ok(!tool_failed($r), 'update_landingpage aendert Titel und Body');
$saved = (string) content_file('kampagne.md');
ok(str_contains($saved, 'title: Neuer Titel'), 'der neue Titel steht in der Datei');
ok(str_contains($saved, '<p>Neu</p>'), 'der neue Body steht in der Datei');
ok(str_contains($saved, 'pageStyle:'), 'pageStyle bleibt unveraendert stehen, wenn es nicht mitgegeben wird');
ok(count(history_files('kampagne')) > 0, 'update_landingpage sichert eine Vorversion wie update_page');

$r = call_tool('update_landingpage', ['slug' => 'kampagne', 'pageStyle' => '']);
ok(!tool_failed($r), 'update_landingpage mit leerem pageStyle entfernt das CSS');
ok(!str_contains((string) content_file('kampagne.md'), 'pageStyle:'), 'pageStyle ist aus der Datei verschwunden');

$r = call_tool('update_landingpage', ['slug' => 'gibt-es-nicht', 'body' => '<p>x</p>']);
ok(tool_failed($r), 'update_landingpage auf unbekannten Slug -> isError');
ok(str_contains(tool_text($r), 'create_landingpage'), 'die Fehlermeldung verweist auf create_landingpage');

$r = call_tool('update_landingpage', ['slug' => 'kampagne', 'body' => '<script>alert(1)</script>']);
ok(tool_failed($r), 'update_landingpage lehnt <script> im body ab');
ok(!str_contains((string) content_file('kampagne.md'), '<script>'), 'der abgelehnte Body wurde nicht geschrieben');

$r = call_tool('update_landingpage', [
    'slug' => 'kampagne', 'pageStyle' => '@import url(https://evil.example/x.css);',
]);
ok(tool_failed($r), 'update_landingpage lehnt externes CSS in pageStyle ab');

$r = call_tool('update_landingpage', ['slug' => 'kampagne', 'title' => 'Nur Titel']);
ok(!tool_failed($r), 'update_landingpage ohne body laesst den Inhalt unveraendert');
ok(str_contains((string) content_file('kampagne.md'), '<p>Neu</p>'), 'der Body von vorher ist noch da');

$r = call_tool('delete_page', ['slug' => 'kampagne']);
ok(!tool_failed($r), 'delete_page loescht auch eine Landingpage');
is_same(content_file('kampagne.md'), false, 'die Datei ist weg');

// ── Bilder lesen ──

group('list_images, get_image');

reset_rate_limits();

$r = call_tool('list_images');
ok(!tool_failed($r), 'list_images meldet keinen Fehler');
$text = tool_text($r);
ok(str_contains($text, '"filename": "vorhanden.png"'), 'list_images enthaelt die Fixture');
ok(str_contains($text, '"mime_type": "image/png"'), 'list_images liefert den echten Mime-Typ');
ok(
    preg_match('#"url":\s*"https?://[^"]+/assets/images/vorhanden\.png"#', $text) === 1,
    'list_images liefert eine absolute URL, nicht nur den relativen Pfad'
);

$r = call_tool('get_image', ['filename' => 'vorhanden.png']);
ok(!tool_failed($r), 'get_image meldet keinen Fehler');
$content = $r['json']['result']['content'] ?? [];
is_same($content[0]['type'] ?? null, 'image', 'erster Content-Block ist vom Typ image');
is_same($content[0]['mimeType'] ?? null, 'image/png', 'mimeType steht im Bild-Block');
is_same(base64_decode((string) ($content[0]['data'] ?? '')), $png_bytes, 'die Base64-Daten entsprechen der Datei byteidentisch');
is_same($content[1]['type'] ?? null, 'text', 'zweiter Content-Block ist Text');
ok(str_contains($content[1]['text'] ?? '', 'vorhanden.png'), 'der Text nennt den Dateinamen');

$r = call_tool('get_image', ['filename' => 'grosses-bild.png']);
ok(!tool_failed($r), 'get_image liefert auch bei >5 MB keinen Fehler');
$content = $r['json']['result']['content'] ?? [];
is_same(count($content), 1, 'bei >5 MB gibt es nur einen Content-Block, kein Bild');
is_same($content[0]['type'] ?? null, 'text', 'der eine Block ist Text');
ok(str_contains($content[0]['text'] ?? '', 'zu groß'), 'der Text nennt die 5-MB-Grenze');
ok(str_contains($content[0]['text'] ?? '', '"size_bytes"'), 'der Text enthaelt trotzdem die Metadaten');

$r = call_tool('get_image', ['filename' => 'gibt-es-nicht.png']);
ok(tool_failed($r), 'get_image auf unbekannte Datei -> isError');
ok(str_contains(tool_text($r), 'list_images'), 'die Fehlermeldung verweist auf list_images');

$r = call_tool('get_image', []);
ok(tool_failed($r), 'get_image ohne filename -> isError');

$r = call_tool('get_image', ['filename' => '../../config/config.php']);
ok(tool_failed($r), 'get_image mit Path-Traversal-Dateinamen -> isError');
ok(is_file($tmp_root . '/config/config.php'), 'config.php ist unangetastet');

// ── Bilder hochladen (seit 2026-09-18 deaktiviert) ──
//
// Test & Abnahme hat live gezeigt: Base64-Bild-Upload per Chat ist
// unzuverlässig (Claude Desktop hat eine lange Base64-Zeichenkette nicht
// fehlerfrei erzeugt, ein 31-KB-Foto kam nach 4 Minuten nur zu einem Drittel
// an). upload_image liefert seither immer isError, siehe mcp.php. Die
// ausführliche Validierungs-/Speicher-Logik, die hier vorher getestet wurde
// (Sanitizing, Kollisionsschutz, Format-/Größenprüfung, Truncation-Erkennung,
// Path-Traversal), steckt unverändert in lib/media.php und wird weiterhin
// über tests/admin.php erreicht — cms_validate_image_upload() &Co. bedienen
// ja weiterhin den Browser-Upload im Admin-Backend.

group('upload_image (deaktiviert)');

reset_rate_limits();

$r = call_tool('upload_image', ['filename' => 'test.png', 'image_data' => $png_b64]);
ok(tool_failed($r), 'upload_image liefert isError, auch mit gueltigen Argumenten');
ok(str_contains(tool_text($r), 'deaktiviert'), 'die Meldung nennt den Grund');
ok(str_contains(tool_text($r), 'Admin-Backend'), 'die Meldung verweist auf den Admin-Upload');
is_same(image_file('test.png'), false, 'es wurde keine Datei angelegt');

$r = call_tool('upload_image', []);
ok(tool_failed($r), 'upload_image ohne Argumente liefert ebenfalls isError');

// ── Bilder loeschen ──

group('delete_image');

reset_rate_limits();

// Fixture direkt anlegen statt über upload_image (deaktiviert, siehe oben).
put($tmp_root . '/assets/images/zu-loeschen.png', $png_bytes);

$r = call_tool('delete_image', ['filename' => 'zu-loeschen.png']);
ok(!tool_failed($r), 'delete_image loescht eine bestehende Datei');
is_same(image_file('zu-loeschen.png'), false, 'die Datei ist weg');
ok(str_contains(tool_text($r), 'keine Sicherungskopie'), 'die Antwort weist auf die fehlende Sicherungskopie hin');

$r = call_tool('delete_image', ['filename' => 'zu-loeschen.png']);
ok(tool_failed($r), 'ein zweiter Loeschversuch auf dieselbe (jetzt fehlende) Datei -> isError');

$r = call_tool('delete_image', ['filename' => '../../lib/media.php']);
ok(tool_failed($r), 'Path-Traversal-Dateiname bei delete_image -> isError');
ok(is_file($tmp_root . '/lib/media.php'), 'lib/media.php ist unangetastet');

$r = call_tool('delete_image', []);
ok(tool_failed($r), 'delete_image ohne filename -> isError');

// ── Design-Tokens lesen/schreiben ──

group('get_styles, update_styles');

reset_rate_limits();

// Ganz am Anfang, bevor auch nur ein einziger Schreibzugriff auf style.css
// stattgefunden hat — nur hier lässt sich der "keine Historie"-Fehlerpfad von
// restore_styles ueberhaupt noch zuverlaessig auslösen (danach existiert für
// 'tokens' garantiert eine Vorversion aus den Tests weiter unten).
$r = call_tool('restore_styles', ['target' => 'tokens']);
ok(tool_failed($r), 'restore_styles(tokens) ohne jede Vorversion -> isError');
is_same(tool_text($r), 'Keine vorherige Version zum Wiederherstellen gefunden.', 'die Meldung ist die aus der Spec');

$r = call_tool('get_styles');
ok(!tool_failed($r), 'get_styles meldet keinen Fehler');
$tokens = tool_json($r);
is_same(count($tokens), 10, 'get_styles liefert alle zehn bekannten Tokens');
is_same($tokens['--color-bg'], '#ffffff', 'get_styles liefert den aktuellen Hintergrundfarbwert');
is_same(
    $tokens['--font-heading'],
    "'League Spartan', 'Helvetica Neue', Helvetica, Arial, sans-serif",
    'get_styles liefert die volle Font-Fallback-Kette, nicht nur den Fontnamen'
);

$r = call_tool('update_styles', ['--color-link' => '#0033aa']);
ok(!tool_failed($r), 'update_styles mit gueltiger Aenderung');
is_same(tool_json($r)['changed'] ?? null, ['--color-link' => '#0033aa'], 'die Antwort nennt nur den geaenderten Schluessel');
ok(str_contains(style_css_file(), '--color-link: #0033aa;'), 'der Wert steht jetzt in style.css');
is_same(count(style_history_files('tokens')), 1, 'der Schreibzugriff hat eine Vorversion angelegt');

$r = call_tool('update_styles', ['--color-xyz' => '#fff']);
ok(tool_failed($r), 'unbekannter Token-Schluessel -> isError');
ok(str_contains(tool_text($r), 'Unbekannter Token-Schlüssel'), 'die Meldung nennt den Grund');
is_same(count(style_history_files('tokens')), 1, 'ein abgewiesener Versuch legt keine Vorversion an');

$r = call_tool('update_styles', ['--font-heading' => 'Comic Sans MS']);
ok(tool_failed($r), 'nicht erlaubter Font -> isError');
ok(str_contains(tool_text($r), 'Nicht erlaubter Font'), 'die Meldung nennt den Grund');

$r = call_tool('update_styles', ['--font-heading' => "'Quicksand', 'Helvetica Neue', Helvetica, Arial, sans-serif"]);
ok(!tool_failed($r), 'der jeweils andere selbst gehostete Font ist erlaubt (gemeinsame Whitelist fuer beide Font-Variablen)');
ok(str_contains(style_css_file(), "--font-heading: 'Quicksand', 'Helvetica Neue', Helvetica, Arial, sans-serif;"), 'der neue Font steht in style.css');
is_same(count(style_history_files('tokens')), 2, 'die erfolgreiche Aenderung legt eine zweite Vorversion an');

$r = call_tool('update_styles', ['--color-bg' => '#fff; } body { color: red']);
ok(tool_failed($r), 'Injection-Zeichen im Wert -> isError');
ok(str_contains(tool_text($r), 'unzulässige Zeichen'), 'die Meldung nennt den Grund');

$r = call_tool('update_styles', ['--color-bg' => '']);
ok(tool_failed($r), 'leerer Wert -> isError');

$r = call_tool('update_styles', []);
ok(tool_failed($r), 'update_styles ohne jede Aenderung -> isError');
ok(str_contains(tool_text($r), 'keine Token-Änderungen'), 'die Meldung nennt den Grund');
is_same(count(style_history_files('tokens')), 2, 'keiner der vier Fehlversuche hat eine Vorversion angelegt');

$r = call_tool('update_styles', ['--max-width' => '1200px', '--header-height' => '90px']);
ok(!tool_failed($r), 'update_styles mit mehreren Schluesseln gleichzeitig');
is_same(
    tool_json($r)['changed'] ?? null,
    ['--max-width' => '1200px', '--header-height' => '90px'],
    'die Antwort nennt beide geaenderten Schluessel'
);
is_same(count(style_history_files('tokens')), 3, 'auch der Mehrfach-Schreibzugriff legt genau eine Vorversion an');

ok(
    str_contains(style_css_file(), "font-family: 'League Spartan';"),
    'der Rest von style.css (u.a. der @font-face-Block) ist unangetastet'
);
ok(str_contains(style_css_file(), '--color-bg: #ffffff;'), 'nicht geaenderte Tokens bleiben unveraendert');

// ── Freies CSS lesen/schreiben ──

group('get_custom_css, update_custom_css');

reset_rate_limits();

$r = call_tool('restore_styles', ['target' => 'custom_css']);
ok(tool_failed($r), 'restore_styles(custom_css) ohne jede Vorversion -> isError');

is_same(custom_css_file(), false, 'assets/custom.css existiert noch nicht');
$r = call_tool('get_custom_css');
ok(!tool_failed($r), 'get_custom_css meldet keinen Fehler, auch wenn die Datei fehlt');
ok(str_contains(tool_text($r), 'existiert noch nicht'), 'die Antwort sagt, dass es noch kein freies CSS gibt');

$css1 = 'body { color: hotpink; }';
$r    = call_tool('update_custom_css', ['css' => $css1]);
ok(!tool_failed($r), 'update_custom_css legt die Datei beim ersten Schreibzugriff an');
is_same(custom_css_file(), $css1, 'der Inhalt steht in assets/custom.css');
is_same(style_history_files('custom_css'), [], 'kein Backup beim allerersten Schreibzugriff (nichts zu sichern)');

$r = call_tool('get_custom_css');
is_same(tool_text($r), $css1, 'get_custom_css liefert den rohen Inhalt, ohne Notiz davor');

foreach ([
    '@import url("https://evil.example/x.css");',
    "body { background: url('https://evil.example/bg.png'); }",
    'body { background: url(//evil.example/bg.png); }',
    "body { background: url('../../config/config.php'); }",
] as $bad_css) {
    $r = call_tool('update_custom_css', ['css' => $bad_css]);
    ok(tool_failed($r), 'Blockliste weist ab: ' . $bad_css);
    ok(str_contains(tool_text($r), 'Externe Nachladevorgänge'), 'die Meldung nennt den Grund');
}
is_same(custom_css_file(), $css1, 'keiner der vier Blockliste-Versuche hat etwas geschrieben');
ok(is_file($tmp_root . '/config/config.php'), 'config.php ist unangetastet (Traversal-Versuch in url())');

$r = call_tool('update_custom_css', []);
ok(tool_failed($r), 'update_custom_css ohne css-Feld -> isError');
ok(str_contains(tool_text($r), 'fehlt oder ist kein Text'), 'die Meldung nennt den Grund');

$r = call_tool('update_custom_css', ['css' => 123]);
ok(tool_failed($r), 'update_custom_css mit einer Zahl statt Text -> isError');

$css2 = 'body { background: url(data:image/png;base64,AAAA); }';
$r    = call_tool('update_custom_css', ['css' => $css2]);
ok(!tool_failed($r), 'data:-URL ist erlaubt');
is_same(custom_css_file(), $css2, 'der neue Inhalt steht in der Datei');
is_same(count(style_history_files('custom_css')), 1, 'der zweite Schreibzugriff sichert den ersten Stand');

$css3 = "body { background: url('assets/images/vorhanden.png'); }";
$r    = call_tool('update_custom_css', ['css' => $css3]);
ok(!tool_failed($r), 'ein assets/-relativer Pfad in url() ist erlaubt');
is_same(count(style_history_files('custom_css')), 2, 'auch dieser Schreibzugriff legt eine Vorversion an');

$r = call_tool('update_custom_css', ['css' => '']);
ok(!tool_failed($r), 'ein leerer Text ist ein gueltiger Aufruf (entfernt das freie CSS wieder)');
ok(str_contains(tool_text($r), 'nicht mehr eingebunden'), 'die Antwort weist auf die fehlende Einbindung hin');
is_same(custom_css_file(), '', 'die Datei ist jetzt leer, aber nicht geloescht');
is_same(count(style_history_files('custom_css')), 3, 'auch das Leeren sichert den vorherigen Stand');

$r = call_tool('get_custom_css');
ok(str_contains(tool_text($r), 'existiert noch nicht'), 'get_custom_css behandelt eine leere Datei wie eine fehlende');

// ── Style-Undo ──

group('restore_styles');

reset_rate_limits();

$r = call_tool('restore_styles', []);
ok(tool_failed($r), 'restore_styles ohne target -> isError');

$r = call_tool('restore_styles', ['target' => 'unbekannt']);
ok(tool_failed($r), 'restore_styles mit ungueltigem target -> isError');
ok(str_contains(tool_text($r), '"tokens" oder "custom_css"'), 'die Meldung nennt die erlaubten Werte');

// tokens: der letzte Schreibzugriff oben war die Aenderung auf
// max-width/header-height — die juengste Vorversion davor hatte bereits
// --color-link: #0033aa und den Quicksand-Heading-Font, aber noch die
// urspruenglichen Layout-Masse.
$before = style_history_files('tokens');
$r      = call_tool('restore_styles', ['target' => 'tokens']);
ok(!tool_failed($r), 'restore_styles(tokens) stellt die juengste Vorversion wieder her');
ok(str_contains(tool_text($r), 'Design-Tokens'), 'die Antwort nennt das Ziel');
ok(str_contains(style_css_file(), '--max-width: 1100px;'), 'das Layout-Mass ist wieder auf dem Vor-Vorversions-Stand');
ok(str_contains(style_css_file(), '--color-link: #0033aa;'), 'die davor gemachte Farb-Aenderung bleibt erhalten');
is_same(count(style_history_files('tokens')), count($before) + 1, 'der ueberschriebene Stand wurde vor dem Zurücksetzen selbst gesichert');

$r = call_tool('restore_styles', ['target' => 'tokens']);
ok(!tool_failed($r), 'ein zweiter restore_styles(tokens) macht den ersten Restore rueckgaengig');
ok(str_contains(style_css_file(), '--max-width: 1200px;'), 'das Layout-Mass ist wieder auf dem Stand von vor dem ersten Restore');

// custom_css: der letzte Schreibzugriff oben hat die Datei geleert — die
// juengste Vorversion enthaelt daher wieder $css3.
$r = call_tool('restore_styles', ['target' => 'custom_css']);
ok(!tool_failed($r), 'restore_styles(custom_css) stellt die juengste Vorversion wieder her');
is_same(custom_css_file(), $css3, 'der Inhalt von vor dem Leeren ist wieder da');

$r = call_tool('restore_styles', ['target' => 'custom_css']);
ok(!tool_failed($r), 'ein zweiter restore_styles(custom_css) macht den ersten Restore rueckgaengig');
is_same(custom_css_file(), '', 'die Datei ist wieder leer');

// ── Redirects ──

group('list_redirects, create_redirect, delete_redirect');

reset_rate_limits();

$r = call_tool('list_redirects');
ok(!tool_failed($r), 'list_redirects funktioniert ohne bestehende Redirects');
ok(str_contains(tool_text($r), 'Noch keine Redirects'), 'Hinweistext bei leerer Liste');

$r = call_tool('create_redirect', ['from' => 'ohne-slash', 'to' => '/ziel']);
ok(tool_failed($r), 'create_redirect ohne führenden "/" bei from -> isError');

$r = call_tool('create_redirect', ['from' => '/kontakt', 'to' => '/neu']);
ok(tool_failed($r), 'create_redirect auf eine noch existierende Seite ("kontakt") -> isError');
ok(str_contains(tool_text($r), 'verdecken'), 'die Meldung nennt den Grund');

$r = call_tool('create_redirect', ['from' => '/entwurf', 'to' => '/neu']);
ok(tool_failed($r), 'Kollisionsprüfung erfasst auch eine noch unveröffentlichte Seite ("entwurf")');

$r = call_tool('create_redirect', ['from' => '/alte-kampagne', 'to' => '/kontakt']);
ok(!tool_failed($r), 'create_redirect legt einen gültigen Redirect an');
$created = tool_json($r)['redirect'] ?? null;
ok($created !== null && str_starts_with($created['id'], 'rdr_'), 'die Antwort enthält den angelegten Redirect samt id');
is_same(count(redirects_in_file()), 1, 'genau ein Redirect wurde gespeichert');

$r = call_tool('create_redirect', ['from' => '/alte-kampagne', 'to' => '/anderes-ziel']);
ok(tool_failed($r), 'zweiter Redirect mit derselben Quelle -> isError (kein Überschreiben)');
is_same(count(redirects_in_file()), 1, 'weiterhin nur ein Redirect');

$r = call_tool('list_redirects');
ok(!tool_failed($r), 'list_redirects funktioniert mit bestehendem Eintrag');
$listed = tool_json($r);
is_same(count($listed), 1, 'list_redirects zeigt genau einen Eintrag');
is_same($listed[0]['from'] ?? null, '/alte-kampagne', 'from steht in der Liste');

$r = call_tool('create_redirect', ['from' => '/kettenquelle', 'to' => '/alte-kampagne']);
ok(!tool_failed($r), 'ein Redirect auf eine bestehende Redirect-Quelle wird trotzdem angelegt');
ok(str_contains(tool_text($r), 'Kette'), 'die Antwort weist auf die entstehende Kette hin (nicht blockierend)');
$kettenquelle = tool_json($r)['redirect']; // bleibt bis zum Ende bestehen — fuer den Rate-Limit-Test unten gebraucht

$r = call_tool('delete_redirect', []);
ok(tool_failed($r), 'delete_redirect ohne id -> isError');

$r = call_tool('delete_redirect', ['id' => 'rdr_unbekannt']);
ok(tool_failed($r), 'delete_redirect mit unbekannter id -> isError');
is_same(count(redirects_in_file()), 2, 'nichts wurde durch den Fehlversuch verändert');

$r = call_tool('delete_redirect', ['id' => $created['id']]);
ok(!tool_failed($r), 'delete_redirect löscht den bestehenden Redirect');
is_same(count(redirects_in_file()), 1, 'ein Eintrag weniger nach dem Löschen');

$r = call_tool('delete_redirect', ['id' => $created['id']]);
ok(tool_failed($r), 'ein zweiter Löschversuch auf dieselbe id -> isError');

// ── Kategorien ──

group('Kategorien: list_categories, Meta-Feld categories, Filter in list_pages');

reset_rate_limits();

$r = call_tool('list_categories');
ok(!tool_failed($r), 'list_categories antwortet ohne Fehler');
is_same(
    tool_json($r)['categories'] ?? null,
    [['slug' => 'seo', 'name' => 'SEO'], ['slug' => 'wordpress', 'name' => 'WordPress']],
    'list_categories liefert Slug und Name aus der Config'
);

$r = call_tool('create_page', [
    'slug' => 'kat-eins', 'title' => 'Kategorie Eins', 'body' => "Text.\n\n[category-list category=\"seo\" style=\"cards\"]\n",
    'meta' => ['categories' => 'seo, wordpress'],
]);
ok(!tool_failed($r), 'create_page nimmt kommagetrennte Kategorien an');
ok(
    preg_match('/^categories: "?seo, wordpress"?$/m', (string) content_file('kat-eins.md')) === 1,
    'beide Slugs stehen im Frontmatter'
);
ok(
    str_contains((string) content_file('kat-eins.md'), '[category-list category="seo" style="cards"]'),
    'der Shortcode steht unverändert im Seiteninhalt'
);

$r = call_tool('get_page', ['slug' => 'kat-eins']);
is_same(tool_json($r)['categories'] ?? null, ['seo', 'wordpress'], 'get_page gibt categories als Liste zurück');

$r = call_tool('create_page', [
    'slug' => 'kat-zwei', 'title' => 'Kategorie Zwei', 'body' => 'Text.',
    'meta' => ['categories' => ['wordpress']],
]);
ok(!tool_failed($r), 'create_page nimmt Kategorien auch als Liste an');
is_same(tool_json(call_tool('get_page', ['slug' => 'kat-zwei']))['categories'] ?? null, ['wordpress'], 'die Liste ist als Zuordnung gespeichert');

$r = call_tool('create_page', [
    'slug' => 'kat-falsch', 'title' => 'Kategorie Falsch', 'body' => 'Text.',
    'meta' => ['categories' => 'seo, gibtsnicht'],
]);
ok(tool_failed($r), 'create_page mit unbekanntem Slug -> isError');
ok(
    str_contains(tool_text($r), 'gibtsnicht') && str_contains(tool_text($r), 'seo, wordpress'),
    'die Meldung nennt den unbekannten und die gültigen Slugs'
);
is_same(content_file('kat-falsch.md'), false, 'die abgelehnte Seite wurde nicht angelegt');

$r = call_tool('create_page', [
    'slug' => 'kat-falsch', 'title' => 'Kategorie Falsch', 'body' => 'Text.',
    'meta' => ['categories' => ['seo', ['verschachtelt']]],
]);
ok(tool_failed($r), 'eine verschachtelte Liste als categories -> isError');

// Merge: update_page ohne categories lässt die Zuordnung stehen.
$r = call_tool('update_page', ['slug' => 'kat-eins', 'title' => 'Kategorie Eins, neu']);
ok(!tool_failed($r), 'update_page ohne categories');
is_same(
    tool_json(call_tool('get_page', ['slug' => 'kat-eins']))['categories'] ?? null,
    ['seo', 'wordpress'],
    'die bestehende Zuordnung bleibt erhalten'
);
$r = call_tool('update_page', ['slug' => 'kat-eins', 'meta' => ['description' => 'Nur ein anderes Meta-Feld']]);
is_same(
    tool_json(call_tool('get_page', ['slug' => 'kat-eins']))['categories'] ?? null,
    ['seo', 'wordpress'],
    'auch ein Update mit anderem Meta-Feld verliert sie nicht'
);

$r = call_tool('update_page', ['slug' => 'kat-eins', 'meta' => ['categories' => 'gibtsnicht']]);
ok(tool_failed($r), 'update_page mit unbekanntem Slug -> isError');
is_same(
    tool_json(call_tool('get_page', ['slug' => 'kat-eins']))['categories'] ?? null,
    ['seo', 'wordpress'],
    'der abgelehnte Aufruf hat die Zuordnung nicht verändert'
);

$r = call_tool('update_page', ['slug' => 'kat-eins', 'meta' => ['categories' => 'seo']]);
ok(!tool_failed($r), 'update_page ersetzt die Zuordnung');
is_same(tool_json(call_tool('get_page', ['slug' => 'kat-eins']))['categories'] ?? null, ['seo'], 'nur noch die neue Kategorie');

// Filter und Listenausgabe.
$r     = call_tool('list_pages');
$pages = array_column(tool_json($r)['pages'] ?? [], 'categories', 'slug');
is_same($pages['kat-eins'] ?? null, ['seo'], 'list_pages gibt categories je Seite als Liste zurück');
is_same($pages['kontakt'] ?? null, [], 'eine Seite ohne Kategorie hat eine leere Liste');

$r = call_tool('list_pages', ['category' => 'wordpress']);
is_same(array_column(tool_json($r)['pages'] ?? [], 'slug'), ['kat-zwei'], 'list_pages mit category filtert auf die Kategorie');

$r = call_tool('list_pages', ['category' => 'wordpress', 'include_drafts' => false]);
ok(
    !tool_failed($r) && str_contains(tool_text($r), 'keine Seiten'),
    'ohne Entwürfe bleibt die Kategorie leer (beide Testseiten sind KI-Entwürfe)'
);

$r = call_tool('list_pages', ['category' => 'gibtsnicht']);
ok(tool_failed($r), 'list_pages mit unbekannter Kategorie -> isError');
ok(str_contains(tool_text($r), 'seo, wordpress'), 'die Meldung nennt die gültigen Slugs');

// Eine Seite mit einer inzwischen gelöschten Kategorie bleibt änderbar,
// solange das Feld nicht mitgesendet wird.
put($tmp_root . '/content/kat-alt.md',
    "---\ntitle: Kategorie Alt\nslug: kat-alt\ncategories: \"seo, geloescht\"\n---\n\nText.\n");
$r = call_tool('update_page', ['slug' => 'kat-alt', 'body' => 'Neuer Text.']);
ok(!tool_failed($r), 'update_page ohne categories auf einer Seite mit gelöschter Kategorie');
is_same(
    tool_json(call_tool('get_page', ['slug' => 'kat-alt']))['categories'] ?? null,
    ['seo', 'geloescht'],
    'der alte Wert bleibt unangetastet stehen'
);

// Leerer Wert entfernt die Zuordnung.
$r = call_tool('update_page', ['slug' => 'kat-alt', 'meta' => ['categories' => '']]);
ok(!tool_failed($r), 'update_page mit leerem categories');
is_same(tool_json(call_tool('get_page', ['slug' => 'kat-alt']))['categories'] ?? null, [], 'die Zuordnung ist entfernt');

// Landingpages nutzen dasselbe Meta-Objekt und damit dieselbe Prüfung.
$r = call_tool('create_landingpage', [
    'slug' => 'kat-landing', 'title' => 'Kategorie Landing', 'body' => '<section><h1>Hallo</h1></section>',
    'meta' => ['categories' => 'gibtsnicht'],
]);
ok(tool_failed($r), 'create_landingpage mit unbekanntem Slug -> isError');
is_same(content_file('kat-landing.md'), false, 'die abgelehnte Landingpage wurde nicht angelegt');

$r = call_tool('create_landingpage', [
    'slug' => 'kat-landing', 'title' => 'Kategorie Landing', 'body' => '<section><h1>Hallo</h1></section>',
    'meta' => ['categories' => 'seo'],
]);
ok(!tool_failed($r), 'create_landingpage mit gültiger Kategorie');
$r = call_tool('update_landingpage', ['slug' => 'kat-landing', 'meta' => ['categories' => 'nochmal-falsch']]);
ok(tool_failed($r), 'update_landingpage mit unbekanntem Slug -> isError');
is_same(tool_json(call_tool('get_page', ['slug' => 'kat-landing']))['categories'] ?? null, ['seo'], 'die Zuordnung der Landingpage ist unverändert');

// Ohne Kategorien in der Config: verständliche Antwort statt leerer Liste.
$config_path   = $tmp_root . '/config/config.php';
$config_backup = (string) file_get_contents($config_path);
$config_data   = require $config_path;
unset($config_data['categories']);
put($config_path, "<?php\nreturn " . var_export($config_data, true) . ";\n");
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($config_path, true);
}

$r = call_tool('list_categories');
ok(!tool_failed($r) && str_contains(tool_text($r), 'noch keine Kategorien'), 'list_categories ohne Kategorien nennt das Admin-Backend');
$r = call_tool('update_page', ['slug' => 'kat-zwei', 'meta' => ['categories' => 'seo']]);
ok(tool_failed($r) && str_contains(tool_text($r), 'noch keine Kategorien'), 'Setzen ohne angelegte Kategorien -> isError mit Hinweis');

put($config_path, $config_backup);

foreach (['kat-eins', 'kat-zwei', 'kat-alt', 'kat-landing'] as $kat_slug) {
    call_tool('delete_page', ['slug' => $kat_slug]);
}

// ── Rate-Limits ──

group('Rate-Limits');

reset_rate_limits();

// Schreib-Limit: 50 Tool-Calls pro Fenster und Installation.
// Lesen zaehlt nicht mit, deshalb zwischendurch ein get_page.
for ($i = 1; $i <= 50; $i++) {
    $r = call_tool('update_page', ['slug' => 'neue-seite', 'body' => "Fassung {$i}"]);
    if (tool_failed($r)) {
        break;
    }
}
is_same($i, 51, '50 Schreibzugriffe gehen durch');

$r = call_tool('get_page', ['slug' => 'neue-seite']);
ok(!tool_failed($r), 'Lesen bleibt auch nach 50 Schreibzugriffen unbegrenzt');

$r = call_tool('update_page', ['slug' => 'neue-seite', 'body' => 'Einer zu viel']);
ok(tool_failed($r), 'der 51. Schreibzugriff wird abgewiesen');
ok(str_contains(tool_text($r), 'Tageslimit'), 'die Meldung nennt das Tageslimit');
ok(str_contains(tool_text($r), 'Admin-Backend'), 'die Meldung nennt den Weg ueber das Admin-Backend');
ok(
    !str_contains((string) content_file('neue-seite.md'), 'Einer zu viel'),
    'der abgewiesene Schreibzugriff hat nichts geschrieben'
);

// Pruning: 51 Sicherungen sind entstanden, behalten werden 20.
is_same(count(history_files('neue-seite')), 20, 'das Pruning haelt die Historie bei 20 Vorversionen');

// upload_image ist deaktiviert (siehe eigene Gruppe) und liefert daher immer
// isError, unabhaengig vom Rate-Limit — kein sinnvoller Test hier mehr.
// delete_image bleibt aktiv und teilt sich weiterhin den Zaehler mit den
// Seiten-Tools (Abschnitt 6.1).
$r = call_tool('delete_image', ['filename' => 'vorhanden.png']);
ok(tool_failed($r), 'auch delete_image wird bei ausgeschoepftem Limit abgewiesen');
is_same(image_file('vorhanden.png'), $png_bytes, 'die Fixture ist unangetastet');

// list_images/get_image bleiben trotz ausgeschoepftem Schreib-Limit unbegrenzt.
$r = call_tool('list_images');
ok(!tool_failed($r), 'list_images bleibt trotz ausgeschoepftem Schreib-Limit unbegrenzt');
$r = call_tool('get_image', ['filename' => 'vorhanden.png']);
ok(!tool_failed($r), 'get_image bleibt trotz ausgeschoepftem Schreib-Limit unbegrenzt');

// Die drei Style-Schreib-Tools teilen sich denselben Zaehler (Abschnitt 6.1)
// — Vorversionen aus den Style-Tests weiter oben existieren
// bereits, restore_styles(tokens) muss deshalb an der Tageslimit-Meldung
// scheitern, nicht an "keine Historie".
$style_before = style_css_file();
$r            = call_tool('update_styles', ['--color-link' => '#000000']);
ok(tool_failed($r), 'update_styles wird bei ausgeschoepftem Limit abgewiesen');
ok(str_contains(tool_text($r), 'Tageslimit'), 'die Meldung nennt das Tageslimit');
is_same(style_css_file(), $style_before, 'der abgewiesene Schreibzugriff hat style.css nicht veraendert');

$custom_before = custom_css_file();
$r             = call_tool('update_custom_css', ['css' => 'body { color: green; }']);
ok(tool_failed($r), 'update_custom_css wird bei ausgeschoepftem Limit abgewiesen');
is_same(custom_css_file(), $custom_before, 'der abgewiesene Schreibzugriff hat custom.css nicht veraendert');

$r = call_tool('restore_styles', ['target' => 'tokens']);
ok(tool_failed($r), 'restore_styles wird bei ausgeschoepftem Limit abgewiesen');
ok(
    str_contains(tool_text($r), 'Tageslimit'),
    'restore_styles zeigt die Tageslimit-Meldung, nicht "keine Historie" (Vorversionen existieren bereits)'
);

// Die Landingpage-Tools teilen sich denselben Zaehler wie die Seiten-Tools.
$r = call_tool('create_landingpage', ['slug' => 'limit-test', 'title' => 'x', 'body' => '<p>x</p>']);
ok(tool_failed($r), 'create_landingpage wird bei ausgeschoepftem Limit abgewiesen');
is_same(content_file('limit-test.md'), false, 'der abgewiesene Aufruf hat nichts angelegt');

$r = call_tool('update_landingpage', ['slug' => 'neue-seite', 'body' => '<p>x</p>']);
ok(tool_failed($r), 'update_landingpage wird bei ausgeschoepftem Limit abgewiesen');

$r = call_tool('get_styles');
ok(!tool_failed($r), 'get_styles bleibt trotz ausgeschoepftem Schreib-Limit unbegrenzt');
$r = call_tool('get_custom_css');
ok(!tool_failed($r), 'get_custom_css bleibt trotz ausgeschoepftem Schreib-Limit unbegrenzt');

// Die Redirect-Schreib-Tools teilen sich denselben Zaehler wie alle anderen
// Schreib-Tools, analog den Bild-/Style-/Landingpage-Tools.
// reset_ip_rate_limit() raeumt nur den allgemeinen 60/Minute-Zaehler weg
// (siehe deren Kommentar) — der ausgeschoepfte Schreib-Zaehler bleibt stehen.
reset_ip_rate_limit();
$redirects_before_limit = redirects_in_file();
$r = call_tool('create_redirect', ['from' => '/limit-test', 'to' => '/kontakt']);
ok(tool_failed($r), 'create_redirect wird bei ausgeschoepftem Limit abgewiesen');
is_same(redirects_in_file(), $redirects_before_limit, 'der abgewiesene Aufruf hat nichts angelegt');

// Existenzpruefung laeuft vor dem Limit (analog delete_page/delete_image) —
// eine unbekannte id scheitert deshalb weiterhin an "nicht gefunden", nicht
// am Tageslimit, unabhaengig vom Zaehlerstand.
$r = call_tool('delete_redirect', ['id' => 'rdr_unbekannt']);
ok(tool_failed($r), 'delete_redirect mit unbekannter id bleibt abgewiesen');
ok(str_contains(tool_text($r), 'nicht gefunden'), 'Meldung "nicht gefunden", nicht das Tageslimit — Existenzpruefung laeuft zuerst');

// Mit einem tatsaechlich existierenden Redirect greift dagegen das Tageslimit.
$r = call_tool('delete_redirect', ['id' => $kettenquelle['id']]);
ok(tool_failed($r), 'delete_redirect eines bestehenden Redirects wird bei ausgeschoepftem Limit abgewiesen');
ok(str_contains(tool_text($r), 'Tageslimit'), 'die Meldung nennt jetzt das Tageslimit, nicht "nicht gefunden"');
is_same(count(redirects_in_file()), 1, 'der Redirect ist weiterhin vorhanden, der abgewiesene Aufruf hat nichts geloescht');

$r = call_tool('list_redirects');
ok(!tool_failed($r), 'list_redirects bleibt trotz ausgeschoepftem Schreib-Limit unbegrenzt');

// Request-Limit pro IP, greift vor der Authentifizierung.
reset_rate_limits();
$statuses = [];
for ($i = 1; $i <= 61; $i++) {
    $statuses[] = rpc('ping', [], 1, 'falscher-token')['status'];
}
is_same($statuses[0], 401, 'die ersten Requests werden regulaer mit 401 abgewiesen');
is_same($statuses[60], 429, 'nach 60 Requests je Minute greift das Limit — auch ohne gueltigen Token');

// ── MCP-Rollen ──
//
// Ausführliche Login-/Benutzerverwaltungs-Abdeckung steht in tests/admin.php
// (eigene Superlist-Unteraufgabe) — hier geht es nur um das, was mcp.php
// selbst neu macht: tools/list gefiltert nach Rolle, Rechteprüfung bei jedem
// einzelnen tools/call, gesperrte Nutzer werden abgewiesen.

group('MCP-Rollen');

reset_rate_limits();

$creator_token = add_test_user($tmp_root, 'content_creator');

$r         = rpc('tools/list', [], 1, $creator_token);
$c_tools   = $r['json']['result']['tools'] ?? [];
$c_names   = array_column($c_tools, 'name');
is_same(count($c_names), 12, 'Content Creator sieht nur die zwoelf Content-/Landingpage-/Kategorien-/Bild-Tools');
is_same(
    $c_names,
    ['list_pages', 'get_page', 'create_page', 'update_page', 'delete_page', 'create_landingpage', 'update_landingpage', 'list_categories', 'list_images', 'get_image', 'upload_image', 'delete_image'],
    'keine Styles-Tools in der Liste, Reihenfolge wie beim Admin'
);

$r = rpc('tools/list', [], 1, $token);
is_same(count($r['json']['result']['tools'] ?? []), 20, 'Admin sieht weiterhin alle zwanzig Tools (Regression)');

// Erlaubtes Tool funktioniert für den Content Creator ganz normal.
$r = rpc('tools/call', ['name' => 'list_pages', 'arguments' => new stdClass()], 1, $creator_token);
ok(!tool_failed($r), 'list_pages funktioniert für den Content Creator (content-Recht)');

// Nicht nur lesen: die gefilterte Liste enthält auch die Schreib-Tools
// (create_page/update_page/delete_page) — hier stellvertretend im Round-Trip
// durchgespielt, damit "erlaubt laut tools/list" nicht nur behauptet ist.
$r = rpc('tools/call', ['name' => 'create_page', 'arguments' => (object) [
    'slug' => 'cc-mcp-seite', 'title' => 'Von der Content Creator per MCP', 'body' => 'Testinhalt.',
]], 1, $creator_token);
ok(!tool_failed($r), 'Content Creator kann create_page tatsächlich aufrufen, nicht nur list_pages');
ok(is_file($tmp_root . '/content/cc-mcp-seite.md'), 'Datei liegt wirklich im Dateisystem');

$r = rpc('tools/call', ['name' => 'delete_page', 'arguments' => (object) ['slug' => 'cc-mcp-seite']], 1, $creator_token);
ok(!tool_failed($r), 'Content Creator kann die Seite über delete_page auch wieder entfernen');
clearstatcache(true, $tmp_root . '/content/cc-mcp-seite.md');
ok(!is_file($tmp_root . '/content/cc-mcp-seite.md'), 'Datei ist wirklich weg');

// Serverseitige Rechteprüfung bei tools/call greift zusätzlich zur
// gefilterten Liste — ein
// Styles-Tool bleibt auch dann gesperrt, wenn es direkt (am Client-Cache
// vorbei) aufgerufen wird.
$r = rpc('tools/call', ['name' => 'get_styles', 'arguments' => new stdClass()], 1, $creator_token);
ok(tool_failed($r), 'get_styles wird für den Content Creator per tools/call abgelehnt, nicht nur ausgeblendet');
ok(str_contains(tool_text($r), 'Content Creator'), 'die Fehlermeldung nennt die eigene Rolle');

$r = rpc('tools/call', ['name' => 'update_custom_css', 'arguments' => (object) ['css' => 'body{}']], 1, $creator_token);
ok(tool_failed($r), 'update_custom_css wird für den Content Creator ebenfalls abgelehnt');
is_same(custom_css_file(), $custom_before, 'der abgelehnte Aufruf hat custom.css nicht veraendert');

// Redirects-Tools sind wie Styles admin-only.
$redirects_before = count(redirects_in_file());
$r = rpc('tools/call', ['name' => 'create_redirect', 'arguments' => (object) ['from' => '/von-cc', 'to' => '/ziel']], 1, $creator_token);
ok(tool_failed($r), 'create_redirect wird für den Content Creator abgelehnt');
is_same(count(redirects_in_file()), $redirects_before, 'der abgelehnte Aufruf hat nichts angelegt');

// initialize-Instructions sind rollenabhängig gekürzt, damit Claude keine
// Tools erwähnt, die für diesen Zugang gar nicht existieren.
$r = rpc('initialize', [], 1, $token);
ok(str_contains($r['json']['result']['instructions'] ?? '', 'update_styles'), 'Admin-Instructions erwähnen die Styles-Tools');
ok(str_contains($r['json']['result']['instructions'] ?? '', 'create_redirect'), 'Admin-Instructions erwähnen die Redirect-Tools');
$r = rpc('initialize', [], 1, $creator_token);
ok(!str_contains($r['json']['result']['instructions'] ?? '', 'update_styles'), 'Content-Creator-Instructions erwähnen sie nicht');
ok(!str_contains($r['json']['result']['instructions'] ?? '', 'create_redirect'), 'Content-Creator-Instructions erwähnen die Redirect-Tools ebenfalls nicht');

// Gesperrter Nutzer: gleiche Ablehnung wie ein ungueltiger Token — mcp.php prueft den Status bei jedem Request neu.
$locked_token = add_test_user($tmp_root, 'content_creator', 'gesperrt');
$r = rpc('ping', [], 1, $locked_token);
is_same($r['status'], 401, 'ein gesperrter Nutzer wird abgelehnt');
is_same(
    $r['json']['error']['message'] ?? null,
    'Ungültiger oder fehlender Connector-Token.',
    'gleiche Meldung wie bei einem ungueltigen Token'
);

// ─── Ergebnis ────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 60) . "\n";
if ($tests_failed === 0) {
    echo "Alle {$tests_run} Pruefungen bestanden.\n";
    exit(0);
}
echo "{$tests_failed} von {$tests_run} Pruefungen fehlgeschlagen.\n";
exit(1);
