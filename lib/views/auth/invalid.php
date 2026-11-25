<?php
/** Fehlerseite für einen ungültigen/abgelaufenen Reset-Token (Spec 5.3). */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <div class="login-form">
        <h1>Link ungültig</h1>
        <div class="msg error">Dieser Link ist ungültig oder abgelaufen.</div>
        <p class="login-form-footer"><a href="admin.php?action=forgot_password">Neuen Link anfordern</a></p>
    </div>
