<?php
/**
 * Lightweight Markdown CMS
 *
 * A simple PHP-based CMS that reads Markdown files with YAML frontmatter.
 * Upload everything via FTP — no build step, no SSH required.
 *
 * Content goes in /content/*.md and /content/subfolder/*.md
 * Subfolders create nested navigation and URL paths.
 *
 * Frontmatter fields:
 *   title       – HTML <title> tag
 *   description – Meta description for SEO
 *   robots      – Meta robots directive (e.g. "index, follow" or "noindex")
 *   slug        – Clean URL path (e.g. "about", "work/project-alpha")
 *   navLabel    – Text shown in the navigation (omit to hide from nav)
 *   order       – Sort order in navigation (lower = first)
 *   heading     – Visible page heading (H1)
 *   headerImage – Path to a header image (shown in two-column layout)
 *
 * Subfolder convention:
 *   /content/work/          → subfolder name matches parent page slug "work"
 *   /content/work/alpha.md  → slug should be "work/alpha" → URL: /work/alpha
 *   These pages appear as a dropdown under the "Work" nav item.
 */

// Markiert diesen Einstiegspunkt, damit config/config.php geladen werden darf.
define('CMS_ENTRY', true);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/config.php';

// Session für CSRF-Schutz des Kontaktformulars (HttpOnly/Secure/SameSite) —
// startet NICHT hier pauschal (L9): das gäbe jedem Besucher ein PHPSESSID-
// Cookie, auch auf Seiten ganz ohne Kontaktformular. cms_start_session() wird
// stattdessen weiter unten nur aufgerufen, wenn der POST-Handler oder das
// Formular selbst eine Session tatsächlich brauchen (Kontaktformular-POST
// bzw. eine Seite, deren Inhalt den [contact-form]-Shortcode enthält).

// ─── Configuration ───────────────────────────────────────────────────────────
$_cms_cfg = cms_config_with_defaults(require __DIR__ . '/config/config.php');
define('SITE_TITLE',   $_cms_cfg['site_title']);
define('SITE_DOMAIN',  $_cms_cfg['site_domain']);
define('CONTENT_DIR',  __DIR__ . '/content');
define('DEFAULT_SLUG', $_cms_cfg['default_slug']);
// $_cms_cfg bleibt für den Kontaktformular-Handler verfügbar (SMTP-Daten)

// Frontmatter-Parser, Pfad-Prüfung und Content-Index — geteilt mit admin.php,
// api.php und mcp.php. Muss nach CONTENT_DIR kommen.
require_once __DIR__ . '/lib/content.php';

// Freies CSS aus der KI-Anbindung — cms_get_custom_css() liefert
// nur den Dateiinhalt, ob und wie assets/custom.css eingebunden wird,
// entscheidet der Head-Block weiter unten.
require_once __DIR__ . '/lib/styles.php';

// Erweiterungen dieser Instanz (extensions/*/bootstrap.php) — fehlt der
// Ordner, passiert nichts. Muss nach den Libs stehen, die sie nutzen.
require_once __DIR__ . '/lib/extensions.php';
cms_extensions_load(__DIR__ . '/extensions');

// 301-Redirect-Verwaltung — cms_redirects_resolve()
// wird im Routing weiter unten gebraucht, nachdem feststeht, dass keine
// lebende Content-Seite unter dem Slug existiert.
require_once __DIR__ . '/lib/redirects.php';

// Kategorien — Shortcode [category-list] und die optionale Anzeige der
// Kategorien unter dem Seiteninhalt.
require_once __DIR__ . '/lib/categories.php';

// Site URL: site_domain ist Pflicht. Der bisherige Host-Header-Fallback war zwar gegen Injection
// bereinigt (Regex), blieb aber vom Request steuerbar und baut u.a. den
// kanonischen Link und Bild-/Stylesheet-URLs — auf einem echten Host ohne
// gesetzte Domain lieber sichtbar 500 als eine vom Angreifer beeinflusste
// URL ausliefern. Ausnahme wie beim HTTPS-Zwang in der .htaccess: lokale
// Entwicklung (localhost/127.0.0.1) funktioniert weiter ohne gesetzte
// site_domain, sonst bräuchte jede lokale Instanz zwingend eine Domain in
// config.php.
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_local_host = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $http_host) === 1;
if (SITE_DOMAIN !== '') {
    define('SITE_URL', rtrim(SITE_DOMAIN, '/'));
} elseif ($is_local_host) {
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $http_host ?: 'localhost');
    define('SITE_URL', $proto . '://' . $host);
} else {
    http_response_code(500);
    exit('Konfigurationsfehler: In den Website-Einstellungen ist keine Domain hinterlegt (site_domain).');
}

// Security-Header setzen, bevor irgendetwas ausgegeben wird
cms_send_security_headers(SITE_URL, false);

// ─── Rohes Markdown ausliefern ───────────────────────────────────────────────
//
// Die .htaccess leitet /content/<datei>.md hierher um, statt den Server die
// Datei direkt ausliefern zu lassen. Grund ist die Entwurfs-Logik: "draft:
// true" steht im Frontmatter, davon weiss Apache nichts. Ohne diesen Umweg
// waere ein Entwurf unter seiner Datei-URL lesbar, obwohl die Seite selbst
// ein 404 liefert. Veroeffentlichte Seiten kommen weiterhin als rohes
// Markdown heraus — das ist laut PRD gewollt.
if (isset($_GET['raw'])) {
    $raw_file = cms_resolve_content_path((string)$_GET['raw']);
    $raw_data = $raw_file === false ? false : cms_parse_md_file($raw_file, true);

    if ($raw_data === false || cms_is_draft($raw_data['meta'])) {
        // Gleiche Antwort fuer "gibt es nicht" und "ist ein Entwurf" — sonst
        // liesse sich an der Antwort ablesen, dass die Datei existiert.
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("404 Not Found\n");
    }

    header('Content-Type: text/markdown; charset=utf-8');
    readfile($raw_file);
    exit;
}

// ─── Markdown Parser ────────────────────────────────────────────────────────
//
// Frontmatter-Parsing und Content-Index liegen in lib/content.php. Das
// Frontend liest im Cast-Modus (cms_parse_md_file(..., true)), weil die
// Navigation order als Zahl und showInNav als bool braucht.

/**
 * Prüft, ob eine URL aus einem Markdown-Link oder -Bild in ein href/src darf.
 *
 * Erlaubt sind http(s), mailto, tel, Anker, Query-Strings und relative Pfade.
 * Blockiert wird vor allem javascript: (führt beim Klick Code im Browser des
 * Besuchers aus) und data: (kann eine ganze HTML-Seite transportieren).
 */
function is_safe_url(string $url): bool {
    // Whitespace und Steuerzeichen entfernen — Browser ignorieren sie beim
    // Auflösen der URL, "java&#9;script:" waere sonst ein Filter-Bypass.
    $probe = strtolower(preg_replace('/[\x00-\x20\x7F]/', '', $url));
    if ($probe === '') {
        return false;
    }

    // Anker, Query, absolute und protokoll-relative Pfade
    if ($probe[0] === '#' || $probe[0] === '?' || $probe[0] === '/') {
        return true;
    }

    if (preg_match('#^(https?|mailto|tel):#', $probe)) {
        return true;
    }

    // Alles Übrige mit ":" vor dem ersten "/" ist ein fremdes Schema
    $colon = strpos($probe, ':');
    if ($colon !== false) {
        $slash = strpos($probe, '/');
        if ($slash === false || $colon < $slash) {
            return false;
        }
    }

    return true; // relativer Pfad, z.B. "assets/images/bild.jpg"
}

/**
 * Convert Markdown to HTML (lightweight, no external dependencies).
 *
 * @param bool $lazy_first_image Lazy Loading: steuert,
 *     ob auch das allererste Bild im Text loading="lazy" bekommt. Das "erste sichtbare
 *     Bild" der Seite soll immer eager bleiben — ist bereits ein Header-Bild vorhanden,
 *     übernimmt das diese Rolle und hier wird true übergeben (jedes Textbild ist lazy).
 *     Ohne Header-Bild ist das erste Textbild selbst das erste sichtbare Bild, der
 *     Aufrufer übergibt dann false, und genau dieses eine Bild bleibt ohne Attribut.
 */
function markdown_to_html(string $md, bool $lazy_first_image = true): string {
    $html = $md;

    // Normalize line endings
    $html = str_replace("\r\n", "\n", $html);

    // Step 1: Extract fenced code blocks before escaping (preserve raw content)
    //
    // L15: Der Platzhalter braucht einen zufaelligen Anteil pro Aufruf — sonst
    // wuerde Fliesstext, der zufaellig "%%CODEBLOCK_0%%" enthaelt, in Schritt 3
    // faelschlich durch einen Code-Block ersetzt (Inhaltsverfaelschung, keine
    // Sicherheitsluecke). Der Token ist pro Seitenaufruf neu, also nicht
    // vorhersehbar.
    $code_blocks = [];
    $placeholder_token = bin2hex(random_bytes(8));
    $html = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) use (&$code_blocks, $placeholder_token) {
        $placeholder = '%%CODEBLOCK_' . $placeholder_token . '_' . count($code_blocks) . '%%';
        $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1]) . '"' : '';
        $code_blocks[$placeholder] = "<pre><code{$lang}>{$code}</code></pre>";
        return $placeholder;
    }, $html);

    // Step 2: Escape all remaining HTML to prevent injection
    $html = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');

    // Step 3: Restore code blocks
    foreach ($code_blocks as $placeholder => $block) {
        $html = str_replace(htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'), $block, $html);
    }

    // Inline code
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

    // Images ![alt](src) — unsichere Schemata bleiben unverändert als Text stehen,
    // damit auffällt, dass der Link nicht übernommen wurde. Lazy Loading: jedes
    // Bild ausser ggf. dem allerersten bekommt loading="lazy"
    // (siehe Doc-Kommentar der Funktion). $first_image_seen zählt nur tatsächlich
    // gerenderte <img>-Tags — ein wegen unsicherem Schema unverändert gelassenes
    // Bild "verbraucht" den Eager-Platz nicht.
    $first_image_seen = false;
    $html = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function ($m) use (&$first_image_seen, $lazy_first_image) {
        if (!is_safe_url($m[2])) return $m[0];
        $lazy = $first_image_seen || $lazy_first_image;
        $first_image_seen = true;
        $loading_attr = $lazy ? ' loading="lazy"' : '';
        return '<img src="' . $m[2] . '" alt="' . $m[1] . '"' . $loading_attr . '>';
    }, $html);

    // Links [text](url)
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        if (!is_safe_url($m[2])) return $m[0];
        return '<a href="' . $m[2] . '">' . $m[1] . '</a>';
    }, $html);

    // Headings
    $html = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

    // Horizontal rule
    $html = preg_replace('/^---$/m', '<hr>', $html);

    // Bold and italic
    $html = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

    // Unordered lists
    $html = preg_replace_callback('/(?:^- .+\n?)+/m', function($m) {
        $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($m[0]));
        return "<ul>\n{$items}\n</ul>\n";
    }, $html);

    // Ordered lists
    $html = preg_replace_callback('/(?:^\d+\. .+\n?)+/m', function($m) {
        $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($m[0]));
        return "<ol>\n{$items}\n</ol>\n";
    }, $html);

    // Blockquotes
    $html = preg_replace_callback('/(?:^> .+\n?)+/m', function($m) {
        $text = preg_replace('/^> /m', '', trim($m[0]));
        return "<blockquote><p>{$text}</p></blockquote>\n";
    }, $html);

    // Paragraphs: wrap remaining text blocks
    $html = preg_replace('/(?<=\n\n|^\n|^)(?!<[a-z])([^\n<][^\n]*(?:\n(?!<[a-z]|\n)[^\n]+)*)(?=\n\n|\n$|$)/s', '<p>$1</p>', $html);

    // Clean up extra newlines
    $html = preg_replace('/\n{3,}/', "\n\n", $html);

    return trim($html);
}

// ─── Content Index: Build slug → file mapping (recursive) ───────────────────

// ─── Navigation Builder (hierarchical) ──────────────────────────────────────

/**
 * Build a two-level navigation structure.
 * Top-level: pages with navLabel and no parent.
 * Sub-level: pages whose parent matches a top-level slug.
 */
function get_navigation(array $content_index): array {
    $top_items = [];
    $sub_items = [];

    foreach ($content_index as $slug => $entry) {
        $meta = $entry['meta'];
        if (empty($meta['navLabel'])) continue;
        // showInNav: default true for backward compatibility (pages with navLabel)
        if (isset($meta['showInNav']) && $meta['showInNav'] === false) continue;

        $item = [
            'slug'     => $slug,
            'label'    => $meta['navLabel'],
            'order'    => isset($meta['order']) ? (int)$meta['order'] : 99,
            'parent'   => $entry['parent'],
            'children' => [],
        ];

        if ($entry['parent'] === null) {
            $top_items[$slug] = $item;
        } else {
            $sub_items[] = $item;
        }
    }

    // Attach children to their parent
    foreach ($sub_items as $child) {
        $parent_slug = $child['parent'];
        if (isset($top_items[$parent_slug])) {
            $top_items[$parent_slug]['children'][] = $child;
        }
    }

    // Sort top-level by order
    usort($top_items, fn($a, $b) => $a['order'] <=> $b['order']);

    // Sort children within each parent
    foreach ($top_items as &$item) {
        usort($item['children'], fn($a, $b) => $a['order'] <=> $b['order']);
    }

    return $top_items;
}

/**
 * Build a flat list of pages for the footer navigation.
 * Only includes pages where showInFooter is explicitly true and navLabel is set.
 */
function get_footer_navigation(array $content_index): array {
    $items = [];

    foreach ($content_index as $slug => $entry) {
        $meta = $entry['meta'];
        if (empty($meta['navLabel'])) continue;
        if (empty($meta['showInFooter'])) continue;

        $items[] = [
            'slug'  => $slug,
            'label' => $meta['navLabel'],
            'order' => isset($meta['order']) ? (int)$meta['order'] : 99,
        ];
    }

    usort($items, fn($a, $b) => $a['order'] <=> $b['order']);
    return $items;
}

// ─── Kontaktformular ─────────────────────────────────────────────────────────

// Absendungen pro IP und Zeitfenster. Grosszügig genug, dass jemand nach einem
// Tippfehler noch einmal schreiben kann, eng genug gegen Mail-Flooding.
define('CONTACT_RATE_MAX',    5);
define('CONTACT_RATE_WINDOW', 3600);

/**
 * Sendet eine E-Mail via PHPMailer/SMTP.
 * Gibt true zurück bei Erfolg, false bei Fehler.
 */
function send_contact_email(array $cfg, string $name, string $email, string $message): bool {
    require_once __DIR__ . '/lib/phpmailer/Exception.php';
    require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/lib/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'];
        $mail->Password   = $cfg['smtp_pass'];
        $mail->SMTPSecure = $cfg['smtp_secure'];
        $mail->Port       = (int)$cfg['smtp_port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['smtp_from_email'], $cfg['smtp_from_name']);
        $mail->addAddress($cfg['contact_to_email']);
        $mail->addReplyTo($email, $name);

        $mail->Subject = 'Neue Kontaktanfrage von ' . $name;
        $mail->Body    = "Name:    {$name}\nE-Mail:  {$email}\n\n" . $message;

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Verarbeitet den POST-Request des Kontaktformulars.
 * Gibt ['success' => bool, 'error' => string|null] zurück.
 */
function handle_contact_submission(array $cfg): array {
    // CSRF-Prüfung
    $token = $_POST['contact_csrf'] ?? '';
    if (!isset($_SESSION['contact_csrf']) || !hash_equals($_SESSION['contact_csrf'], $token)) {
        return ['success' => false, 'error' => 'Ungültiges Sicherheits-Token. Bitte lade die Seite neu.'];
    }

    // Honeypot: Bot-Erkennung — Feld muss leer bleiben
    // Klammern zwingend: ?? bindet schwächer als !== (Operator-Präzedenz-Falle)
    if (($_POST['contact_website'] ?? '') !== '') {
        // Stille Ablehnung — für Bots identisches Verhalten wie Erfolg
        return ['success' => true, 'error' => null];
    }

    // Timing-Check: Formular in unter 2 Sekunden abgesendet = wahrscheinlich Bot
    // Render-Zeitstempel wird in der Session gespeichert (nicht user-controlled)
    $render_time = $_SESSION['contact_render_time'] ?? 0;
    if ($render_time === 0) {
        // Fehlender Zeitstempel bei gültigem CSRF-Token: die Session lebt, nur
        // der Stempel fehlt (z.B. Seite aus dem Cache, zweites Absenden).
        // Früher lief das in die stille Erfolgsmeldung — die Nachricht war weg,
        // der Absender dachte, sie sei angekommen.
        return ['success' => false, 'error' => 'Bitte lade die Seite neu und sende die Nachricht erneut.'];
    }
    if ((time() - $render_time) < 2) {
        return ['success' => true, 'error' => null]; // stille Ablehnung
    }
    unset($_SESSION['contact_render_time']);

    // Eingaben validieren und bereinigen
    $name    = trim($_POST['contact_name'] ?? '');
    $email   = trim($_POST['contact_email'] ?? '');
    $message = trim($_POST['contact_message'] ?? '');

    if ($name === '' || mb_strlen($name) > 100) {
        return ['success' => false, 'error' => 'Bitte gib deinen Namen ein (max. 100 Zeichen).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
        return ['success' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse ein.'];
    }
    if ($message === '' || mb_strlen($message) > 5000) {
        return ['success' => false, 'error' => 'Bitte gib eine Nachricht ein (max. 5000 Zeichen).'];
    }

    // SMTP-Konfiguration prüfen
    if (empty($cfg['smtp_host']) || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])
        || empty($cfg['smtp_from_email']) || empty($cfg['contact_to_email'])) {
        return ['success' => false, 'error' => 'Das Kontaktformular ist derzeit nicht konfiguriert.'];
    }

    // Rate-Limit: Honeypot und Timing-Check kosten ein Skript je eine Zeile.
    // Ohne Zähler liesse sich der hinterlegte SMTP-Account beliebig oft
    // ansteuern — das Risiko ist nicht der Serverausfall, sondern die
    // Absender-Reputation der Domain.
    $rate = cms_rate_limit_read('contact', cms_client_ip(), CONTACT_RATE_WINDOW);
    if ($rate['count'] >= CONTACT_RATE_MAX) {
        $minutes = max(1, (int)ceil(($rate['reset'] - time()) / 60));
        return [
            'success' => false,
            'error'   => "Es wurden zu viele Nachrichten von dieser Verbindung gesendet. "
                       . "Bitte versuche es in {$minutes} Minuten erneut.",
        ];
    }
    // Vor dem Versand zählen: jeder Versuch zählt, nicht nur der erfolgreiche.
    cms_rate_limit_hit('contact', cms_client_ip(), CONTACT_RATE_WINDOW);

    if (!send_contact_email($cfg, $name, $email, $message)) {
        return ['success' => false, 'error' => 'Die Nachricht konnte nicht gesendet werden. Bitte versuche es später erneut.'];
    }

    // CSRF-Token nach Erfolg erneuern
    $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
    return ['success' => true, 'error' => null];
}

/**
 * Rendert das Kontaktformular als HTML-String.
 * Wird durch den Shortcode [contact-form] in Markdown-Inhalten aufgerufen.
 */
function render_contact_form(?array $result): string {
    // CSRF-Token erzeugen oder vorhandenen verwenden
    if (empty($_SESSION['contact_csrf'])) {
        $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = htmlspecialchars($_SESSION['contact_csrf'], ENT_QUOTES, 'UTF-8');
    // Render-Zeitstempel in Session speichern (nicht user-controllable im POST)
    $_SESSION['contact_render_time'] = time();

    // Erfolgsmeldung
    if ($result !== null && $result['success']) {
        return '<div class="contact-success" role="alert">'
             . 'Vielen Dank! Deine Nachricht wurde erfolgreich gesendet. '
             . 'Ich melde mich so bald wie möglich zurück.'
             . '</div>';
    }

    // Fehlermeldung (Felder vorausfüllen für bessere UX)
    $error_html = '';
    if ($result !== null && !$result['success'] && $result['error'] !== null) {
        $error_html = '<div class="contact-error" role="alert">'
                    . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8')
                    . '</div>';
    }

    // Formularfelder vorausfüllen (nach Fehler)
    $val_name    = htmlspecialchars($_POST['contact_name']    ?? '', ENT_QUOTES, 'UTF-8');
    $val_email   = htmlspecialchars($_POST['contact_email']   ?? '', ENT_QUOTES, 'UTF-8');
    $val_message = htmlspecialchars($_POST['contact_message'] ?? '', ENT_QUOTES, 'UTF-8');

    return <<<HTML
{$error_html}
<form class="contact-form" method="post" action="" novalidate>
  <input type="hidden" name="contact_csrf" value="{$csrf}">

  <!-- Honeypot: für Menschen unsichtbar, Bots füllen es aus -->
  <div class="contact-honeypot" aria-hidden="true">
    <label for="contact_website">Website (bitte leer lassen)</label>
    <input type="text" id="contact_website" name="contact_website"
           tabindex="-1" autocomplete="off" value="">
  </div>

  <div class="contact-field">
    <label for="cf_name">Name <span class="required">*</span></label>
    <input type="text" id="cf_name" name="contact_name"
           value="{$val_name}" required maxlength="100" autocomplete="name">
  </div>

  <div class="contact-field">
    <label for="cf_email">E-Mail <span class="required">*</span></label>
    <input type="email" id="cf_email" name="contact_email"
           value="{$val_email}" required maxlength="200" autocomplete="email">
  </div>

  <div class="contact-field">
    <label for="cf_message">Nachricht <span class="required">*</span></label>
    <textarea id="cf_message" name="contact_message"
              required maxlength="5000" rows="6">{$val_message}</textarea>
  </div>

  <button type="submit" name="contact_submit" class="contact-btn">
    Nachricht senden
  </button>
</form>
HTML;
}

// ─── Kontaktformular POST-Handler (vor dem Routing) ──────────────────────────
$contact_result = null;
if (!empty($_cms_cfg['contact_enabled']) && isset($_POST['contact_submit'])) {
    // Session erst hier starten (L9), nicht mehr pauschal ganz oben —
    // CSRF-/Timing-Check in handle_contact_submission() braucht $_SESSION.
    cms_start_session();
    $contact_result = handle_contact_submission($_cms_cfg);
}

// Haken für Erweiterungen: POST-Handler o. Ä. vor dem Seitenaufbau.
cms_do_action('before_routing', $_cms_cfg);

// ─── Routing ────────────────────────────────────────────────────────────────

// Ohne Entwürfe: Seiten mit draft: true sind nicht öffentlich abrufbar und
// tauchen weder in der Navigation noch im Footer auf. Für einen Draft-Slug
// greift damit derselbe 404-Zweig wie für einen unbekannten Slug.
$content_index = cms_build_content_index(CONTENT_DIR, '', false);

// Get slug from URL (via .htaccess rewrite)
$requested_slug = isset($_GET['slug']) ? trim($_GET['slug'], '/') : '';
if ($requested_slug === '' || $requested_slug === 'index.php') {
    $requested_slug = DEFAULT_SLUG;
}

// XML-Sitemap: eigener Ausstieg VOR der
// Slug-Sanitisierung weiter unten — die entfernt den Punkt und würde aus
// "sitemap.xml" sonst "sitemapxml". Kein Cache (bewusste Entscheidung): $content_index oben wird ohnehin bei jedem Seitenaufruf neu
// gebaut, eine zusätzliche Generierung hier ist keine messbare Zusatzlast.
if ($requested_slug === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo cms_build_sitemap_xml($content_index, SITE_URL);
    exit;
}

// robots.txt: gleicher früher Ausstieg wie oben bei
// der Sitemap, aus demselben Grund (Punkt würde die Sanitize-Regex sonst
// entfernen). Inhalt kommt aus config.php, pflegbar über Einstellungen →
// robots.txt — Fallback greift nur, falls der Schlüssel dort noch fehlt
// (z.B. bestehende Installationen vor diesem Feature, config.php wird nicht
// automatisch nachgezogen).
if ($requested_slug === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $_cms_cfg['robots_txt'] ?? "User-agent: *\nAllow: /\n";
    exit;
}

// Sanitize (allow slashes for nested slugs)
$requested_slug = preg_replace('/[^a-z0-9_\/-]/i', '', $requested_slug);

// 301-Redirects: Content-Seiten haben immer
// Vorrang (Prüfung direkt darunter), der reservierte Sonderfall "404" bleibt
// unberührt — ein Redirect ersetzt nie die 404-Seite selbst. Nur wenn keine
// lebende Seite existiert, wird geprüft, ob die alte URL als Redirect-Quelle
// hinterlegt ist. Keine automatische Kettenauflösung (lib/redirects.php).
if ($requested_slug !== '404' && !isset($content_index[$requested_slug])) {
    $redirect_to = cms_redirects_resolve($requested_slug);
    if ($redirect_to !== null) {
        header('Location: ' . $redirect_to, true, 301);
        exit;
    }
}

// Look up the page in our index. "404" ist reservierter Slug: ein
// Direktaufruf von /404 landet immer hier, nie im
// else-Zweig unten — auch wenn content/404.md selbst nicht als Entwurf
// markiert ist und deshalb normal in $content_index stünde.
if ($requested_slug === '404' || !isset($content_index[$requested_slug])) {
    http_response_code(404);
    $page_title = 'Seite nicht gefunden';
    $page_description = '';
    $page_robots = 'noindex, nofollow';
    $page_content = '<p>Die angeforderte Seite existiert leider nicht.</p>';
    $page_meta = [];
    $canonical_url = '';
    $is_landingpage = false;
    $page_category_names = [];

    // Pflegbare 404-Seite: content/404.md ist eine
    // ganz normale Content-Datei, pflegbar über den bestehenden Editor.
    // Direkt geparst statt über $content_index, damit auch ein als Entwurf
    // markiertes content/404.md greift ("unabhängig vom Frontmatter-Inhalt")
    // — der Entwurfs-Filter in cms_build_content_index() würde die Datei
    // sonst rausfiltern. Existiert die Datei nicht oder lässt sie sich nicht
    // parsen, bleibt der generische Text oben stehen (keine Breaking Change).
    // page_robots bleibt bewusst hart auf "noindex, nofollow" — eine 404-Seite
    // bekommt keine abweichende Robots-Anweisung aus ihrem eigenen Frontmatter.
    $custom_404_data = cms_parse_md_file(CONTENT_DIR . '/404.md', true);
    if ($custom_404_data !== false) {
        $page_meta = $custom_404_data['meta'];
        $page_title = $page_meta['title'] ?? $page_title;
        $page_description = $page_meta['description'] ?? '';
        $page_content = markdown_to_html($custom_404_data['body'], !empty($page_meta['headerImage']));
    }
} else {
    $entry = $content_index[$requested_slug];
    $data = cms_parse_md_file($entry['file'], true);
    if ($data === false) {
        $data = ['meta' => $entry['meta'], 'body' => ''];
    }
    $page_meta = $data['meta'];
    $page_title = $page_meta['title'] ?? ucfirst($requested_slug);
    $page_description = $page_meta['description'] ?? '';
    $page_robots = $page_meta['robots'] ?? 'index, follow';
    // Landingpages: contentType: html liefert rohes HTML statt
    // Markdown — kein markdown_to_html(), Default bleibt 'markdown'.
    $is_landingpage = (($page_meta['contentType'] ?? 'markdown') === 'html');
    $page_content = $is_landingpage ? $data['body'] : markdown_to_html($data['body'], !empty($page_meta['headerImage']));
    // Shortcode [contact-form] ersetzen (kann in <p> eingeschlossen sein) —
    // funktioniert in rohem HTML genauso wie in Markdown (reiner String-Replace)
    //
    // Nur wenn der Shortcode auf DIESER Seite tatsächlich vorkommt (L9): vorher
    // rief render_contact_form() auf jeder Seite mit contact_enabled=true auf,
    // startete damit über cms_start_session() indirekt eine Session — jede
    // Seite bekam ein PHPSESSID-Cookie, nicht nur die Kontaktseite.
    if (!empty($_cms_cfg['contact_enabled']) && strpos($page_content, '[contact-form]') !== false) {
        cms_start_session();
        $form_html = render_contact_form($contact_result);
        $page_content = preg_replace('/<p>\s*\[contact-form\]\s*<\/p>/i', $form_html, $page_content);
        $page_content = str_replace('[contact-form]', $form_html, $page_content);
    }
    // Haken für Erweiterungen: eigene Shortcodes im fertigen Seiten-HTML.
    $page_content = cms_apply_filters('page_content', $page_content, $_cms_cfg, $requested_slug);
    // Shortcode [category-list ...] — gleiches Muster, deshalb auch in rohem
    // HTML einer Landingpage wirksam.
    $site_categories = cms_categories_load($_cms_cfg);
    $page_content = cms_category_shortcode_apply($page_content, $site_categories, $content_index, $requested_slug, SITE_URL);
    // Kategorien der Seite als Textzeile unter dem Inhalt, nur wenn sitewide
    // eingeschaltet. Eine Landingpage hat keinen Inhalts-Wrapper und bringt
    // ihr eigenes Layout mit, dort bleibt die Zeile weg.
    $page_category_names = (!empty($_cms_cfg['categories_show_on_page']) && !$is_landingpage)
        ? cms_category_names($page_meta['categories'] ?? '', $site_categories)
        : [];
    // Canonical: optionales Frontmatter-Override, sonst
    // wie bisher automatisch aus SITE_URL + Slug.
    $canonical_url = !empty($page_meta['canonical']) ? $page_meta['canonical'] : SITE_URL . '/' . $requested_slug;
}

// Search-Console-Verifizierungscode — sitesweiter Einstellungswert, kein
// Seiten-Frontmatter, deshalb vor dem unset() unten aus $_cms_cfg gelesen
// und für den <head> gemerkt.
$search_console_verification = trim((string)($_cms_cfg['search_console_verification'] ?? ''));

// Logo & Favicon — ebenfalls sitesweite Einstellungswerte, deshalb hier und
// nicht erst im Header-Markup gelesen. ??-Fallbacks: bestehende Installationen
// haben die vier Schlüssel erst nach manuellem Nachtrag in config/config.php
// (Fallback-Muster für später ergänzte Config-Schlüssel).
$site_logo           = trim((string)($_cms_cfg['site_logo'] ?? ''));
$site_favicon        = trim((string)($_cms_cfg['site_favicon'] ?? ''));
$site_logo_position  = (string)($_cms_cfg['site_logo_position'] ?? 'links');
$site_logo_display   = (string)($_cms_cfg['site_logo_display'] ?? 'logo_only');

unset($_cms_cfg); // nicht mehr benötigt

$navigation = get_navigation($content_index);
$footer_navigation = get_footer_navigation($content_index);
$has_header_image = !empty($page_meta['headerImage']);
$header_image = $has_header_image ? htmlspecialchars($page_meta['headerImage']) : '';

// Open Graph / Twitter Card: eigene Felder mit
// Fallback auf die vorhandenen Standard-Meta-Felder, wenn leer. og:image
// braucht eine absolute URL — anders als $header_image oben (bewusst relativ,
// siehe lib/media.php-Kommentar), deshalb hier separat aus dem rohen
// Frontmatter-Wert gebaut statt aus der schon escapten Variable.
$og_title = !empty($page_meta['ogTitle']) ? $page_meta['ogTitle'] : $page_title;
$og_description = !empty($page_meta['ogDescription']) ? $page_meta['ogDescription'] : $page_description;
$og_image_source = !empty($page_meta['ogImage']) ? $page_meta['ogImage'] : ($page_meta['headerImage'] ?? '');
$og_image_url = $og_image_source !== '' ? SITE_URL . '/' . ltrim($og_image_source, '/') : '';

$current_slug = $requested_slug;

// Helper: check if current page is within a nav item (for active state on parent)
function is_active(string $item_slug, string $current): bool {
    return $current === $item_slug || strpos($current, $item_slug . '/') === 0;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="robots" content="<?= htmlspecialchars($page_robots) ?>">
    <?php if ($canonical_url): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonical_url) ?>">
    <?php endif; ?>
    <!-- Open Graph / Twitter Card -->
    <meta property="og:type" content="website">
    <?php if ($og_title !== ''): ?>
    <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
    <?php endif; ?>
    <?php if ($og_description !== ''): ?>
    <meta property="og:description" content="<?= htmlspecialchars($og_description) ?>">
    <?php endif; ?>
    <?php if ($canonical_url): ?>
    <meta property="og:url" content="<?= htmlspecialchars($canonical_url) ?>">
    <?php endif; ?>
    <?php if ($og_image_url !== ''): ?>
    <meta property="og:image" content="<?= htmlspecialchars($og_image_url) ?>">
    <?php endif; ?>
    <!-- Twitter/X liest og:title/og:description/og:image automatisch mit,
         kein eigenes Feld-Set nötig. -->
    <meta name="twitter:card" content="summary_large_image">
    <?php if ($search_console_verification !== ''): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($search_console_verification) ?>">
    <?php endif; ?>
    <?php
    // Favicon — nur eingebunden, wenn eins hochgeladen
    // wurde (Spec 5.4), gleiches Muster wie das bedingte custom.css weiter
    // unten: kein zusätzlicher Tag/Request im Normalfall ohne Favicon.
    if ($site_favicon !== ''):
    ?>
    <link rel="icon" href="<?= SITE_URL ?>/assets/branding/<?= htmlspecialchars($site_favicon) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/style.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/core.css">
    <?php
    // Nur einbinden, wenn die Datei existiert und tatsächlich Inhalt hat
    // (Abschnitt 4.2) — kein zusätzlicher Request/404 im
    // Normalfall ohne KI-Style-Anpassungen. Reihenfolge nach style.css/core.css, damit
    // die Override-Ebene tatsächlich überschreiben kann.
    if (trim(cms_get_custom_css()) !== ''):
    ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/custom.css">
    <?php endif; ?>
    <?php
    // Seiteneigenes CSS einer Landingpage (Abschnitt 4.3) — nur auf
    // dieser einen Seite, nach style.css/custom.css, damit es überschreiben
    // kann. </style> im Wert wird entschärft, damit ein CSS-Wert den Block
    // nicht vorzeitig schließen und danach beliebiges Markup einschleusen kann.
    $page_style = trim($page_meta['pageStyle'] ?? '');
    if ($page_style !== ''):
    ?>
    <style><?= str_ireplace('</style', '<\/style', $page_style) ?></style>
    <?php endif; ?>
</head>
<body>

    <!-- Header / Navigation -->
    <?php
    // Logo-Position wirkt sich nur aus, solange überhaupt ein Logo gesetzt
    // ist (Spec 5.1/5.3) — sonst bleibt der Header exakt wie zuvor.
    $header_class = 'site-header' . ($site_logo !== '' ? ' site-header--' . $site_logo_position : '');
    ?>
    <header class="<?= htmlspecialchars($header_class) ?>">
        <?php if ($site_logo !== ''): ?>
            <!-- Mit Logo: Bild immer, Text-Titel nur im Modus "logo_und_titel"
                 (Spec 5.3) — alt-Text trägt in beiden Fällen SITE_TITLE, damit
                 Screenreader/Suchmaschinen ihn nicht verlieren. -->
            <a href="<?= SITE_URL ?>/<?= DEFAULT_SLUG ?>" class="site-branding">
                <img src="<?= SITE_URL ?>/assets/branding/<?= htmlspecialchars($site_logo) ?>" alt="<?= htmlspecialchars(SITE_TITLE) ?>" class="site-logo">
                <?php if ($site_logo_display === 'logo_und_titel'): ?>
                    <span class="site-name"><?= htmlspecialchars(SITE_TITLE) ?></span>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <!-- Ohne Logo: unverändertes Verhalten wie zuvor. -->
            <a href="<?= SITE_URL ?>/<?= DEFAULT_SLUG ?>" class="site-name"><?= htmlspecialchars(SITE_TITLE) ?></a>
        <?php endif; ?>
        <nav class="site-nav">
            <?php foreach ($navigation as $item): ?>
                <?php if (empty($item['children'])): ?>
                    <!-- Simple nav link (no children) -->
                    <a href="<?= SITE_URL ?>/<?= htmlspecialchars($item['slug']) ?>"
                       class="nav-link <?= is_active($item['slug'], $current_slug) ? 'active' : '' ?>">
                        <?= htmlspecialchars(strtoupper($item['label'])) ?>
                    </a>
                <?php else: ?>
                    <!-- Nav link with dropdown submenu -->
                    <div class="nav-dropdown">
                        <a href="<?= SITE_URL ?>/<?= htmlspecialchars($item['slug']) ?>"
                           class="nav-link <?= is_active($item['slug'], $current_slug) ? 'active' : '' ?>">
                            <?= htmlspecialchars(strtoupper($item['label'])) ?>
                            <svg class="dropdown-arrow" width="10" height="6" viewBox="0 0 10 6" fill="none">
                                <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                        <div class="nav-submenu">
                            <?php foreach ($item['children'] as $child): ?>
                                <a href="<?= SITE_URL ?>/<?= htmlspecialchars($child['slug']) ?>"
                                   class="nav-submenu-link <?= ($child['slug'] === $current_slug) ? 'active' : '' ?>">
                                    <?= htmlspecialchars($child['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <button class="nav-toggle" aria-label="Menü öffnen">
            <span></span><span></span><span></span>
        </button>
    </header>

    <!-- Main Content -->
    <?php if ($is_landingpage): ?>
        <!-- Landingpage: rohes HTML, kein page-content/content-text-Wrapper -->
        <main class="landingpage-content"><?= $page_content ?></main>
    <?php else: ?>
    <main class="page-content <?= $has_header_image ? 'with-image' : 'text-only' ?>">
        <?php if ($has_header_image): ?>
            <div class="content-image">
                <img src="<?= $header_image ?>" alt="<?= htmlspecialchars($page_meta['heading'] ?? $page_title) ?>">
            </div>
        <?php endif; ?>

        <div class="content-text">
            <?php if (!empty($page_meta['heading'])): ?>
                <h1 class="page-heading"><?= htmlspecialchars($page_meta['heading']) ?></h1>
            <?php endif; ?>
            <?= $page_content ?>
            <?php if ($page_category_names !== []): ?>
                <p class="page-categories"><?= count($page_category_names) === 1 ? 'Kategorie' : 'Kategorien' ?>: <?= e(implode(', ', $page_category_names)) ?></p>
            <?php endif; ?>
        </div>
    </main>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="site-footer">
        <?php if (!empty($footer_navigation)): ?>
        <nav class="footer-nav">
            <?php foreach ($footer_navigation as $fi): ?>
                <a href="<?= SITE_URL ?>/<?= htmlspecialchars($fi['slug']) ?>"><?= htmlspecialchars($fi['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(SITE_TITLE) ?>. Alle Rechte vorbehalten.</p>
        <p>ClaudyCMS ist ein eigenständiges Produkt und steht in keiner Verbindung zu Anthropic oder Claude.</p>
    </footer>

    <!-- Cookie-Hinweis (kein Consent-Banner: es wird nur ein technisch notwendiges
         Session-Cookie gesetzt, das nach § 25 TDDDG keine Einwilligung braucht —
         daher reiner Hinweis mit "Verstanden" statt Akzeptieren/Ablehnen, L9/L10) -->
    <div id="cookie-banner" class="cookie-banner">
        <div class="cookie-banner-inner">
            <div class="cookie-banner-text">
                Diese Website setzt beim Absenden des Kontaktformulars ein technisch notwendiges Session-Cookie.
                Weitere Informationen findest du in unserer <a href="<?= SITE_URL ?>/datenschutz">Datenschutzerklärung</a>.
            </div>
            <div class="cookie-banner-actions">
                <button class="cookie-btn cookie-btn-dismiss" id="cookie-dismiss">Verstanden</button>
            </div>
        </div>
    </div>

    <script nonce="<?= htmlspecialchars(cms_csp_nonce()) ?>">
        // Mobile Nav Toggle
        document.querySelector('.nav-toggle')?.addEventListener('click', function() {
            document.querySelector('.site-nav').classList.toggle('open');
            this.classList.toggle('open');
        });

        // Mobile: toggle submenus
        document.querySelectorAll('.nav-dropdown > .nav-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    this.parentElement.classList.toggle('open');
                }
            });
        });

        // Cookie-Hinweis: reine Kenntnisnahme, kein Opt-out — das einzige gesetzte
        // Cookie ist technisch notwendig und braucht keine Einwilligung (L9/L10).
        (function() {
            var banner = document.getElementById('cookie-banner');
            var seen = localStorage.getItem('cookie_notice_dismissed');

            if (seen === null) {
                banner.classList.add('visible');
            }

            document.getElementById('cookie-dismiss')?.addEventListener('click', function() {
                localStorage.setItem('cookie_notice_dismissed', '1');
                banner.classList.remove('visible');
            });
        })();
    </script>

</body>
</html>
