<?php
declare(strict_types=1);

/**
 * Erweiterungen: Zusatzfunktionen, die nur auf einer einzelnen Instanz laufen.
 *
 * Jede Erweiterung liegt in extensions/<name>/ und wird über ihre
 * bootstrap.php eingehängt. Der Ordner steht in keiner Whitelist (weder in
 * den Release-Builds noch im Update), ausgeliefert wird er nur per Deploy aus
 * dem Repo. Erweiterungen entstehen ausschließlich über das Dateisystem, nie
 * über Admin-Oberfläche oder MCP.
 *
 *   - cms_extensions_load()   bootstrap.php aller Erweiterungen laden
 *   - cms_add_filter()        Wert-verändernder Haken registrieren
 *   - cms_apply_filters()     Haken nacheinander auf einen Wert anwenden
 *   - cms_add_action()        Haken ohne Rückgabewert registrieren
 *   - cms_do_action()         alle Haken eines Namens aufrufen
 *
 * Haken im Kern (index.php):
 *   before_routing (Aktion)  Argument: Config. Vor dem Seitenaufbau, für POST-Handler.
 *   page_content   (Filter)  Wert: fertiges Seiten-HTML, Argumente: Config, Slug.
 *
 * Eine fehlerhafte Erweiterung darf die Seite nicht lahmlegen: Ausnahmen und
 * Parse-Fehler landen im Error-Log, der Kern läuft weiter.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @return array<string, array<int, callable>> Registry, als Referenz */
function &cms_hook_registry(): array {
    static $registry = [];
    return $registry;
}

function cms_add_filter(string $hook, callable $callback): void {
    $registry = &cms_hook_registry();
    $registry[$hook][] = $callback;
}

function cms_add_action(string $hook, callable $callback): void {
    cms_add_filter($hook, $callback);
}

/** Wendet alle Filter eines Namens in Registrierungsreihenfolge an. */
function cms_apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    $registry = cms_hook_registry();
    foreach ($registry[$hook] ?? [] as $callback) {
        try {
            $value = $callback($value, ...$args);
        } catch (\Throwable $e) {
            error_log('Erweiterung (' . $hook . '): ' . $e->getMessage());
        }
    }
    return $value;
}

/** Ruft alle Aktionen eines Namens auf. */
function cms_do_action(string $hook, mixed ...$args): void {
    $registry = cms_hook_registry();
    foreach ($registry[$hook] ?? [] as $callback) {
        try {
            $callback(...$args);
        } catch (\Throwable $e) {
            error_log('Erweiterung (' . $hook . '): ' . $e->getMessage());
        }
    }
}

/**
 * Lädt extensions/<name>/bootstrap.php für jedes Unterverzeichnis mit
 * unauffälligem Namen ([a-z0-9-]). Fehlt der Ordner, passiert nichts — der
 * Normalfall in Free und Pro.
 */
function cms_extensions_load(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $entries = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
    sort($entries);
    foreach ($entries as $path) {
        if (!preg_match('/^[a-z0-9-]+$/', basename($path))) {
            continue;
        }
        $bootstrap = $path . '/bootstrap.php';
        if (!is_file($bootstrap)) {
            continue;
        }
        try {
            require_once $bootstrap;
        } catch (\Throwable $e) {
            error_log('Erweiterung ' . basename($path) . ' nicht geladen: ' . $e->getMessage());
        }
    }
}
