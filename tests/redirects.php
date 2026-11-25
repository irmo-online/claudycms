<?php
/**
 * Tests für lib/redirects.php
 *
 * Aufruf:  php tests/redirects.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Gleiches Muster wie tests/roundtrip.php (lib/content.php): kein
 * Testframework im Repo, dieses Skript ist das Netz für die
 * Redirect-Bibliothek — Validierung, Lookup fürs Routing, Anlegen/Löschen.
 * Das Locking selbst (flock über die Lese-Ändern-Schreiben-Folge) wird hier
 * nicht eigens geprüft, analog lib/users.php, das ebenfalls kein eigenes
 * Nebenläufigkeits-Testskript hat — der Admin-Weg (tests/admin.php) und der
 * MCP-Weg (tests/mcp.php) decken den echten HTTP-Round-Trip ab.
 *
 * Diese Datei wird nicht deployed — siehe exclude-Liste in
 * .github/workflows/main.yml.
 */

// ─── Testumgebung ────────────────────────────────────────────────────────────

// lib/redirects.php verlangt CMS_ENTRY, damit sie nicht direkt aufrufbar ist.
define('CMS_ENTRY', true);

$repo_root = dirname(__DIR__);

// CMS_REDIRECTS_FILE zeigt auf ein Wegwerf-Verzeichnis. Die echte
// config/redirects.json wird nie angefasst.
$tmp_dir = sys_get_temp_dir() . '/cms-redirects-test-' . getmypid();
define('CMS_REDIRECTS_FILE', $tmp_dir . '/redirects.json');

require_once $repo_root . '/lib/redirects.php';

// ─── Mini-Testhelfer (identisch zu tests/roundtrip.php) ────────────────────

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

/** Räumt das Wegwerf-Verzeichnis rekursiv ab. */
function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

mkdir($tmp_dir, 0777, true);
register_shutdown_function(fn() => cleanup($tmp_dir));

// ─────────────────────────────────────────────────────────────────────────────
group('1. Leerer Zustand');
// ─────────────────────────────────────────────────────────────────────────────

is_same(cms_load_redirects(), [], 'cms_load_redirects() liefert [] ohne existierende Datei');
is_same(cms_redirects_resolve('irgendwas'), null, 'cms_redirects_resolve() liefert null ohne Treffer');

// ─────────────────────────────────────────────────────────────────────────────
group('2. Pfad-Validierung (cms_redirects_valid_path)');
// ─────────────────────────────────────────────────────────────────────────────

$invalid_from_cases = [
    'alte-seite'                    => 'ohne führenden Schrägstrich',
    ''                               => 'leerer String',
    '//evil.test/phish'              => 'protokoll-relative URL',
    'https://evil.test/phish'        => 'absolute URL mit Schema',
    '/work/../../config/config.php'  => 'Traversal-Anteil',
    '/mit spaces'                    => 'Leerzeichen',
    '/mit;semikolon'                 => 'Sonderzeichen',
];
foreach ($invalid_from_cases as $path => $label) {
    $result = cms_create_redirect($path, '/ziel', [], 'test');
    is_same($result['ok'], false, "create_redirect lehnt ungültige alte URL ab: {$label}");
    is_same($result['code'] ?? null, 'invalid_from', "Grund ist invalid_from: {$label}");
}

foreach ($invalid_from_cases as $path => $label) {
    if ($path === '') continue; // 'to' leer wäre dieselbe Fehlerursache wie oben, nicht aussagekräftig hier
    $result = cms_create_redirect('/quelle', $path, [], 'test');
    is_same($result['ok'], false, "create_redirect lehnt ungültige neue URL ab: {$label}");
    is_same($result['code'] ?? null, 'invalid_to', "Grund ist invalid_to: {$label}");
}

$same_result = cms_create_redirect('/gleich', '/gleich', [], 'test');
is_same($same_result['ok'], false, 'create_redirect lehnt identische alte/neue URL ab');
is_same($same_result['code'] ?? null, 'same_path', 'Grund ist same_path');

// ─────────────────────────────────────────────────────────────────────────────
group('3. Kollision mit bestehender Content-Seite');
// ─────────────────────────────────────────────────────────────────────────────

$shadow_result = cms_create_redirect('/kontakt', '/neu', ['kontakt', 'home'], 'test');
is_same($shadow_result['ok'], false, 'create_redirect lehnt eine noch existierende Seite als Quelle ab');
is_same($shadow_result['code'] ?? null, 'shadows_page', 'Grund ist shadows_page');

// Verschachtelter Slug (führender "/" wird für den Vergleich entfernt).
$shadow_nested = cms_create_redirect('/work/projekt-alpha', '/work/neu', ['work/projekt-alpha'], 'test');
is_same($shadow_nested['ok'], false, 'Kollisionsprüfung funktioniert auch für verschachtelte Slugs');

// Zielseite (nicht Quelle) darf weiterhin existieren — nur "from" wird geprüft.
$to_may_exist = cms_create_redirect('/alt', '/kontakt', ['kontakt'], 'test');
ok($to_may_exist['ok'], 'create_redirect erlaubt eine existierende Seite als Ziel');

// ─────────────────────────────────────────────────────────────────────────────
group('4. Anlegen, Auflösen, Duplikate');
// ─────────────────────────────────────────────────────────────────────────────

$created = cms_create_redirect('/alte-seite', '/neue-seite', ['home'], 'admin@example.test');
ok($created['ok'], 'create_redirect legt einen gültigen Redirect an');
ok(str_starts_with($created['redirect']['id'] ?? '', 'rdr_'), 'id trägt das rdr_-Präfix');
is_same($created['redirect']['from'], '/alte-seite', 'from wird unverändert gespeichert');
is_same($created['redirect']['to'], '/neue-seite', 'to wird unverändert gespeichert');
is_same($created['redirect']['created_by'], 'admin@example.test', 'created_by wird übernommen');
ok($created['warning'] === null, 'keine Kettenwarnung, wenn "to" keine bestehende Quelle ist');
ok((bool)strtotime($created['redirect']['created_at']), 'created_at ist ein gültiger Zeitstempel');

is_same(count(cms_load_redirects()), 2, 'cms_load_redirects() zeigt beide bisher angelegten Redirects');

is_same(cms_redirects_resolve('alte-seite'), '/neue-seite', 'cms_redirects_resolve() findet den Treffer (Slug ohne führenden "/")');
is_same(cms_redirects_resolve('/alte-seite'), '/neue-seite', 'cms_redirects_resolve() funktioniert auch mit führendem "/" im Argument');
is_same(cms_redirects_resolve('unbekannt'), null, 'cms_redirects_resolve() liefert null für einen unbekannten Slug');

$duplicate = cms_create_redirect('/alte-seite', '/noch-eine-seite', [], 'test');
is_same($duplicate['ok'], false, 'zweiter Redirect mit derselben Quelle wird abgelehnt');
is_same($duplicate['code'] ?? null, 'from_exists', 'Grund ist from_exists');
is_same(cms_redirects_resolve('alte-seite'), '/neue-seite', 'die ursprüngliche Zuordnung bleibt nach dem abgelehnten Duplikat unverändert');

// ─────────────────────────────────────────────────────────────────────────────
group('5. Kettenwarnung (nicht blockierend)');
// ─────────────────────────────────────────────────────────────────────────────

// "to" zeigt auf eine bereits bestehende Redirect-Quelle ("/alte-seite") —
// das Anlegen gelingt trotzdem, es gibt nur einen Hinweis (Spec: keine
// automatische Kettenauflösung, Kettenbildung ist Admin-Verantwortung).
$chain = cms_create_redirect('/noch-eine-quelle', '/alte-seite', [], 'test');
ok($chain['ok'], 'ein Redirect auf eine bestehende Redirect-Quelle wird trotzdem angelegt');
ok($chain['warning'] !== null, 'aber mit einer Kettenwarnung');
is_same(cms_redirects_resolve('noch-eine-quelle'), '/alte-seite', 'aufgelöst wird nur einmal, keine automatische Weiterverfolgung der Kette');

// ─────────────────────────────────────────────────────────────────────────────
group('6. Löschen');
// ─────────────────────────────────────────────────────────────────────────────

$before_delete = count(cms_load_redirects());
$delete_result = cms_delete_redirect($created['redirect']['id']);
ok($delete_result['ok'], 'delete_redirect löscht einen bestehenden Redirect');
is_same(count(cms_load_redirects()), $before_delete - 1, 'ein Eintrag weniger nach dem Löschen');
is_same(cms_redirects_resolve('alte-seite'), null, 'die alte URL liefert nach dem Löschen keinen Treffer mehr');

$second_delete = cms_delete_redirect($created['redirect']['id']);
is_same($second_delete['ok'], false, 'ein zweiter Löschversuch auf dieselbe id schlägt fehl');
is_same($second_delete['code'] ?? null, 'not_found', 'Grund ist not_found');

$unknown_delete = cms_delete_redirect('rdr_unbekannt');
is_same($unknown_delete['ok'], false, 'Löschen einer unbekannten id schlägt fehl');
is_same($unknown_delete['code'] ?? null, 'not_found', 'Grund ist not_found');

// Nach dem Löschen darf dieselbe Quelle erneut vergeben werden — kein
// Geister-Duplikat-Fehler, weil der alte Eintrag wirklich weg ist.
$recreate = cms_create_redirect('/alte-seite', '/wirklich-neu', [], 'test');
ok($recreate['ok'], 'nach dem Löschen lässt sich dieselbe Quelle erneut vergeben');

// ─── Ergebnis ────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 60) . "\n";
if ($tests_failed === 0) {
    echo "Alle {$tests_run} Prüfungen grün.\n";
    exit(0);
}
echo "{$tests_failed} von {$tests_run} Prüfungen fehlgeschlagen.\n";
exit(1);
