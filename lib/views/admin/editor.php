<?php
// Seiten-Editor (Neu anlegen und Bearbeiten). Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── EDITOR ────────────────────────────────────────────────────── -->
    <?php
        $is_new = ($action === 'new');
        $filepath = '';
        $meta = [
            'title' => '', 'description' => '', 'canonical' => '', 'robots' => 'index, follow', 'slug' => '',
            'navLabel' => '', 'order' => '', 'heading' => '', 'headerImage' => '', 'categories' => '',
            'ogTitle' => '', 'ogDescription' => '', 'ogImage' => '',
            'showInNav' => 'true', 'showInFooter' => 'false',
            // contentType bleibt bei neuen Seiten leer (= Markdown,
            // Default laut Spec 4.1), pageStyle ist nur für Landingpages belegt.
            'contentType' => '', 'pageStyle' => '',
        ];
        $body = '';
        $folder = preg_replace('/[^a-z0-9_\-]/', '', get_str('folder'));
        $file_name = '';

        // Die Maske "Neue Seite" erscheint nach einem POST nur, wenn das
        // Anlegen fehlgeschlagen ist (bei Erfolg wird umgeleitet). Dann die
        // Eingaben wieder einsetzen, statt eine leere Maske zu zeigen.
        if ($is_new && isset($_POST['save_file'])) {
            $meta      = array_merge($meta, editor_meta_from_post());
            $body      = post_str('body');
            $folder    = preg_replace('/[^a-z0-9_\-]/', '', post_str('file_folder'));
            $file_name = post_str('file_name');
        }

        if (!$is_new && get_str('file') !== '') {
            $filepath_safe = cms_safe_path(get_str('file'), CONTENT_DIR);
            if ($filepath_safe !== false) {
                $filepath = $filepath_safe;
                $data = cms_parse_md_file(CONTENT_DIR . '/' . $filepath, false);
                if ($data !== false) {
                    $meta = array_merge($meta, $data['meta']);
                    $body = $data['body'];
                }
            }
        }
    ?>

    <?php
        // Der rechte Editor-Panel-Bereich (Tabs, Platzhalter) reagiert
        // per JS auf den Seitentyp. data-content-type ist bei bestehenden Seiten
        // fest (contentType kann im Editor nicht mehr gewechselt werden, Spec
        // 4.5 "beim Neuanlegen"), bei neuen Seiten startet es auf "markdown" und
        // wird von der Typ-Auswahl unten per JS aktualisiert.
        $initial_content_type = ContentType::fromMeta($meta)->value;
    ?>
    <form method="post" id="editor-form" data-content-type="<?= $initial_content_type ?>" data-is-new="<?= $is_new ? '1' : '0' ?>">
        <?= csrf_field() ?>
        <?php if (!$is_new): ?>
            <input type="hidden" name="filepath" value="<?= e($filepath) ?>">
            <!-- contentType ist nach dem Anlegen fest — ohne dieses Feld würde der
                 Whitelist-Fix (frontmatter_keys) beim Speichern ins Leere
                 laufen, weil kein POST-Feld den bestehenden Wert trägt. -->
            <input type="hidden" name="meta_contentType" value="<?= e($meta['contentType']) ?>">
        <?php endif; ?>

        <div class="editor-panel editor-panel-full">
            <h2><?= $is_new ? 'Neue Seite erstellen' : 'Seite bearbeiten' ?></h2>

            <div class="form-group">
                <label for="meta_heading">Seitentitel</label>
                <input type="text" id="meta_heading" name="meta_heading" value="<?= e($meta['heading']) ?>" placeholder="Titel der Seite (H1)">
                <div class="settings-hint is-hidden" id="heading-hint">Wirkt bei einer HTML-Landingpage nicht als Überschrift — der HTML-Rendering-Zweig überspringt den Überschriften-/Bild-Wrapper. Der Titel dient hier nur als Vorlage für Dateiname, URL-Slug und HTML-Title.</div>
            </div>

            <!-- Body-Editor (Markdown oder HTML) + optionales pageStyle-CSS + Vorschau -->
            <div class="editor-panel-header">
                <h2 id="content-heading">Inhalt</h2>
                <div class="preview-toggle">
                    <button type="button" id="btn-editor" class="active">Markdown</button>
                    <button class="is-hidden" type="button" id="btn-css">CSS</button>
                    <button type="button" id="btn-preview">Vorschau</button>
                </div>
            </div>
            <textarea name="body" id="markdown-editor" class="editor-textarea" placeholder="Schreibe hier deinen Inhalt in Markdown..."><?= e($body) ?></textarea>
            <!-- pageStyle: eigenes CSS, wirkt nur auf dieser
                 einen Seite. Nur für Landingpages relevant, JS blendet den Tab bei
                 Markdown-Seiten aus — das Feld bleibt aber immer im Formular, damit
                 ein einmal gesetzter Wert beim Speichern nicht verloren geht. -->
            <textarea name="meta_pageStyle" id="pagestyle-editor" class="editor-textarea is-hidden" placeholder="Eigenes CSS für diese Seite (optional), z. B.&#10;.hero { background: #eee; padding: 4rem; }"><?= e($meta['pageStyle']) ?></textarea>
            <div id="markdown-preview" class="markdown-preview"></div>
            <!-- Landingpage-Vorschau: sandbox ohne allow-scripts —
                 isoliert HTML + pageStyle von der Admin-Oberfläche und ist ein
                 zusätzliches, von der serverseitigen Validierung unabhängiges
                 Sicherheitsnetz. Den Inhalt lädt das Formular
                 landingpage-preview-form unten, siehe dort. -->
            <iframe id="landingpage-preview" name="landingpage-preview" class="markdown-preview landingpage-preview" sandbox=""></iframe>
        </div>

        <div class="editor-panel editor-panel-full">
            <h2>Einstellungen</h2>

            <?php if ($is_new): ?>
                <div class="form-group">
                    <label for="file_name">Dateipfad (relativ zu /content/)</label>
                    <div class="folder-picker">
                        <select class="select-auto" name="file_folder">
                            <option value="">/ (Hauptverzeichnis)</option>
                            <?php foreach (cms_list_subdirs() as $sd): ?>
                                <option value="<?= e($sd) ?>" <?= ($folder === $sd) ? 'selected' : '' ?>><?= e($sd) ?>/</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="file_name" name="file_name" value="<?= e($file_name) ?>" placeholder="dateiname.md" required pattern="[a-z0-9_-]+\.md">
                    </div>
                </div>
                <div class="form-group">
                    <label>Seitentyp</label>
                    <div class="checkbox-row">
                        <label>
                            <input type="radio" name="meta_contentType" value="" <?= $initial_content_type === ContentType::Html->value ? '' : 'checked' ?>> Markdown-Seite
                        </label>
                        <label>
                            <input type="radio" name="meta_contentType" value="<?= ContentType::Html->value ?>" <?= $initial_content_type === ContentType::Html->value ? 'checked' : '' ?>> HTML-Landingpage
                        </label>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label>Datei</label>
                    <input type="text" value="<?= e($filepath) ?>" disabled>
                </div>
            <?php endif; ?>

            <details class="settings-accordion" open>
                <summary>Navigation &amp; Bild</summary>
                <div class="settings-accordion-body">
                    <div class="form-group">
                        <label for="meta_headerImage">Header-Bild (Pfad)</label>
                        <input type="text" id="meta_headerImage" name="meta_headerImage" value="<?= e($meta['headerImage']) ?>" placeholder="assets/images/bild.jpg">
                    </div>

                    <?php
                        $cat_list     = cms_categories_load();
                        $cat_selected = cms_category_slugs_parse($meta['categories'] ?? '');
                        $cat_known    = array_column($cat_list, 'slug');
                        $cat_unknown  = array_values(array_diff($cat_selected, $cat_known));
                    ?>
                    <?php if ($cat_list !== [] || $cat_unknown !== []): ?>
                        <div class="form-group">
                            <label>Kategorien</label>
                            <?php foreach ($cat_list as $c): ?>
                                <label>
                                    <input type="checkbox" name="meta_categories[]" value="<?= e($c['slug']) ?>"
                                           <?= in_array($c['slug'], $cat_selected, true) ? 'checked' : '' ?>>
                                    <?= e($c['name']) ?>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($cat_unknown as $slug): ?>
                                <label>
                                    <input type="checkbox" name="meta_categories[]" value="<?= e($slug) ?>" checked>
                                    <?= e($slug) ?> <span class="settings-hint">(unbekannt)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="meta_navLabel">Navigations-Label</label>
                            <input type="text" id="meta_navLabel" name="meta_navLabel" value="<?= e($meta['navLabel']) ?>" placeholder="Text in der Navigation">
                        </div>
                        <div class="form-group">
                            <label for="meta_order">Reihenfolge</label>
                            <input type="number" id="meta_order" name="meta_order" value="<?= e($meta['order']) ?>" placeholder="1, 2, 3...">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="meta_showInNav" value="1"
                                       <?= ($meta['showInNav'] === 'true' || $meta['showInNav'] === true) ? 'checked' : '' ?>>
                                In Hauptnavigation anzeigen
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="meta_showInFooter" value="1"
                                       <?= ($meta['showInFooter'] === 'true' || $meta['showInFooter'] === true) ? 'checked' : '' ?>>
                                In Footer-Navigation anzeigen
                            </label>
                        </div>
                    </div>
                </div>
            </details>

            <details class="settings-accordion">
                <summary>SEO &amp; Metadaten</summary>
                <div class="settings-accordion-body">
                    <div class="form-group">
                        <label for="meta_title">HTML-Title (&lt;title&gt;)</label>
                        <input type="text" id="meta_title" name="meta_title" value="<?= e($meta['title']) ?>" placeholder="Wird aus dem Seitentitel übernommen">
                    </div>

                    <div class="form-group">
                        <label for="meta_description">Meta-Description</label>
                        <input type="text" id="meta_description" name="meta_description" value="<?= e($meta['description']) ?>" placeholder="Kurzbeschreibung für Suchmaschinen">
                    </div>

                    <div class="form-group">
                        <label for="meta_slug">URL-Slug</label>
                        <input type="text" id="meta_slug" name="meta_slug" value="<?= e($meta['slug']) ?>" placeholder="z.B. work/projekt-alpha">
                    </div>

                    <div class="form-group">
                        <label for="meta_canonical">Canonical-URL (optional)</label>
                        <input type="text" id="meta_canonical" name="meta_canonical" value="<?= e($meta['canonical']) ?>" placeholder="Leer lassen für automatisch aus Domain + URL-Slug">
                    </div>

                    <div class="form-group">
                        <label for="meta_robots">Robots</label>
                        <select id="meta_robots" name="meta_robots">
                            <option value="index, follow" <?= ($meta['robots'] === 'index, follow') ? 'selected' : '' ?>>index, follow</option>
                            <option value="noindex, follow" <?= ($meta['robots'] === 'noindex, follow') ? 'selected' : '' ?>>noindex, follow</option>
                            <option value="index, nofollow" <?= ($meta['robots'] === 'index, nofollow') ? 'selected' : '' ?>>index, nofollow</option>
                            <option value="noindex, nofollow" <?= ($meta['robots'] === 'noindex, nofollow') ? 'selected' : '' ?>>noindex, nofollow</option>
                        </select>
                    </div>

                    <!-- Open Graph / Twitter Card: alle drei optional, fallen beim
                         Rendern in index.php auf Title/Description/
                         Header-Bild zurück, wenn leer. -->
                    <h3 class="meta-subheading">Open Graph / Social Media (optional)</h3>
                    <div class="form-group">
                        <label for="meta_ogTitle">OG-Titel</label>
                        <input type="text" id="meta_ogTitle" name="meta_ogTitle" value="<?= e($meta['ogTitle']) ?>" placeholder="Leer lassen für Title">
                    </div>
                    <div class="form-group">
                        <label for="meta_ogDescription">OG-Beschreibung</label>
                        <input type="text" id="meta_ogDescription" name="meta_ogDescription" value="<?= e($meta['ogDescription']) ?>" placeholder="Leer lassen für Description">
                    </div>
                    <div class="form-group">
                        <label for="meta_ogImage">OG-Bild (Pfad)</label>
                        <input type="text" id="meta_ogImage" name="meta_ogImage" value="<?= e($meta['ogImage']) ?>" placeholder="Leer lassen für Header-Bild — assets/images/bild.jpg">
                    </div>
                </div>
            </details>
        </div>

        <div class="editor-actions">
            <?php // Der Dateipfad für eine neue Seite wird serverseitig aus
                  // file_folder und file_name gebaut (Fund L13). Das frühere
                  // Inline-Skript, das ihn im Browser zusammengesetzt hat, ist
                  // damit überflüssig — Seiten anlegen geht jetzt auch ohne
                  // JavaScript. ?>
            <button type="submit" name="save_file" class="btn btn-primary">Speichern</button>
            <a href="admin.php" class="btn btn-outline">Abbrechen</a>
            <span class="spacer"></span>
            <?php if (!$is_new): ?>
                <button type="submit" name="delete_file" class="btn btn-danger btn-sm"
                        data-confirm="Sicher, dass du diese Seite löschen möchtest?">
                    Löschen
                </button>
            <?php endif; ?>
        </div>
    </form>

    <?php // Lädt die Landingpage-Vorschau vom Server ins iframe oben
          // (handle_landingpage_preview()). Steht außerhalb des Editor-
          // Formulars, weil Formulare nicht verschachtelt sein dürfen. ?>
    <form id="landingpage-preview-form" class="is-hidden" method="post" action="admin.php?action=preview_landingpage" target="landingpage-preview">
        <?= csrf_field() ?>
        <input type="hidden" name="body">
        <input type="hidden" name="page_style">
    </form>

    <!-- Live Preview Script (Markdown-Vorschau + HTML-Landingpage-Vorschau) -->
    <script nonce="<?= e(cms_csp_nonce()) ?>">
    (function() {
        var form         = document.getElementById('editor-form');
        var editor       = document.getElementById('markdown-editor');
        var cssEditor    = document.getElementById('pagestyle-editor');
        var preview      = document.getElementById('markdown-preview');
        var lpPreview    = document.getElementById('landingpage-preview');
        var lpForm       = document.getElementById('landingpage-preview-form');
        var btnEditor    = document.getElementById('btn-editor');
        var btnCss       = document.getElementById('btn-css');
        var btnPreview   = document.getElementById('btn-preview');
        var contentHead  = document.getElementById('content-heading');
        var headingHint  = document.getElementById('heading-hint');
        var typeRadios   = document.querySelectorAll('input[name="meta_contentType"]');

        function isLandingpage() {
            return form.dataset.contentType === 'html';
        }

        // Blendet Tabs/Platzhalter passend zum Seitentyp um —
        // Markdown-Seiten: Markdown/Vorschau wie bisher. Landingpages:
        // HTML/CSS/Vorschau, dritter Tab für das seiteneigene pageStyle-CSS.
        function applyContentType() {
            if (isLandingpage()) {
                btnEditor.textContent = 'HTML';
                btnCss.classList.remove('is-hidden');
                contentHead.textContent = 'Inhalt (HTML)';
                editor.placeholder = 'Rohes HTML, das zwischen Header und Footer eingefügt wird...';
                headingHint.style.display = 'block';
            } else {
                btnEditor.textContent = 'Markdown';
                btnCss.classList.add('is-hidden');
                contentHead.textContent = 'Inhalt';
                editor.placeholder = 'Schreibe hier deinen Inhalt in Markdown...';
                headingHint.style.display = 'none';
            }
            showEditor();
        }

        function setActive(btn) {
            [btnEditor, btnCss, btnPreview].forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
        }

        function showEditor() {
            editor.style.display = 'block';
            cssEditor.style.display = 'none';
            preview.style.display = 'none';
            lpPreview.style.display = 'none';
            setActive(btnEditor);
        }

        function showCss() {
            editor.style.display = 'none';
            cssEditor.style.display = 'block';
            preview.style.display = 'none';
            lpPreview.style.display = 'none';
            setActive(btnCss);
        }

        function showPreview() {
            editor.style.display = 'none';
            cssEditor.style.display = 'none';
            setActive(btnPreview);
            if (isLandingpage()) {
                // sandbox ohne allow-scripts: isoliert HTML +
                // pageStyle von der Admin-Oberfläche, unabhängig von der
                // serverseitigen Basis-Validierung (6.3). Kein srcdoc: das
                // erbte die Admin-CSP, die keine Inline-Styles erlaubt.
                preview.style.display = 'none';
                lpPreview.style.display = 'block';
                lpForm.elements.namedItem('body').value = editor.value;
                lpForm.elements.namedItem('page_style').value = cssEditor.value;
                lpForm.submit();
            } else {
                lpPreview.style.display = 'none';
                preview.style.display = 'block';
                preview.innerHTML = renderMarkdown(editor.value);
            }
        }

        btnEditor.addEventListener('click', showEditor);
        btnCss.addEventListener('click', showCss);
        btnPreview.addEventListener('click', showPreview);

        // Nur beim Neuanlegen vorhanden —
        // bei bestehenden Seiten ist data-content-type serverseitig fest gesetzt.
        typeRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                form.dataset.contentType = radio.value === 'html' ? 'html' : 'markdown';
                applyContentType();
            });
        });

        // Dateiname, URL-Slug und HTML-Title werden aus dem Seitentitel
        // vorbelegt. Sobald jemand ein Feld selbst ändert, gilt dessen Wert und
        // der Titel überschreibt es nicht mehr. Bestehende Seiten behalten
        // Dateiname und Slug, nur ein leerer HTML-Title wird vorbelegt.
        var isNew      = form.dataset.isNew === '1';
        var titleInput = document.getElementById('meta_heading');
        var fileInput  = document.getElementById('file_name');
        var slugInput  = document.getElementById('meta_slug');
        var htmlTitle  = document.getElementById('meta_title');

        function slugify(text) {
            return text.toLowerCase()
                .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        // Ein Feld ist "manuell", wenn es beim Laden schon einen Wert hatte
        // oder der Nutzer hineingetippt hat.
        function trackManual(input) {
            if (!input) return { manual: true };
            var state = { manual: input.value !== '' };
            input.addEventListener('input', function() { state.manual = true; });
            return state;
        }

        var fileState  = trackManual(fileInput);
        var slugState  = trackManual(slugInput);
        var titleState = trackManual(htmlTitle);

        titleInput.addEventListener('input', function() {
            var title = titleInput.value;
            var slug  = slugify(title);
            if (isNew && !fileState.manual) {
                fileInput.value = slug === '' ? '' : slug + '.md';
            }
            if (isNew && !slugState.manual) {
                slugInput.value = slug;
            }
            if (!titleState.manual) {
                htmlTitle.value = title;
            }
        });

        applyContentType();

        // Gegenstück zu is_safe_url() in index.php: javascript:/data:-URLs
        // dürfen weder in href noch in src landen. Trifft hier zwar nur den
        // eingeloggten Admin selbst, gehört aber zur gleichen Regel.
        function isSafeUrl(url) {
            // Whitespace und Steuerzeichen raus: Browser ignorieren sie beim
            // Auflösen der URL, "java<TAB>script:" wäre sonst ein Bypass.
            var probe = url.toLowerCase().replace(/[\u0000-\u0020\u007f]/g, '');
            if (probe === '') return false;
            if (probe[0] === '#' || probe[0] === '?' || probe[0] === '/') return true;
            if (/^(https?|mailto|tel):/.test(probe)) return true;
            var colon = probe.indexOf(':');
            if (colon !== -1) {
                var slash = probe.indexOf('/');
                if (slash === -1 || colon < slash) return false;
            }
            return true;
        }

        // Lightweight client-side Markdown renderer
        function renderMarkdown(md) {
            var html = md;

            // Escape HTML first (security)
            html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            // Fenced code blocks
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function(m, lang, code) {
                return '<pre><code>' + code + '</code></pre>';
            });

            // Inline code
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

            // Images — nur erlaubte URL-Schemata, sonst bleibt der Markdown-Text stehen
            html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function(m, alt, src) {
                return isSafeUrl(src) ? '<img src="' + src + '" alt="' + alt + '">' : m;
            });

            // Links
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(m, text, url) {
                return isSafeUrl(url) ? '<a href="' + url + '">' + text + '</a>' : m;
            });

            // Headings
            html = html.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

            // HR
            html = html.replace(/^---$/gm, '<hr>');

            // Bold + Italic
            html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

            // Unordered lists
            html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

            // Blockquotes
            html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');

            // Paragraphs
            html = html.replace(/\n\n(?!<)/g, '</p><p>');
            html = '<p>' + html + '</p>';
            html = html.replace(/<p>\s*(<[huo]|<pre|<block|<hr)/g, '$1');
            html = html.replace(/(<\/[huo]l>|<\/pre>|<\/blockquote>|<hr>)\s*<\/p>/g, '$1');
            html = html.replace(/<p>\s*<\/p>/g, '');

            return html;
        }
    })();
    </script>

