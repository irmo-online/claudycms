<?php
// Reiter "Redirects" unter "Website & SEO". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── TAB "REDIRECTS" — optisch der dritte Reiter unter
         "Website & SEO" (Sidebar-Link zeigt auf action=site, dessen Tab-Leiste
         verlinkt hierher). Technisch unverändert eine eigene action mit
         eigenem require_role('redirects') weiter oben, keine Vermischung mit
         den site-Handlern. ──────────────────────────────────────────────── -->
    <?php
        $redirects_list = cms_load_redirects();
        usort($redirects_list, static fn(array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));
    ?>

    <div class="settings-section">
        <h2>Redirect anlegen</h2>
        <p class="settings-hint">
            Alte und neue URL als interner Pfad mit führendem "/", z. B. "/alte-seite" → "/neue-seite".
            Kein Schema/Host — externe Ziele werden nicht unterstützt. Ein Redirect darf keine noch
            existierende Seite verdecken.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="redirect_from">Alte URL</label>
                <input type="text" id="redirect_from" name="redirect_from" placeholder="/alte-seite" required>
            </div>
            <div class="form-group">
                <label for="redirect_to">Neue URL</label>
                <input type="text" id="redirect_to" name="redirect_to" placeholder="/neue-seite" required>
            </div>
            <button type="submit" name="create_redirect" class="btn btn-primary btn-sm">Anlegen</button>
        </form>
    </div>

    <div class="settings-section mt-section">
        <h2>Bestehende Redirects <span class="section-note"><?= count($redirects_list) ?></span></h2>
        <div class="file-list file-list-flat">
            <?php foreach ($redirects_list as $r): ?>
                <div class="file-item">
                    <div class="file-item-name">
                        <?= e($r['from']) ?> &rarr; <?= e($r['to']) ?>
                    </div>
                    <div class="file-item-actions">
                        <div class="file-item-meta">
                            angelegt am <?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?>
                            von <?= e($r['created_by']) ?>
                        </div>
                        <form method="post" class="inline-form" data-confirm="Redirect &quot;<?= e($r['from']) ?>&quot; wirklich löschen? Die alte URL liefert danach wieder 404.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_id" value="<?= e($r['id']) ?>">
                            <button type="submit" name="delete_redirect" class="btn btn-danger btn-sm">Löschen</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($redirects_list === []): ?>
                <div class="file-item"><div class="file-item-name text-muted">Noch keine Redirects angelegt.</div></div>
            <?php endif; ?>
        </div>
    </div>

