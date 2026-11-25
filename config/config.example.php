<?php
/**
 * CMS-Konfiguration — Vorlage (Beispieldatei, sicher einchecken).
 *
 * Nutzung:
 *   cp config/config.example.php config/config.php
 *   Danach alle Platzhalter durch echte Werte ersetzen
 *   (oder bequem über das Admin-Panel pflegen).
 *
 * Admin-Passwort-Hash generieren mit:
 *   php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT);"
 *
 * WICHTIG: config/config.php niemals direkt aufrufen, öffentlich zugänglich
 * machen oder mit echten Zugangsdaten einchecken.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    // ── Website ──────────────────────────────────────────────────────────────
    'site_title'          => 'Meine Website',
    'site_domain'         => '',        // z.B. 'https://www.example.com' — leer = automatisch
    'default_slug'        => 'home',   // Standard-Startseite (Slug der Startseite)

    // ── Admin-Passwort (Hash über password_hash(), PASSWORD_DEFAULT) ─────────
    // WICHTIG: Vor dem Deployment über die Admin-Einstellungen ändern!
    'admin_password_hash' => 'REPLACE_WITH_PASSWORD_HASH',

    // ── Upload ───────────────────────────────────────────────────────────────
    'max_upload_size'     => 5242880,   // Bytes (Standard: 5 MB)

    // ── Sicherheit ───────────────────────────────────────────────────────────
    'max_login_attempts'  => 5,         // Fehlversuche bis zur Sperrung
    'login_lockout_time'  => 900,       // Sperrzeit in Sekunden (Standard: 15 Minuten)

    // ── REST-API (api.php) ───────────────────────────────────────────────────
    // Bearer-Token für externe Clients (Obsidian, n8n, eigene Skripte):
    //   Authorization: Bearer <api_key>
    // Erzeugen mit: php -r "echo bin2hex(random_bytes(32));"
    // Leer = REST-API deaktiviert, jeder Aufruf endet mit HTTP 401.
    'api_key'             => '',

    // ── KI-Anbindung (MCP-Connector) ─────────────────────────────────────────
    // Seit Einführung der Benutzerverwaltung nur noch für die einmalige Migration
    // beim ersten Request relevant: cms_migrate_users() übernimmt diesen Wert
    // 1:1 als persönlichen mcp_token_hash des migrierten Admin-Accounts, damit
    // ein bereits eingerichteter Claude-Connector gültig bleibt. mcp.php prüft
    // Verbindungen danach nur noch gegen config/users.json (Tab "Benutzer" /
    // "Mein Profil"), nicht mehr gegen diesen Schlüssel. Neue Installationen
    // lassen ihn leer.
    'ai_connector_token'  => '',

    // Sollen Änderungen der KI sofort öffentlich sein?
    // false (Standard): Die KI speichert als Entwurf (draft: true). Die Seite
    //   ist bis zur Freigabe im Admin-Backend nicht öffentlich erreichbar —
    //   auch eine bereits veröffentlichte Seite nicht, sobald die KI sie ändert.
    // true: Änderungen der KI gehen direkt live.
    // Die Vorversion wird in beiden Fällen unter content/.history/ gesichert.
    'ai_publish_immediately' => false,

    // ── Kontaktformular & SMTP ────────────────────────────────────────────────
    'contact_enabled'     => false,    // Kontaktformular aktivieren (true/false)
    'contact_to_email'    => '',        // Empfänger-Adresse für Kontaktanfragen
    'smtp_host'           => '',        // z.B. 'mail.example.com'
    'smtp_port'           => 587,       // 587 (STARTTLS) oder 465 (SSL)
    'smtp_secure'         => 'tls',     // 'tls' (STARTTLS) oder 'ssl'
    'smtp_user'           => '',        // SMTP-Benutzername / E-Mail-Adresse
    'smtp_pass'           => '',        // SMTP-Passwort
    'smtp_from_email'     => '',        // Absender-E-Mail-Adresse
    'smtp_from_name'      => '',        // Absender-Name (z.B. 'Meine Website')

    // ── robots.txt ───────────────────────────────────────────────────────────
    // Wird unter /robots.txt dynamisch ausgeliefert (index.php), keine
    // statische Datei nötig. Pflegbar über Einstellungen → robots.txt.
    'robots_txt'          => "User-agent: *\nAllow: /\n",

    // ── Search-Console-Verifizierungscode ────────────────────────────────────
    // Wird als <meta name="google-site-verification"> im <head> aller Seiten
    // ausgegeben. Pflegbar über Einstellungen → SEO. Leer = kein Meta-Tag.
    'search_console_verification' => '',

    // ── Logo & Favicon ────────────────────────────────────────────────────────
    // Dateiname in assets/branding/ (lib/branding.php), leer = kein Logo bzw.
    // kein Favicon (Fallback: Text-Titel wie bisher / Browser-Standard-Icon).
    'site_logo'            => '',
    'site_favicon'         => '',
    // Logo-Position im Header: 'links' | 'mittig' | 'rechts'.
    'site_logo_position'   => 'links',
    // Anzeige-Modus: 'logo_only' (ersetzt den Text-Titel) | 'logo_und_titel'
    // (beide nebeneinander). Default beim ersten Upload: 'logo_only'.
    'site_logo_display'    => 'logo_only',
];
