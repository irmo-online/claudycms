<?php
/**
 * Formular für ein neues Passwort bei gültigem Token.
 * $is_new unterscheidet die Einladung eines frischen Nutzers ("Passwort
 * festlegen") vom Zurücksetzen eines bestehenden. $title ist die
 * passende Überschrift, $token und $error (?string) kommen aus admin.php.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <form class="login-form" method="post" action="admin.php?action=reset_password&token=<?= urlencode($token) ?>">
        <h1><?= e($title) ?></h1>
        <?php if ($error): ?>
            <div class="msg error"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="password" name="new_password" placeholder="<?= $is_new ? 'Passwort' : 'Neues Passwort' ?>" minlength="12" autofocus required>
        <input type="password" name="new_password_confirm" placeholder="<?= $is_new ? 'Passwort bestätigen' : 'Neues Passwort bestätigen' ?>" minlength="12" required>
        <button type="submit"><?= $is_new ? 'Passwort festlegen' : 'Passwort speichern' ?></button>
        <p class="login-form-footer"><a href="admin.php">Zurück zum Login</a></p>
    </form>
