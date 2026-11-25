<?php
/**
 * Seitenrahmen des Admin-Bereichs: Dokumentkopf, Sidebar, Topbar,
 * Meldungen und Reiter-Leiste. Bindet danach die View $view ein
 * (lib/views/admin/<$view>.php), null heißt: nur der Rahmen.
 *
 * Wird von admin.php im globalen Scope eingebunden, alle Variablen des
 * Controllers ($action, $message, $error, $me, $admin_cfg …) sind hier und
 * in den Views direkt sichtbar.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin — Content Manager</title>
    <?= admin_css_link() ?>
</head>
<body>

<div class="admin-shell">
<aside class="admin-sidebar">
    <div class="admin-sidebar-logo">
        <?php if ($admin_site_logo !== ''): ?>
            <img src="<?= e(SITE_URL) ?>/assets/branding/<?= e($admin_site_logo) ?>" alt="<?= e($admin_cfg['site_title']) ?>">
        <?php else: ?>
            <?= e($admin_cfg['site_title']) ?>
        <?php endif; ?>
    </div>
    <nav class="admin-nav">
        <a href="admin.php" class="<?= in_array($action, ['list', 'new', 'edit'], true) ? 'active' : '' ?>"><?= admin_nav_icon('pages') ?> Alle Seiten</a>
        <a href="admin.php?action=images" class="<?= $action === 'images' ? 'active' : '' ?>"><?= admin_nav_icon('media') ?> Medien</a>
        <?php if (cms_role_can($admin_role, 'settings')): ?>
            <a href="admin.php?action=ai" class="<?= $action === 'ai' ? 'active' : '' ?>"><?= admin_nav_icon('ai') ?> KI-Anbindung</a>
            <a href="admin.php?action=site" class="<?= ($action === 'site' || $action === 'redirects') ? 'active' : '' ?>"><?= admin_nav_icon('site') ?> Website &amp; SEO</a>
            <a href="admin.php?action=settings" class="<?= $action === 'settings' ? 'active' : '' ?>"><?= admin_nav_icon('settings') ?> Einstellungen</a>
        <?php endif; ?>
        <?php if (cms_role_can($admin_role, 'users')): ?>
            <a href="admin.php?action=users" class="<?= $action === 'users' ? 'active' : '' ?>"><?= admin_nav_icon('users') ?> Benutzer</a>
        <?php endif; ?>
    </nav>
</aside>
<div class="admin-main">
<header class="admin-topbar">
    <div class="admin-topbar-links">
        <a href="<?= e(SITE_URL) ?>" target="_blank">Website ansehen</a>
        <a href="admin.php?action=logout">Abmelden</a>
    </div>
    <a href="admin.php?action=profile" class="admin-topbar-profile"><?= admin_nav_icon('profile') ?> Mein Profil</a>
</header>

<div class="container">

<?php if ($message): ?>
    <div class="msg success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="msg error"><?= e($error) ?></div>
<?php endif; ?>

<?php
    // Reiter-Leiste mit Linie auf JEDER Admin-Seite, nicht mehr nur bei
    // "Website & SEO": verhindert einen Layout-Sprung beim Wechseln
    // zwischen den Seiten und macht jede
    // Seite bei Bedarf leicht um weitere Reiter erweiterbar. Seiten ohne
    // eigene Unterseiten zeigen genau einen, immer aktiven Reiter mit dem
    // Seitentitel — der übernimmt gleichzeitig die Rolle der bisher
    // fehlenden Überschrift (z. B. hatte "KI-Anbindung" bisher keine).
    $single_tab_titles = [
        'list'     => 'Alle Seiten',
        'images'   => 'Medien',
        'ai'       => 'KI-Anbindung',
        'settings' => 'Einstellungen',
        'users'    => 'Benutzer',
        'profile'  => 'Mein Profil',
    ];
?>
<?php if ($action === 'site' || $action === 'redirects'): ?>
    <div class="page-tabs">
        <a href="admin.php?action=site&tab=website" class="<?= ($action === 'site' && ($_GET['tab'] ?? 'website') === 'website') ? 'active' : '' ?>">Website</a>
        <a href="admin.php?action=site&tab=seo" class="<?= ($action === 'site' && ($_GET['tab'] ?? '') === 'seo') ? 'active' : '' ?>">SEO</a>
        <a href="admin.php?action=redirects" class="<?= $action === 'redirects' ? 'active' : '' ?>">Redirects</a>
    </div>
<?php elseif (isset($single_tab_titles[$action])): ?>
    <div class="page-tabs">
        <a href="admin.php?action=<?= $action ?>" class="active"><?= e($single_tab_titles[$action]) ?></a>
    </div>
<?php elseif ($action === 'edit' || $action === 'new'): ?>
    <div class="page-tabs">
        <a href="admin.php?action=<?= $action ?><?= ($action === 'edit' && isset($_GET['file'])) ? '&file=' . urlencode($_GET['file']) : '' ?>" class="active"><?= $action === 'new' ? 'Neue Seite' : 'Seite bearbeiten' ?></a>
    </div>
<?php endif; ?>

<?php if ($view !== null) { require __DIR__ . '/' . $view . '.php'; } ?>

</div>
</div><!-- /.admin-main -->
</div><!-- /.admin-shell -->

<?php // Ersetzt die früheren onclick/onsubmit-Attribute: Inline-Handler sind
      // per CSP nicht mehr erlaubt (nur Skripte mit passendem Nonce laufen). ?>
<script nonce="<?= e(cms_csp_nonce()) ?>">
(function() {
    document.querySelectorAll('form[data-confirm]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm(form.dataset.confirm)) e.preventDefault();
        });
    });
    document.querySelectorAll('button[data-confirm]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm(btn.dataset.confirm)) e.preventDefault();
        });
    });
})();
</script>

</body>
</html>
