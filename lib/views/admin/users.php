<?php
// Tab "Benutzer". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── TAB "BENUTZER" ──────────────────────────────────────── -->
    <?php
        // $me kommt aus dem Kopf des Templates.
        $users_list  = cms_load_users();
        $admin_count = cms_count_admins();
        usort($users_list, static fn(array $a, array $b): int => strcmp($a['email'], $b['email']));
    ?>

    <div class="settings-section">
        <h2>Nutzer einladen</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="invite_email">E-Mail-Adresse</label>
                <input type="email" id="invite_email" name="invite_email" required>
            </div>
            <div class="form-group">
                <label for="invite_role">Rolle</label>
                <select id="invite_role" name="invite_role">
                    <?= admin_enum_options([Role::ContentCreator, Role::Admin], Role::ContentCreator->value) ?>
                </select>
                <div class="settings-hint">
                    Content Creator: Seiten, Ordner und Bilder pflegen — kein Zugriff auf Einstellungen, Styles oder diesen Tab.
                    Admin: voller Zugriff, wie der bestehende Account.
                </div>
            </div>
            <button type="submit" name="invite_user" class="btn btn-primary btn-sm">Einladen</button>
        </form>
    </div>

    <div class="settings-section mt-section">
        <h2>Bestehende Nutzer <span class="section-note"><?= count($users_list) ?></span></h2>
        <div class="file-list file-list-flat">
            <?php foreach ($users_list as $u): ?>
                <?php
                    $is_last_admin   = $u['role'] === Role::Admin->value && $admin_count <= 1;
                    $needs_password  = $u['password_hash'] === null;
                    $is_self         = $u['id'] === ($me['id'] ?? null);
                ?>
                <div class="file-item">
                    <div class="file-item-name">
                        <?= e($u['email']) ?>
                        <?php if ($u['role'] === Role::Admin->value): ?><span class="badge badge-ai"><?= e(Role::Admin->label()) ?></span><?php else: ?><span class="text-muted-small"><?= e(Role::ContentCreator->label()) ?></span><?php endif; ?>
                        <?php if ($u['status'] === UserStatus::Gesperrt->value): ?><span class="badge badge-draft">Gesperrt</span><?php endif; ?>
                        <?php if ($is_self): ?><span class="text-muted-small">(du)</span><?php endif; ?>
                    </div>
                    <div class="file-item-actions">
                        <div class="file-item-meta">
                            <?= $u['last_login_at'] !== null ? 'Letzter Login: ' . e(date('d.m.Y H:i', strtotime($u['last_login_at']))) : 'Noch nie eingeloggt' ?>
                        </div>

                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button type="submit" name="send_user_token" class="btn btn-outline btn-sm">
                                <?= $needs_password ? 'Einladung erneut senden' : 'Passwort zurücksetzen' ?>
                            </button>
                        </form>

                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button type="submit" name="regenerate_user_token" class="btn btn-outline btn-sm">MCP-Token neu generieren</button>
                        </form>

                        <form method="post" class="inline-form"
                              data-confirm="<?= $u['status'] === UserStatus::Aktiv->value ? 'Diesen Nutzer sperren? Login und MCP-Zugriff funktionieren danach sofort nicht mehr.' : 'Diesen Nutzer entsperren?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <input type="hidden" name="new_status" value="<?= $u['status'] === UserStatus::Aktiv->value ? UserStatus::Gesperrt->value : UserStatus::Aktiv->value ?>">
                            <button type="submit" name="toggle_user_status" class="btn btn-outline btn-sm"
                                <?= ($is_last_admin && $u['status'] === UserStatus::Aktiv->value) ? 'disabled title="Letzter verbleibender Admin-Account kann nicht gesperrt werden"' : '' ?>>
                                <?= $u['status'] === UserStatus::Aktiv->value ? 'Sperren' : 'Entsperren' ?>
                            </button>
                        </form>

                        <form method="post" class="inline-form" data-confirm="Nutzer &quot;<?= e($u['email']) ?>&quot; wirklich unwiderruflich löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                            <button type="submit" name="delete_user" class="btn btn-danger btn-sm"
                                <?= $is_last_admin ? 'disabled title="Letzter verbleibender Admin-Account kann nicht gelöscht werden"' : '' ?>>Löschen</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

