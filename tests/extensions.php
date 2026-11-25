<?php
/**
 * Tests für lib/extensions.php (Haken und Laden von extensions/*)
 *
 * Aufruf:  php tests/extensions.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Diese Datei wird nicht deployed — siehe exclude-Liste in
 * .github/workflows/main.yml.
 */

define('CMS_ENTRY', true);

$repo_root = dirname(__DIR__);
$tmp_dir   = sys_get_temp_dir() . '/cms-extensions-test-' . getmypid();

require_once $repo_root . '/lib/extensions.php';

// ─── Mini-Testhelfer (identisch zu tests/redirects.php) ────────────────────

$tests_run    = 0;
$tests_failed = 0;

function group(string $name): void {
    echo "\n" . $name . "\n";
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
        echo "        erhalten: " . var_export($actual, true) . "\n";
    }
}

function put(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

// Fehler aus kaputten Erweiterungen gehen ins Error-Log — hier abfangen.
$log_file = $tmp_dir . '.log';
ini_set('error_log', $log_file);
@unlink($log_file);

// ─── Haken ──────────────────────────────────────────────────────────────────

group('Filter');
is_same(cms_apply_filters('t_leer', 'x'), 'x', 'ohne Haken bleibt der Wert unverändert');
cms_add_filter('t_filter', fn(string $v, string $arg) => $v . '1' . $arg);
cms_add_filter('t_filter', fn(string $v, string $arg) => $v . '2');
is_same(cms_apply_filters('t_filter', 'a', 'B'), 'a1B2', 'Haken laufen nacheinander, Argumente kommen an');
cms_add_filter('t_fehler', fn(string $v) => throw new RuntimeException('kaputt'));
cms_add_filter('t_fehler', fn(string $v) => $v . '!');
is_same(cms_apply_filters('t_fehler', 'a'), 'a!', 'ein Haken mit Ausnahme wird übersprungen, die übrigen laufen');
ok_log('kaputt', 'Ausnahme steht im Error-Log');

group('Aktionen');
$calls = [];
cms_add_action('t_aktion', function (string $a) use (&$calls) { $calls[] = "1:$a"; });
cms_add_action('t_aktion', function (string $a) use (&$calls) { $calls[] = "2:$a"; });
cms_do_action('t_aktion', 'x');
is_same($calls, ['1:x', '2:x'], 'alle Aktionen laufen in Reihenfolge');
cms_do_action('t_unbekannt');
is_same(count($calls), 2, 'unbekannter Name tut nichts');

// ─── Laden ──────────────────────────────────────────────────────────────────

group('Erweiterungen laden');
cms_extensions_load($tmp_dir . '/gibt-es-nicht');
is_same(true, true, 'fehlender Ordner ist kein Fehler');

put($tmp_dir . '/ext/b-zweite/bootstrap.php', "<?php\n\$GLOBALS['t_order'][] = 'b';\n");
put($tmp_dir . '/ext/a-erste/bootstrap.php', "<?php\n\$GLOBALS['t_order'][] = 'a';\n");
put($tmp_dir . '/ext/Gross/bootstrap.php', "<?php\n\$GLOBALS['t_order'][] = 'gross';\n");
put($tmp_dir . '/ext/ohne-bootstrap/readme.txt', 'x');
put($tmp_dir . '/ext/c-parsefehler/bootstrap.php', "<?php\nthis is not php;\n");
put($tmp_dir . '/ext/d-wirft/bootstrap.php', "<?php\nthrow new RuntimeException('beim Laden');\n");
put($tmp_dir . '/ext/e-nach-fehler/bootstrap.php', "<?php\n\$GLOBALS['t_order'][] = 'e';\n");
$GLOBALS['t_order'] = [];
cms_extensions_load($tmp_dir . '/ext');
is_same($GLOBALS['t_order'], ['a', 'b', 'e'], 'alphabetisch geladen, ungültige Namen und fehlende bootstrap.php übersprungen, Fehler stoppen den Rest nicht');
ok_log('c-parsefehler', 'Parse-Fehler wird mit Namen geloggt');
ok_log('beim Laden', 'Ausnahme beim Laden wird geloggt');

function ok_log(string $needle, string $what): void {
    global $log_file;
    is_same(strpos((string) @file_get_contents($log_file), $needle) !== false, true, $what);
}

rrmdir($tmp_dir);
@unlink($log_file);

echo "\n" . ($tests_run - $tests_failed) . " von {$tests_run} Prüfungen bestanden.\n";
exit($tests_failed === 0 ? 0 : 1);
