<?php
/**
 * Schreiben von config/config.php.
 *
 * Vorher lebte save_config() nur in admin.php. Die Installationsroutine
 * (install.php) hat sie hierher verschoben, weil der Installer sie für die
 * Formular-Verarbeitung ebenso braucht wie admin.php für die
 * bestehenden Einstellungen — ein Duplikat wäre der zweite Ort, an dem das
 * Schreibformat (var_export() + CMS_ENTRY-Guard) gepflegt werden müsste.
 *
 * load_config() bleibt bewusst in admin.php: Sie bricht mit
 * http_response_code(503) und einer festen Fehlerseite ab, wenn die Datei
 * fehlt — für install.php wäre genau das falsch, dort ist das Fehlen der
 * Datei vor der Installation der Normalfall (Self-Lock, Spec 5.1).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Prozess-Cache für die geladene Konfiguration.
 *
 * Liegt hier und nicht als static in load_config() (admin.php), weil ein
 * static dort von save_config() aus nicht erreichbar wäre — und genau das
 * braucht es: nach jedem Schreiben muss der Cache weg, sonst zeigt die
 * Einstellungsseite direkt nach dem Speichern noch den alten Stand. Die
 * Handler laden nach save_config() absichtlich frisch nach.
 *
 * @param array|null $set   Wert ablegen; null heißt "nur lesen"
 * @param bool       $clear Cache verwerfen (hat Vorrang vor $set)
 * @return array|null       Der gecachte Wert, oder null wenn nichts gecacht ist
 */
function cms_config_cache(?array $set = null, bool $clear = false): ?array {
    static $cache = null;
    if ($clear) {
        $cache = null;
    } elseif ($set !== null) {
        $cache = $set;
    }
    return $cache;
}

/** Verwirft den Config-Cache. Nach jedem Schreibzugriff auf CONFIG_FILE aufrufen. */
function cms_config_cache_clear(): void {
    cms_config_cache(null, true);
}

// ─── Defaults ───────────────────────────────────────────────────────────────

/**
 * Werte für Schlüssel, die admin.php und index.php ohne Fallback lesen.
 *
 * install.php schreibt alle davon. Fehlen kann ein Schlüssel trotzdem: nach
 * einem Update, dessen Config-Migration nicht gelaufen ist, oder bei einer
 * von Hand gepflegten config.php. Ohne Default wird daraus mehr als eine
 * Warning — max_login_attempts als (int)null = 0 sperrt jeden Login, und
 * site_domain als null gilt in `SITE_DOMAIN !== ''` als gesetzt.
 *
 * Werte wie in config/config.example.php. site_title bewusst leer statt
 * "Meine Website": die Mail-Texte in lib/admin/ haben für '' schon einen
 * eigenen Fallback, und im Frontend ist ein leerer Name unauffälliger als
 * ein Platzhalter.
 */
const CMS_CONFIG_DEFAULTS = [
    'site_title'         => '',
    'site_domain'        => '',
    'default_slug'       => 'home',
    'max_upload_size'    => 5242880,
    'max_login_attempts' => 5,
    'login_lockout_time' => 900,
    'categories'         => [],
    'categories_show_on_page' => false,
];

/**
 * Legt CMS_CONFIG_DEFAULTS unter die gelesene Konfiguration. Gespeicherte
 * Werte gewinnen immer, auch leere. Schreibt nichts zurück — speichert ein
 * Handler danach über save_config(), landen fehlende Defaults allerdings mit
 * in der Datei, und die repariert sich damit von selbst.
 */
function cms_config_with_defaults(array $cfg): array {
    return $cfg + CMS_CONFIG_DEFAULTS;
}

/**
 * Baut den PHP-Quelltext für config/config.php. Ausgelagert aus save_config(),
 * damit der Double-Checked-Locking-Schreibweg in install.php
 * dasselbe Format über einen eigenen, gesperrten Dateihandle schreiben
 * kann, ohne dafür save_config() selbst aufzurufen — die würde mit einem
 * zweiten fopen()+flock() auf dieselbe Datei im selben Prozess blockieren
 * (flock() ist pro offenem Handle, nicht pro Prozess: zwei Handles derselben
 * Datei im selben Prozess sperren sich gegenseitig aus, echter Deadlock).
 */
function cms_render_config_php(array $config): string {
    $php  = "<?php\n";
    $php .= "/**\n * CMS-Konfiguration — verwaltet vom Admin-Panel.\n";
    $php .= " * HTTP-Zugriff ist durch config/.htaccess gesperrt.\n */\n";
    $php .= "if (!defined('CMS_ENTRY')) { http_response_code(403); exit('Forbidden'); }\n\n";
    $php .= "return " . var_export($config, true) . ";\n";
    return $php;
}

/**
 * Schreibt die Konfiguration sicher als PHP-Datei nach CONFIG_FILE.
 * Nur interne Aufrufe — der Pfad kommt aus einer Konstante, nie vom Nutzer.
 */
function save_config(array $config): bool {
    // LOCK_EX verhindert Race-Conditions beim gleichzeitigen Schreiben
    $ok = file_put_contents(CONFIG_FILE, cms_render_config_php($config), LOCK_EX) !== false;
    // Auch im Fehlerfall verwerfen: file_put_contents() kann teilweise
    // geschrieben haben, der Cache wäre dann weder der alte noch der neue Stand.
    cms_config_cache_clear();
    return $ok;
}

/**
 * Double-Checked-Locking-Schreibweg für install.php, analog
 * cms_migrate_users() in lib/users.php (Zeile ~493): ein einziger gesperrter
 * Dateihandle über Prüfung UND Schreiben hinweg. install.php prüft den
 * Self-Lock bereits ganz oben, bevor Formular/Validierung
 * überhaupt laufen — zwischen dieser ersten Prüfung und dem tatsächlichen
 * Schreiben hier kann aber ein paralleler zweiter Request dieselbe Lücke
 * genutzt haben. 'c+' legt die Datei notfalls leer an, ohne einen
 * bestehenden Inhalt zu verwerfen — genau wie cms_users_open_locked().
 *
 * @return string 'ok' | 'collision' (ein anderer Request war schneller) | 'error'
 */
function cms_install_write_config(array $config): string {
    $handle = @fopen(CONFIG_FILE, 'c+');
    if ($handle === false) {
        return 'error';
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return 'error';
    }

    rewind($handle);
    $existing = stream_get_contents($handle);
    if (trim((string)$existing) !== '') {
        flock($handle, LOCK_UN);
        fclose($handle);
        return 'collision';
    }

    rewind($handle);
    $ok = ftruncate($handle, 0) && fwrite($handle, cms_render_config_php($config)) !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    cms_config_cache_clear();
    return $ok ? 'ok' : 'error';
}

// ─── Installations-Kennung ──────────────────────────────────────────────────

/**
 * Domain der Installation, ohne Schema und abschließenden Slash — für
 * serverInfo.name (mcp.php) und das 'api'-Feld (api.php), die als einfacher
 * Bezeichner-String gebraucht werden, keine URL (MCP-Konvention, Vorbilder
 * anderer Server: "filesystem", "github").
 *
 * Liest site_domain aus der übergebenen Konfiguration (z.B. mcp_config()),
 * entfernt Schema (https://, http://) und einen abschließenden Slash. Ist
 * site_domain leer, Fallback auf den (gegen Host-Header-Injection
 * bereinigten) HTTP_HOST — exakt dieselbe Fallback-Logik wie das bisherige
 * mcp_site_url() in mcp.php, nur ohne Schema-Präfix.
 */
function cms_site_domain(array $config): string {
    $domain = (string) ($config['site_domain'] ?? '');
    if ($domain !== '') {
        return rtrim(preg_replace('#^https?://#i', '', $domain), '/');
    }
    return preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
}
