<?php
declare(strict_types=1);

/**
 * POST-Handler für "Website & SEO", "Einstellungen" und die Schalter im Tab
 * "KI-Anbindung" (Live/Entwurf, Undo).
 *
 * Jeder Handler gibt ein HandlerResult zurück, admin.php ruft ihn über die
 * Handler-Map auf (admin_post_handlers()).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Website-/SEO-/Einstellungen speichern (fünf eigene Handler statt
// einem gemeinsamen Formular) ─────────────────────────────────────────────
// Früher ein einziger save_settings-Handler für Website+SEO+Upload+
// Sicherheit+Passwort+Kontakt/SMTP in einem Formular. Seit die Anzeige auf
// "Website & SEO" (zwei Reiter) und "Einstellungen" aufgeteilt ist, braucht
// jeder Abschnitt sein eigenes <form> — ein gemeinsamer Handler würde beim
// Speichern eines Reiters die $_POST-Felder der anderen Reiter vermissen und
// deren Werte mit den Hartcode-Defaults überschreiben (z.B. "Sicherheit"
// speichern hätte sonst site_title auf "Meine Website" zurückgesetzt). Jeder
// Handler ändert deshalb über cfg_update() nur seine eigenen Schlüssel einer
// frisch geladenen Config — save_config() erhält Fremdschlüssel (siehe dessen
// Kommentar), das trägt genau diesen Fall.
// guard() prüft die Rolle vor dem Token: Der GET-Gate in admin.php
// deckt nur die Anzeige ab, jeder POST-Handler bräuchte ohne eigenen Check
// nicht einmal die passende action in der URL.

function handle_save_website_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array|string {
        $website_error = '';

        $site_title = trim(post_str('site_title'));
        $cfg['site_title'] = $site_title !== '' ? $site_title : 'Meine Website';

        $site_domain = rtrim(trim(post_str('site_domain')), '/');
        if ($site_domain !== '' && !preg_match('/^https?:\/\/[a-zA-Z0-9.\-]+(:\d+)?$/', $site_domain)) {
            $website_error = 'Ungültige Domain. Erlaubtes Format: https://www.example.com';
        } else {
            $cfg['site_domain'] = $site_domain;
        }

        $default_slug = preg_replace('/[^a-z0-9_\/-]/i', '', trim(post_str('default_slug', 'about')));
        $cfg['default_slug'] = $default_slug !== '' ? $default_slug : 'about';

        return $website_error !== '' ? $website_error : $cfg;
    });
    return HandlerResult::fromError($error, 'Website-Einstellungen gespeichert.', 'site');
}

function handle_save_seo_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array|string {
        $seo_error = '';

        // Search-Console-Verifizierungscode — wird als
        // <meta name="google-site-verification"> im <head> aller Seiten
        // ausgegeben (index.php). Zeichen-Whitelist statt freiem Text wie bei
        // robots_txt: der Wert landet in einem HTML-Attribut, nicht in einer
        // eigenständig ausgelieferten Datei.
        // Komfort: Google zeigt bei der "HTML-Tag"-Methode den kompletten
        // <meta>-Tag zum Kopieren an, nicht nur den Code selbst — naheliegend,
        // versehentlich den ganzen Tag einzufügen. Wird das erkannt, wird nur
        // der content-Wert übernommen.
        $search_console = trim(post_str('search_console_verification'));
        if ($search_console !== '' && preg_match('/content=["\']([^"\']+)["\']/i', $search_console, $sc_match)) {
            $search_console = trim($sc_match[1]);
        }
        if ($search_console !== '' && !preg_match('/^[A-Za-z0-9_\-.=]{1,255}$/', $search_console)) {
            $seo_error = 'Ungültiger Search-Console-Verifizierungscode. Erlaubt sind Buchstaben, Ziffern sowie - _ . =';
        } else {
            $cfg['search_console_verification'] = $search_console;
        }

        // robots.txt — freier Text, wird 1:1 unter
        // /robots.txt ausgeliefert. CRLF normalisieren (Browser-Textareas
        // schicken oft \r\n), sonst nur trimmen: kein Zeichen-Whitelist nötig,
        // die Datei wird als text/plain ausgeliefert, keine Interpretation.
        $robots_txt = trim(str_replace("\r\n", "\n", post_str('robots_txt')));
        if (mb_strlen($robots_txt) > 5000) {
            $seo_error = 'robots.txt darf höchstens 5000 Zeichen lang sein.';
        } else {
            $cfg['robots_txt'] = $robots_txt;
        }

        return $seo_error !== '' ? $seo_error : $cfg;
    });
    return HandlerResult::fromError($error, 'SEO-Einstellungen gespeichert.', 'site');
}

function handle_save_upload_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array {
        $cfg['max_upload_size'] = max(1, min(50, post_int('max_upload_mb', 5))) * 1024 * 1024;
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Upload-Einstellungen gespeichert.', 'settings');
}

function handle_save_security_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array {
        $cfg['max_login_attempts'] = max(3, min(20, post_int('max_login_attempts', 5)));
        $cfg['login_lockout_time'] = max(60, min(86400, post_int('login_lockout_minutes', 15) * 60));
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Sicherheits-Einstellungen gespeichert.', 'settings');
}

function handle_save_contact_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array|string {
        $contact_error = '';

        $cfg['contact_enabled']  = post_bool('contact_enabled');
        $contact_to = trim(post_str('contact_to_email'));
        if ($contact_to !== '' && !filter_var($contact_to, FILTER_VALIDATE_EMAIL)) {
            $contact_error = 'Ungültige Empfänger-E-Mail-Adresse.';
        } else {
            $cfg['contact_to_email'] = $contact_to;
        }
        $smtp_from = trim(post_str('smtp_from_email'));
        if ($smtp_from !== '' && !filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) {
            $contact_error = 'Ungültige Absender-E-Mail-Adresse.';
        } else {
            $cfg['smtp_from_email'] = $smtp_from;
        }
        $cfg['smtp_from_name'] = mb_substr(trim(post_str('smtp_from_name')), 0, 100);
        $cfg['smtp_host']      = mb_substr(trim(post_str('smtp_host')), 0, 253);
        $cfg['smtp_user']      = mb_substr(trim(post_str('smtp_user')), 0, 200);
        $smtp_port   = post_int('smtp_port', 587);
        $smtp_secure = SmtpSecurity::tryFrom(post_str('smtp_secure', 'tls')) ?? SmtpSecurity::Tls;
        $cfg['smtp_port']      = in_array($smtp_port, [25, 465, 587], true) ? $smtp_port : 587;
        $cfg['smtp_secure']    = $smtp_secure->value;
        // Passwort nur überschreiben wenn neu eingegeben (leeres Feld = unveränderter Wert)
        $smtp_pw = post_str('smtp_pass');
        if ($smtp_pw !== '') {
            $cfg['smtp_pass'] = $smtp_pw;
        }

        return $contact_error !== '' ? $contact_error : $cfg;
    });
    return HandlerResult::fromError($error, 'Kontaktformular- & E-Mail-Einstellungen gespeichert.', 'settings');
}

// ─── KI-Anbindung: Toggle, Undo ─────────────────────────────────────────────
//
// Der frühere Handler regenerate_ai_token (globaler ai_connector_token in
// config.php) ist mit der Benutzerverwaltung entfallen: mcp.php prüft seit
// dem Umbau auf users.json nur noch personengebundene Tokens (Tab
// "Benutzer" für andere Nutzer, "Mein Profil" für den eigenen —
// regenerate_own_token/regenerate_user_token in handlers-users.php). Ein
// Klick hier hätte nur noch einen Wert geändert, den mcp.php gar nicht mehr
// liest.

// Live vs. Entwurf umschalten. Wirkt sich erst auf den
// nächsten KI-Schreibzugriff aus, nicht rückwirkend auf bestehende Entwürfe.
function handle_save_ai_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array {
        $cfg['ai_publish_immediately'] = post_bool('ai_publish_immediately');
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Einstellung gespeichert.', 'ai');
}

// Undo: letzte gesicherte Vorversion einer Seite wiederherstellen.
// cms_history_restore() sichert den überschriebenen
// Stand selbst, bevor es zurücksetzt — bleibt also seinerseits umkehrbar.
function handle_restore_history(): HandlerResult {
    $error = guard('settings');
    if ($error !== null) {
        return HandlerResult::failure($error, 'ai');
    }
    $result = cms_history_restore(post_str('restore_slug'));
    return $result['ok']
        ? HandlerResult::success('Vorversion wiederhergestellt (Stand ' . $result['stamp'] . ').', 'ai')
        : HandlerResult::failure($result['error'] ?? 'Fehler beim Wiederherstellen.', 'ai');
}
