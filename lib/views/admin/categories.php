<?php
// Abschnitt "Kategorien" im Tab "Einstellungen". Eingebunden von
// lib/views/admin/settings.php im Scope von admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
$categories_list  = cms_categories_load($cfg);
$categories_usage = cms_categories_usage();
?>
    <div class="settings-section mt-section">
        <h2>Kategorien</h2>
        <div class="settings-hint">
            Seiten lassen sich im Editor einer oder mehreren Kategorien zuordnen. Der Slug bleibt beim Umbenennen
            gleich, die Zuordnung in den Seiten also gültig. Beim Löschen werden die Seiten nicht verändert.
            Die Seiten einer Kategorie lassen sich per <code>[category-list category=&quot;slug&quot; style=&quot;list&quot;]</code>
            in jede Seite einbinden, mit <code>style=&quot;cards&quot;</code> als Karten mit Beschreibung und Bild.
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="categories_show_on_page" value="1"
                           <?= !empty($cfg['categories_show_on_page']) ? 'checked' : '' ?>>
                    Kategorien unter dem Seiteninhalt anzeigen
                </label>
                <div class="settings-hint">Zeigt auf jeder Seite die Namen ihrer Kategorien als Textzeile, ohne Links.</div>
            </div>
            <div class="settings-actions">
                <button type="submit" name="save_category_settings" class="btn btn-primary btn-sm">Speichern</button>
            </div>
        </form>

        <?php if ($categories_list === []): ?>
            <p class="settings-hint">Noch keine Kategorien angelegt.</p>
        <?php else: ?>
            <div class="file-list file-list-flat">
                <?php foreach ($categories_list as $c): ?>
                    <?php $used = $categories_usage[$c['slug']] ?? 0; ?>
                    <div class="file-item">
                        <div class="file-item-name">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="category_slug" value="<?= e($c['slug']) ?>">
                                <input type="text" name="category_name" value="<?= e($c['name']) ?>"
                                       maxlength="<?= ADMIN_CATEGORY_NAME_MAX ?>" aria-label="Name der Kategorie">
                                <button type="submit" name="rename_category" class="btn btn-secondary btn-sm">Umbenennen</button>
                            </form>
                        </div>
                        <div class="file-item-actions">
                            <div class="file-item-meta">
                                <code><?= e($c['slug']) ?></code> · auf <?= (int)$used ?> Seite(n)
                            </div>
                            <form method="post" class="inline-form"
                                  data-confirm="Kategorie &quot;<?= e($c['name']) ?>&quot; wirklich löschen?<?= $used > 0 ? ' Sie ist noch auf ' . (int)$used . ' Seite(n) eingetragen, die Seiten bleiben unverändert.' : '' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="category_slug" value="<?= e($c['slug']) ?>">
                                <button type="submit" name="delete_category" class="btn btn-danger btn-sm">Löschen</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="mt-section">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="category_name_new">Neue Kategorie</label>
                <input type="text" id="category_name_new" name="category_name"
                       maxlength="<?= ADMIN_CATEGORY_NAME_MAX ?>" placeholder="z. B. SEO">
            </div>
            <div class="settings-actions">
                <button type="submit" name="create_category" class="btn btn-primary btn-sm">Anlegen</button>
            </div>
        </form>
    </div>
