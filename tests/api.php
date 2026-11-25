<?php
/**
 * End-to-End-Tests für api.php
 *
 * Aufruf:  php tests/api.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Anders als tests/roundtrip.php werden hier keine Funktionen aufgerufen,
 * sondern echte HTTP-Requests gestellt: Auth, Routing, Statuscodes und
 * Header lassen sich nur über den Transport prüfen.
 *
 * Dafür baut das Skript ein Wegwerf-Webroot in sys_get_temp_dir() (Kopie von
 * api.php und lib/, eigenes content/ und config/config.php) und startet
 * darauf einen php -S. Die echten Inhalte des Repos werden nicht angefasst,
 * und der Rate-Limit-Zähler landet ebenfalls im Wegwerf-Verzeichnis.
 *
 * Diese Datei wird nicht deployed — siehe exclude-Liste in
 * .github/workflows/deploy.yml.
 */

$repo_root = dirname(__DIR__);
$tmp_root  = sys_get_temp_dir() . '/cms-api-test-' . getmypid();
$api_key   = 'test-key-' . bin2hex(random_bytes(8));

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
 * api.php muss kopiert werden, nicht verlinkt: __DIR__ folgt Symlinks, und
 * die Datei würde sonst content/ und config/ des echten Repos benutzen.
 */
function build_webroot(string $repo_root, string $tmp_root, string $api_key): void {
    cleanup($tmp_root);
    mkdir($tmp_root . '/lib', 0777, true);
    mkdir($tmp_root . '/content', 0777, true);

    copy($repo_root . '/api.php', $tmp_root . '/api.php');
    copy($repo_root . '/lib/security.php', $tmp_root . '/lib/security.php');
    copy($repo_root . '/lib/content.php', $tmp_root . '/lib/content.php');
    // api.php bindet lib/config.php ebenfalls zwingend ein (cms_site_domain()).
    copy($repo_root . '/lib/config.php', $tmp_root . '/lib/config.php');

    put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export([
        'site_title'  => 'Testseite',
        // Das 'api'-Feld in der Uebersicht leitet sich aus dieser Domain ab
        // (cms_site_domain()) statt aus einem festen Literal.
        'site_domain' => 'https://testseite.example',
        'api_key'     => $api_key,
    ], true) . ";\n");

    // Router für php -S: simuliert auf Wunsch einen Server ohne PATH_INFO,
    // damit api_route() den Rückfallweg über REQUEST_URI nehmen muss.
    // Alle anderen Requests bedient php -S wie ohne Router.
    put($tmp_root . '/router.php', <<<'PHP'
<?php
if (isset($_GET['ohne_path_info'])) {
    unset($_SERVER['PATH_INFO']);
    require __DIR__ . '/api.php';
    return true;
}
return false;
PHP);
}

/** Legt die Testinhalte an. */
function build_fixtures(string $tmp_root): void {
    put($tmp_root . '/content/home.md',
        "---\ntitle: Startseite\nslug: home\norder: 1\nnavLabel: Start\n---\n\n# Willkommen\n");
    put($tmp_root . '/content/kontakt.md',
        "---\ntitle: Kontakt\nslug: kontakt\norder: 3\n---\n\nSchreib uns.\n");
    put($tmp_root . '/content/about.md',
        "---\ntitle: Über uns\nslug: about\norder: 2\nshowInFooter: false\n---\n\nWer wir sind.\n");
    put($tmp_root . '/content/entwurf.md',
        "---\ntitle: Entwurf\nslug: entwurf\ndraft: true\n---\n\nNoch nicht fertig.\n");
    put($tmp_root . '/content/work/projekt-alpha.md',
        "---\ntitle: Projekt Alpha\nslug: work/projekt-alpha\n---\n\nVerschachtelt.\n");
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
 * Ein Request gegen die Test-Instanz.
 *
 * @param string|null $token null = ohne Authorization-Header
 * @return array{status:int, headers:array<string,string>, body:string, json:mixed}
 */
function request(string $method, string $path, ?array $json = null, ?string $token = null): array {
    global $base_url, $api_key;

    $token = func_num_args() >= 4 ? $token : $api_key;

    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $body = null;
    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
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
            $status = (int) $m[1];   // bei Redirects gewinnt die letzte Zeile
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $head[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    return [
        'status'  => $status,
        'headers' => $head,
        'body'    => $raw,
        'json'    => json_decode($raw, true),
    ];
}

/** Rohinhalt einer Content-Datei im Wegwerf-Webroot. */
function content_file(string $rel): string|false {
    global $tmp_root;
    $path = $tmp_root . '/content/' . $rel;
    return is_file($path) ? (string) file_get_contents($path) : false;
}

// ─── Testlauf ───────────────────────────────────────────────────────────────

register_shutdown_function('stop_server');
register_shutdown_function(static function () use (&$tmp_root) { cleanup($tmp_root); });

build_webroot($repo_root, $tmp_root, $api_key);
build_fixtures($tmp_root);

$port     = free_port();
$base_url = 'http://127.0.0.1:' . $port;
start_server($tmp_root, $port);

echo "api.php-Tests gegen {$base_url}\n";

// ── Authentifizierung ──

group('Authentifizierung');

$r = request('GET', '/api.php/pages', null, null);
is_same($r['status'], 401, 'ohne Authorization-Header -> 401');
is_same($r['json']['code'] ?? null, 'unauthorized', 'Fehlercode unauthorized');
ok(isset($r['headers']['www-authenticate']), 'WWW-Authenticate-Header gesetzt');

$r = request('GET', '/api.php/pages', null, 'falscher-key');
is_same($r['status'], 401, 'falscher Key -> 401');
is_same(
    $r['json']['error'] ?? null,
    'Ungültiger oder fehlender API-Key.',
    'gleiche Meldung wie ohne Key (keine Existenz-Preisgabe)'
);

$r = request('GET', '/api.php/pages', null, $api_key . 'x');
is_same($r['status'], 401, 'Key mit angehaengtem Zeichen -> 401');

$r = request('GET', '/api.php/pages');
is_same($r['status'], 200, 'richtiger Key -> 200');
is_same($r['json']['success'] ?? null, true, 'success-Flag im Erfolgsfall');

// ── Routing ──

group('Routing');

$r = request('GET', '/api.php');
is_same($r['status'], 200, 'GET /api.php liefert die Endpunkt-Uebersicht');
is_same($r['json']['data']['api'] ?? null, 'testseite.example', 'Name in der Uebersicht ist die Domain aus site_domain');

$r = request('GET', '/api.php/unsinn');
is_same($r['status'], 404, 'unbekannte Ressource -> 404');
is_same($r['json']['code'] ?? null, 'unknown_resource', 'Fehlercode unknown_resource');

$r = request('GET', '/api.php?resource=pages&slug=home');
is_same($r['status'], 200, 'Query-Fallback ?resource=&slug= funktioniert');
is_same($r['json']['data']['page']['slug'] ?? null, 'home', 'Query-Fallback liefert die richtige Seite');

$r = request('GET', '/api.php/pages/work/projekt-alpha');
is_same($r['status'], 200, 'verschachtelter Slug im Pfad');
is_same($r['json']['data']['page']['file'] ?? null, 'work/projekt-alpha.md', 'Dateipfad der verschachtelten Seite');

// Rückfallweg für Server ohne PATH_INFO: die Route muss dann aus
// REQUEST_URI kommen. Der Router (build_webroot()) entfernt PATH_INFO.
$r = request('GET', '/api.php/pages/work/projekt-alpha?ohne_path_info=1');
is_same($r['status'], 200, 'ohne PATH_INFO: Route aus REQUEST_URI');
is_same($r['json']['data']['page']['slug'] ?? null, 'work/projekt-alpha', 'ohne PATH_INFO: richtige Seite');
$r = request('GET', '/api.php?ohne_path_info=1&resource=pages&slug=home');
is_same($r['json']['data']['page']['slug'] ?? null, 'home', 'ohne PATH_INFO: Query-Fallback greift weiterhin');
ok(
    !str_contains((string) @file_get_contents($tmp_root . '/server.log'), 'preg_match'),
    'kein preg_match-Warning im Server-Log'
);

$r = request('OPTIONS', '/api.php/pages', null, null);
is_same($r['status'], 204, 'OPTIONS-Preflight -> 204 (ohne Auth)');
is_same($r['headers']['access-control-allow-origin'] ?? null, '*', 'CORS-Origin-Header');
ok(str_contains($r['headers']['access-control-allow-headers'] ?? '', 'Authorization'), 'CORS erlaubt den Authorization-Header');

// ── Lesen ──

group('GET /pages');

$r     = request('GET', '/api.php/pages');
$pages = $r['json']['data']['pages'] ?? [];
$slugs = array_column($pages, 'slug');

is_same(count($pages), 5, 'alle fuenf Seiten inkl. Entwurf');
is_same(array_slice($slugs, 0, 3), ['home', 'about', 'kontakt'], 'Sortierung nach order');
ok(!isset($pages[0]['body']), 'Liste enthaelt keinen Body');
is_same($pages[0]['meta']['navLabel'] ?? null, 'Start', 'Frontmatter ist in der Liste enthalten');
is_same($pages[0]['meta']['order'] ?? null, '1', 'Frontmatter-Werte kommen als String zurueck');

$draft = null;
foreach ($pages as $p) {
    if ($p['slug'] === 'entwurf') $draft = $p;
}
is_same($draft['draft'] ?? null, true, 'Entwurf ist als draft markiert');

$r     = request('GET', '/api.php/pages?include_drafts=0');
$slugs = array_column($r['json']['data']['pages'] ?? [], 'slug');
ok(!in_array('entwurf', $slugs, true), 'include_drafts=0 blendet Entwuerfe aus');
is_same(count($slugs), 4, 'sonst bleiben alle Seiten uebrig');

group('GET /pages/{slug}');

$r = request('GET', '/api.php/pages/home');
is_same($r['status'], 200, 'bestehende Seite -> 200');
is_same($r['json']['data']['page']['meta']['title'] ?? null, 'Startseite', 'Titel aus dem Frontmatter');
is_same(trim($r['json']['data']['page']['body'] ?? ''), '# Willkommen', 'Body wird mitgeliefert');

$r = request('GET', '/api.php/pages/gibtsnicht');
is_same($r['status'], 404, 'unbekannter Slug -> 404');
is_same($r['json']['code'] ?? null, 'not_found', 'Fehlercode not_found');

$r = request('GET', '/api.php/pages/entwurf?include_drafts=0');
is_same($r['status'], 404, 'Entwurf ist bei include_drafts=0 nicht abrufbar');

// ── Anlegen ──

group('POST /pages');

$r = request('POST', '/api.php/pages', [
    'slug'  => 'neue-seite',
    'title' => 'Neue Seite',
    'body'  => "Frisch angelegt.\n",
]);
is_same($r['status'], 201, 'neue Seite -> 201');
is_same($r['json']['data']['page']['slug'] ?? null, 'neue-seite', 'Slug in der Antwort');
ok(str_contains((string) content_file('neue-seite.md'), 'title: Neue Seite'), 'Datei liegt auf der Platte');
is_same($r['json']['data']['page']['draft'] ?? null, false, 'ohne draft-Feld ist die Seite live');

$r = request('POST', '/api.php/pages', ['slug' => 'neue-seite', 'title' => 'Nochmal']);
is_same($r['status'], 409, 'doppelter Slug -> 409');
is_same($r['json']['code'] ?? null, 'exists', 'Fehlercode exists');

$r = request('POST', '/api.php/pages', ['title' => 'Ohne Slug']);
is_same($r['status'], 400, 'fehlender Slug -> 400');
is_same($r['json']['code'] ?? null, 'missing_slug', 'Fehlercode missing_slug');

$r = request('POST', '/api.php/pages', ['slug' => 'ohne-titel']);
is_same($r['status'], 400, 'fehlender Titel -> 400');
is_same($r['json']['code'] ?? null, 'missing_title', 'Fehlercode missing_title');
is_same(content_file('ohne-titel.md'), false, 'bei fehlendem Titel wird nichts geschrieben');

$r = request('POST', '/api.php/pages/home', ['slug' => 'x', 'title' => 'X']);
is_same($r['status'], 405, 'POST auf /pages/{slug} -> 405');

$r = request('POST', '/api.php/pages', [
    'slug'  => 'geheimer-entwurf',
    'title' => 'Geheim',
    'draft' => true,
]);
is_same($r['json']['data']['page']['draft'] ?? null, true, 'draft: true wird uebernommen');
$slugs = array_column(request('GET', '/api.php/pages?include_drafts=0')['json']['data']['pages'] ?? [], 'slug');
ok(!in_array('geheimer-entwurf', $slugs, true), 'neuer Entwurf taucht in der oeffentlichen Liste nicht auf');

// Verschachtelte Schreibweise (wie die GET-Antwort)
$r = request('POST', '/api.php/pages', [
    'slug' => 'verschachtelt',
    'meta' => ['title' => 'Verschachtelt', 'order' => 9],
    'body' => 'Mit meta-Objekt.',
]);
is_same($r['status'], 201, 'meta-Objekt-Schreibweise -> 201');
is_same($r['json']['data']['page']['meta']['order'] ?? null, '9', 'Zahl aus dem JSON landet im Frontmatter');

// ── Aendern ──

group('PUT /pages/{slug}');

$r = request('PUT', '/api.php/pages/neue-seite', ['navLabel' => 'Neu']);
is_same($r['status'], 200, 'Teil-Update -> 200');
is_same($r['json']['data']['page']['meta']['title'] ?? null, 'Neue Seite', 'nicht uebergebene Felder bleiben stehen');
is_same($r['json']['data']['page']['meta']['navLabel'] ?? null, 'Neu', 'neues Feld ist da');
is_same(trim($r['json']['data']['page']['body'] ?? ''), 'Frisch angelegt.', 'ohne body-Feld bleibt der Inhalt unberuehrt');

$r = request('PUT', '/api.php/pages/neue-seite', ['body' => 'Neuer Inhalt.']);
is_same(trim($r['json']['data']['page']['body'] ?? ''), 'Neuer Inhalt.', 'mit body-Feld wird der Inhalt ersetzt');

$r = request('PUT', '/api.php/pages/neue-seite', ['slug' => 'andere-adresse', 'title' => 'X']);
is_same($r['status'], 400, 'Slug-Wechsel -> 400');
is_same($r['json']['code'] ?? null, 'slug_immutable', 'Fehlercode slug_immutable');
is_same(request('GET', '/api.php/pages/neue-seite')['status'], 200, 'die Seite ist danach unveraendert erreichbar');

$r = request('PUT', '/api.php/pages/gibtsnicht', ['title' => 'X']);
is_same($r['status'], 404, 'Update einer unbekannten Seite -> 404');

$r = request('PUT', '/api.php/pages', ['title' => 'X']);
is_same($r['status'], 405, 'PUT ohne Slug -> 405');

$r = request('PATCH', '/api.php/pages/neue-seite', ['navLabel' => 'Patch']);
is_same($r['json']['data']['page']['meta']['navLabel'] ?? null, 'Patch', 'PATCH wirkt wie PUT');

$r = request('PUT', '/api.php/pages/geheimer-entwurf', ['draft' => false]);
is_same($r['json']['data']['page']['draft'] ?? null, false, 'draft: false veroeffentlicht den Entwurf');
ok(!str_contains((string) content_file('geheimer-entwurf.md'), 'draft:'), 'draft-Zeile ist aus der Datei raus');

// ── Loeschen ──

group('DELETE /pages/{slug}');

is_same(request('DELETE', '/api.php/pages/verschachtelt')['status'], 200, 'Loeschen -> 200');
is_same(content_file('verschachtelt.md'), false, 'Datei ist weg');
is_same(request('DELETE', '/api.php/pages/verschachtelt')['status'], 404, 'zweites Loeschen -> 404');
is_same(request('DELETE', '/api.php/pages')['status'], 405, 'DELETE ohne Slug -> 405');

// ── Vorversionen (Feature-PRD 6.3 gilt fuer KI-Anbindung *und* API) ──

group('Backup vor Schreibzugriffen');

/** Vorversionen einer Seite im Wegwerf-Webroot. */
function history_files(string $slug): array {
    global $tmp_root;
    return glob($tmp_root . '/content/.history/' . $slug . '/*.md') ?: [];
}

is_same(count(history_files('verschachtelt')), 1, 'DELETE sichert die Datei vorher');

put($tmp_root . '/content/gesichert.md', "---\ntitle: Gesichert\nslug: gesichert\n---\n\nAlter Stand.");
$r = request('PUT', '/api.php/pages/gesichert', ['body' => 'Neuer Stand.']);
is_same($r['status'], 200, 'PUT auf die Testseite -> 200');
$versions = history_files('gesichert');
is_same(count($versions), 1, 'PUT sichert die Vorversion');
ok(
    str_contains((string) file_get_contents($versions[0]), 'Alter Stand.'),
    'die Vorversion enthaelt den Stand VOR der Aenderung'
);

$r = request('POST', '/api.php/pages', ['slug' => 'frisch', 'title' => 'Frisch', 'body' => 'x']);
is_same($r['status'], 201, 'POST legt an');
is_same(history_files('frisch'), [], 'POST legt keine Vorversion an — es gab keine');

// ── Eingaben, die wehtun sollen ──

group('Fehlerhafte und boesartige Eingaben');

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$api_key}",
    'content'       => '{kein json',
    'ignore_errors' => true,
]]);
$raw  = (string) @file_get_contents($base_url . '/api.php/pages', false, $ctx);
$body = json_decode($raw, true);
is_same($body['code'] ?? null, 'invalid_json', 'kaputtes JSON -> invalid_json');

$r = request('POST', '/api.php/pages', ['slug' => 'x1', 'title' => 'X', 'meta' => 'kein objekt']);
is_same($r['json']['code'] ?? null, 'invalid_meta', 'meta als String -> invalid_meta');

$r = request('POST', '/api.php/pages', ['slug' => 'x2', 'title' => 'X', 'tags' => ['a', 'b']]);
is_same($r['status'], 400, 'verschachtelter Frontmatter-Wert -> 400');
is_same($r['json']['code'] ?? null, 'invalid_meta', 'Fehlercode invalid_meta');
is_same(content_file('x2.md'), false, 'dabei wird nichts geschrieben');

$r = request('POST', '/api.php/pages', ['slug' => 'x3', 'title' => 'X', 'body' => 42]);
is_same($r['json']['code'] ?? null, 'invalid_body', 'body als Zahl -> invalid_body');

$r = request('POST', '/api.php/pages', ['slug' => 'x4', 'title' => 'X', 'draft' => 'vielleicht']);
is_same($r['json']['code'] ?? null, 'invalid_draft', 'unklares draft -> invalid_draft');

// Frontmatter-Injection: der Zeilenumbruch darf keinen zweiten Schluessel anlegen.
$r = request('POST', '/api.php/pages', [
    'slug'  => 'injektion',
    'title' => "Harmlos\nrobots: noindex",
]);
is_same($r['status'], 201, 'Titel mit Zeilenumbruch wird angenommen');
$file = (string) content_file('injektion.md');
ok(!preg_match('/^robots:/m', $file), 'kein untergeschobener robots-Schluessel in der Datei');
is_same($r['json']['data']['page']['meta']['robots'] ?? null, null, 'und auch keiner in der Antwort');

// Path-Traversal ueber den Slug: darf content/ nicht verlassen. Bewusst ueber
// den Query-Fallback, weil ein URL-Client die ..-Segmente im Pfad sonst schon
// vor dem Absenden wegkuerzt und der Request api.php gar nicht erreicht.
$r = request('GET', '/api.php?resource=pages&slug=' . rawurlencode('../../config/config.php'));
ok(in_array($r['status'], [400, 404], true), 'Traversal im Slug -> kein 200');
ok(!str_contains($r['body'], 'api_key'), 'und nichts aus der Konfiguration in der Antwort');

$r = request('POST', '/api.php/pages', ['slug' => '../../evil', 'title' => 'Evil']);
is_same($r['status'], 201, 'Traversal im Slug wird bereinigt statt abgelehnt');
is_same($r['json']['data']['page']['slug'] ?? null, 'evil', 'aus ../../evil wird evil');
ok(is_file($tmp_root . '/content/evil.md'), 'die Datei liegt in content/');
ok(!is_file(dirname($tmp_root) . '/evil.md'), 'und nirgends darueber');

// ── Deaktivierte API (fail closed) ──

group('API ohne konfigurierten Key');

stop_server();
put($tmp_root . '/config/config.php', "<?php\nreturn ['site_title' => 'Testseite', 'api_key' => ''];\n");
start_server($tmp_root, $port);

$r = request('GET', '/api.php/pages');
is_same($r['status'], 401, 'leerer api_key -> 401 statt offener API');

stop_server();
unlink($tmp_root . '/config/config.php');
start_server($tmp_root, $port);

$r = request('GET', '/api.php/pages');
is_same($r['status'], 401, 'fehlende config/config.php -> 401 statt 500');
is_same($r['json']['success'] ?? null, false, 'und trotzdem sauberes JSON');

// ── Rate-Limit (zuletzt: verbraucht das Fenster fuer diese IP) ──

group('Rate-Limit');

stop_server();
put($tmp_root . '/config/config.php', "<?php\nreturn " . var_export([
    'site_title' => 'Testseite',
    'api_key'    => $api_key,
], true) . ";\n");
cleanup($tmp_root . '/config/ratelimit');
start_server($tmp_root, $port);

$r = request('GET', '/api.php/pages');
is_same($r['headers']['x-ratelimit-limit'] ?? null, '60', 'X-RateLimit-Limit-Header');
is_same($r['headers']['x-ratelimit-remaining'] ?? null, '59', 'X-RateLimit-Remaining zaehlt herunter');

$last = null;
for ($i = 0; $i < 60; $i++) {
    $last = request('GET', '/api.php');
}
is_same($last['status'], 429, 'ab dem 61. Request -> 429');
is_same($last['json']['code'] ?? null, 'rate_limited', 'Fehlercode rate_limited');
ok(isset($last['headers']['retry-after']), 'Retry-After-Header gesetzt');

$r = request('GET', '/api.php/pages', null, 'falscher-key');
is_same($r['status'], 429, 'das Limit greift auch vor der Authentifizierung');

// ─── Ergebnis ───────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 60) . "\n";
echo $tests_failed === 0
    ? "Alle {$tests_run} Pruefungen bestanden.\n"
    : "{$tests_failed} von {$tests_run} Pruefungen fehlgeschlagen.\n";

$log = $tmp_root . '/server.log';
if ($tests_failed > 0 && is_file($log) && filesize($log) > 0) {
    echo "\nServer-Log:\n" . file_get_contents($log) . "\n";
}

exit($tests_failed === 0 ? 0 : 1);
