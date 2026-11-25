<?php
/**
 * Gemeinsame Sicherheits-Grundlagen für alle Einstiegspunkte.
 *
 * Wird von index.php und admin.php eingebunden, bevor irgendeine Ausgabe
 * erfolgt. Enthält:
 *   - cms_start_session()          Session mit HttpOnly/Secure/SameSite
 *   - cms_send_security_headers()  CSP, HSTS, Frame-Optionen, nosniff
 *   - cms_send_preview_headers()   CSP der Landingpage-Vorschau im Editor
 *   - cms_csp_nonce()              Nonce für die Inline-<script>-Blöcke
 *   - cms_rate_limit_*()           dateibasierte Zähler pro IP
 *   - e()                          HTML-Escaping mit festen Flags
 *
 * HTTP-Zugriff auf diesen Ordner ist zusätzlich per lib/.htaccess gesperrt.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Läuft der aktuelle Request über HTTPS?
 * Berücksichtigt auch Reverse-Proxy-Setups (X-Forwarded-Proto).
 */
function cms_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Startet die Session mit gehärteten Cookie-Flags.
 *
 * HttpOnly:  kein Zugriff aus JavaScript (schützt bei XSS vor Session-Diebstahl).
 * Secure:    Cookie nur über HTTPS. Wird nur gesetzt, wenn der Request selbst
 *            über HTTPS läuft — sonst käme auf einer lokalen http-Instanz nie
 *            eine Session zustande. Den HTTPS-Zwang erledigt die .htaccess.
 * SameSite:  Lax — blockt Cross-Site-POSTs und damit CSRF auf Transportebene.
 */
function cms_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => cms_is_https(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Pro Request ein zufälliger Nonce für die erlaubten Inline-Skripte.
 * Jedes <script> im Markup braucht nonce="<?= cms_csp_nonce() ?>", sonst
 * blockt die CSP es.
 */
function cms_csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Setzt die Security-Header inklusive Content-Security-Policy.
 *
 * @param string $site_url Konfigurierte Site-URL. Wird als zusätzliche Quelle
 *                         aufgenommen, weil Stylesheet und Bilder absolut über
 *                         SITE_URL eingebunden werden — bei abweichender
 *                         Host-Schreibweise (mit/ohne www) reicht 'self' nicht.
 * @param bool   $admin    true für admin.php und install.php: strengere
 *                         Frame-Regeln, Inline-Styles nur mit Nonce.
 */
function cms_send_security_headers(string $site_url = '', bool $admin = false): void {
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: ' . ($admin ? 'DENY' : 'SAMEORIGIN'));

    // HSTS nur über HTTPS senden — über http ignorieren Browser den Header
    // ohnehin, und auf einer lokalen Instanz würde er nur stören.
    if (cms_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }

    if ($admin) {
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        header('X-Robots-Tag: noindex, nofollow');
    }

    $self = cms_csp_self($site_url);

    // style-src: Admin und Installer ohne 'unsafe-inline'. Ihr Markup hat keine
    // style-Attribute mehr, die wenigen <style>-Blöcke (Installer) tragen den
    // Nonce. Eingeschleustes CSS kann im Admin damit weder Oberfläche
    // überdecken noch Formularwerte per Attribut-Selektor nach außen tragen.
    // Das Frontend behält 'unsafe-inline': pageStyle als <style>-Block und
    // freies Landingpage-HTML mit style-Attributen sind dort gewollter Inhalt.
    // Die Landingpage-Vorschau im Editor hat deshalb eine eigene CSP
    // (cms_send_preview_headers()).
    //
    // script-src kommt überall ohne 'unsafe-inline' aus — alle Inline-Skripte
    // tragen den Nonce, Event-Handler-Attribute gibt es keine mehr.
    $style_src = $admin
        ? "style-src {$self} 'nonce-" . cms_csp_nonce() . "'"
        : "style-src {$self} 'unsafe-inline'";
    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors " . ($admin ? "'none'" : "'self'"),
        "form-action 'self'",
        "script-src {$self} 'nonce-" . cms_csp_nonce() . "'",
        $style_src,
        "img-src {$self} data: https:",
        "font-src {$self}",
        "connect-src {$self}",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/**
 * 'self' plus die konfigurierte Site-URL als CSP-Quelle. Nur ein sauberes
 * scheme://host[:port] wird übernommen, alles andere könnte die
 * Direktiven-Liste aufbrechen.
 */
function cms_csp_self(string $site_url): string {
    if ($site_url !== '' && preg_match('#^https?://[a-zA-Z0-9.\-]+(:\d+)?$#', $site_url)) {
        return "'self' " . $site_url;
    }
    return "'self'";
}

/**
 * Header für die Landingpage-Vorschau im Admin-Editor. Ersetzt die
 * Admin-CSP, die cms_send_security_headers() schon gesetzt hat (header()
 * überschreibt gleichnamige Header).
 *
 * Die Vorschau zeigt freies HTML samt pageStyle, also Inline-Styles, die der
 * Admin-Bereich selbst nicht mehr erlaubt. Ausführen darf sie nichts: kein
 * script-src, dazu sandbox. Sie läuft in einem iframe der Editor-Seite,
 * deshalb frame-ancestors 'self' statt 'none'. Bilder und Fonts wie in der
 * Admin-CSP, damit die Vorschau lädt, was sie vorher als srcdoc laden durfte.
 */
function cms_send_preview_headers(string $site_url): void {
    $self = cms_csp_self($site_url);
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'self'",
        "style-src {$self} 'unsafe-inline'",
        "img-src {$self} data: https:",
        "font-src {$self}",
        'sandbox',
    ]));
}

// ─── Dateibasierte Rate-Limits ──────────────────────────────────────────────
//
// Ablage in config/ratelimit/ statt in sys_get_temp_dir(). Auf klassischem
// Shared Hosting ist das typischerweise /tmp — zwischen den Kunden desselben
// Servers geteilt, also von fremden Prozessen les-, schreib- und löschbar,
// und viele Hoster räumen es regelmässig auf. Jeder Aufräumlauf hätte die
// Login-Sperre auf null gesetzt.
//
// config/ ist per config/.htaccess vom HTTP-Zugriff ausgeschlossen und liegt
// nicht im Git (siehe .gitignore).

/**
 * Verzeichnis für die Zählerdateien. Legt es bei Bedarf an und fällt auf das
 * Temp-Verzeichnis zurück, falls das nicht möglich ist (dann verhält sich das
 * Limit wie vorher, statt ganz auszufallen).
 */
function cms_rate_limit_dir(): string {
    $dir = dirname(__DIR__) . '/config/ratelimit';
    if (is_dir($dir)) {
        return $dir;
    }
    if (@mkdir($dir, 0700, true) || is_dir($dir)) {
        return $dir;
    }
    return sys_get_temp_dir();
}

/**
 * Pfad der Zählerdatei. Der Schlüssel (i.d.R. die IP) wird gehasht, damit er
 * nicht im Dateinamen steht.
 */
function cms_rate_limit_file(string $bucket, string $key): string {
    $bucket = preg_replace('/[^a-z0-9_\-]/i', '', $bucket);
    return cms_rate_limit_dir() . '/' . $bucket . '_' . hash('sha256', $key) . '.json';
}

/**
 * Löscht alte Zählerdateien. Nötig, seit die Dateien im Projekt liegen: in
 * /tmp hat der Hoster aufgeräumt, hier tut es sonst niemand. Läuft nur bei
 * jedem 20. Schreibzugriff, damit nicht jeder Request das Verzeichnis scannt.
 */
function cms_rate_limit_cleanup(int $max_age = 604800): void {
    if (random_int(1, 20) !== 1) {
        return;
    }
    $cutoff = time() - $max_age;
    foreach (glob(cms_rate_limit_dir() . '/*.json') ?: [] as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * Aktueller Stand eines Zählers. Ist das Zeitfenster abgelaufen, beginnt die
 * Zählung von vorn.
 *
 * @return array{count:int, reset:int}
 */
function cms_rate_limit_read(string $bucket, string $key, int $window): array {
    $file = cms_rate_limit_file($bucket, $key);
    if (is_file($file)) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && isset($data['count'], $data['reset']) && (int)$data['reset'] > time()) {
            return ['count' => (int)$data['count'], 'reset' => (int)$data['reset']];
        }
    }
    return ['count' => 0, 'reset' => time() + $window];
}

/**
 * Zählt einen Versuch und gibt den neuen Stand zurück.
 *
 * @return array{count:int, reset:int}
 */
function cms_rate_limit_hit(string $bucket, string $key, int $window): array {
    $state = cms_rate_limit_read($bucket, $key, $window);
    $state['count']++;
    @file_put_contents(cms_rate_limit_file($bucket, $key), json_encode($state), LOCK_EX);
    cms_rate_limit_cleanup();
    return $state;
}

/**
 * IP des Aufrufers. Proxy-Header werden bewusst *nicht* ausgewertet — die
 * kann ein Angreifer frei setzen und damit jedes IP-Limit umgehen.
 */
function cms_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * HTML-Escaping für Text und Attributwerte. Die Flags stehen explizit da,
 * weil PHP 8.0 per Default nur ENT_COMPAT setzt — einfache Anführungszeichen
 * blieben dann unescaped, und die Sicherheit hinge an der PHP-Version des
 * Hosters. null wird zum leeren String.
 */
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
