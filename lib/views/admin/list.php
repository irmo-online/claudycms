<?php
// Tab "Alle Seiten". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── FILE LIST ─────────────────────────────────────────────────── -->
    <?php
        $subdirs    = cms_list_subdirs();
        $categories = cms_categories_load();
        $list_state = admin_page_list_state($categories);
        $all_rows   = admin_page_list_rows($categories);
        $rows       = admin_page_list_sort(
            admin_page_list_filter($all_rows, $list_state['category']),
            $list_state['sort'],
            $list_state['dir']
        );
    ?>

    <div class="file-list">
        <div class="file-list-header">
            <h2>Seiten (<?= $list_state['category'] !== ''
                ? count($rows) . ' von ' . count($all_rows)
                : count($all_rows) ?>)</h2>
            <div class="file-list-tools">
                <?php if ($categories !== []): ?>
                    <?php // GET-Formular: der Filter steht in der URL, wie die Sortierung. ?>
                    <form method="get" action="admin.php" class="page-filter">
                        <?php if ($list_state['sort'] !== null): ?>
                            <input type="hidden" name="sort" value="<?= e($list_state['sort']->value) ?>">
                            <input type="hidden" name="dir" value="<?= e($list_state['dir']->value) ?>">
                        <?php endif; ?>
                        <label for="page_filter_category">Kategorie</label>
                        <select id="page_filter_category" name="category">
                            <option value="">Alle</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= e($c['slug']) ?>"<?= $c['slug'] === $list_state['category'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline btn-sm">Filtern</button>
                    </form>
                <?php endif; ?>
                <a href="admin.php?action=new" class="btn btn-primary btn-sm">+ Neue Seite</a>
            </div>
        </div>

        <?php if ($all_rows === []): ?>
            <div class="file-item"><span class="file-item-name">Noch keine Seiten vorhanden.</span></div>
        <?php elseif ($rows === []): ?>
            <div class="file-item">
                <span class="file-item-name">Keine Seiten in dieser Kategorie.</span>
                <a href="<?= e(admin_page_list_url($list_state['sort'], $list_state['dir'], '')) ?>">Alle Seiten anzeigen</a>
            </div>
        <?php else: ?>
            <table class="page-table">
                <thead>
                    <tr>
                        <?= admin_page_list_sort_th('Seitenname', PageListSort::Name, $list_state) ?>
                        <?php if ($categories !== []): ?>
                            <th scope="col">Kategorien</th>
                        <?php endif; ?>
                        <?= admin_page_list_sort_th('Geändert', PageListSort::Date, $list_state) ?>
                        <th scope="col" class="page-table-actions">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $rel = $row['rel'];
                        // KI-Kennzeichnung, und für menschliche Bearbeitung
                        // dasselbe Badge mit der E-Mail-Adresse statt "ai".
                        $by_ai    = $row['edited_by'] === 'ai';
                        $by_human = $row['edited_by'] !== '' && !$by_ai;
                        // Der Tooltip am Badge nennt nur einen Zeitpunkt, der
                        // zur Kennzeichnung gehört, nie die Dateizeit.
                        $marked_at = $row['edited_from_file'] ? null : $row['edited'];
                    ?>
                    <tr>
                        <td class="page-table-name">
                            <div class="file-item-name">
                                <a href="admin.php?action=edit&file=<?= urlencode($rel) ?>"><?= e($row['title']) ?></a>
                                <?php if (strpos($rel, '/') !== false): ?>
                                    <span class="folder"><?= e(dirname($rel)) ?>/</span>
                                <?php endif; ?>
                                <?php if ($row['is_draft']): ?>
                                    <span class="badge badge-draft" title="Nicht öffentlich erreichbar">Entwurf</span>
                                <?php endif; ?>
                                <?php if ($row['is_landingpage']): ?>
                                    <span class="badge badge-landingpage" title="Rohes HTML statt Markdown">Landingpage</span>
                                <?php endif; ?>
                                <?php if ($by_ai): ?>
                                    <span class="badge badge-ai" title="<?= $marked_at !== null
                                        ? 'Zuletzt von der KI geändert am ' . e($marked_at->format('d.m.Y \u\m H:i')) . ' Uhr'
                                        : 'Zuletzt von der KI geändert' ?>">von KI</span>
                                <?php elseif ($by_human): ?>
                                    <span class="badge badge-ai" title="<?= $marked_at !== null
                                        ? 'Zuletzt geändert am ' . e($marked_at->format('d.m.Y \u\m H:i')) . ' Uhr'
                                        : 'Zuletzt geändert' ?>">von <?= e($row['edited_by']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="file-item-meta">
                                <?php if ($row['slug'] !== ''): ?>
                                    <a href="<?= e(SITE_URL) ?>/<?= e($row['slug']) ?>" target="_blank" class="link-muted">/<?= e($row['slug']) ?></a>
                                <?php else: ?>
                                    <?= e($rel) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php if ($categories !== []): ?>
                            <td class="page-table-categories" data-label="Kategorien"><?= e(implode(', ', $row['category_names'])) ?></td>
                        <?php endif; ?>
                        <td class="page-table-date" data-label="Geändert">
                            <?php if ($row['edited'] === null): ?>
                                –
                            <?php elseif ($row['edited_from_file']): ?>
                                <span title="Dateizeit. Die Seite wurde noch nie im Backend oder von der KI gespeichert."><?= e($row['edited']->format('d.m.Y H:i')) ?></span>
                            <?php else: ?>
                                <?= e($row['edited']->format('d.m.Y H:i')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="page-table-actions">
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="filepath" value="<?= e($rel) ?>">
                                <?php if ($row['is_draft']): ?>
                                    <button type="submit" name="publish_file" class="btn btn-primary btn-sm">
                                        Veröffentlichen
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="unpublish_file" class="btn btn-outline btn-sm"
                                            data-confirm="Diese Seite ist danach öffentlich nicht mehr erreichbar. Fortfahren?">
                                        Auf Entwurf
                                    </button>
                                <?php endif; ?>
                                <button type="submit" name="delete_file" class="btn btn-danger btn-sm"
                                        data-confirm="Seite &quot;<?= e($row['title']) ?>&quot; wirklich löschen? Das lässt sich nicht rückgängig machen.">
                                    Löschen
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Folder management -->
    <form method="post" class="folder-form">
        <?= csrf_field() ?>
        <div class="form-group flush">
            <label for="folder_name">Neuer Unterordner</label>
            <input type="text" id="folder_name" name="folder_name" placeholder="z.B. blog" pattern="[a-z0-9_-]+" required>
        </div>
        <button type="submit" name="create_folder" class="btn btn-outline btn-sm">Ordner erstellen</button>
        <?php if (!empty($subdirs)): ?>
            <span class="inline-note">
                Vorhanden: <?= e(implode(', ', $subdirs)) ?>
            </span>
        <?php endif; ?>
    </form>

