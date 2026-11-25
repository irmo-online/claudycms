<?php
declare(strict_types=1);

/**
 * POST-Handler für Seiten, Ordner und Bilder.
 *
 * Seiten, Ordner und Bilder sind für alle Rollen gleich nutzbar, deshalb
 * prüfen die Handler nur 'content' bzw. 'images'. Anders als die übrigen
 * Handler setzen sie meist keinen Ziel-Tab: das Formular postet auf die URL,
 * auf der es steht, und genau dieser Tab wird danach wieder gerendert.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Seiten ─────────────────────────────────────────────────────────────────

/**
 * Frontmatter aus dem Editor-Formular. Leere Felder fehlen im Ergebnis, damit
 * sie nicht als leere Schlüssel in der Datei landen.
 *
 * Wird beim Speichern gebraucht und beim erneuten Anzeigen der Maske "Neue
 * Seite" nach einem Fehler, damit die Eingaben nicht verloren gehen.
 */
function editor_meta_from_post(): array {
    $meta = [];
    // contentType/pageStyle (Abschnitt 4.5): ohne diese
    // beiden Schlüssel würde das Backend eine per MCP angelegte
    // Landingpage beim ersten Speichern hier stillschweigend zurück
    // in eine kaputt gerenderte Markdown-Seite verwandeln (HTML liefe
    // wieder durch markdown_to_html()) — sicherheitsrelevanter Fix,
    // kein reines Feature-Detail (Spec 6.8).
    $frontmatter_keys = [
        'title', 'description', 'canonical', 'robots', 'slug', 'navLabel', 'order', 'heading', 'headerImage',
        'contentType', 'pageStyle', 'ogTitle', 'ogDescription', 'ogImage',
    ];
    foreach ($frontmatter_keys as $key) {
        $value = post_str('meta_' . $key);
        if ($value !== '') {
            $meta[$key] = $value;
        }
    }
    // Kategorien kommen als Checkbox-Array. post_str() liefert bei einem
    // Array den Default, deshalb hier direkt lesen und jeden Eintrag prüfen.
    // Unbekannte Slugs bleiben erhalten (Kategorie später gelöscht, Seite
    // behält die Zuordnung), nur Müll fliegt raus.
    $posted_categories = $_POST['meta_categories'] ?? [];
    if (is_array($posted_categories)) {
        $category_slugs = [];
        foreach ($posted_categories as $slug) {
            if (is_string($slug) && $slug !== '' && $slug === cms_category_make_slug($slug)) {
                $category_slugs[] = $slug;
            }
        }
        $category_slugs = cms_category_slugs_parse(cms_category_slugs_join($category_slugs));
        if ($category_slugs !== []) {
            $meta['categories'] = cms_category_slugs_join($category_slugs);
        }
    }
    // Boolean-Felder für Navigation
    $meta['showInNav']    = post_bool('meta_showInNav')    ? 'true' : 'false';
    $meta['showInFooter'] = post_bool('meta_showInFooter') ? 'true' : 'false';
    return $meta;
}

function handle_save_file(): HandlerResult {
    $error = guard('content');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }

    // Der Pfad kommt beim Bearbeiten aus dem versteckten Feld, beim
    // Anlegen aus Ordner-Auswahl und Dateiname.
    //
    // Fund L13: Bisher hat allein ein Inline-Skript den Pfad aus beiden
    // Feldern zusammengesetzt — ohne JavaScript liess sich keine Seite
    // anlegen, weil serverseitig nur "filepath" gelesen wurde.
    $requested_path = trim(post_str('filepath'));
    $is_create = ($requested_path === '');
    if ($is_create) {
        $folder = trim(post_str('file_folder'));
        $name   = trim(post_str('file_name'));
        $requested_path = $folder !== '' ? $folder . '/' . $name : $name;
    }
    // Ein Fehler beim Anlegen führt zurück in die Maske "Neue Seite", die
    // sich dann aus dem POST wieder füllt.
    $fail_action = $is_create ? 'new' : null;

    $filepath = cms_safe_path($requested_path, CONTENT_DIR);
    if ($filepath === false || !cms_is_valid_content_file($filepath)) {
        return HandlerResult::failure('Ungültiger Dateipfad. Nur .md-Dateien in erlaubten Ordnern sind zulässig.', $fail_action);
    }

    // Anlegen darf keine bestehende Seite überschreiben: das Formular kennt
    // deren Inhalt nicht, und im Backend gibt es kein Backup.
    if ($is_create && file_exists(CONTENT_DIR . '/' . $filepath)) {
        return HandlerResult::failure('Diese Datei existiert bereits. Bitte einen anderen Dateinamen wählen.', $fail_action);
    }

    $meta = editor_meta_from_post();

    // Entwurfsstatus einer bestehenden Seite erhalten. Gesetzt wird er
    // bisher nur über die KI-Anbindung, das Admin-Formular kennt kein
    // Feld dafür — ohne diese Zeile würde jedes Speichern im Backend
    // einen Entwurf stillschweigend veröffentlichen.
    $existing = cms_parse_md_file(CONTENT_DIR . '/' . $filepath, false);
    if ($existing !== false && cms_is_draft($existing['meta'])) {
        $meta['draft'] = 'true';
    }

    // Menschliche Bearbeitung kennzeichnen (Spec 5.6) — dieselbe
    // Funktion, die mcp.php für "lastEditedBy: ai" nutzt, hier mit der
    // E-Mail des speichernden Nutzers statt "ai".
    $me = current_user();
    if ($me !== null) {
        $meta = cms_apply_edit_marks($meta, ['edited_by' => $me['email']]);
    }

    $result = cms_save_content_file($filepath, $meta, post_str('body'));
    if ($result['ok']) {
        // Nach dem Anlegen weiter im Bearbeitungsmodus der neuen Seite, nicht
        // wieder in der leeren Maske. Die Meldung erzeugt
        // admin_page_created_message() auf der Zielseite.
        return $is_create
            ? HandlerResult::redirect('admin.php?action=edit&file=' . rawurlencode($filepath) . '&created=1')
            : HandlerResult::success('Datei gespeichert: ' . $filepath);
    }
    return HandlerResult::failure($result['code'] === 'invalid_path'
        ? 'Ungültiger Dateipfad. Nur .md-Dateien in erlaubten Ordnern sind zulässig.'
        : 'Fehler beim Speichern.', $fail_action);
}

/**
 * Meldung für den Editor direkt nach dem Anlegen einer Seite. Der Redirect
 * dorthin trägt nur das Flag "created", der Text ist fest und der Pfad wird
 * erst genannt, wenn er auf eine vorhandene Seite zeigt.
 */
function admin_page_created_message(string $action): string {
    if ($action !== 'edit' || get_str('created') !== '1') {
        return '';
    }
    $filepath = cms_safe_path(get_str('file'), CONTENT_DIR);
    if ($filepath === false || cms_resolve_content_path($filepath) === false || !is_file(CONTENT_DIR . '/' . $filepath)) {
        return '';
    }
    return 'Seite erstellt: ' . $filepath;
}

function handle_delete_file(): HandlerResult {
    $error = guard('content');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    // Fund L2: Der Löschweg hat die Dateiendung nicht geprüft, der
    // Speicherweg schon. cms_delete_content_file() erzwingt sie — damit
    // sind auch die Backups unter content/.history/ ausser Reichweite.
    $requested_path = post_str('filepath');
    $filepath = cms_safe_path($requested_path, CONTENT_DIR);
    $result   = cms_delete_content_file($requested_path);

    if ($result['ok']) {
        // Zurück zur Liste: der Editor der gelöschten Seite hätte nichts
        // mehr zum Anzeigen.
        return HandlerResult::success('Datei gelöscht: ' . (string)$filepath, 'list');
    }
    return HandlerResult::failure($result['code'] === 'invalid_path' ? 'Ungültiger Dateipfad.' : 'Fehler beim Löschen.');
}

// Entwurf veröffentlichen
//
// Gegenstück zur Entwurfsregel der KI-Anbindung (Feature-PRD 6.2): Solange
// ai_publish_immediately nicht true ist, legt jeder Schreibzugriff über
// mcp.php die Seite als draft: true ab — sie ist dann öffentlich nicht
// erreichbar. Hier wird das Flag entfernt und die Seite geht live.
//
// Kein Backup nach content/.history/: es ändert sich kein Inhalt, nur die
// Sichtbarkeit. Die Sicherung gehört laut PRD 6.3 zu KI- und API-Schreib-
// zugriffen, nicht zu Aktionen im Admin-Backend.
function handle_publish_file(): HandlerResult {
    $error = guard('content');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    $requested_path = post_str('filepath');
    $full = cms_resolve_content_path($requested_path);
    $data = $full === false ? false : cms_parse_md_file($full, false);

    if ($data === false) {
        return HandlerResult::failure('Ungültiger Dateipfad.');
    }
    if (!cms_is_draft($data['meta'])) {
        return HandlerResult::failure('Diese Seite ist bereits veröffentlicht.');
    }
    unset($data['meta']['draft']);
    $result = cms_save_content_file($requested_path, $data['meta'], $data['body']);
    return $result['ok']
        ? HandlerResult::success('Seite veröffentlicht: ' . $requested_path)
        : HandlerResult::failure('Fehler beim Veröffentlichen.');
}

// Seite zurück auf Entwurf setzen (nimmt sie aus der öffentlichen Auslieferung)
function handle_unpublish_file(): HandlerResult {
    $error = guard('content');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    $requested_path = post_str('filepath');
    $full = cms_resolve_content_path($requested_path);
    $data = $full === false ? false : cms_parse_md_file($full, false);

    if ($data === false) {
        return HandlerResult::failure('Ungültiger Dateipfad.');
    }
    if (cms_is_draft($data['meta'])) {
        return HandlerResult::failure('Diese Seite ist bereits ein Entwurf.');
    }
    $data['meta']['draft'] = 'true';
    $result = cms_save_content_file($requested_path, $data['meta'], $data['body']);
    return $result['ok']
        ? HandlerResult::success('Seite auf Entwurf gesetzt: ' . $requested_path)
        : HandlerResult::failure('Fehler beim Zurücksetzen.');
}

function handle_create_folder(): HandlerResult {
    $error = guard('content');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    $folder_name = post_str('folder_name');
    if (!preg_match('/^[a-z0-9_\-]+$/', $folder_name)) {
        return HandlerResult::failure('Ungültiger Ordnername. Nur Kleinbuchstaben, Zahlen, - und _ erlaubt.');
    }
    $full_path = CONTENT_DIR . '/' . $folder_name;
    if (is_dir($full_path)) {
        return HandlerResult::failure('Ordner existiert bereits.');
    }
    return mkdir($full_path, 0755, true)
        ? HandlerResult::success('Ordner erstellt: ' . $folder_name)
        : HandlerResult::failure('Fehler beim Erstellen des Ordners.');
}

// ─── Landingpage-Vorschau ───────────────────────────────────────────────────

/**
 * Liefert die Vorschau einer Landingpage für das iframe im Editor. Kein
 * Eintrag in der Handler-Map: die Antwort ist nicht die Admin-Seite mit einer
 * Meldung, sondern ein eigenes Dokument mit eigener CSP.
 *
 * Früher baute das Editor-JS die Vorschau als srcdoc zusammen. Ein
 * srcdoc-Dokument erbt aber die CSP der Admin-Seite, und die erlaubt keine
 * Inline-Styles mehr — pageStyle und style-Attribute im HTML wären in der
 * Vorschau blockiert, live aber sichtbar. Deshalb kommt die Vorschau jetzt
 * per POST vom Server, mit cms_send_preview_headers().
 *
 * Gezeigt wird der ungespeicherte Stand aus dem Editor, nicht die Datei.
 */
function handle_landingpage_preview(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit;
    }
    $error = guard('content');
    if ($error !== null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($error);
    }

    cms_send_preview_headers(SITE_URL);
    $body       = post_str('body');
    $page_style = trim(post_str('page_style'));
    require dirname(__DIR__) . '/views/admin/landingpage-preview.php';
    exit;
}

// ─── Bilder ─────────────────────────────────────────────────────────────────

// Validierung und Speichern laufen über lib/media.php — dieselben
// Funktionen, die auch die MCP-Tools nutzen. Der vom Browser gesendete und
// frei fälschbare $_FILES['type'] wird nicht geprüft, cms_validate_image_upload()
// prüft stattdessen den von getimagesize() erkannten echten Typ gegen die
// Whitelist (siehe lib/media.php).
function handle_upload_image(): HandlerResult {
    $error = guard('images');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return HandlerResult::failure('Fehler beim Upload. Bitte versuche es erneut.');
    }
    $file = $_FILES['image'];
    $binary_data = file_get_contents($file['tmp_name']);
    if ($binary_data === false) {
        return HandlerResult::failure('Fehler beim Upload. Bitte versuche es erneut.');
    }

    $validation = cms_validate_image_upload($file['name'], $binary_data);
    if (!$validation['ok']) {
        return HandlerResult::failure($validation['error']);
    }
    $target_name = cms_sanitize_image_filename($file['name']);
    $stored_name = cms_store_image($target_name, $binary_data);
    return $stored_name === ''
        ? HandlerResult::failure('Fehler beim Speichern des Bildes.')
        : HandlerResult::success('Bild hochgeladen: ' . $stored_name);
}

function handle_delete_image(): HandlerResult {
    $error = guard('images');
    if ($error !== null) {
        return HandlerResult::failure($error);
    }
    $image_name = post_str('image_name');
    // cms_resolve_image_path() prüft Zeichen-Whitelist und erlaubte Endung,
    // zusätzlich per realpath() abgesichert.
    if (cms_resolve_image_path($image_name) === false) {
        return HandlerResult::failure('Ungültiger Dateiname.');
    }
    return cms_delete_image($image_name)
        ? HandlerResult::success('Bild gelöscht: ' . $image_name)
        : HandlerResult::failure('Fehler beim Löschen.');
}
