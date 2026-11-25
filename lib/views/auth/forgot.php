<?php
/**
 * Formular "Passwort vergessen". Variablen: $message (?array).
 *
 * $message ist entweder null (erster Aufruf, GET) oder das Ergebnis von
 * handle_forgot_password_request(). Nach einer erfolgreichen Anfrage bleibt
 * das Formular absichtlich verschwunden — die generische Meldung deckt den
 * Erfolgsfall wie den Nicht-Treffer gleichermassen ab (Enumeration-Schutz,
 * Spec 6.2), ein zweiter Versuch geht über den Link zurück zum Formular.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

$show_form = $message === null || $message['type'] === 'error';
?>
    <form class="login-form" method="post" action="admin.php?action=forgot_password">
        <h1>Passwort vergessen</h1>
        <?php if ($message): ?>
            <div class="msg <?= $message['type'] === 'success' ? 'success' : 'error' ?>"><?= e($message['text']) ?></div>
        <?php endif; ?>
        <?php if ($show_form): ?>
            <?= csrf_field() ?>
            <input type="email" name="forgot_email" placeholder="E-Mail-Adresse" autofocus required>
            <button type="submit">Link anfordern</button>
        <?php endif; ?>
        <p class="login-form-footer"><a href="admin.php">Zurück zum Login</a></p>
    </form>
