<?php
/**
 * Login-Formular. Variablen: $error (?string).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <form class="login-form" method="post">
        <h1>Admin Login</h1>
        <?php if ($error): ?>
            <div class="msg error"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <input type="email" name="admin_email" placeholder="E-Mail-Adresse" autofocus required>
        <input type="password" name="admin_password" placeholder="Passwort" required>
        <button type="submit">Anmelden</button>
        <p class="login-form-footer"><a href="admin.php?action=forgot_password">Passwort vergessen?</a></p>
    </form>
