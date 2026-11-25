<?php
/**
 * 301-Redirect-Verwaltung.
 *
 * Zentrale Redirect-Tabelle als eigene JSON-Datei config/redirects.json,
 * nicht pro-Seite im Frontmatter (mit Raphael abgestimmt, 2026-09-23).
 * Struktur/Lock-Muster 1:1 von lib/users.php übernommen:
 *
 *   - cms_redirects_open_locked()/_read_locked()/_write_locked()/_close_locked()
 *     flock() auf offen gehaltenem Handle über die gesamte
 *     Lese-Ändern-Schreiben-Folge, damit sich gleichzeitige Änderungen aus
 *     Admin-Backend und MCP nicht überschreiben.
 *   - cms_load_redirects()      Lesen, ungesperrt (analog cms_load_users())
 *   - cms_redirects_resolve()   Lookup fürs Routing (index.php)
 *   - cms_create_redirect()     Anlegen mit Validierung
 *   - cms_delete_redirect()     Löschen per id
 *
 * Ein Eintrag: {id, from, to, created_at, created_by}. from/to sind interne
 * Pfade (führender "/", kein Schema/Host) — externe Redirect-Ziele sind
 * bewusst nicht Teil von v1 (Open-Redirect-Risiko, Spec Nicht-Ziele).
 *
 * HTTP-Zugriff auf diesen Ordner ist per lib/.htaccess gesperrt, die Datei
 * config/redirects.json zusätzlich über das bereits bestehende, den ganzen
 * config/-Ordner rekursiv sperrende config/.htaccess (kein neuer Eintrag
 * nötig, analog config/users.json, config/ratelimit/, config/reset/).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Datei-Zugriff ──────────────────────────────────────────────────────────

// Überschreibbar per !defined()-Guard, analog CMS_USERS_FILE in lib/users.php
// — admin.php/mcp.php brauchen das nicht, Testskripte gegen ein
// Wegwerf-Verzeichnis schon.
if (!defined('CMS_REDIRECTS_FILE')) {
    define('CMS_REDIRECTS_FILE', dirname(__DIR__) . '/config/redirects.json');
}

function cms_redirects_file(): string {
    return CMS_REDIRECTS_FILE;
}

/** Einheitliches Fehlerformat, analog cms_users_error() in lib/users.php. */
function cms_redirects_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'error' => $message];
}

/**
 * Öffnet config/redirects.json exklusiv gesperrt zum Lesen und Schreiben in
 * einer Folge. 'c+' legt die Datei bei Bedarf leer an, ohne einen
 * bestehenden Inhalt zu verwerfen. Rückgabe false, wenn weder Verzeichnis
 * noch Datei beschreibbar sind — der Aufrufer bricht dann kontrolliert ab.
 *
 * @return resource|false
 */
function cms_redirects_open_locked() {
    $file = cms_redirects_file();
    $dir  = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

/** @param resource $handle */
function cms_redirects_read_locked($handle): array {
    rewind($handle);
    $raw  = stream_get_contents($handle);
    $data = json_decode((string)$raw, true);
    return is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
}

/** @param resource $handle */
function cms_redirects_write_locked($handle, array $redirects): bool {
    $json = json_encode(['redirects' => $redirects], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    rewind($handle);
    if (!ftruncate($handle, 0)) {
        return false;
    }
    $written = fwrite($handle, $json);
    fflush($handle);
    return $written !== false;
}

/** @param resource $handle */
function cms_redirects_close_locked($handle): void {
    flock($handle, LOCK_UN);
    fclose($handle);
}

// ─── Lesen ──────────────────────────────────────────────────────────────────

/**
 * Alle Redirects, ungefiltert. Kein Lock: ein gelegentlich leicht veralteter
 * Lesezugriff ist hier unkritisch, genau wie bei cms_load_users().
 *
 * @return list<array>
 */
function cms_load_redirects(): array {
    $file = cms_redirects_file();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data['redirects'] ?? null) ? $data['redirects'] : [];
}

/**
 * Löst einen angefragten Slug gegen die Redirect-Tabelle auf (Routing in
 * index.php). $slug kommt ohne führenden Schrägstrich (wie $requested_slug
 * dort), "from" wird intern mit führendem "/" gespeichert. Keine
 * automatische Kettenauflösung — ein Treffer, der selbst wieder auf eine
 * Redirect-Quelle zeigt, wird nur einmal weitergeleitet (Spec-Entscheidung).
 */
function cms_redirects_resolve(string $slug): ?string {
    $from = '/' . ltrim($slug, '/');
    foreach (cms_load_redirects() as $redirect) {
        if (($redirect['from'] ?? '') === $from) {
            return (string)$redirect['to'];
        }
    }
    return null;
}

// ─── Validierung ────────────────────────────────────────────────────────────

/**
 * Prüft, ob ein Pfad als Redirect-Quelle/-Ziel taugt: führender "/", kein
 * Schema/Host, keine Traversal-Anteile. Gleiches Zeichenset wie ein Slug im
 * Routing (index.php sanitisiert $requested_slug mit derselben Klasse).
 */
function cms_redirects_valid_path(string $path): bool {
    if ($path === '' || $path[0] !== '/') {
        return false;
    }
    if (strpos($path, '://') !== false || str_starts_with($path, '//')) {
        return false; // Schema/Host bzw. protokoll-relative URL — kein interner Pfad.
    }
    if (strpos($path, '..') !== false) {
        return false;
    }
    return (bool)preg_match('#^/[a-zA-Z0-9_/-]+$#', $path);
}

// ─── Schreiben ──────────────────────────────────────────────────────────────

/**
 * Legt einen neuen Redirect an (Spec Punkt 7). $existing_content_slugs sind
 * die Slugs aller Seiten aus dem Content-Index (ohne führenden "/") — ein
 * Redirect darf keine lebende Seite verdecken (Acceptance-Kriterium). Die
 * Kollisionsprüfung mit bestehenden Redirect-Quellen läuft *innerhalb*
 * desselben Locks wie das Schreiben, damit zwei gleichzeitige Anlage-Versuche
 * mit derselben Quelle sich nicht gegenseitig aushebeln.
 *
 * Eine zweite Quelle, die auf dieselbe schon bestehende Quelle zeigt, wird
 * abgelehnt (eine Quelle → ein Ziel, kein stilles Überschreiben) — dafür
 * erst löschen, dann neu anlegen (analog dem fehlenden update_redirect-Tool).
 *
 * $warning ist kein Ablehnungsgrund: zeigt "to" selbst auf eine bestehende
 * Redirect-Quelle, entstünde eine (bewusst nicht aufgelöste) Kette — das
 * Anlegeformular/MCP-Tool gibt das als Hinweis weiter, blockiert aber nicht.
 *
 * @return array{ok: true, redirect: array, warning: ?string}|array{ok: false, code: string, error: string}
 */
function cms_create_redirect(string $from, string $to, array $existing_content_slugs, string $created_by): array {
    $from = trim($from);
    $to   = trim($to);

    if (!cms_redirects_valid_path($from)) {
        return cms_redirects_error('invalid_from', 'Die alte URL muss ein interner Pfad mit führendem "/" sein, z. B. "/alte-seite".');
    }
    if (!cms_redirects_valid_path($to)) {
        return cms_redirects_error('invalid_to', 'Die neue URL muss ein interner Pfad mit führendem "/" sein, z. B. "/neue-seite".');
    }
    if ($from === $to) {
        return cms_redirects_error('same_path', 'Alte und neue URL dürfen nicht identisch sein.');
    }

    $from_slug = ltrim($from, '/');
    if (in_array($from_slug, $existing_content_slugs, true)) {
        return cms_redirects_error('shadows_page', 'Diese URL gehört zu einer noch existierenden Seite — ein Redirect darf keine lebende Seite verdecken.');
    }

    $handle = cms_redirects_open_locked();
    if ($handle === false) {
        return cms_redirects_error('write_failed', 'Redirect-Verwaltung ist aktuell nicht beschreibbar.');
    }
    $redirects = cms_redirects_read_locked($handle);

    foreach ($redirects as $existing) {
        if (($existing['from'] ?? '') === $from) {
            cms_redirects_close_locked($handle);
            return cms_redirects_error('from_exists', 'Für diese alte URL existiert bereits ein Redirect. Erst löschen, dann neu anlegen.');
        }
    }

    $warning = null;
    foreach ($redirects as $existing) {
        if (($existing['from'] ?? '') === $to) {
            $warning = 'Die neue URL ist selbst als Redirect-Quelle hinterlegt — es entsteht eine Kette, die nicht automatisch aufgelöst wird.';
            break;
        }
    }

    $redirect = [
        'id'         => 'rdr_' . bin2hex(random_bytes(8)),
        'from'       => $from,
        'to'         => $to,
        'created_at' => date('c'),
        'created_by' => $created_by,
    ];
    $redirects[] = $redirect;

    if (!cms_redirects_write_locked($handle, $redirects)) {
        cms_redirects_close_locked($handle);
        return cms_redirects_error('write_failed', 'Speichern ist gerade nicht möglich. Bitte versuch es erneut.');
    }
    cms_redirects_close_locked($handle);

    return ['ok' => true, 'redirect' => $redirect, 'warning' => $warning];
}

/** Löscht einen Redirect unwiderruflich per id. */
function cms_delete_redirect(string $id): array {
    $handle = cms_redirects_open_locked();
    if ($handle === false) {
        return cms_redirects_error('write_failed', 'Redirect-Verwaltung ist aktuell nicht beschreibbar.');
    }
    $redirects = cms_redirects_read_locked($handle);

    $found = false;
    foreach ($redirects as $existing) {
        if (($existing['id'] ?? '') === $id) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        cms_redirects_close_locked($handle);
        return cms_redirects_error('not_found', 'Redirect nicht gefunden.');
    }

    $remaining = array_values(array_filter($redirects, static fn(array $r): bool => ($r['id'] ?? '') !== $id));
    $ok = cms_redirects_write_locked($handle, $remaining);
    cms_redirects_close_locked($handle);
    return $ok ? ['ok' => true] : cms_redirects_error('write_failed', 'Löschen ist gerade nicht möglich. Bitte versuch es erneut.');
}
