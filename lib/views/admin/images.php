<?php
// Tab "Medien". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── IMAGE MANAGER ─────────────────────────────────────────────── -->
    <?php $images = cms_list_images(); ?>

    <div class="editor-panel">
        <h2>Bilder verwalten (<?= count($images) ?>)</h2>

        <form method="post" enctype="multipart/form-data" class="upload-form">
            <?= csrf_field() ?>
            <div class="form-group flush">
                <label>Bild hochladen (max. <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?> MB — JPG, PNG, GIF, WebP)</label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
            </div>
            <button type="submit" name="upload_image" class="btn btn-primary btn-sm">Hochladen</button>
        </form>

        <?php if (!empty($images)): ?>
            <div class="image-grid">
                <?php foreach ($images as $img): ?>
                    <?php $img_name = $img['filename']; ?>
                    <?php // Pfad als data-Attribut statt im onclick: kein JS-String-Kontext
                          // (dort würde htmlspecialchars ein ' nicht zuverlässig neutralisieren)
                          // und keine Inline-Handler, die die CSP blocken würde. ?>
                    <div class="image-thumb" title="<?= e($img_name) ?>" data-copy-path="assets/images/<?= e($img_name) ?>">
                        <img src="<?= e(SITE_URL) ?>/assets/images/<?= e($img_name) ?>" alt="<?= e($img_name) ?>" loading="lazy">
                        <span class="image-name"><?= e($img_name) ?></span>
                        <form class="inline-form" method="post" data-confirm="Bild löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="image_name" value="<?= e($img_name) ?>">
                            <button type="submit" name="delete_image" class="image-delete" title="Löschen">&times;</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="hint-below">
                Klicke auf ein Bild, um den Pfad zu kopieren.
            </p>
        <?php else: ?>
            <p class="empty-note">Noch keine Bilder hochgeladen.</p>
        <?php endif; ?>
    </div>

    <script nonce="<?= e(cms_csp_nonce()) ?>">
    function copyImagePath(path) {
        navigator.clipboard.writeText(path).then(function() {
            var msg = document.createElement('div');
            msg.className = 'msg success';
            msg.textContent = 'Pfad kopiert: ' + path;
            document.querySelector('.container').insertBefore(msg, document.querySelector('.container').firstChild);
            setTimeout(function() { msg.remove(); }, 2000);
        });
    }

    document.querySelectorAll('.image-thumb[data-copy-path]').forEach(function(thumb) {
        thumb.addEventListener('click', function(e) {
            // Klick auf den Löschen-Button nicht als "Pfad kopieren" werten
            if (e.target.closest('form')) return;
            copyImagePath(thumb.dataset.copyPath);
        });
    });
    </script>

