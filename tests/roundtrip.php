<?php
/**
 * Round-Trip-Tests für lib/content.php
 *
 * Aufruf:  php tests/roundtrip.php
 * Rückgabe: Exit-Code 0 = alles grün, 1 = mindestens ein Fehlschlag.
 *
 * Warum es diese Datei gibt: das Repo hat kein Testframework. Bevor die
 * Frontmatter-Logik aus index.php und admin.php in lib/content.php
 * zusammengeführt wurde, gab es kein Netz, das eine Regression gefangen
 * hätte. Diese Datei ist dieses Netz — sie prüft die Eigenschaften, auf die
 * sich Frontend, Admin, api.php und mcp.php verlassen.
 *
 * Nicht abgedeckt: gerendertes HTML von index.php/admin.php. Dafür ist der
 * Instanz-Diff aus "Übergabe Security-Fixes.md" da (zwei Stände parallel
 * per php -S starten und die Ausgabe vergleichen).
 *
 * Der Ordner tests/ gehört nur zum Repository und muss nicht auf den
 * Webserver hochgeladen werden.
 */

// ─── Testumgebung ────────────────────────────────────────────────────────────

// lib/content.php verlangt CMS_ENTRY, damit sie nicht direkt aufrufbar ist.
define('CMS_ENTRY', true);

$repo_root   = dirname(__DIR__);
$real_content = $repo_root . '/content';

// CONTENT_DIR zeigt für die Pfad- und CRUD-Tests auf ein Wegwerf-Verzeichnis.
// Die echten Inhalte werden nur gelesen, nie geschrieben.
$tmp_content = sys_get_temp_dir() . '/cms-roundtrip-' . getmypid();
define('CONTENT_DIR', $tmp_content);

require_once $repo_root . '/lib/content.php';

// ─── Mini-Testhelfer ─────────────────────────────────────────────────────────

$tests_run    = 0;
$tests_failed = 0;
$current_group = '';

function group(string $name): void {
    global $current_group;
    $current_group = $name;
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

/** Legt eine Datei im Wegwerf-Verzeichnis an, Ordner werden mit erstellt. */
function fixture(string $rel, string $contents): string {
    $full = CONTENT_DIR . '/' . $rel;
    $dir  = dirname($full);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($full, $contents);
    return $full;
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

mkdir($tmp_content, 0777, true);
register_shutdown_function(fn() => cleanup($tmp_content));

// ─────────────────────────────────────────────────────────────────────────────
group('1. Round-Trip über die echten Inhalte in content/');
// ─────────────────────────────────────────────────────────────────────────────

// Die Eigenschaft, auf die es ankommt: einmal gelesen und wieder geschrieben,
// darf sich beim nächsten Lesen nichts mehr ändern. Byte-Gleichheit zur
// Ursprungsdatei ist ausdrücklich NICHT gefordert — build_frontmatter()
// normalisiert (setzt z.B. numerische Werte in Anführungszeichen).

$real_files = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($real_content, FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
        $real_files[] = $f->getPathname();
    }
}
sort($real_files);

ok(count($real_files) > 0, 'content/ enthält Markdown-Dateien (' . count($real_files) . ' gefunden)');

foreach ($real_files as $path) {
    $label = substr($path, strlen($real_content) + 1);

    foreach ([false, true] as $cast) {
        $mode = $cast ? 'cast' : 'string';

        $first  = cms_parse_md_file($path, $cast);
        $ser1   = cms_serialize_md_file($first['meta'], $first['body']);
        $second = cms_parse_frontmatter($ser1, $cast);
        $ser2   = cms_serialize_md_file($second['meta'], $second['body']);

        is_same($second['meta'], $first['meta'], "{$label} [{$mode}]: Metadaten stabil");
        is_same($second['body'], $first['body'], "{$label} [{$mode}]: Body stabil");
        is_same($ser2, $ser1, "{$label} [{$mode}]: Serialisierung idempotent");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
group('2. Cast-Verhalten der beiden Aufrufer (Fund U8)');
// ─────────────────────────────────────────────────────────────────────────────

// index.php braucht int/bool (Navigation), admin.php braucht Strings
// (Formularfelder). Ein gemeinsamer Parser muss beides bedienen können.

$raw = "---\ntitle: \"Test\"\norder: 7\nshowInNav: true\nshowInFooter: false\nrobots: \"index, follow\"\n---\n\nText";

$as_string = cms_parse_frontmatter($raw, false);
is_same($as_string['meta']['order'],        '7',     'String-Modus: order bleibt String');
is_same($as_string['meta']['showInNav'],    'true',  'String-Modus: showInNav bleibt String');
is_same($as_string['meta']['showInFooter'], 'false', 'String-Modus: showInFooter bleibt String');

$as_cast = cms_parse_frontmatter($raw, true);
is_same($as_cast['meta']['order'],        7,     'Cast-Modus: order wird int');
is_same($as_cast['meta']['showInNav'],    true,  'Cast-Modus: showInNav wird bool true');
is_same($as_cast['meta']['showInFooter'], false, 'Cast-Modus: showInFooter wird bool false');
is_same($as_cast['meta']['robots'], 'index, follow', 'Cast-Modus: Textwerte bleiben unangetastet');

// Der Stolperstein, der die Zusammenführung gefährlich macht: (string)false
// ist "" — ein naiver Serializer würde showInFooter beim Speichern verlieren.
$reser = cms_serialize_md_file($as_cast['meta'], $as_cast['body']);
$back  = cms_parse_frontmatter($reser, true);
ok(array_key_exists('showInFooter', $back['meta']), 'bool false überlebt die Serialisierung (Schlüssel bleibt erhalten)');
is_same($back['meta']['showInFooter'], false, 'bool false kommt als false zurück, nicht als leerer String');
is_same($back['meta']['showInNav'],    true,  'bool true kommt als true zurück, nicht als 1');

// ─────────────────────────────────────────────────────────────────────────────
group('3. Escaping und Injection im Frontmatter (Fund M2)');
// ─────────────────────────────────────────────────────────────────────────────

$tricky = [
    'anfuehrungszeichen' => 'Sag "Hallo"',
    'backslash'          => 'C:\\Pfad\\zur\\Datei',
    'doppelpunkt'        => 'Titel: mit Doppelpunkt',
    'beides'             => 'Er sagte: "Pfad C:\\tmp"',
    'raute'              => 'Preis #1',
    'klammern'           => 'Liste [a, b] und {c}',
    'apostroph'          => "Anna's Seite",
    'nur_zahl'           => '2026',
];

foreach ($tricky as $key => $value) {
    $ser    = cms_serialize_md_file([$key => $value], 'Body');
    $parsed = cms_parse_frontmatter($ser, false);
    is_same($parsed['meta'][$key] ?? null, $value, "Sonderzeichen überleben: {$key}");
    is_same($parsed['body'], 'Body', "Body bleibt intakt bei: {$key}");
}

// Zeilenumbrüche werden zu Leerzeichen normalisiert — dokumentiertes Verhalten
// von build_frontmatter(). Entscheidend ist, dass daraus kein zweiter
// Frontmatter-Schlüssel wird.
$injected = cms_serialize_md_file(['title' => "Titel\nrobots: noindex, nofollow"], 'Body');
$parsed   = cms_parse_frontmatter($injected, false);
ok(!isset($parsed['meta']['robots']), 'Zeilenumbruch im Wert schiebt keinen robots-Schlüssel unter');
is_same($parsed['meta']['title'], 'Titel robots: noindex, nofollow', 'Zeilenumbruch wird zum Leerzeichen');

// Ein eingebettetes --- darf den Frontmatter-Block nicht vorzeitig schliessen.
$injected = cms_serialize_md_file(['title' => "Ende\n---\nSchadcode"], 'Echter Body');
$parsed   = cms_parse_frontmatter($injected, false);
is_same($parsed['body'], 'Echter Body', 'Eingebettetes --- schliesst den Block nicht vorzeitig');
ok(!isset($parsed['meta']['Schadcode']), 'Eingebettetes --- erzeugt keinen Extra-Schlüssel');

// Tabulator wird zum Leerzeichen, damit kein Zeichen ersatzlos verschwindet.
$parsed = cms_parse_frontmatter(cms_serialize_md_file(['title' => "A\tB"], 'x'), false);
is_same($parsed['meta']['title'], 'A B', 'Tabulator wird zum Leerzeichen');

// Leere Werte fallen raus, null ebenso.
$ser = cms_serialize_md_file(['title' => 'Da', 'description' => '', 'heading' => null], 'x');
$parsed = cms_parse_frontmatter($ser, false);
ok(!isset($parsed['meta']['description']), 'Leerer Wert wird nicht geschrieben');
ok(!isset($parsed['meta']['heading']),     'null wird nicht geschrieben');
is_same($parsed['meta']['title'], 'Da',    'Nicht-leere Werte bleiben');

// Schlüsselnamen: nur was wie ein YAML-Schlüssel aussieht, darf geschrieben
// werden. Sonst könnte ein KI-Schreibzugriff beliebiges YAML einschleusen.
$ser = cms_serialize_md_file(['gut' => 'ja', 'nicht gut' => 'nein', 'auch:nicht' => 'nein'], 'x');
$parsed = cms_parse_frontmatter($ser, false);
ok(isset($parsed['meta']['gut']),          'Gültiger Schlüssel wird geschrieben');
ok(!isset($parsed['meta']['nicht gut']),   'Schlüssel mit Leerzeichen wird verworfen');
ok(strpos($ser, 'auch:nicht') === false,   'Schlüssel mit Doppelpunkt wird verworfen');

// ─────────────────────────────────────────────────────────────────────────────
group('4. Path-Traversal (cms_safe_path / cms_resolve_content_path)');
// ─────────────────────────────────────────────────────────────────────────────

fixture('home.md', "---\ntitle: Home\nslug: home\n---\n\nHallo");
fixture('work/projekt-alpha.md', "---\ntitle: Alpha\nslug: work/projekt-alpha\n---\n\nAlpha");
fixture('.history/home/20260906.md', "---\ntitle: Backup\n---\n\nAlt");

$boese = [
    '../config/config.php',
    '../../etc/passwd',
    '../../../../../../etc/passwd',
    'work/../../config/config.php',
    '/etc/passwd',
    '....//config/config.php',
    '..%2f..%2fconfig%2fconfig.php',
    "home.md\0.txt",
    '',
    '.',
    '..',
    '../',
    './../home.md',
    'work/./../../home.md',
    '.history/home/20260906.md',
    'home.php',
    'home.md.php',
];

foreach ($boese as $input) {
    $label = $input === '' ? '(leerer String)' : str_replace("\0", '\\0', $input);
    $result = cms_resolve_content_path($input);

    if ($result === false) {
        ok(true, "abgewiesen: {$label}");
        continue;
    }

    // Nicht abgewiesen? Dann muss der Pfad wenigstens beweisbar innerhalb von
    // CONTENT_DIR liegen und auf .md enden — sonst ist es ein echter Fund.
    $base = realpath(CONTENT_DIR);
    $real = realpath($result) ?: $result;
    $inside = strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
    $is_md  = strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'md';
    ok($inside && $is_md, "nicht ausgebrochen: {$label} → " . substr($real, strlen($base)));
}

// Gültige Pfade müssen weiterhin durchkommen.
is_same(cms_safe_path('home.md', CONTENT_DIR), 'home.md', 'gültig: home.md');
is_same(cms_safe_path('work/projekt-alpha.md', CONTENT_DIR), 'work/projekt-alpha.md', 'gültig: work/projekt-alpha.md');
is_same(cms_safe_path('neue-seite.md', CONTENT_DIR), 'neue-seite.md', 'gültig: noch nicht existierende Datei');
is_same(cms_safe_path('blog/neuer-post.md', CONTENT_DIR), 'blog/neuer-post.md', 'gültig: Datei in noch nicht existierendem Ordner');

// Dateiendung (Fund L2: delete_file prüfte sie nicht)
ok(cms_is_valid_content_file('home.md'),        'is_valid_content_file: home.md');
ok(!cms_is_valid_content_file('home.php'),      'is_valid_content_file lehnt .php ab');
ok(!cms_is_valid_content_file('home.md.php'),   'is_valid_content_file lehnt Doppelendung ab');
ok(!cms_is_valid_content_file('.htaccess'),     'is_valid_content_file lehnt .htaccess ab');

// ─────────────────────────────────────────────────────────────────────────────
group('5. Content-Index, Navigation und Entwürfe');
// ─────────────────────────────────────────────────────────────────────────────

fixture('kontakt.md', "---\ntitle: Kontakt\nslug: contact\nnavLabel: Kontakt\norder: 3\nshowInNav: false\nshowInFooter: true\n---\n\nSchreib mir");
fixture('entwurf.md', "---\ntitle: Entwurf\nslug: entwurf\nnavLabel: Entwurf\norder: 5\ndraft: true\nshowInNav: true\n---\n\nNoch nicht fertig");
fixture('work/geheim.md', "---\ntitle: Geheim\nslug: work/geheim\nnavLabel: Geheim\ndraft: true\n---\n\nUnfertig");

$index_all = cms_build_content_index(CONTENT_DIR, '', true);
$index_pub = cms_build_content_index(CONTENT_DIR, '', false);

ok(isset($index_all['home']),  'Index: home über Frontmatter-Slug gefunden');
ok(isset($index_all['contact']), 'Index: kontakt.md liegt unter seinem Slug "contact", nicht unter dem Dateinamen');
ok(isset($index_all['work/projekt-alpha']), 'Index: verschachtelte Seite gefunden');
is_same($index_all['work/projekt-alpha']['parent'], 'work', 'Index: parent wird gesetzt');
is_same($index_all['home']['parent'], null, 'Index: Top-Level-Seite hat parent null');
ok(!isset($index_all['.history/home/20260906']), 'Index: .history wird nicht mitgelesen');

ok(isset($index_all['entwurf']),  'Index mit Entwürfen: Entwurf ist enthalten');
ok(!isset($index_pub['entwurf']), 'Öffentlicher Index: Entwurf ist gefiltert');
ok(!isset($index_pub['work/geheim']), 'Öffentlicher Index: Entwurf im Unterordner ist gefiltert');
ok(isset($index_pub['home']),     'Öffentlicher Index: normale Seite bleibt');

ok(cms_is_draft(['draft' => true]),   'is_draft: bool true');
ok(cms_is_draft(['draft' => 'true']), 'is_draft: String "true"');
ok(!cms_is_draft(['draft' => false]), 'is_draft: bool false');
ok(!cms_is_draft(['draft' => 'false']), 'is_draft: String "false"');
ok(!cms_is_draft([]),                 'is_draft: Schlüssel fehlt');

// Traversal-Reihenfolge: Dateien des aktuellen Ordners vor Unterordnern.
// index.php hat sich darauf verlassen (glob() erst *.md, dann GLOB_ONLYDIR).
$scanned = cms_scan_content_dir(CONTENT_DIR);
$first_sub = null;
$last_top  = null;
foreach ($scanned as $i => $rel) {
    if (strpos($rel, '/') === false) $last_top = $i;
    elseif ($first_sub === null)     $first_sub = $i;
}
ok($first_sub === null || $last_top === null || $last_top < $first_sub,
   'Scan-Reihenfolge: Top-Level-Dateien kommen vor Unterordner-Dateien');

// ─────────────────────────────────────────────────────────────────────────────
group('6. Seiten-CRUD');
// ─────────────────────────────────────────────────────────────────────────────

is_same(cms_sanitize_slug('  Über Mich  '), 'ber-mich', 'sanitize_slug: Umlaut fällt weg, Rest wird kleingeschrieben');
is_same(cms_sanitize_slug('Work/Projekt Alpha'), 'work/projekt-alpha', 'sanitize_slug: Schrägstrich bleibt, Leerzeichen wird Bindestrich');
is_same(cms_sanitize_slug('a//b'), 'a/b', 'sanitize_slug: doppelte Schrägstriche werden zusammengefasst');
is_same(cms_sanitize_slug('../../etc/passwd'), 'etc/passwd', 'sanitize_slug: Traversal-Punkte fallen weg');
is_same(cms_sanitize_slug('---'), '', 'sanitize_slug: reine Trennzeichen ergeben leeren Slug');

// list_pages
$pages = cms_list_pages(true);
ok(count($pages) >= 5, 'list_pages liefert alle Seiten (' . count($pages) . ')');
$slugs = array_column($pages, 'slug');
ok(in_array('home', $slugs, true), 'list_pages enthält home');
$pub_slugs = array_column(cms_list_pages(false), 'slug');
ok(!in_array('entwurf', $pub_slugs, true), 'list_pages ohne Entwürfe filtert korrekt');

// get_page
$page = cms_get_page('contact');
ok($page !== false, 'get_page findet Seite über den Frontmatter-Slug');
is_same($page['meta']['title'], 'Kontakt', 'get_page liefert die Metadaten');
is_same($page['body'], 'Schreib mir', 'get_page liefert den Body');
is_same(cms_get_page('gibt-es-nicht'), false, 'get_page auf unbekannten Slug liefert false');
is_same(cms_get_page('entwurf', false), false, 'get_page ohne Entwürfe liefert für einen Entwurf false');
ok(cms_get_page('entwurf', true) !== false, 'get_page mit Entwürfen findet den Entwurf');

// create_page
$res = cms_create_page('blog/erster-post', ['title' => 'Erster Post', 'navLabel' => 'Erster'], 'Hallo Welt');
ok($res['ok'], 'create_page legt eine Seite in einem neuen Ordner an');
ok(file_exists(CONTENT_DIR . '/blog/erster-post.md'), 'create_page erzeugt die Datei am erwarteten Ort');
$created = cms_get_page('blog/erster-post');
is_same($created['meta']['slug'], 'blog/erster-post', 'create_page schreibt den Slug ins Frontmatter');
is_same($created['body'], 'Hallo Welt', 'create_page schreibt den Body');

$res = cms_create_page('blog/erster-post', ['title' => 'Nochmal'], 'x');
is_same($res['ok'], false, 'create_page lehnt einen bereits vergebenen Slug ab');
is_same($res['code'], 'exists', 'create_page meldet den Grund "exists"');

$res = cms_create_page('---', ['title' => 'Leer'], 'x');
is_same($res['ok'], false, 'create_page lehnt einen leeren Slug ab');
is_same($res['code'], 'invalid_slug', 'create_page meldet den Grund "invalid_slug"');

$res = cms_create_page('entwurf-neu', ['title' => 'Neu'], 'Text', ['draft' => true]);
ok($res['ok'], 'create_page mit draft-Option');
ok(cms_is_draft(cms_get_page('entwurf-neu')['meta']), 'create_page setzt draft: true');
is_same(cms_get_page('entwurf-neu', false), false, 'Als Entwurf angelegte Seite ist öffentlich unsichtbar');

// update_page
$res = cms_update_page('blog/erster-post', ['title' => 'Geänderter Titel'], 'Neuer Text');
ok($res['ok'], 'update_page ändert eine bestehende Seite');
$updated = cms_get_page('blog/erster-post');
is_same($updated['meta']['title'], 'Geänderter Titel', 'update_page schreibt die neuen Metadaten');
is_same($updated['body'], 'Neuer Text', 'update_page schreibt den neuen Body');
is_same($updated['meta']['navLabel'], 'Erster', 'update_page erhält nicht übergebene Felder');

$res = cms_update_page('gibt-es-nicht', ['title' => 'x'], 'y');
is_same($res['ok'], false, 'update_page auf unbekannten Slug schlägt fehl');
is_same($res['code'], 'not_found', 'update_page meldet den Grund "not_found"');

$res = cms_update_page('blog/erster-post', [], 'Text', ['draft' => true]);
ok($res['ok'], 'update_page kann eine Seite auf Entwurf setzen');
ok(cms_is_draft(cms_get_page('blog/erster-post')['meta']), 'update_page setzt draft: true');
$res = cms_update_page('blog/erster-post', [], 'Text', ['draft' => false]);
ok(!cms_is_draft(cms_get_page('blog/erster-post')['meta']), 'update_page kann den Entwurfsstatus wieder aufheben');

// delete_page
$res = cms_delete_page('blog/erster-post');
ok($res['ok'], 'delete_page löscht eine Seite');
ok(!file_exists(CONTENT_DIR . '/blog/erster-post.md'), 'delete_page entfernt die Datei');
is_same(cms_get_page('blog/erster-post'), false, 'Gelöschte Seite ist nicht mehr auffindbar');

$res = cms_delete_page('gibt-es-nicht');
is_same($res['ok'], false, 'delete_page auf unbekannten Slug schlägt fehl');

// Fund L2: über den Dateipfad darf nichts ausserhalb von .md gelöscht werden.
fixture('nicht-loeschen.txt', 'bleibt');
$res = cms_delete_content_file('nicht-loeschen.txt');
is_same($res['ok'], false, 'delete_content_file lehnt eine Nicht-Markdown-Datei ab (Fund L2)');
ok(file_exists(CONTENT_DIR . '/nicht-loeschen.txt'), 'Die Nicht-Markdown-Datei existiert noch');

$res = cms_delete_content_file('.history/home/20260906.md');
is_same($res['ok'], false, 'delete_content_file kommt nicht an .history heran (Fund L2)');
ok(file_exists(CONTENT_DIR . '/.history/home/20260906.md'), 'Die Backup-Datei existiert noch');

$res = cms_delete_content_file('kontakt.md');
ok($res['ok'], 'delete_content_file löscht eine reguläre .md-Datei');

// ─────────────────────────────────────────────────────────────────────────────
group('7. Versionshistorie und KI-Kennzeichnung');
// ─────────────────────────────────────────────────────────────────────────────

fixture('historie.md', "---\ntitle: Historie\nslug: historie\n---\n\nStand eins");

// ── Kennzeichnung ──
$res = cms_update_page('historie', [], 'Stand zwei', ['edited_by' => 'ai']);
ok($res['ok'], 'update_page mit edited_by schreibt');
$meta = cms_get_page('historie')['meta'];
is_same($meta['lastEditedBy'] ?? null, 'ai', 'update_page setzt lastEditedBy');
ok(
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string)($meta['lastEditedAt'] ?? '')) === 1,
    'lastEditedAt ist ein ISO-8601-Zeitstempel (' . ($meta['lastEditedAt'] ?? '–') . ')'
);

// Der Zeitstempel enthält Doppelpunkte — er muss den Round-Trip überstehen,
// sonst zerlegt er beim nächsten Speichern den Frontmatter-Block.
$again = cms_get_page('historie')['meta'];
is_same($again['lastEditedAt'], $meta['lastEditedAt'], 'lastEditedAt bleibt beim Wiederlesen unverändert');

$res = cms_update_page('historie', [], 'Stand drei');
$meta = cms_get_page('historie')['meta'];
is_same($meta['lastEditedBy'] ?? null, 'ai', 'update_page ohne edited_by lässt die Kennzeichnung stehen');

$res = cms_create_page('ki-neu', ['title' => 'Von der KI'], 'Text', ['edited_by' => 'ai']);
is_same(cms_get_page('ki-neu')['meta']['lastEditedBy'] ?? null, 'ai', 'create_page setzt lastEditedBy');

// ── Backup vor dem Schreiben ──
is_same(cms_history_list('historie'), [], 'ohne backup-Option entsteht keine Vorversion');

$res = cms_update_page('historie', [], 'Stand vier', ['backup' => true, 'edited_by' => 'ai']);
ok($res['ok'], 'update_page mit backup-Option schreibt');
$versions = cms_history_list('historie');
is_same(count($versions), 1, 'update_page mit backup legt genau eine Vorversion an');
ok(
    str_contains((string)file_get_contents($versions[0]['file']), 'Stand drei'),
    'Die Vorversion enthält den Stand VOR der Änderung'
);
is_same(cms_get_page('historie')['body'], 'Stand vier', 'Die Seite selbst trägt den neuen Stand');

// Verschachtelte Slugs bekommen verschachtelte Historien-Ordner.
fixture('work/beta.md', "---\ntitle: Beta\nslug: work/beta\n---\n\nAlt");
cms_update_page('work/beta', [], 'Neu', ['backup' => true]);
ok(
    is_dir(CONTENT_DIR . '/.history/work/beta'),
    'Verschachtelter Slug landet in .history/work/beta/'
);

// ── Der Historien-Ordner bleibt aus Index und Auslieferung heraus ──
ok(!isset(cms_build_content_index()['. history']), 'Index enthält keine Historien-Einträge');
$index_files = array_column(cms_build_content_index(), 'rel');
ok(
    count(array_filter($index_files, fn($f) => str_contains($f, '.history'))) === 0,
    'Kein Eintrag im Index zeigt auf .history'
);
ok(is_file(CONTENT_DIR . '/.history/.htaccess'), '.history/.htaccess wird zur Laufzeit angelegt');

// ── Backup vor dem Löschen ──
fixture('weg.md', "---\ntitle: Weg\nslug: weg\n---\n\nInhalt");
$res = cms_delete_page('weg', ['backup' => true]);
ok($res['ok'], 'delete_page mit backup löscht');
is_same(count(cms_history_list('weg')), 1, 'delete_page sichert die Datei vor dem Löschen');
is_same(cms_get_page('weg'), false, 'Die gelöschte Seite ist weg');

// ── Pruning ──
$hist_dir = CONTENT_DIR . '/.history/prune';
mkdir($hist_dir, 0777, true);
for ($i = 1; $i <= 25; $i++) {
    file_put_contents(sprintf('%s/2026-01-%02d_120000.md', $hist_dir, $i), "Version {$i}");
}
is_same(count(cms_history_list('prune')), 25, '25 Vorversionen angelegt');
$removed = cms_history_prune('prune');
is_same($removed, 5, 'prune entfernt alles über 20 Versionen hinaus');
$rest = cms_history_list('prune');
is_same(count($rest), 20, 'Nach dem Pruning bleiben 20 Vorversionen');
is_same($rest[0]['stamp'], '2026-01-25_120000', 'Die neueste Vorversion bleibt erhalten');
is_same($rest[19]['stamp'], '2026-01-06_120000', 'Die ältesten fünf sind weg');

// Altersgrenze: greift auch, wenn die Zahl der Versionen unter dem Limit liegt.
touch($rest[19]['file'], time() - (91 * 86400));
touch($rest[18]['file'], time() - (89 * 86400));
$removed = cms_history_prune('prune');
is_same($removed, 1, 'prune entfernt eine Vorversion, die älter als 90 Tage ist');
is_same(count(cms_history_list('prune')), 19, 'Die 89 Tage alte Vorversion bleibt');

// ── Wiederherstellen (Undo) ──
$res = cms_history_restore('historie');
ok($res['ok'], 'history_restore setzt auf die letzte Vorversion zurück');
is_same(cms_get_page('historie')['body'], 'Stand drei', 'Der Body entspricht wieder der Vorversion');
is_same(
    count(cms_history_list('historie')),
    2,
    'Das Zurücksetzen sichert den überschriebenen Stand ebenfalls'
);
ok(
    str_contains((string)file_get_contents(cms_history_list('historie')[0]['file']), 'Stand vier'),
    'Die neueste Vorversion ist der Stand vor dem Zurücksetzen (Undo ist umkehrbar)'
);

$res = cms_history_restore('historie', 'gibt-es-nicht');
is_same($res['ok'], false, 'history_restore auf unbekannten Zeitstempel schlägt fehl');
$res = cms_history_restore('ki-neu');
is_same($res['code'] ?? null, 'not_found', 'history_restore ohne Vorversion meldet not_found');

// ─────────────────────────────────────────────────────────────────────────────
group('8. XML-Sitemap');
// ─────────────────────────────────────────────────────────────────────────────

// cms_build_sitemap_xml() nimmt einen fertigen Content-Index entgegen, keinen
// Ordner — der Testfall baut ihn deshalb von Hand, statt sich auf den
// Zustand zu verlassen, den die Gruppen davor im gemeinsamen CONTENT_DIR
// hinterlassen haben. Für lastmod braucht es trotzdem echte Dateien mit
// gesetzter mtime.
$sm_dir = CONTENT_DIR . '/sitemap-fixtures';
mkdir($sm_dir, 0777, true);

$sm_home_file = $sm_dir . '/home.md';
file_put_contents($sm_home_file, 'Start');
touch($sm_home_file, strtotime('2026-01-01 12:00:00'));

$sm_index = [
    'home' => [
        'file' => $sm_home_file, 'rel' => 'sitemap-fixtures/home.md',
        'meta' => ['robots' => 'index, follow'], 'parent' => null,
    ],
    // robots: noindex, … — Spec-Acceptance: darf nicht in der Sitemap stehen.
    'geheim' => [
        'file' => $sm_home_file, 'rel' => 'sitemap-fixtures/geheim.md',
        'meta' => ['robots' => 'noindex, follow'], 'parent' => null,
    ],
    // Kein robots-Feld im Frontmatter — muss wie index.php auf index/follow
    // defaulten, nicht stillschweigend ausgeschlossen werden.
    'ohne-robots-feld' => [
        'file' => $sm_home_file, 'rel' => 'sitemap-fixtures/ohne-robots-feld.md',
        'meta' => [], 'parent' => null,
    ],
    // Slug mit XML-Sonderzeichen (frei vergebbar über das slug-Frontmatter-
    // Feld) — darf die Sitemap nicht kaputt machen.
    'work/&kaputt' => [
        'file' => $sm_home_file, 'rel' => 'sitemap-fixtures/kaputt.md',
        'meta' => ['robots' => 'index, nofollow'], 'parent' => 'work',
    ],
    // "404" ist reservierter Slug — muss auch mit
    // explizitem index/follow ausgeschlossen bleiben, der Ausschluss hängt
    // am Slug, nicht am robots-Feld. PHP castet einen rein numerischen
    // Array-Schlüssel wie "404" beim Aufbau von $content_index automatisch
    // zu int — dieser Fixture-Eintrag deckt genau diesen Stolperstein ab
    // (siehe cms_build_sitemap_xml()).
    '404' => [
        'file' => $sm_home_file, 'rel' => 'sitemap-fixtures/404.md',
        'meta' => ['robots' => 'index, follow'], 'parent' => null,
    ],
];

$sitemap = cms_build_sitemap_xml($sm_index, 'https://example.test/');

ok(str_starts_with($sitemap, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"),
   'Sitemap beginnt mit der XML-Deklaration');
ok(str_contains($sitemap, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'),
   'urlset trägt den Sitemap-Namespace');
ok(str_contains($sitemap, '<loc>https://example.test/home</loc>'),
   'Normale Seite ist enthalten, abschliessender Slash von site_url entfernt');
ok(!str_contains($sitemap, '/geheim<'),
   'Seite mit robots: noindex ist ausgeschlossen');
ok(str_contains($sitemap, '<loc>https://example.test/ohne-robots-feld</loc>'),
   'Seite ohne robots-Feld gilt als index/follow (gleicher Default wie index.php)');
ok(str_contains($sitemap, '<lastmod>2026-01-01T12:00:00'),
   'lastmod kommt aus dem Datei-Änderungsdatum');
ok(str_contains($sitemap, '<loc>https://example.test/work/&amp;kaputt</loc>'),
   'Slug mit "&" wird für XML escaped');
ok(!str_contains($sitemap, '/404<'),
   'Reservierter Slug "404" ist trotz robots: index, follow ausgeschlossen');

$sm_doc = new DOMDocument();
ok($sm_doc->loadXML($sitemap), 'Ergebnis ist wohlgeformtes XML (DOMDocument::loadXML)');
is_same($sm_doc->getElementsByTagName('url')->length, 3,
   'Genau drei <url>-Einträge (die noindex-Seite und der reservierte Slug "404" sind draussen)');

group('Kategorien: categories überlebt Parsen und Schreiben');

foreach (['seo', 'seo, wordpress', 'ueber-seo, wordpress, a-b'] as $cat_value) {
    $ser   = cms_serialize_md_file(['title' => 'K', 'categories' => $cat_value], 'Text');
    foreach ([false, true] as $cast) {
        $back = cms_parse_frontmatter($ser, $cast);
        is_same($back['meta']['categories'] ?? null, $cat_value, "categories '{$cat_value}' bleibt erhalten (Cast: " . ($cast ? 'ja' : 'nein') . ')');
        $again = cms_parse_frontmatter(cms_serialize_md_file($back['meta'], $back['body']), $cast);
        is_same($again['meta']['categories'] ?? null, $cat_value, "categories '{$cat_value}' übersteht zweimaliges Round-Trip");
    }
}

// ─── Ergebnis ────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 60) . "\n";
if ($tests_failed === 0) {
    echo "Alle {$tests_run} Prüfungen grün.\n";
    exit(0);
}
echo "{$tests_failed} von {$tests_run} Prüfungen fehlgeschlagen.\n";
exit(1);
