<?php
// Tab "Einstellungen". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── EINSTELLUNGEN (nur Rolle admin) — reduziert auf
         technische Betriebseinstellungen: Website/SEO/Redirects sind jetzt
         "Website & SEO", Passwort/E-Mail/KI-Zugang sind "Mein Profil". Jeder
         Abschnitt hat sein eigenes <form> mit eigenem Handler (s.o.), damit
         das Speichern eines Abschnitts nicht die Werte der anderen
         überschreibt. ─────────────────────────────────────────────────── -->
    <?php $cfg = load_config(); ?>

    <div class="settings-grid">
        <div>
            <div class="settings-section">
                <h2>Uploads</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="max_upload_mb">Max. Uploadgröße (MB)</label>
                        <input type="number" id="max_upload_mb" name="max_upload_mb"
                               value="<?= (int)round($cfg['max_upload_size'] / 1024 / 1024) ?>"
                               min="1" max="50">
                        <div class="settings-hint">Erlaubt: 1–50 MB. Aktuell: <?= round($cfg['max_upload_size'] / 1024 / 1024, 1) ?> MB.</div>
                    </div>

                    <div class="form-group">
                        <label>Erlaubte Bildformate</label>
                        <input type="text" value="JPG, PNG, GIF, WebP" disabled>
                        <div class="settings-hint">Aus Sicherheitsgründen fest im Code definiert.</div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" name="save_upload_settings" class="btn btn-primary btn-sm">Speichern</button>
                    </div>
                </form>
            </div>
        </div>

        <div>
            <div class="settings-section">
                <h2>Sicherheit</h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="max_login_attempts">Max. Fehlversuche</label>
                        <input type="number" id="max_login_attempts" name="max_login_attempts"
                               value="<?= (int)$cfg['max_login_attempts'] ?>"
                               min="3" max="20">
                        <div class="settings-hint">Nach dieser Anzahl falscher Passwörter wird das Login gesperrt. Erlaubt: 3–20.</div>
                    </div>

                    <div class="form-group">
                        <label for="login_lockout_minutes">Sperrzeit (Minuten)</label>
                        <input type="number" id="login_lockout_minutes" name="login_lockout_minutes"
                               value="<?= (int)round($cfg['login_lockout_time'] / 60) ?>"
                               min="1" max="1440">
                        <div class="settings-hint">Wartezeit nach zu vielen Fehlversuchen. Aktuell: <?= round($cfg['login_lockout_time'] / 60) ?> Minuten.</div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" name="save_security_settings" class="btn btn-primary btn-sm">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div><!-- /.settings-grid -->

    <div class="settings-section mt-section">
        <h2>Kontaktformular &amp; E-Mail (SMTP)</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="settings-grid">
                <!-- Linke Hälfte: Kontaktformular -->
                <div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="contact_enabled" value="1"
                                   <?= !empty($cfg['contact_enabled']) ? 'checked' : '' ?>>
                            Kontaktformular aktivieren
                        </label>
                        <div class="settings-hint">Ermöglicht die Einbindung per <code>[contact-form]</code> in Markdown-Seiten.</div>
                    </div>

                    <div class="form-group">
                        <label for="contact_to_email">Empfänger-E-Mail-Adresse</label>
                        <input type="email" id="contact_to_email" name="contact_to_email"
                               value="<?= e($cfg['contact_to_email'] ?? '') ?>"
                               placeholder="deine@email.de" maxlength="200">
                        <div class="settings-hint">An diese Adresse werden die Kontaktanfragen gesendet.</div>
                    </div>
                </div>

                <!-- Rechte Hälfte: SMTP -->
                <div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_from_email">Absender-E-Mail</label>
                            <input type="email" id="smtp_from_email" name="smtp_from_email"
                                   value="<?= e($cfg['smtp_from_email'] ?? '') ?>"
                                   placeholder="noreply@example.com" maxlength="200">
                        </div>
                        <div class="form-group">
                            <label for="smtp_from_name">Absender-Name</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name"
                                   value="<?= e($cfg['smtp_from_name'] ?? '') ?>"
                                   placeholder="Meine Website" maxlength="100">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_host">SMTP-Host</label>
                            <input type="text" id="smtp_host" name="smtp_host"
                                   value="<?= e($cfg['smtp_host'] ?? '') ?>"
                                   placeholder="mail.example.com" maxlength="253">
                            <div class="settings-hint">z.B. <code>mail.all-inkl.com</code></div>
                        </div>
                        <div class="form-group">
                            <label for="smtp_user">SMTP-Benutzername</label>
                            <input type="text" id="smtp_user" name="smtp_user"
                                   value="<?= e($cfg['smtp_user'] ?? '') ?>"
                                   placeholder="user@example.com" maxlength="200" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp_port">Port</label>
                            <select id="smtp_port" name="smtp_port">
                                <option value="587" <?= ($cfg['smtp_port'] ?? 587) == 587 ? 'selected' : '' ?>>587 — STARTTLS (empfohlen)</option>
                                <option value="465" <?= ($cfg['smtp_port'] ?? 587) == 465 ? 'selected' : '' ?>>465 — SSL/TLS</option>
                                <option value="25"  <?= ($cfg['smtp_port'] ?? 587) == 25  ? 'selected' : '' ?>>25 — unverschlüsselt</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="smtp_secure">Verschlüsselung</label>
                            <select id="smtp_secure" name="smtp_secure">
                                <?= admin_enum_options(SmtpSecurity::cases(), (string)($cfg['smtp_secure'] ?? SmtpSecurity::Tls->value)) ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtp_pass">SMTP-Passwort</label>
                        <?php // Nie vorausfüllen: ein type="password"-Feld verbirgt nur die Anzeige,
                              // der Wert stünde im Quelltext. Leeres Feld = Passwort bleibt unverändert. ?>
                        <input type="password" id="smtp_pass" name="smtp_pass" value=""
                               autocomplete="new-password" placeholder="••••••••">
                        <div class="settings-hint">
                            <?php if (($cfg['smtp_pass'] ?? '') !== ''): ?>
                                Ein Passwort ist gesetzt. Feld leer lassen, um es unverändert zu übernehmen.
                            <?php else: ?>
                                Noch kein Passwort gesetzt.
                            <?php endif; ?>
                            Es wird im Klartext in <code>config/config.php</code> abgelegt — diese Datei ist
                            per <code>.htaccess</code> vor HTTP-Zugriff und per <code>.gitignore</code> vor Git geschützt.
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-actions mt-section">
                <button type="submit" name="save_contact_settings" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/categories.php'; ?>
