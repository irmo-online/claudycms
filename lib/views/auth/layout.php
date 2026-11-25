<?php
/**
 * Dokumentrahmen der Seiten vor dem Login (Anmelden, Passwort vergessen,
 * Passwort setzen, Link ungültig). Eingebunden über admin_render_auth(),
 * die $view und $title setzt.
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
    <title><?= e($title) ?></title>
    <?= admin_css_link() ?>
</head>
<body class="login-page">
<?php require __DIR__ . '/' . $view . '.php'; ?>
</body>
</html>
