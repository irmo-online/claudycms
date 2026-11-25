<?php
/**
 * api.php — REST-API für externe Clients (Obsidian, VS Code REST Client, n8n).
 *
 * Neubau nach PRD 4.4; die ursprüngliche Fassung ist bei der Repo-
 * Neuaufsetzung verloren gegangen. Die gesamte Content-Logik kommt aus
 * lib/content.php — hier steht nur der Transport: Auth, Routing,
 * Rate-Limit, JSON.
 *
 * Basis-URL:  https://<domain>/api.php/pages
 * Auth:       Authorization: Bearer <api_key>   (aus config/config.php)
 *
 * Antwortformat:
 *   Erfolg:  {"success": true,  "data": …}
 *   Fehler:  {"success": false, "error": "…", "code": "…"}
 *
 * Frontmatter-Werte kommen als Strings zurück — genau so, wie sie in der
 * Datei stehen. Beim Schreiben nimmt die API zusätzlich echte JSON-Typen an
 * (true/false/Zahlen); cms_build_frontmatter() setzt sie korrekt um.
 *
 * Der Deploy-Hook aus PRD 4.4 (POST /deploy) ist bewusst nicht dabei: das
 * Flag hat keinen Konsumenten (PRD Abschnitt 10, Lücke 6).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

// CMS_ENTRY öffnet config/config.php und die Bibliotheken in lib/.
// API_ENTRY ist der in PRD 5.1 vorgesehene Marker für den API-Einstiegspunkt —
// er unterscheidet die API von index.php/admin.php für alles, was nur über
// diesen Weg erreichbar sein soll.
define('CMS_ENTRY', true);
define('API_ENTRY', true);

require __DIR__ . '/lib/security.php';
require __DIR__ . '/lib/content.php';
require __DIR__ . '/lib/config.php';

const API_VERSION     = '1.0.0';
const API_RATE_LIMIT  = 60;         // Requests …
const API_RATE_WINDOW = 60;         // … pro Zeitfenster in Sekunden, je IP
const API_MAX_BODY    = 1048576;    // 1 MB Request-Body

// ─── Antwort-Helfer ─────────────────────────────────────────────────────────

function api_send(array $payload, int $status = 200): never {
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

function api_ok(mixed $data, int $status = 200): never {
    api_send(['success' => true, 'data' => $data], $status);
}

/** $code ist maschinenlesbar, $message die deutsche Meldung für Menschen. */
function api_fail(int $status, string $code, string $message): never {
    api_send(['success' => false, 'error' => $message, 'code' => $code], $status);
}

// Ohne diese beiden Handler antwortet die API bei einem PHP-Fehler mit einer
// leeren Seite — display_errors ist aus, und ein Client bekäme HTTP 200 mit
// null Byte Inhalt. Stattdessen soll auch dann sauberes JSON herauskommen.
set_exception_handler(static function (Throwable $e): void {
    api_fail(500, 'internal_error', 'Interner Serverfehler.');
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($err !== null && in_array($err['type'], $fatal, true) && !headers_sent()) {
        api_fail(500, 'internal_error', 'Interner Serverfehler.');
    }
});

// ─── CORS ───────────────────────────────────────────────────────────────────
//
// Offen laut PRD 4.4: abgesichert wird über den Bearer-Token, nicht über den
// Origin. Bewusst ohne Allow-Credentials — das ginge mit "*" ohnehin nicht
// und würde Cookies ins Spiel bringen, die die API gar nicht kennt.

function api_send_cors_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

// ─── Authentifizierung (Bearer-Token) ───────────────────────────────────────

/**
 * Erwarteten API-Key laden: ENV vor config/config.php. Leer = API deaktiviert.
 *
 * Fehlt config/config.php ganz, gibt es hier — anders als in index.php — kein
 * hartes require und damit keinen HTTP 500, sondern ein leerer Key und in der
 * Folge 401. Gleiches fail-closed-Verhalten wie in mcp.php.
 */
function api_configured_key(): string {
    $env = getenv('CMS_API_KEY');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $file = __DIR__ . '/config/config.php';
    if (is_file($file)) {
        $cfg = require $file;
        if (is_array($cfg) && !empty($cfg['api_key'])) {
            return (string) $cfg['api_key'];
        }
    }
    return '';
}

/**
 * config/config.php einmal laden, für cms_site_domain() in der Übersichts-
 * Antwort. Analog mcp_config() in mcp.php — anders als
 * api_configured_key() (API-Key, ENV-Vorrang) wird hier nur die Datei
 * gebraucht, kein Umgebungsvariablen-Fallback.
 *
 * Fehlt die Datei, gibt es kein hartes require, sondern ein leeres Array —
 * cms_site_domain() fällt dann auf den Host-Header zurück.
 */
function api_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg  = [];
        $file = __DIR__ . '/config/config.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
    }
    return $cfg;
}

/**
 * Token aus dem Authorization-Header.
 *
 * Unter CGI/FastCGI reicht der Header nicht immer bis zu PHP durch. Deshalb
 * die zwei Fallbacks: REDIRECT_HTTP_AUTHORIZATION (füllt die .htaccess-Regel)
 * und apache_request_headers() unter mod_php.
 */
function api_bearer_token(): string {
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return $m[1];
    }
    return '';
}

// ─── Routing ────────────────────────────────────────────────────────────────

/**
 * Ressource und Slug aus dem Pfad hinter api.php lesen.
 *
 * PATH_INFO ist der Normalfall (/api.php/pages/work/projekt-alpha). Fehlt es,
 * greift der Query-Parameter-Fallback aus PRD 4.4 (?resource=pages&slug=…).
 *
 * Bewusst kein rawurldecode(): PATH_INFO liefert der Server bereits dekodiert,
 * ein zweiter Durchgang würde %2F nachträglich zu einem Pfadtrenner machen.
 * Slugs bestehen ohnehin nur aus [a-z0-9/_-] und brauchen keine Kodierung.
 *
 * @return array{resource: string, slug: string}
 */
function api_route(): array {
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');

    if ($path === '' && isset($_SERVER['REQUEST_URI'])
        && preg_match('~/api\.php(/[^?#]*)~', $_SERVER['REQUEST_URI'], $m)) {
        $path = $m[1];
    }

    $path = trim($path, '/');
    if ($path !== '') {
        $parts = explode('/', $path, 2);
        return ['resource' => $parts[0], 'slug' => trim($parts[1] ?? '', '/')];
    }

    return [
        'resource' => trim((string) ($_GET['resource'] ?? ''), '/'),
        'slug'     => trim((string) ($_GET['slug'] ?? ''), '/'),
    ];
}

// ─── Request-Body ───────────────────────────────────────────────────────────

/** JSON-Body einlesen und als assoziatives Array zurückgeben. */
function api_read_json_body(): array {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > API_MAX_BODY) {
        api_fail(413, 'payload_too_large', 'Der Request-Body ist zu gross (Maximum: 1 MB).');
    }

    // Selbst lesen statt CONTENT_LENGTH zu glauben — bei chunked transfer
    // fehlt der Header.
    $raw = (string) file_get_contents('php://input', false, null, 0, API_MAX_BODY + 1);
    if (strlen($raw) > API_MAX_BODY) {
        api_fail(413, 'payload_too_large', 'Der Request-Body ist zu gross (Maximum: 1 MB).');
    }
    if (trim($raw) === '') {
        api_fail(400, 'invalid_json', 'Es wurde kein JSON-Body übermittelt.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || isset($data[0])) {
        api_fail(400, 'invalid_json', 'Der Request-Body muss ein JSON-Objekt sein.');
    }
    return $data;
}

/**
 * Zerlegt den JSON-Body in Frontmatter, Body-Text und Optionen.
 *
 * Zwei Schreibweisen sind erlaubt: verschachtelt wie die GET-Antwort
 * ({"meta": {…}, "body": "…"}) oder flach ({"title": "…", "body": "…"}).
 * Fehlt "meta", gilt alles ausser den reservierten Schlüsseln als Frontmatter.
 *
 * @return array{meta: array<string, mixed>, body: ?string, draft: ?bool, slug: string}
 */
function api_payload_parts(array $data): array {
    if (array_key_exists('meta', $data)) {
        if (!is_array($data['meta']) || isset($data['meta'][0])) {
            api_fail(400, 'invalid_meta', 'Das Feld "meta" muss ein JSON-Objekt sein.');
        }
        $meta = $data['meta'];
    } else {
        $meta = array_diff_key($data, array_flip(['meta', 'body', 'draft', 'slug']));
    }

    // cms_build_frontmatter() würde ein Array stillschweigend zu "Array"
    // stringifizieren. Lieber klar abweisen.
    foreach ($meta as $key => $val) {
        if (is_array($val) || is_object($val)) {
            api_fail(
                400,
                'invalid_meta',
                'Verschachtelte Werte sind im Frontmatter nicht möglich. Betroffen: ' . (string) $key
            );
        }
    }

    $body = null;
    if (array_key_exists('body', $data)) {
        if (!is_string($data['body'])) {
            api_fail(400, 'invalid_body', 'Das Feld "body" muss Text sein.');
        }
        $body = $data['body'];
    }

    $draft = null;
    if (array_key_exists('draft', $data)) {
        $draft = filter_var($data['draft'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($draft === null) {
            api_fail(400, 'invalid_draft', 'Das Feld "draft" muss true oder false sein.');
        }
    }

    // Der Slug ist die Adresse der Seite, kein gewöhnliches Frontmatter-Feld.
    // Er wird oben aus dem Pfad bzw. aus data["slug"] genommen; die Kopie im
    // meta-Objekt fliegt raus, damit es nur eine Quelle gibt.
    $slug_in = (string) ($data['slug'] ?? $meta['slug'] ?? '');
    unset($meta['slug']);

    return ['meta' => $meta, 'body' => $body, 'draft' => $draft, 'slug' => $slug_in];
}

/** Übersetzt die Fehlercodes von lib/content.php in HTTP-Statuscodes. */
function api_crud_status(string $code): int {
    return match ($code) {
        'not_found'                   => 404,
        'exists'                      => 409,
        'invalid_slug', 'invalid_path' => 400,
        default                       => 500,
    };
}

/** Bricht ab, wenn das CRUD-Ergebnis ein Fehler ist. */
function api_guard_crud(array $result): void {
    if (empty($result['ok'])) {
        $code = (string) ($result['code'] ?? 'io_error');
        api_fail(api_crud_status($code), $code, (string) ($result['error'] ?? 'Unbekannter Fehler.'));
    }
}

// ─── Seiten-Darstellung ─────────────────────────────────────────────────────

/**
 * Eine Seite in der Form, in der die API sie ausliefert.
 * Metadaten ungecastet (Strings), damit gelesene und zurückgeschriebene
 * Werte identisch sind.
 */
function api_page_payload(array $page, bool $with_body): array {
    $out = [
        'slug'  => $page['slug'],
        'file'  => $page['rel'],
        'draft' => cms_is_draft($page['meta']),
        'meta'  => (object) $page['meta'],
    ];
    if ($with_body) {
        $out['body'] = $page['body'];
    }
    return $out;
}

/**
 * Alle Seiten mit vollständigem Frontmatter, ohne Body (PRD 4.4).
 *
 * Der Index castet die Werte — die Navigation braucht int/bool. Die API
 * liefert sie dagegen als Strings aus, deshalb wird die Datei für die Ausgabe
 * ein zweites Mal geparst. Bei der Seitenzahl, für die dieses CMS gedacht ist,
 * ist das vertretbar; der Index selbst liest ohnehin jede Datei.
 */
function api_collect_pages(bool $include_drafts): array {
    $pages = [];

    foreach (cms_build_content_index(null, '', $include_drafts) as $slug => $entry) {
        $data = cms_parse_md_file($entry['file'], false);
        if ($data === false) {
            continue;
        }

        $order = $entry['meta']['order'] ?? null;
        $pages[] = [
            'sort_order' => is_numeric($order) ? (int) $order : PHP_INT_MAX,
            'sort_title' => (string) ($entry['meta']['title'] ?? $slug),
            'page'       => [
                'slug'  => $slug,
                'file'  => $entry['rel'],
                'draft' => cms_is_draft($entry['meta']),
                'meta'  => (object) $data['meta'],
            ],
        ];
    }

    // Sortierung nach order laut PRD; bei gleichem order entscheidet der Titel,
    // sonst hinge die Reihenfolge an der Reihenfolge des Verzeichnis-Scans.
    usort($pages, static function (array $a, array $b): int {
        return [$a['sort_order'], mb_strtolower($a['sort_title'])]
           <=> [$b['sort_order'], mb_strtolower($b['sort_title'])];
    });

    return array_column($pages, 'page');
}

/** Entwürfe mitliefern? Standard ja — die API ist authentifiziert. */
function api_include_drafts(): bool {
    if (!isset($_GET['include_drafts'])) {
        return true;
    }
    return filter_var($_GET['include_drafts'], FILTER_VALIDATE_BOOL);
}

// ─── Ablauf ─────────────────────────────────────────────────────────────────

api_send_cors_headers();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Preflight beantworten, bevor irgendetwas geprüft wird: der Browser schickt
// dabei keinen Authorization-Header mit.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Rate-Limit vor der Authentifizierung — sonst wäre der API-Key beliebig oft
// ratbar. Zähler liegt in config/ratelimit/, siehe lib/security.php.
$limit = cms_rate_limit_hit('api', cms_client_ip(), API_RATE_WINDOW);
header('X-RateLimit-Limit: ' . API_RATE_LIMIT);
header('X-RateLimit-Remaining: ' . max(0, API_RATE_LIMIT - $limit['count']));
header('X-RateLimit-Reset: ' . $limit['reset']);

if ($limit['count'] > API_RATE_LIMIT) {
    header('Retry-After: ' . max(1, $limit['reset'] - time()));
    api_fail(429, 'rate_limited', 'Zu viele Anfragen. Bitte später erneut versuchen.');
}

$expected_key = api_configured_key();
$provided_key = api_bearer_token();

if ($expected_key === '' || $provided_key === '' || !hash_equals($expected_key, $provided_key)) {
    // Zeitkonstanter Vergleich via hash_equals; identische Meldung für
    // "kein Key", "falscher Key" und "API nicht konfiguriert".
    header('WWW-Authenticate: Bearer');
    api_fail(401, 'unauthorized', 'Ungültiger oder fehlender API-Key.');
}

$route    = api_route();
$resource = $route['resource'];
$slug     = $route['slug'];

if ($resource === '') {
    api_ok([
        'api'       => cms_site_domain(api_config()),
        'version'   => API_VERSION,
        'endpoints' => [
            'GET    /api.php/pages',
            'GET    /api.php/pages/{slug}',
            'POST   /api.php/pages',
            'PUT    /api.php/pages/{slug}',
            'DELETE /api.php/pages/{slug}',
        ],
    ]);
}

if ($resource !== 'pages') {
    api_fail(404, 'unknown_resource', 'Unbekannte Ressource. Verfügbar ist: pages');
}

switch ($method) {

    // ── Lesen ──
    case 'GET':
    case 'HEAD':
        if ($slug === '') {
            api_ok(['pages' => api_collect_pages(api_include_drafts())]);
        }
        $page = cms_get_page($slug, api_include_drafts());
        if ($page === false) {
            api_fail(404, 'not_found', 'Es gibt keine Seite mit diesem Slug.');
        }
        api_ok(['page' => api_page_payload($page, true)]);

    // ── Anlegen ──
    case 'POST':
        if ($slug !== '') {
            api_fail(405, 'method_not_allowed', 'Neue Seiten werden per POST auf /pages angelegt, nicht auf /pages/{slug}.');
        }

        $parts = api_payload_parts(api_read_json_body());
        if ($parts['slug'] === '') {
            api_fail(400, 'missing_slug', 'Für eine neue Seite ist ein Slug erforderlich.');
        }
        if (trim((string) ($parts['meta']['title'] ?? '')) === '') {
            api_fail(400, 'missing_title', 'Für eine neue Seite ist ein Titel erforderlich.');
        }

        $opts   = $parts['draft'] === null ? [] : ['draft' => $parts['draft']];
        $result = cms_create_page($parts['slug'], $parts['meta'], $parts['body'] ?? '', $opts);
        api_guard_crud($result);

        $page = cms_get_page($result['slug']);
        api_ok(['page' => $page === false ? null : api_page_payload($page, true)], 201);

    // ── Ändern (Frontmatter wird gemerged, Body nur bei Angabe) ──
    case 'PUT':
    case 'PATCH':
        if ($slug === '') {
            api_fail(405, 'method_not_allowed', 'Zum Ändern muss der Slug im Pfad stehen: /pages/{slug}.');
        }

        $parts = api_payload_parts(api_read_json_body());
        if ($parts['slug'] !== '' && $parts['slug'] !== $slug) {
            // Ein Slug-Wechsel würde nur das Frontmatter ändern, nicht die Datei
            // verschieben — Adresse und Dateiname liefen auseinander. Lieber
            // löschen und neu anlegen.
            api_fail(400, 'slug_immutable', 'Der Slug einer bestehenden Seite lässt sich nicht ändern.');
        }

        // backup: Feature-PRD 6.3 sichert die Vorversion vor jedem Schreibzugriff
        // über KI-Anbindung *und* API. Die Kennzeichnung lastEditedBy bleibt
        // aussen vor — hier schreibt ein externer Client, nicht die KI.
        $opts = ['backup' => true];
        if ($parts['draft'] !== null) {
            $opts['draft'] = $parts['draft'];
        }
        $result = cms_update_page($slug, $parts['meta'], $parts['body'], $opts);
        api_guard_crud($result);

        $page = cms_get_page($result['slug']);
        api_ok(['page' => $page === false ? null : api_page_payload($page, true)]);

    // ── Löschen ──
    case 'DELETE':
        if ($slug === '') {
            api_fail(405, 'method_not_allowed', 'Zum Löschen muss der Slug im Pfad stehen: /pages/{slug}.');
        }
        api_guard_crud(cms_delete_page($slug, ['backup' => true]));
        api_ok(['slug' => $slug, 'deleted' => true]);

    default:
        header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        api_fail(405, 'method_not_allowed', 'Diese HTTP-Methode wird nicht unterstützt.');
}
