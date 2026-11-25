<?php
/**
 * mcp.php — MCP-Server für die KI-Anbindung (Feature-PRD Phase 1a)
 *
 * Inhaltspflege per natürlicher Sprache: Claude spricht als Custom Connector
 * über MCP mit diesem Endpunkt und liest bzw. schreibt content/*.md.
 *
 * Transport: MCP "Streamable HTTP", synchroner Modus — ein POST-Request
 * (JSON-RPC 2.0) → eine JSON-Antwort. Kein SSE, keine Dauerverbindung.
 * Läuft damit auf klassischem PHP-FPM/Shared-Hosting.
 *
 * Connector-URL:  https://<domain>/mcp.php/<connector-token>
 *
 * Der Token ist seit Einführung der Benutzerverwaltung personengebunden: er wird
 * gegen config/users.json aufgelöst (lib/users.php), nicht mehr gegen einen
 * einzelnen globalen Token in config.php. Die Rolle des gefundenen Nutzers
 * bestimmt, welche Tools sichtbar sind und nutzbar bleiben (siehe unten).
 *
 * Die gesamte Content-Logik kommt aus lib/content.php — hier steht nur der
 * Transport: Auth, JSON-RPC, Tool-Definitionen, Rate-Limit,
 * Entwurfsregel. Der Minimal-Parser aus dem Spike ist damit abgelöst.
 *
 * Methoden:  initialize · notifications/* · ping · tools/list · tools/call
 * Tools:     list_pages · get_page · create_page · update_page · delete_page
 *            list_images · get_image · upload_image · delete_image
 *            get_styles · update_styles · get_custom_css · update_custom_css
 *            restore_styles
 *            create_landingpage · update_landingpage — eigener
 *            Content-Typ (contentType: html) neben Markdown-Seiten, nutzt
 *            dieselbe Speicher-/Backup-/Rate-Limit-Infrastruktur weiter.
 *            list_redirects · create_redirect · delete_redirect — 301-Weiterleitungen,
 *            admin-only wie die Styles-Tools.
 *            list_categories — Kategorien nur lesen. Zugeordnet werden sie
 *            über das Meta-Feld "categories" der Seiten-Tools, angelegt und
 *            gelöscht nur im Admin-Backend.
 *
 * Schreibzugriffe an Seiten (create/update/delete):
 *   - zählen gegen ein Tageslimit (MCP_WRITE_LIMIT, Feature-PRD 6.6)
 *   - sichern die Vorversion nach content/.history/ (Feature-PRD 6.3)
 *   - setzen lastEditedBy: ai + lastEditedAt (Feature-PRD 5.2)
 *   - speichern als Entwurf, solange ai_publish_immediately nicht true ist
 *
 * Die Bild-Tools nutzen lib/media.php und denselben Zähler wie
 * die Seiten-Schreibzugriffe (MCP_WRITE_LIMIT) — kein separater Bucket.
 * Anders als bei Seiten gibt es für Bilder kein Backup: gelöscht ist gelöscht.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

// CMS_ENTRY öffnet config/config.php und die Bibliotheken in lib/.
// MCP_ENTRY markiert diesen Einstiegspunkt analog zu API_ENTRY in api.php.
define('CMS_ENTRY', true);
define('MCP_ENTRY', true);

require __DIR__ . '/lib/security.php';
require __DIR__ . '/lib/enums.php';
require __DIR__ . '/lib/content.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/styles.php';
require __DIR__ . '/lib/users.php';
require __DIR__ . '/lib/redirects.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/categories.php';

const MCP_SERVER_VERSION    = '1.0.0';
const MCP_PROTOCOL_FALLBACK = '2025-06-18';

const MCP_MAX_BODY     = 1048576;   // 1 MB Request-Body
const MCP_RATE_LIMIT   = 60;        // Requests …
const MCP_RATE_WINDOW  = 60;        // … pro Zeitfenster in Sekunden, je IP
const MCP_WRITE_LIMIT  = 50;        // Schreib-Tool-Calls …
const MCP_WRITE_WINDOW = 86400;     // … pro Zeitfenster, je Installation

// Obergrenze für Base64-Bilddaten in der get_image-Antwort (Abschnitt 6.2).
// Bezieht sich auf die Dateigröße auf der Platte, nicht die
// (grössere) Base64-Kodierung. Reine technische Schutzgrenze gegen
// übergrosse Tool-Antworten, kein Admin-Einstellungsfeld.
const MCP_IMAGE_INLINE_LIMIT = 5 * 1024 * 1024;

// Die id des laufenden Requests. Steht global, weil die Fehlerbehandlung sie
// noch braucht, wenn der reguläre Ablauf längst abgebrochen ist.
$mcp_id      = null;
$mcp_replied = false;

// ─── Antwort-Helfer ─────────────────────────────────────────────────────────

function mcp_send(array $payload, int $status = 200): never {
    $GLOBALS['mcp_replied'] = true;
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** $result als object zulässig, damit ein leeres Ergebnis als {} statt [] serialisiert wird (z.B. ping). */
function rpc_result(mixed $id, array|object $result): never {
    mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

/** JSON-RPC-Fehler. HTTP bleibt 200, sofern nicht anders angegeben (Transport-Ebene ok). */
function rpc_error(mixed $id, int $code, string $message, int $http = 200): never {
    mcp_send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $http);
}

// Ohne diese beiden Handler bekäme Claude bei einem PHP-Fehler eine leere
// Antwort mit HTTP 200 — und keinen Hinweis, dass etwas schiefgelaufen ist.
// Der Text bleibt bewusst unspezifisch: Pfade und Klassennamen gehören nicht
// in eine Antwort, die über einen fremden Dienst läuft.
set_exception_handler(static function (Throwable $e): void {
    rpc_error($GLOBALS['mcp_id'] ?? null, -32603, 'Interner Serverfehler.');
});

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['mcp_replied'])) {
        return;
    }
    $err   = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($err !== null && in_array($err['type'], $fatal, true)) {
        rpc_error($GLOBALS['mcp_id'] ?? null, -32603, 'Interner Serverfehler.');
    }
});

// ─── Konfiguration ──────────────────────────────────────────────────────────

/**
 * config/config.php einmal laden.
 *
 * Fehlt die Datei, gibt es — anders als in index.php — kein hartes require und
 * damit keinen HTTP 500, sondern ein leeres Array. In der Folge ist der Token
 * leer und jede Anfrage endet mit 401 (fail closed).
 */
function mcp_config(): array {
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
 * Sollen KI-Änderungen sofort live gehen? (Feature-PRD 6.2)
 * Default false: alles, was die KI schreibt, ist zunächst ein Entwurf.
 */
function mcp_publish_immediately(): bool {
    return filter_var(mcp_config()['ai_publish_immediately'] ?? false, FILTER_VALIDATE_BOOL);
}

/**
 * Öffentliche Basis-URL der Website, für absolute Bild-Links in den
 * MCP-Bild-Tools — cms_list_images() liefert bewusst nur
 * relative Pfade (siehe lib/media.php), Claude braucht aber einen klickbaren
 * Link. Gleiche Logik wie index.php/admin.php: konfigurierte Domain zuerst,
 * sonst der (gegen Host-Header-Injection bereinigte) Host-Header.
 */
function mcp_site_url(): string {
    static $url = null;
    if ($url === null) {
        $domain = (string) (mcp_config()['site_domain'] ?? '');
        if ($domain !== '') {
            $url = rtrim($domain, '/');
        } else {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host  = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $url   = $proto . '://' . $host;
        }
    }
    return $url;
}

/**
 * Servername für serverInfo.name (initialize) und den Health-Check —
 * aus der Installationsdomain abgeleitet statt eines festen Literals,
 * damit mehrere Installationen (z.B. eine Agentur mit mehreren Kunden-Sites)
 * als Connector in Claude unterscheidbar sind. Ersetzt die frühere Konstante
 * MCP_SERVER_NAME.
 */
function mcp_server_name(): string {
    return cms_site_domain(mcp_config());
}

// mcp_max_upload_size() ist mit upload_image entfallen (siehe dort) —
// nur dort gebraucht. Bei Reaktivierung aus der Git-Historie wiederherstellen
// (Commit 5fef877).

// ─── Token-Authentifizierung (Token-in-URL) ─────────────────────────────────

/**
 * Token aus dem Pfad hinter mcp.php lesen.
 *
 * PATH_INFO ist der Normalfall und funktioniert beim Hoster. Der ?token=-Weg
 * bleibt als Rückfallebene für Server ohne PATH_INFO — er ist gleichwertig,
 * landet aber eher in Logs und Referrern.
 */
function mcp_extract_token(): string {
    $path = $_SERVER['PATH_INFO'] ?? '';
    if ($path === '' && isset($_SERVER['REQUEST_URI'])
        && preg_match('~/mcp\.php/([^/?#]+)~', $_SERVER['REQUEST_URI'], $m)) {
        $path = '/' . $m[1];
    }
    $token = trim($path, '/');
    if ($token === '' && isset($_GET['token'])) {
        $token = (string) $_GET['token'];
    }
    return $token;
}

// ─── Tool-Ergebnisse ────────────────────────────────────────────────────────
//
// MCP unterscheidet zwei Fehlerebenen: Protokollfehler (unbekannte Methode,
// kaputtes JSON) gehen als JSON-RPC-"error" zurück, Fehler *innerhalb* eines
// Tools als reguläres Ergebnis mit isError: true. Nur so sieht Claude den
// Fehlertext und kann darauf reagieren, statt den Aufruf als Transportproblem
// zu behandeln.

/** Erfolgreiches Tool-Ergebnis mit beliebigen Content-Blöcken (Text und/oder Bild). */
function mcp_tool_content(array $blocks): never {
    rpc_result($GLOBALS['mcp_id'] ?? null, [
        'content' => $blocks,
        'isError' => false,
    ]);
}

/** Erfolgreiches Tool-Ergebnis: Text (meist JSON) an Claude. */
function mcp_tool_ok(string $text): never {
    mcp_tool_content([['type' => 'text', 'text' => $text]]);
}

/** Ergebnis als JSON, mit einer vorangestellten Meldung im Klartext. */
function mcp_tool_json(array $data, string $note = ''): never {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    mcp_tool_ok($note === '' ? (string) $json : $note . "\n\n" . $json);
}

/** Fehlgeschlagenes Tool-Ergebnis. Der Text ist für Claude und den Nutzer gedacht. */
function mcp_tool_error(string $text): never {
    rpc_result($GLOBALS['mcp_id'] ?? null, [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => true,
    ]);
}

// ─── Argument-Prüfung ───────────────────────────────────────────────────────

/** Pflicht-Textargument. Fehlt es, endet der Tool-Call mit isError. */
function mcp_require_string(array $args, string $name): string {
    $val = $args[$name] ?? null;
    if (!is_string($val) || trim($val) === '') {
        mcp_tool_error("Das Feld \"{$name}\" fehlt oder ist leer.");
    }
    return $val;
}

/** Optionales Textargument, oder null wenn es nicht übergeben wurde. */
function mcp_optional_string(array $args, string $name): ?string {
    if (!array_key_exists($name, $args) || $args[$name] === null) {
        return null;
    }
    if (!is_string($args[$name])) {
        mcp_tool_error("Das Feld \"{$name}\" muss Text sein.");
    }
    return $args[$name];
}

/**
 * Baut das Frontmatter aus den Tool-Argumenten.
 *
 * "title" ist als eigenes Argument geführt, weil Claude es fast immer setzt;
 * alles Weitere kommt über "meta". Der Slug wird herausgefiltert — er ist die
 * Adresse der Seite und kommt aus dem gleichnamigen Argument, sonst gäbe es
 * zwei Quellen für dieselbe Angabe.
 */
function mcp_meta_from_args(array $args): array {
    $meta = [];

    if (array_key_exists('meta', $args) && $args['meta'] !== null) {
        if (!is_array($args['meta']) || isset($args['meta'][0])) {
            mcp_tool_error('Das Feld "meta" muss ein Objekt aus Schlüssel-Wert-Paaren sein.');
        }
        $meta = $args['meta'];
    }

    // Vor der Prüfung auf einfache Werte: "categories" darf als Liste
    // ankommen, weil get_page und list_pages das Feld als Liste ausgeben.
    if (array_key_exists('categories', $meta)) {
        $meta['categories'] = mcp_categories_from_meta($meta['categories']);
    }

    foreach ($meta as $key => $val) {
        if (is_array($val) || is_object($val)) {
            mcp_tool_error(
                'Frontmatter-Werte müssen einfache Werte sein (Text, Zahl, true/false). '
                . 'Betroffen ist das Feld: ' . (string) $key
            );
        }
    }

    $title = mcp_optional_string($args, 'title');
    if ($title !== null) {
        $meta['title'] = $title;
    }

    unset($meta['slug']);
    return $meta;
}

/**
 * Prüft das Meta-Feld "categories" und liefert den Frontmatter-Wert.
 *
 * Angenommen werden kommagetrennte Slugs oder eine Liste von Slugs. Ein
 * unbekannter Slug ist ein Fehler, der die gültigen nennt — so korrigiert
 * sich Claude selbst, statt eine Zuordnung zu speichern, die das Frontend
 * stillschweigend ignoriert. Ein leerer Wert entfernt die Zuordnung.
 *
 * Geprüft wird nur, was mitgesendet wird: eine Seite, die noch eine
 * inzwischen gelöschte Kategorie trägt, bleibt über update_page änderbar,
 * solange das Feld nicht angefasst wird.
 */
function mcp_categories_from_meta(mixed $value): string {
    if (is_array($value)) {
        foreach ($value as $part) {
            if (!is_string($part) && !is_int($part)) {
                mcp_tool_error('Das Feld "categories" muss kommagetrennte Slugs oder eine Liste von Slugs enthalten.');
            }
        }
        $value = implode(',', $value);
    }
    if ($value === null) {
        $value = '';
    }
    if (!is_string($value) && !is_int($value)) {
        mcp_tool_error('Das Feld "categories" muss kommagetrennte Slugs oder eine Liste von Slugs enthalten.');
    }

    $slugs   = cms_category_slugs_parse((string) $value);
    $valid   = array_column(cms_categories_load(mcp_config()), 'slug');
    $unknown = array_values(array_diff($slugs, $valid));
    if ($unknown !== []) {
        mcp_tool_error(
            'Unbekannte Kategorie: ' . implode(', ', $unknown) . '. '
            . ($valid === []
                ? 'Es sind noch keine Kategorien angelegt. Das geht nur im Admin-Backend unter Einstellungen.'
                : 'Gültige Slugs sind: ' . implode(', ', $valid) . '. Neue Kategorien werden nur im '
                  . 'Admin-Backend unter Einstellungen angelegt.')
        );
    }

    return cms_category_slugs_join($slugs);
}

// mcp_decode_base64_image() ist mit upload_image entfallen (siehe dort) —
// nur dort gebraucht. Bei Reaktivierung aus der Git-Historie wiederherstellen
// (Commit 5fef877).

// ─── Schreib-Rate-Limit (Feature-PRD 6.6) ───────────────────────────────────
//
// 50 Schreib-Tool-Calls pro Zeitfenster und Installation, Lesen unbegrenzt.
// Der Zähler hängt bewusst nicht an der IP: Claude ruft aus wechselnden
// Rechenzentren an, ein IP-Limit wäre wirkungslos. Das Fenster läuft ab dem
// ersten Schreibzugriff, nicht ab Mitternacht — für den Zweck (Missbrauch und
// Endlosschleifen abfangen) reicht das und spart eine Kalenderrechnung.

/** Prüft das Limit und zählt den Aufruf. Bricht mit einem Tool-Fehler ab, wenn es erreicht ist. */
function mcp_guard_write_limit(): void {
    $state = cms_rate_limit_read('mcp_write', 'installation', MCP_WRITE_WINDOW);

    if ($state['count'] >= MCP_WRITE_LIMIT) {
        $wieder = date('d.m.Y H:i', $state['reset']);
        mcp_tool_error(
            'Tageslimit für KI-Änderungen erreicht (' . MCP_WRITE_LIMIT . ' Änderungen). '
            . "Ab {$wieder} Uhr geht es weiter — bis dahin lassen sich Inhalte im "
            . 'Admin-Backend der Website weiter bearbeiten.'
        );
    }

    cms_rate_limit_hit('mcp_write', 'installation', MCP_WRITE_WINDOW);
}

// ─── Tool-Rechte (Abschnitt 5.5) ────────────────────────────────────────────
//
// Ordnet jedes Tool einem Recht aus Role::permissions() (lib/enums.php) zu.
// Steuert zwei Dinge: die pro Rolle gefilterte tools/list-Antwort (nur UX,
// Claude soll keine Tools vorschlagen, die ohnehin abgelehnt würden) und die
// serverseitige Prüfung bei jedem einzelnen tools/call weiter unten — Spec
// 5.5 verlangt ausdrücklich beides, die gefilterte Liste allein ist kein
// Zugriffsschutz. 'settings'/'users' tauchen hier nicht auf: kein MCP-Tool
// braucht diese Rechte, sie gelten nur im Admin-Backend.
const MCP_TOOL_PERMISSIONS = [
    'list_pages'         => 'content',
    'get_page'           => 'content',
    'create_page'        => 'content',
    'update_page'        => 'content',
    'delete_page'        => 'content',
    'create_landingpage' => 'content',
    'update_landingpage' => 'content',
    'list_categories'    => 'content',
    'list_images'        => 'images',
    'get_image'          => 'images',
    'upload_image'       => 'images',
    'delete_image'       => 'images',
    'get_styles'         => 'styles',
    'update_styles'      => 'styles',
    'get_custom_css'     => 'styles',
    'update_custom_css'  => 'styles',
    'restore_styles'     => 'styles',
    'list_redirects'     => 'redirects',
    'create_redirect'    => 'redirects',
    'delete_redirect'    => 'redirects',
];

// ─── Tool-Definitionen ──────────────────────────────────────────────────────
//
// Beschreibungen bewusst ausführlich und auf Deutsch (Feature-PRD 5): Claude
// soll ohne kundenseitiges Prompt-Engineering das richtige Tool wählen.

/** Schema-Baustein für frei wählbare Frontmatter-Felder. */
function mcp_meta_schema(): array {
    return [
        'type'        => 'object',
        'description' => 'Weitere Frontmatter-Felder als Schlüssel-Wert-Paare. '
            . 'Gebräuchlich sind: description (Meta-Description für Suchmaschinen), '
            . 'navLabel (Beschriftung in der Navigation, ohne dieses Feld erscheint die '
            . 'Seite nicht im Menü), order (Sortierung im Menü, kleiner = weiter vorne), '
            . 'heading (sichtbare Überschrift), headerImage (Pfad zu einem Kopfbild), '
            . 'robots (z.B. "noindex, nofollow"), showInNav und showInFooter (true/false), '
            . 'ogTitle/ogDescription/ogImage (Open Graph für Social-Media-Vorschauen — '
            . 'fallen beim Rendern automatisch auf title/description/headerImage zurück, '
            . 'wenn leer; ogImage ist ein Bildpfad wie headerImage, kein URL-Fetch nötig), '
            . 'categories (Kategorien der Seite als kommagetrennte Slugs, z.B. "seo, wordpress" — '
            . 'nur Slugs aus list_categories, ein leerer Wert entfernt die Zuordnung). '
            . 'Nur einfache Werte — keine Listen, keine verschachtelten Objekte.',
        'additionalProperties' => true,
    ];
}

function mcp_tool_definitions(): array {
    $slug_desc = 'Der Slug der Seite, also ihre Adresse ohne Dateiendung — '
        . 'zum Beispiel "kontakt" oder "work/projekt-alpha". '
        . 'Erlaubt sind Kleinbuchstaben, Ziffern, Bindestriche und Schrägstriche.';

    // Der Shortcode hat kein eigenes Tool, eine Seite damit entsteht über
    // create_page/update_page. Die Syntax steht deshalb in deren Beschreibung.
    $category_shortcode_desc = ' Seiten einer Kategorie lassen sich im Inhalt mit dem Shortcode '
        . '[category-list category="slug" style="list"] verlinken (in einer eigenen Zeile; '
        . 'style ist "list" für eine Linkliste oder "cards" für Karten mit Beschreibung und '
        . 'Kopfbild). Angezeigt werden nur veröffentlichte Seiten der Kategorie, die Slugs '
        . 'nennt list_categories.';

    $image_filename_desc = 'Der Dateiname des Bildes in assets/images/, z. B. '
        . '"produktfoto.jpg". Mit list_images lässt sich nachsehen, welche Bilder es gibt.';

    // Beschreibung der Font-Whitelist wird aus CMS_STYLE_FONT_WHITELIST (lib/styles.php)
    // gebaut statt fest eingetippt — bleibt so automatisch konsistent, falls die Liste
    // sich künftig ändert (Abschnitt 8: weitere selbst gehostete Fonts).
    $style_font_desc = 'Erlaubt sind ausschließlich bereits selbst gehostete Fonts, jeweils '
        . 'mit vollständiger Fallback-Kette wie in get_styles zu sehen: "'
        . implode('" oder "', CMS_STYLE_FONT_WHITELIST) . '".';

    $style_token_descriptions = [
        '--color-bg'         => 'Hintergrundfarbe der Website (CSS-Farbwert, z. B. #ffffff oder rgb(...)).',
        '--color-text'       => 'Haupttextfarbe.',
        '--color-text-light' => 'Hellere Textfarbe, z. B. für Meta-Angaben und Untertitel.',
        '--color-border'     => 'Farbe von Trennlinien und Rahmen.',
        '--color-link'       => 'Farbe von Links im Fließtext.',
        '--color-link-hover' => 'Farbe von Links beim Überfahren mit der Maus (Hover).',
        '--font-heading'     => 'Schriftart für Überschriften. ' . $style_font_desc,
        '--font-body'        => 'Schriftart für den Fließtext. ' . $style_font_desc,
        '--max-width'        => 'Maximale Breite des Seiteninhalts (CSS-Längenangabe, z. B. 1100px).',
        '--header-height'    => 'Höhe des Headers (CSS-Längenangabe, z. B. 80px).',
    ];

    // Jeder der zehn bekannten Schlüssel wird einzeln als Property gelistet (statt eines
    // freien "additionalProperties: true") — additionalProperties: false lehnt einen
    // unbekannten Schlüssel damit schon auf Schema-Ebene ab, cms_update_style_tokens()
    // (lib/styles.php) prüft es beim Schreiben zusätzlich noch einmal serverseitig.
    $style_token_properties = [];
    foreach (CMS_STYLE_TOKEN_KEYS as $key) {
        $style_token_properties[$key] = ['type' => 'string', 'description' => $style_token_descriptions[$key]];
    }

    return [
        [
            'name'        => 'list_pages',
            'description' => 'Listet alle Seiten der Website auf, mit Titel, Slug, '
                . 'Navigations-Beschriftung, Kategorien und Entwurfsstatus. Nutze dieses Tool '
                . 'zuerst, wenn du wissen musst, welche Seiten es gibt oder wie der Slug einer '
                . 'Seite lautet, die der Nutzer nur mit ihrem Namen bezeichnet.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'include_drafts' => [
                        'type'        => 'boolean',
                        'description' => 'Entwürfe mit auflisten. Standard: true.',
                    ],
                    'category' => [
                        'type'        => 'string',
                        'description' => 'Nur Seiten dieser Kategorie auflisten (Slug aus '
                            . 'list_categories). Weglassen für alle Seiten.',
                    ],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'get_page',
            'description' => 'Liest den vollständigen Inhalt einer einzelnen Seite: den '
                . 'Markdown-Text und alle Metadaten. Rufe dieses Tool immer auf, bevor du '
                . 'eine Seite mit update_page änderst — nur so kennst du den bestehenden '
                . 'Text und kannst ihn gezielt ergänzen, statt ihn zu überschreiben.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => $slug_desc],
                ],
                'required'             => ['slug'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'create_page',
            'description' => 'Legt eine neue Seite mit Slug, Titel und Markdown-Inhalt an. '
                . 'Nutze dies, wenn der Nutzer eine Seite hinzufügen möchte, die es noch '
                . 'nicht gibt. Prüfe vorher mit list_pages, ob der Slug schon vergeben ist. '
                . 'Damit die Seite in der Navigation erscheint, gib navLabel in "meta" an.'
                . $category_shortcode_desc,
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'  => ['type' => 'string', 'description' => $slug_desc],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Titel der Seite. Steht im Browser-Tab und als '
                            . '<title> im Quelltext.',
                    ],
                    'body'  => [
                        'type'        => 'string',
                        'description' => 'Der Seiteninhalt als Markdown, ohne '
                            . 'Frontmatter-Block. Überschriften mit #, Links mit [Text](Ziel).',
                    ],
                    'meta'  => mcp_meta_schema(),
                ],
                'required'             => ['slug', 'title', 'body'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'update_page',
            'description' => 'Ändert Titel, Metadaten oder Inhalt einer bestehenden Seite. '
                . 'Nutze dies, wenn der Nutzer bittet, einen Text zu ändern, zu ergänzen '
                . 'oder zu kürzen. Lies die Seite zuerst mit get_page: "body" ersetzt den '
                . 'kompletten Inhalt, ein Absatz "on top" muss also mitsamt dem bisherigen '
                . 'Text übergeben werden. Sollen nur Metadaten geändert werden, lass "body" weg.'
                . $category_shortcode_desc,
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'  => ['type' => 'string', 'description' => $slug_desc],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Neuer Titel. Weglassen, um den bisherigen zu behalten.',
                    ],
                    'body'  => [
                        'type'        => 'string',
                        'description' => 'Der vollständige neue Markdown-Inhalt. Ersetzt den '
                            . 'bisherigen Text komplett. Weglassen, um ihn unverändert zu lassen.',
                    ],
                    'meta'  => mcp_meta_schema(),
                ],
                'required'             => ['slug'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'delete_page',
            'description' => 'Löscht eine bestehende Seite. Vorher wird automatisch eine '
                . 'Sicherungskopie angelegt, die der Website-Betreiber im Admin-Backend '
                . 'wiederherstellen kann. Frag im Zweifel nach, bevor du dieses Tool aufrufst.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => $slug_desc],
                ],
                'required'             => ['slug'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'create_landingpage',
            'description' => 'Legt eine neue eigenständige Landingpage mit rohem HTML-Inhalt an '
                . '(kein Markdown, kein Escaping) — für Verkaufs-/Kampagnenseiten mit mehr '
                . 'visueller Freiheit als create_page. Website-Header und -Footer bleiben '
                . 'erhalten, der Bereich dazwischen ist frei gestaltbar. Erscheint standardmäßig '
                . 'NICHT in der Navigation — navLabel in "meta" nur setzen, wenn der Nutzer das '
                . 'ausdrücklich wünscht. Prüfe vorher mit list_pages, ob der Slug schon vergeben ist.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'      => ['type' => 'string', 'description' => $slug_desc],
                    'title'     => [
                        'type'        => 'string',
                        'description' => 'Titel der Seite. Steht im Browser-Tab und als '
                            . '<title> im Quelltext.',
                    ],
                    'body'      => [
                        'type'        => 'string',
                        'description' => 'Der Seiteninhalt als rohes HTML (kein Markdown) — '
                            . 'landet unverändert zwischen Header und Footer. Verbotene Tags: '
                            . '<script>, <iframe>, <object>, <embed>, <link>; keine '
                            . 'on*-Attribute (onclick, onerror, ...). Grob fehlerhaftes HTML '
                            . '(z. B. nicht geschlossene Tags) wird abgelehnt.',
                    ],
                    'pageStyle' => [
                        'type'        => 'string',
                        'description' => 'Optionales CSS, das nur auf dieser einen Seite wirkt '
                            . '(im <head> nach style.css/custom.css eingebunden, kann diese also '
                            . 'überschreiben). @import und externe url(http(s)://...) sind nicht '
                            . 'erlaubt — nur selbst gehostete/relative Pfade oder data:-URLs.',
                    ],
                    'meta'      => mcp_meta_schema(),
                ],
                'required'             => ['slug', 'title', 'body'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'update_landingpage',
            'description' => 'Ändert Titel, HTML-Inhalt, seiteneigenes CSS oder Metadaten einer '
                . 'bestehenden Landingpage. Lies die Seite zuerst mit get_page: "body" ersetzt '
                . 'den kompletten HTML-Inhalt. Gleiche Regeln wie bei create_landingpage '
                . '(Tag-Blockliste, kein <script>, CSS ohne externe Nachladevorgänge).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'      => ['type' => 'string', 'description' => $slug_desc],
                    'title'     => [
                        'type'        => 'string',
                        'description' => 'Neuer Titel. Weglassen, um den bisherigen zu behalten.',
                    ],
                    'body'      => [
                        'type'        => 'string',
                        'description' => 'Der vollständige neue HTML-Inhalt. Ersetzt den '
                            . 'bisherigen Inhalt komplett. Weglassen, um ihn unverändert zu '
                            . 'lassen. Gleiche Validierung wie bei create_landingpage.',
                    ],
                    'pageStyle' => [
                        'type'        => 'string',
                        'description' => 'Neues seiteneigenes CSS, ersetzt das bisherige '
                            . 'vollständig. Ein leerer Text entfernt das bisherige CSS wieder. '
                            . 'Weglassen, um es unverändert zu lassen.',
                    ],
                    'meta'      => mcp_meta_schema(),
                ],
                'required'             => ['slug'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'list_categories',
            'description' => 'Listet alle Kategorien der Website mit Slug und Name auf. Der Slug '
                . 'ist das, was im Meta-Feld "categories" einer Seite, im Parameter "category" von '
                . 'list_pages und im Shortcode [category-list] steht. Kategorien anlegen, '
                . 'umbenennen und löschen geht nur im Admin-Backend unter Einstellungen.',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'list_images',
            'description' => 'Listet alle hochgeladenen Bilder der Website mit Dateiname, '
                . 'URL, Größe und Änderungsdatum auf.',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'get_image',
            'description' => 'Liest ein einzelnes Bild anhand seines Dateinamens. Gibt bei '
                . 'Dateien bis 5 MB das Bild direkt zur Ansicht zurück, bei größeren nur '
                . 'Metadaten und die öffentliche URL.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'filename' => ['type' => 'string', 'description' => $image_filename_desc],
                ],
                'required'             => ['filename'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'upload_image',
            'description' => 'Vorübergehend deaktiviert. Bild-Upload per Chat ist aktuell nicht '
                . 'verfügbar — verweise den Nutzer stattdessen auf den Bild-Upload im '
                . 'Admin-Backend der Website ("Bilder verwalten").',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'filename'   => [
                        'type'        => 'string',
                        'description' => 'Gewünschter Dateiname inklusive Endung, z. B. '
                            . '"produktfoto.jpg". Wird bereinigt; bei Namenskollision hängt '
                            . 'das CMS automatisch eine Zahl an (kein Fehler) — der '
                            . 'tatsächlich verwendete Name steht in der Antwort.',
                    ],
                    'image_data' => [
                        'type'        => 'string',
                        'description' => 'Der Bildinhalt Base64-kodiert. Ein optionaler '
                            . 'data:image/...;base64,-Präfix wird toleriert und abgeschnitten.',
                    ],
                ],
                'required'             => ['filename', 'image_data'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'delete_image',
            'description' => 'Löscht ein bestehendes Bild unwiderruflich. Kein automatisches '
                . 'Backup — vorher beim Nutzer nachfragen, falls Unsicherheit besteht, ob das '
                . 'Bild noch gebraucht wird.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'filename' => ['type' => 'string', 'description' => $image_filename_desc],
                ],
                'required'             => ['filename'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'get_styles',
            'description' => 'Liest die aktuellen Design-Tokens der Website: Farben, Fonts und '
                . 'Layout-Grundmaße (die zehn Variablen im :root-Block von assets/style.css).',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'update_styles',
            'description' => 'Ändert einzelne Design-Tokens der Website, z. B. Akzentfarbe oder '
                . 'Schriftart. Rufe zuerst get_styles auf, um die aktuellen Werte und die exakte '
                . 'Fallback-Kette der erlaubten Fonts zu sehen. Nur bereits selbst gehostete Fonts '
                . 'sind erlaubt. Wirkt sofort auf die Live-Website, es gibt keinen Entwurfsmodus wie bei Seiten.',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => $style_token_properties,
                'minProperties'        => 1,
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'get_custom_css',
            'description' => 'Liest das freie, zusätzliche CSS der Website (assets/custom.css), '
                . 'das über die Design-Tokens hinausgeht.',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'update_custom_css',
            'description' => 'Überschreibt das freie CSS der Website (assets/custom.css) '
                . 'vollständig. Externe Nachladevorgänge (@import, externe url()) sind nicht '
                . 'erlaubt — nur selbst gehostete/relative Pfade oder data:-URLs. Ein leerer Text '
                . 'entfernt das freie CSS wieder. Wirkt sofort auf die Live-Website, es gibt '
                . 'keinen Entwurfsmodus.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'css' => [
                        'type'        => 'string',
                        'description' => 'Der komplette neue Inhalt von assets/custom.css. '
                            . 'Ersetzt den bisherigen Inhalt vollständig, kein Anhängen — lies '
                            . 'vorher mit get_custom_css, wenn Bestehendes erhalten bleiben soll. '
                            . 'Ein leerer Text entfernt das freie CSS.',
                    ],
                ],
                'required'             => ['css'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'restore_styles',
            'description' => 'Macht die letzte Änderung an den Design-Tokens oder am freien CSS '
                . 'rückgängig — stellt die jeweils jüngste gesicherte Vorversion wieder her. '
                . 'Es gibt keine Admin-Oberfläche für Styles, dieses Tool ist der einzige Weg, '
                . 'eine KI-Style-Änderung zurückzunehmen.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'target' => [
                        'type'        => 'string',
                        'enum'        => ['tokens', 'custom_css'],
                        'description' => '"tokens" macht die letzte Änderung an den '
                            . 'Design-Tokens rückgängig (get_styles/update_styles), '
                            . '"custom_css" die letzte Änderung am freien CSS '
                            . '(get_custom_css/update_custom_css).',
                    ],
                ],
                'required'             => ['target'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'list_redirects',
            'description' => 'Listet alle angelegten 301-Redirects (alte URL → neue URL) mit '
                . 'id, Anlegedatum und Nutzer — für einen Überblick vor create_redirect/delete_redirect.',
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'create_redirect',
            'description' => 'Legt einen neuen 301-Redirect an: Aufrufe der alten URL werden '
                . 'dauerhaft zur neuen URL weitergeleitet. Beide Pfade sind interne Pfade mit '
                . 'führendem "/" (kein Schema/Host, keine externen Ziele). Eine alte URL, die '
                . 'einer noch existierenden Seite entspricht, wird abgelehnt — ein Redirect darf '
                . 'keine lebende Seite verdecken. Existiert für die alte URL bereits ein Redirect, '
                . 'muss er zuerst mit delete_redirect entfernt werden (kein Überschreiben).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'from' => [
                        'type'        => 'string',
                        'description' => 'Die alte URL, z. B. "/alte-seite".',
                    ],
                    'to' => [
                        'type'        => 'string',
                        'description' => 'Die neue URL, auf die weitergeleitet wird, z. B. "/neue-seite".',
                    ],
                ],
                'required'             => ['from', 'to'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name'        => 'delete_redirect',
            'description' => 'Löscht einen bestehenden Redirect unwiderruflich. Die alte URL '
                . 'liefert danach wieder den normalen 404-Zweig. Mit list_redirects vorher die id '
                . 'nachsehen.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'description' => 'Die id des Redirects, siehe list_redirects.',
                    ],
                ],
                'required'             => ['id'],
                'additionalProperties' => false,
            ],
        ],
    ];
}

/** Tool-Definitionen, gefiltert auf das, was die Rolle laut MCP_TOOL_PERMISSIONS sehen darf. */
function mcp_tool_definitions_for_role(string $role): array {
    return array_values(array_filter(
        mcp_tool_definitions(),
        static fn(array $tool): bool => cms_role_can($role, MCP_TOOL_PERMISSIONS[$tool['name']] ?? '')
    ));
}

// ─── Tools ──────────────────────────────────────────────────────────────────

/** Schreiboptionen für lib/content.php: Kennzeichnung, Backup, Entwurfsregel. */
function mcp_write_opts(bool $with_draft_rule): array {
    $opts = ['edited_by' => 'ai', 'backup' => true];
    if ($with_draft_rule && !mcp_publish_immediately()) {
        $opts['draft'] = true;
    }
    return $opts;
}

/** Hinweistext zur Entwurfsregel, der jeder Schreibantwort vorangestellt wird. */
function mcp_draft_note(bool $created): string {
    if (mcp_publish_immediately()) {
        return $created
            ? 'Die Seite wurde angelegt und ist sofort öffentlich erreichbar.'
            : 'Die Änderung wurde gespeichert und ist sofort öffentlich sichtbar.';
    }
    return $created
        ? 'Entwurf gespeichert, wartet auf Freigabe durch den Website-Betreiber. '
          . 'Die Seite ist noch nicht öffentlich erreichbar — sie wird erst im '
          . 'Admin-Backend freigegeben.'
        : 'Entwurf gespeichert, wartet auf Freigabe durch den Website-Betreiber. '
          . 'Wichtig: Die Seite ist bis zur Freigabe im Admin-Backend nicht mehr '
          . 'öffentlich erreichbar. Die vorherige Fassung ist gesichert und lässt '
          . 'sich dort wiederherstellen.';
}

/** Bricht mit einem Tool-Fehler ab, wenn das CRUD-Ergebnis ein Fehler ist. */
function mcp_guard_crud(array $result): void {
    if (empty($result['ok'])) {
        mcp_tool_error((string) ($result['error'] ?? 'Der Vorgang ist fehlgeschlagen.'));
    }
}

function mcp_tool_list_pages(array $args): never {
    $include_drafts = !array_key_exists('include_drafts', $args)
        || filter_var($args['include_drafts'], FILTER_VALIDATE_BOOL);

    $category = mcp_optional_string($args, 'category');
    $category = $category === null ? '' : trim($category);
    if ($category !== '') {
        // Gleiche Prüfung und Meldung wie beim Setzen: ein Tippfehler soll
        // nicht wie eine leere Kategorie aussehen.
        $category = mcp_categories_from_meta($category);
    }

    $pages = cms_list_pages($include_drafts);
    if ($pages === []) {
        mcp_tool_ok('Die Website hat noch keine Seiten.');
    }

    // cms_list_pages() kennt keine Kategorien, sie kommen aus demselben
    // Content-Index dazu.
    $index = cms_build_content_index(null, '', $include_drafts);
    foreach ($pages as $i => $page) {
        $pages[$i]['categories'] = cms_category_slugs_parse($index[$page['slug']]['meta']['categories'] ?? '');
    }

    if ($category !== '') {
        $pages = array_values(array_filter(
            $pages,
            static fn(array $page): bool => in_array($category, $page['categories'], true)
        ));
        if ($pages === []) {
            mcp_tool_ok("In der Kategorie \"{$category}\" gibt es keine Seiten.");
        }
    }

    mcp_tool_json(
        ['pages' => $pages],
        count($pages) . ' Seite(n) gefunden.'
    );
}

function mcp_tool_get_page(array $args): never {
    $slug = mcp_require_string($args, 'slug');
    $page = cms_get_page($slug);

    if ($page === false) {
        mcp_tool_error(
            "Es gibt keine Seite mit dem Slug \"{$slug}\". "
            . 'Mit list_pages lässt sich nachsehen, welche Seiten es gibt.'
        );
    }

    mcp_tool_json([
        'slug'       => $page['slug'],
        'draft'      => cms_is_draft($page['meta']),
        'categories' => cms_category_slugs_parse($page['meta']['categories'] ?? ''),
        'meta'       => (object) $page['meta'],
        'body'       => $page['body'],
    ]);
}

function mcp_tool_create_page(array $args): never {
    $slug  = mcp_require_string($args, 'slug');
    $meta  = mcp_meta_from_args($args);
    $body  = mcp_optional_string($args, 'body') ?? '';

    if (trim((string) ($meta['title'] ?? '')) === '') {
        mcp_tool_error('Für eine neue Seite wird ein Titel gebraucht.');
    }

    mcp_guard_write_limit();

    $result = cms_create_page($slug, $meta, $body, mcp_write_opts(true));
    mcp_guard_crud($result);

    mcp_tool_json(
        ['slug' => $result['slug'], 'draft' => $result['draft']],
        mcp_draft_note(true)
    );
}

function mcp_tool_update_page(array $args): never {
    $slug = mcp_require_string($args, 'slug');
    $meta = mcp_meta_from_args($args);
    $body = mcp_optional_string($args, 'body');

    if (cms_get_page($slug) === false) {
        mcp_tool_error(
            "Es gibt keine Seite mit dem Slug \"{$slug}\". "
            . 'Eine neue Seite wird mit create_page angelegt.'
        );
    }

    mcp_guard_write_limit();

    $result = cms_update_page($slug, $meta, $body, mcp_write_opts(true));
    mcp_guard_crud($result);

    mcp_tool_json(
        ['slug' => $result['slug'], 'draft' => $result['draft']],
        mcp_draft_note(false)
    );
}

function mcp_tool_delete_page(array $args): never {
    $slug = mcp_require_string($args, 'slug');

    if (cms_get_page($slug) === false) {
        mcp_tool_error("Es gibt keine Seite mit dem Slug \"{$slug}\".");
    }

    mcp_guard_write_limit();

    // Kein Entwurfs-Flag: eine gelöschte Datei kann keinen Status tragen. Die
    // Vorversion in content/.history/ ist hier die einzige Rückfallebene.
    $result = cms_delete_page($slug, ['backup' => true]);
    mcp_guard_crud($result);

    mcp_tool_json(
        ['slug' => $slug, 'deleted' => true],
        "Die Seite \"{$slug}\" wurde gelöscht. Eine Sicherungskopie liegt im "
        . 'Admin-Backend bereit und lässt sich dort wiederherstellen.'
    );
}

// ─── Landingpage-Tools ───────────────────────────────────────────────────────
//
// Eigenes Tool-Paar statt Erweiterung von create_page/update_page (Spec
// Entscheidung 2) — eigene Tool-Beschreibung, kein Risiko, dass eine normale
// Markdown-Seite versehentlich HTML-interpretiert wird. Nutzen intern aber
// dieselben cms_create_page()/cms_update_page() aus lib/content.php weiter:
// contentType ist ein Frontmatter-Feld wie jedes andere, keine eigene
// Speicherlogik nötig (Spec 4.1). Body-Validierung über
// cms_validate_landingpage_html() (lib/content.php, Spec 6.3), CSS-Prüfung
// bei pageStyle über cms_custom_css_safe() (lib/styles.php) — dieselbe
// Blockliste wie update_custom_css (Spec 6.4).

/**
 * Prüft ein pageStyle-Feld in $meta, falls gesetzt und nicht leer. Läuft
 * bewusst auf $meta['pageStyle'] statt auf dem eigenen "pageStyle"-Argument,
 * damit ein über das freie "meta"-Objekt mitgeschmuggelter Wert dieselbe
 * Prüfung durchläuft wie der reguläre Weg über das eigene Argument.
 */
function mcp_guard_landingpage_style(array $meta): void {
    if (!empty($meta['pageStyle']) && !cms_custom_css_safe((string) $meta['pageStyle'])) {
        mcp_tool_error(
            'Externe Nachladevorgänge (@import, externe url()) sind im seiteneigenen CSS '
            . 'nicht erlaubt — nur selbst gehostete/relative Pfade oder data:-URLs.'
        );
    }
}

function mcp_tool_create_landingpage(array $args): never {
    $slug       = mcp_require_string($args, 'slug');
    mcp_require_string($args, 'title'); // Pflichtfeld, landet über mcp_meta_from_args() in $meta['title']
    $body       = mcp_require_string($args, 'body');
    $page_style = mcp_optional_string($args, 'pageStyle');
    $meta       = mcp_meta_from_args($args);

    // contentType wird immer automatisch gesetzt (Spec 4.1), auch wenn im
    // freien "meta"-Objekt versehentlich ein anderer Wert mitkäme.
    $meta['contentType'] = ContentType::Html->value;
    if ($page_style !== null) {
        $meta['pageStyle'] = $page_style;
    }

    // Reihenfolge nach Spec 5.1: Rate-Limit zuerst, dann Slug-Kollision, dann
    // Validierung — wie bei update_styles/update_custom_css
    // ausdrücklich vorgegeben, nicht das sonst übliche Muster (Existenz-/
    // Kollisionsprüfung vor dem Rate-Limit wie bei delete_page).
    mcp_guard_write_limit();

    if (cms_get_page($slug) !== false) {
        mcp_tool_error(
            "Der Slug \"{$slug}\" ist bereits vergeben. Für eine bestehende Seite "
            . 'update_landingpage nutzen.'
        );
    }

    $html_error = cms_validate_landingpage_html($body);
    if ($html_error !== null) {
        mcp_tool_error($html_error);
    }
    mcp_guard_landingpage_style($meta);

    $result = cms_create_page($slug, $meta, $body, mcp_write_opts(true));
    mcp_guard_crud($result);

    mcp_tool_json(
        ['slug' => $result['slug'], 'draft' => $result['draft']],
        mcp_draft_note(true)
    );
}

function mcp_tool_update_landingpage(array $args): never {
    $slug       = mcp_require_string($args, 'slug');
    $body       = mcp_optional_string($args, 'body');
    $page_style = mcp_optional_string($args, 'pageStyle');
    $meta       = mcp_meta_from_args($args);

    $meta['contentType'] = ContentType::Html->value;
    if ($page_style !== null) {
        $meta['pageStyle'] = $page_style;
    }

    // Reihenfolge nach Spec 5.2: Rate-Limit vor Existenzprüfung, wie bei
    // create_landingpage (Begründung dort).
    mcp_guard_write_limit();

    if (cms_get_page($slug) === false) {
        mcp_tool_error(
            "Es gibt keine Seite mit dem Slug \"{$slug}\". "
            . 'Eine neue Landingpage wird mit create_landingpage angelegt.'
        );
    }

    if ($body !== null) {
        $html_error = cms_validate_landingpage_html($body);
        if ($html_error !== null) {
            mcp_tool_error($html_error);
        }
    }
    mcp_guard_landingpage_style($meta);

    $result = cms_update_page($slug, $meta, $body, mcp_write_opts(true));
    mcp_guard_crud($result);

    mcp_tool_json(
        ['slug' => $result['slug'], 'draft' => $result['draft']],
        mcp_draft_note(false)
    );
}

// ─── Kategorien ─────────────────────────────────────────────────────────────

function mcp_tool_list_categories(array $args): never {
    $categories = cms_categories_load(mcp_config());
    if ($categories === []) {
        mcp_tool_ok(
            'Es sind noch keine Kategorien angelegt. Das geht nur im Admin-Backend '
            . 'unter Einstellungen.'
        );
    }

    mcp_tool_json(
        ['categories' => $categories],
        count($categories) . ' Kategorie(n) gefunden.'
    );
}

// ─── Bild-Tools ──────────────────────────────────────────────────────────────
//
// Nutzen lib/media.php für Validierung/Sanitizing/Speichern/Löschen
// (Abschnitt 4.1) — dieselben Funktionen, auf die admin.php (action=images)
// umgestellt ist. Hier steht nur der MCP-Transport: Base64 rein/raus, die
// 5-MB-Grenze bei get_image, absolute URLs über mcp_site_url().

function mcp_tool_list_images(array $args): never {
    $images = cms_list_images();
    if ($images === []) {
        mcp_tool_ok('Es sind noch keine Bilder hochgeladen.');
    }

    $site = mcp_site_url();
    $out  = array_map(
        static fn(array $img): array => [...$img, 'url' => $site . '/' . $img['url']],
        $images
    );

    mcp_tool_json(['images' => $out], count($out) . ' Bild(er) gefunden.');
}

function mcp_tool_get_image(array $args): never {
    $filename = mcp_require_string($args, 'filename');
    $full     = cms_resolve_image_path($filename);

    // Dieselbe Fehlermeldung für ungültigen Dateinamen und für "existiert
    // nicht" — analog get_page, keine Preisgabe von Validierungsdetails.
    if ($full === false || !file_exists($full)) {
        mcp_tool_error(
            "Es gibt kein Bild mit dem Dateinamen \"{$filename}\". "
            . 'Mit list_images lässt sich nachsehen, welche Bilder es gibt.'
        );
    }

    $size        = (int) (@filesize($full) ?: 0);
    $mime        = cms_image_mime_type($full);
    $url         = mcp_site_url() . '/assets/images/' . basename($full);
    $modified_at = date('c', (int) (@filemtime($full) ?: 0));

    if ($size > MCP_IMAGE_INLINE_LIMIT) {
        mcp_tool_json([
            'filename'    => basename($full),
            'url'         => $url,
            'size_bytes'  => $size,
            'mime_type'   => $mime,
            'modified_at' => $modified_at,
        ], 'Datei zu groß für Direktvorschau (>5 MB), hier der Link: ' . $url);
    }

    $data = @file_get_contents($full);
    if ($data === false) {
        mcp_tool_error('Die Datei liess sich nicht lesen.');
    }

    mcp_tool_content([
        ['type' => 'image', 'data' => base64_encode($data), 'mimeType' => $mime],
        ['type' => 'text', 'text' => "Dateiname: " . basename($full) . "\nURL: {$url}"],
    ]);
}

/**
 * Vorübergehend deaktiviert (2026-09-18) — der Praxistest hat
 * live gezeigt, dass Base64-Bild-Upload per Chat nicht zuverlässig ist:
 * Tool-Aufruf-Argumente sind in MCP ein normales JSON-Feld (kein
 * Ressourcen-Verweis-Mechanismus für Tool-*Eingaben*, anders als bei
 * Tool-*Ergebnissen* — siehe MCP-Spec), das Modell muss die komplette
 * Base64-Zeichenkette also selbst Token für Token generieren. Bei einem
 * 31-KB-Foto (~41.000 Zeichen) kam nach 4 Minuten nur ein Drittel an.
 * cms_validate_image_upload() (lib/media.php) erkennt solche abgeschnittenen
 * Uploads seit dem Fix zuverlässig — das löst aber nicht das
 * Übertragungsproblem selbst. Wird in einem eigenen Feature neu angegangen.
 *
 * list_images/get_image/delete_image bleiben aktiv — nur ein Upload braucht
 * die lange, vom Modell generierte Zeichenkette. Die zugrundeliegenden
 * Bibliotheksfunktionen (cms_validate_image_upload(), cms_sanitize_image_filename(),
 * cms_store_image()) sind unverändert nutzbar und bedienen weiterhin den
 * Browser-Upload in admin.php — nur dieser MCP-Weg ist abgeschaltet.
 * Frühere Implementierung: siehe Git-Historie (Commit 5fef877).
 */
function mcp_tool_upload_image(array $args): never {
    mcp_tool_error(
        'Bild-Upload per Chat ist aktuell deaktiviert. Bitte das Bild stattdessen über '
        . 'das Admin-Backend der Website hochladen (Bereich "Bilder verwalten").'
    );
}

function mcp_tool_delete_image(array $args): never {
    $filename = mcp_require_string($args, 'filename');
    $full     = cms_resolve_image_path($filename);

    // Existenz vor dem Rate-Limit prüfen (wie delete_page) — ein Tippfehler
    // im Dateinamen soll kein Kontingent aus MCP_WRITE_LIMIT verbrauchen.
    if ($full === false || !file_exists($full)) {
        mcp_tool_error(
            "Es gibt kein Bild mit dem Dateinamen \"{$filename}\". "
            . 'Mit list_images lässt sich nachsehen, welche Bilder es gibt.'
        );
    }

    mcp_guard_write_limit();

    if (!cms_delete_image($filename)) {
        mcp_tool_error('Das Bild liess sich nicht löschen.');
    }

    mcp_tool_ok(
        "Das Bild \"{$filename}\" wurde unwiderruflich gelöscht. Anders als bei Seiten "
        . 'gibt es keine Sicherungskopie.'
    );
}

// ─── Style-Tools ─────────────────────────────────────────────────────────────
//
// Nutzen lib/styles.php für Parsing/Validierung/Backup (Abschnitt 4.1).
// Anders als bei Seiten/Bildern gibt es keinen Entwurfsmodus — jede
// erfolgreiche Änderung ist sofort live (Abschnitt 0 Punkt 9).

function mcp_tool_get_styles(array $args): never {
    $tokens = cms_get_style_tokens();
    if ($tokens === []) {
        mcp_tool_error(
            'Die Design-Tokens konnten nicht gelesen werden — assets/style.css fehlt oder '
            . 'der :root-Block darin ist beschädigt.'
        );
    }

    mcp_tool_json($tokens, count($tokens) . ' Design-Tokens gefunden.');
}

function mcp_tool_update_styles(array $args): never {
    // Reihenfolge exakt wie Abschnitt 5.2 vorgegeben:
    // Rate-Limit-Check zuerst, erst danach die Schlüssel-/Wert-Validierung —
    // anders als z.B. bei delete_image, wo die Existenzprüfung bewusst vor
    // dem Limit läuft.
    mcp_guard_write_limit();

    // Grobe Typprüfung vor der eigentlichen Validierung in lib/styles.php:
    // ein Token-Wert, der als JSON-Array/-Objekt statt als String ankommt,
    // würde dort sonst über eine (string)-Umwandlung stillschweigend zu
    // "Array" werden, statt einen sprechenden Fehler zu liefern.
    foreach ($args as $key => $value) {
        if (!is_string($value)) {
            mcp_tool_error('Der Wert für "' . $key . '" muss Text sein.');
        }
    }

    $result = cms_update_style_tokens($args);
    if ($result['ok'] === false) {
        mcp_tool_error((string) $result['error']);
    }

    mcp_tool_json(
        ['changed' => $result['changed']],
        'Design-Tokens aktualisiert. Die Änderung ist sofort live auf der Website sichtbar.'
    );
}

function mcp_tool_get_custom_css(array $args): never {
    $css = cms_get_custom_css();
    if (trim($css) === '') {
        mcp_tool_ok(
            'assets/custom.css ist leer oder existiert noch nicht — es ist aktuell kein freies '
            . 'CSS über die Design-Tokens hinaus hinterlegt.'
        );
    }
    mcp_tool_ok($css);
}

function mcp_tool_update_custom_css(array $args): never {
    // Reihenfolge exakt wie Abschnitt 5.4: Rate-Limit-Check zuerst,
    // danach erst jede weitere Prüfung (Feldform, Blockliste) — analog
    // update_styles, wo Spec 5.2 dieselbe Reihenfolge vorgibt.
    mcp_guard_write_limit();

    // Kein mcp_require_string(): ein leerer Text ist hier ein gültiger, bewusster
    // Aufruf ("entferne das freie CSS wieder"), keine fehlende Eingabe.
    if (!array_key_exists('css', $args) || !is_string($args['css'])) {
        mcp_tool_error('Das Feld "css" fehlt oder ist kein Text.');
    }
    $css = $args['css'];

    $result = cms_update_custom_css($css);
    if ($result['ok'] === false) {
        mcp_tool_error((string) $result['error']);
    }

    $note = 'assets/custom.css aktualisiert. Die Änderung ist sofort live auf der Website sichtbar.';
    if (trim($css) === '') {
        $note .= ' Es ist jetzt kein freies CSS mehr hinterlegt — die Datei wird deshalb nicht mehr eingebunden.';
    }
    mcp_tool_ok($note);
}

function mcp_tool_restore_styles(array $args): never {
    $target = mcp_require_string($args, 'target');
    if (!in_array($target, ['tokens', 'custom_css'], true)) {
        mcp_tool_error('Das Feld "target" muss "tokens" oder "custom_css" sein.');
    }

    // Existenzprüfung vor dem Rate-Limit — analog delete_page/delete_image:
    // ohne jede Vorversion kann der Aufruf ohnehin nicht gelingen und soll
    // kein Kontingent aus dem 50er-Tageslimit verbrauchen.
    if (cms_style_history_list($target) === []) {
        mcp_tool_error('Keine vorherige Version zum Wiederherstellen gefunden.');
    }

    mcp_guard_write_limit();

    if (!cms_style_history_restore($target)) {
        mcp_tool_error('Die Wiederherstellung ist fehlgeschlagen.');
    }

    $label = $target === 'tokens' ? 'den Design-Tokens' : 'dem freien CSS';
    mcp_tool_ok(
        "Die letzte Änderung an {$label} wurde rückgängig gemacht und ist sofort live sichtbar. "
        . 'Der überschriebene Stand wurde vor dem Zurücksetzen selbst gesichert und lässt sich '
        . 'mit einem weiteren Aufruf von restore_styles ebenfalls wiederherstellen.'
    );
}

// ─── Tool-Implementierungen: Redirects ──────────────────────────────────────
//
// Nutzt lib/redirects.php für Speicherung/Validierung. Admin-only, wie die
// Styles-Tools — Redirects wirken site-weit auf das Routing, nicht auf
// einzelne Inhalte.

function mcp_tool_list_redirects(array $args): never {
    $redirects = cms_load_redirects();
    if ($redirects === []) {
        mcp_tool_ok('Noch keine Redirects angelegt.');
    }
    mcp_tool_json($redirects, count($redirects) . ' Redirect(s) gefunden.');
}

function mcp_tool_create_redirect(array $args): never {
    $from = mcp_require_string($args, 'from');
    $to   = mcp_require_string($args, 'to');

    // Rate-Limit vor der Validierung — analog update_styles/update_custom_css
    // (Spec 5.2/5.4): Schreib-Tools zählen einheitlich zuerst.
    mcp_guard_write_limit();

    $existing_slugs = array_map(
        static fn(array $p): string => $p['slug'],
        cms_list_pages(true)
    );
    // 'ai' als created_by, analog 'ai' bei lastEditedBy für Seiten (Feature-PRD
    // 5.2) — kein Zugriff auf $mcp_user nötig, gleiche Konvention wie dort.
    $result = cms_create_redirect($from, $to, $existing_slugs, 'ai');
    if ($result['ok'] === false) {
        mcp_tool_error((string) $result['error']);
    }

    $note = 'Redirect angelegt: ' . $result['redirect']['from'] . ' → ' . $result['redirect']['to'] . '.';
    if ($result['warning'] !== null) {
        $note .= ' Hinweis: ' . $result['warning'];
    }
    mcp_tool_json(['redirect' => $result['redirect']], $note);
}

function mcp_tool_delete_redirect(array $args): never {
    $id = mcp_require_string($args, 'id');

    // Existenzprüfung vor dem Rate-Limit — analog delete_page/delete_image:
    // ein Tippfehler in der id soll kein Kontingent verbrauchen.
    $exists = false;
    foreach (cms_load_redirects() as $r) {
        if (($r['id'] ?? '') === $id) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        mcp_tool_error('Redirect nicht gefunden.');
    }

    mcp_guard_write_limit();

    $result = cms_delete_redirect($id);
    if ($result['ok'] === false) {
        mcp_tool_error((string) $result['error']);
    }
    mcp_tool_ok('Redirect gelöscht. Die alte URL liefert jetzt wieder den normalen 404-Zweig.');
}

// ─── Ablauf ─────────────────────────────────────────────────────────────────

// Rate-Limit vor der Authentifizierung — sonst wäre der Connector-Token
// beliebig oft ratbar. Zähler liegt in config/ratelimit/, siehe lib/security.php.
$limit = cms_rate_limit_hit('mcp', cms_client_ip(), MCP_RATE_WINDOW);
if ($limit['count'] > MCP_RATE_LIMIT) {
    header('Retry-After: ' . max(1, $limit['reset'] - time()));
    rpc_error(null, -32000, 'Zu viele Anfragen. Bitte später erneut versuchen.', 429);
}

// Migration hier zusätzlich zu admin.php (Spec 4.2): mcp.php kann
// schon vor dem ersten Admin-Login aufgerufen werden (z.B. gleich nach dem
// Deploy dieses Features), und der Token-Lookup unten braucht users.json
// bereits vorhanden. Idempotent, gleicher Schnellpfad wie in admin.php.
cms_migrate_users(mcp_config());

$provided_token = mcp_extract_token();
$mcp_user       = $provided_token !== '' ? cms_find_user_by_mcp_token($provided_token) : null;

if ($mcp_user === null || ($mcp_user['status'] ?? '') !== UserStatus::Aktiv->value) {
    // cms_find_user_by_mcp_token() vergleicht zeitkonstant per hash_equals.
    // Identische Fehlermeldung für "kein Token", "falscher Token" und
    // "Nutzer gesperrt" (keine Existenz-Preisgabe, analog Login-Schutz Spec
    // 6.4). Ein im Admin-Backend neu erzeugter oder gesperrter Token macht
    // die alte Connector-URL genau hier sofort ungültig.
    rpc_error(null, -32001, 'Ungültiger oder fehlender Connector-Token.', 401);
}

$http_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($http_method === 'GET' || $http_method === 'HEAD') {
    // Kein SSE-Stream im synchronen Modus — nur eine schlichte Health-Antwort.
    mcp_send(['status' => 'ok', 'server' => mcp_server_name(), 'version' => MCP_SERVER_VERSION]);
}

if ($http_method !== 'POST') {
    header('Allow: POST, GET');
    rpc_error(null, -32600, 'Nur POST wird unterstützt.', 405);
}

$raw = (string) file_get_contents('php://input', false, null, 0, MCP_MAX_BODY + 1);
if (strlen($raw) > MCP_MAX_BODY) {
    rpc_error(null, -32600, 'Die Anfrage ist zu gross (Maximum: 1 MB).', 413);
}

$msg = json_decode($raw, true);

if (!is_array($msg)) {
    rpc_error(null, -32700, 'Parse error: ungültiges JSON.');
}
if (isset($msg[0])) {
    // JSON-RPC-Batches werden nicht unterstützt (synchroner Ein-Request-Modus).
    rpc_error(null, -32600, 'Batch-Requests werden nicht unterstützt.');
}

$mcp_id = $msg['id'] ?? null;
$method = (string) ($msg['method'] ?? '');
$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

// ─── MCP-Methoden ───────────────────────────────────────────────────────────

// Notifications tragen keine id und bekommen keinen Body zurück. Der Fall
// deckt neben notifications/initialized auch notifications/cancelled ab.
if (str_starts_with($method, 'notifications/') || $method === 'initialized') {
    $mcp_replied = true;
    http_response_code(202);
    exit;
}

switch ($method) {
    case 'initialize':
        // protocolVersion des Clients spiegeln, statt eine feste Version zu
        // erzwingen — so bleibt der Connector über Spec-Versionen hinweg nutzbar.
        $client_protocol = is_string($params['protocolVersion'] ?? null)
            ? $params['protocolVersion']
            : MCP_PROTOCOL_FALLBACK;

        // Instructions rollenabhängig gekürzt (Spec 5.5): ein
        // Content Creator sieht die Styles-Tools weder in tools/list noch
        // darf er sie aufrufen — eine Anleitung, die sie trotzdem erwähnt,
        // würde Claude auf Tools verweisen, die für diesen Nutzer gar nicht
        // existieren.
        $instructions = 'Dieser Server pflegt die Inhalte einer Website, die als '
            . 'Markdown-Dateien vorliegt. Verschaffe dir mit list_pages einen Überblick '
            . 'und lies eine Seite mit get_page, bevor du sie mit update_page änderst — '
            . 'update_page ersetzt den Inhalt vollständig. list_images und get_image '
            . 'funktionieren; upload_image ist aktuell deaktiviert (verweise auf das '
            . 'Admin-Backend), delete_image nur nach Rückfrage beim Nutzer — es gibt kein '
            . 'Backup. create_landingpage/update_landingpage legen eigenständige Seiten mit '
            . 'rohem HTML statt Markdown an (Verkaufs-/Kampagnenseiten) — kein Ersatz für '
            . 'create_page/update_page, sondern ein eigenes Werkzeug für einen anderen Zweck. '
            . 'Seiten lassen sich Kategorien zuordnen (Meta-Feld "categories", Slugs aus '
            . 'list_categories); der Shortcode [category-list category="slug"] im Seiteninhalt '
            . 'verlinkt alle veröffentlichten Seiten einer Kategorie.';
        if (cms_role_can($mcp_user['role'], 'styles')) {
            $instructions .= ' get_styles/update_styles ändern Design-Tokens (Farben, Fonts, '
                . 'Layout-Grundmaße), get_custom_css/update_custom_css das freie CSS darüber '
                . 'hinaus — beide wirken sofort live, ohne Entwurfsmodus. restore_styles macht '
                . 'die jeweils letzte Style-Änderung rückgängig, es gibt keine Admin-Oberfläche '
                . 'dafür.';
        }
        if (cms_role_can($mcp_user['role'], 'redirects')) {
            $instructions .= ' list_redirects/create_redirect/delete_redirect verwalten '
                . '301-Weiterleitungen (alte URL → neue URL). Ein Redirect darf keine noch '
                . 'existierende Seite verdecken; für ein geändertes Ziel erst löschen, dann neu '
                . 'anlegen (kein update_redirect).';
        }
        $instructions .= ' Antworte auf Deutsch und gib den Hinweis zum Entwurfsstatus aus der '
            . 'Tool-Antwort an den Nutzer weiter.';

        rpc_result($mcp_id, [
            'protocolVersion' => $client_protocol,
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => mcp_server_name(), 'version' => MCP_SERVER_VERSION],
            'instructions'    => $instructions,
        ]);

    case 'ping':
        rpc_result($mcp_id, new stdClass());

    case 'tools/list':
        rpc_result($mcp_id, ['tools' => mcp_tool_definitions_for_role($mcp_user['role'])]);

    case 'tools/call':
        $tool = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // Rechteprüfung bei jedem einzelnen Aufruf, nicht nur über die pro
        // Rolle gefilterte tools/list-Antwort oben (Spec 5.5: "kein
        // Sicherheitsmechanismus, nur UX"). Ein unbekanntes Tool fällt hier
        // ebenfalls durch (kein Eintrag in MCP_TOOL_PERMISSIONS) und landet
        // im selben Protokollfehler wie unten der Fallback nach dem Switch.
        $permission = MCP_TOOL_PERMISSIONS[$tool] ?? null;
        if ($permission !== null && !cms_role_can($mcp_user['role'], $permission)) {
            $rolle = $mcp_user['role'] === Role::Admin->value ? 'Admin' : 'Content Creator';
            mcp_tool_error("Für dieses Tool fehlt deiner Rolle ({$rolle}) die Berechtigung.");
        }

        switch ($tool) {
            case 'list_pages':    mcp_tool_list_pages($args);
            case 'get_page':      mcp_tool_get_page($args);
            case 'create_page':   mcp_tool_create_page($args);
            case 'update_page':   mcp_tool_update_page($args);
            case 'delete_page':   mcp_tool_delete_page($args);
            case 'create_landingpage': mcp_tool_create_landingpage($args);
            case 'update_landingpage': mcp_tool_update_landingpage($args);
            case 'list_categories':    mcp_tool_list_categories($args);
            case 'list_images':   mcp_tool_list_images($args);
            case 'get_image':     mcp_tool_get_image($args);
            case 'upload_image':  mcp_tool_upload_image($args);
            case 'delete_image':  mcp_tool_delete_image($args);
            case 'get_styles':        mcp_tool_get_styles($args);
            case 'update_styles':     mcp_tool_update_styles($args);
            case 'get_custom_css':    mcp_tool_get_custom_css($args);
            case 'update_custom_css': mcp_tool_update_custom_css($args);
            case 'restore_styles':    mcp_tool_restore_styles($args);
            case 'list_redirects':    mcp_tool_list_redirects($args);
            case 'create_redirect':   mcp_tool_create_redirect($args);
            case 'delete_redirect':   mcp_tool_delete_redirect($args);
        }

        // Unbekanntes Tool ist ein Protokollfehler, kein Tool-Fehler: die Liste
        // der Tools stammt vom Server, ein Treffer daneben ist kein Nutzerthema.
        rpc_error($mcp_id, -32602, "Unbekanntes Tool: {$tool}");

    default:
        rpc_error($mcp_id, -32601, "Methode nicht unterstützt: {$method}");
}
