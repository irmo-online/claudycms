<?php
// Seite "Mein Profil". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── MEIN PROFIL (Spec 5.3, eigene Seite für JEDE
         Rolle, nicht mehr nur die reduzierte Alternative zu "Einstellungen"
         für Rollen ohne 'settings'-Recht — Inhalt für admin und
         content_creator identisch: eigene E-Mail, eigenes Passwort, eigener
         KI-Zugang. Website-/SMTP-/Sicherheits-Felder gehören zu
         "Website & SEO"/"Einstellungen" und tauchen hier gar nicht auf. -->
    <?php $own_token_set = !empty($me['mcp_token_hash']); // $me kommt aus dem Kopf des Templates ?>

    <div class="settings-section">
        <h2>Mein Profil</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="profile_email">E-Mail-Adresse</label>
                <input type="email" id="profile_email" name="profile_email"
                       value="<?= e($me['email']) ?>" required>
            </div>

            <hr class="settings-divider">

            <div class="form-group">
                <label for="new_password">Neues Passwort <span class="section-note">optional</span></label>
                <input type="password" id="new_password" name="new_password"
                       autocomplete="new-password" minlength="12">
                <div class="settings-hint">Mindestens 12 Zeichen. Feld freilassen, um das Passwort nicht zu ändern.</div>
            </div>
            <div class="form-group">
                <label for="new_password_confirm">Neues Passwort bestätigen</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm"
                       autocomplete="new-password">
            </div>

            <hr class="settings-divider">

            <div class="form-group">
                <label for="current_password">Aktuelles Passwort</label>
                <input type="password" id="current_password" name="current_password"
                       autocomplete="current-password">
                <div class="settings-hint">Zur Bestätigung nötig, wenn du E-Mail oder Passwort änderst.</div>
            </div>

            <div class="settings-actions mt-section">
                <button type="submit" name="save_profile" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>

    <div class="settings-section mt-section">
        <h2>Mein KI-Zugang (MCP)</h2>
        <?php if (!$own_token_set): ?>
            <p class="settings-hint">Noch kein eigener Connector-Token eingerichtet.</p>
        <?php else: ?>
            <p class="settings-hint">
                Ein persönlicher Connector-Token ist eingerichtet. Aus Sicherheitsgründen wird er nach
                dem Erzeugen nicht erneut angezeigt — bei Bedarf neu generieren.
            </p>
        <?php endif; ?>
        <form method="post"<?= $own_token_set ? ' data-confirm="Bestehende Connector-Verbindungen in Claude werden dadurch sofort ungültig. Fortfahren?"' : '' ?>>
            <?= csrf_field() ?>
            <button type="submit" name="regenerate_own_token" class="btn btn-primary btn-sm">
                <?= $own_token_set ? 'Token neu generieren' : 'Token erzeugen' ?>
            </button>
        </form>
    </div>

