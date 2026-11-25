<?php
// Reiter "Website" und "SEO" unter "Website & SEO". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── WEBSITE & SEO (nur Rolle admin) — Reiter Website/SEO/Redirects.
         Redirects bleibt technisch die eigenständige action=redirects weiter
         unten (eigene Handler, eigene require_role-Prüfung) — hier nur als
         dritter Tab-Link verlinkt, keine doppelte Datenhaltung. -->
    <?php
        $cfg = load_config();
        // ?? -Fallbacks, weil bestehende Installationen die vier
        // Logo/Favicon-Schlüssel erst nach einem manuellen Nachtrag in
        // config/config.php haben (Fallback-Muster für später ergänzte Config-Schlüssel).
        $site_logo          = trim((string)($cfg['site_logo'] ?? ''));
        $site_favicon       = trim((string)($cfg['site_favicon'] ?? ''));
        $site_logo_position = (string)($cfg['site_logo_position'] ?? 'links');
        $site_logo_display  = (string)($cfg['site_logo_display'] ?? 'logo_only');
        $site_tab = ($_GET['tab'] ?? 'website') === 'seo' ? 'seo' : 'website';
    ?>

    <?php if ($site_tab === 'website'): ?>

        <div class="settings-section">
            <h2>Website</h2>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="site_title">Seitenname</label>
                    <input type="text" id="site_title" name="site_title"
                           value="<?= e($cfg['site_title']) ?>"
                           placeholder="Meine Website" required>
                    <div class="settings-hint">Wird im Browser-Tab und im Seitenkopf angezeigt.</div>
                </div>

                <div class="form-group">
                    <label for="site_domain">Domain</label>
                    <input type="text" id="site_domain" name="site_domain"
                           value="<?= e($cfg['site_domain']) ?>"
                           placeholder="https://www.example.com">
                    <div class="settings-hint">
                        Vollständige URL ohne abschließenden Slash. Leer lassen für automatische Erkennung.
                        <strong>Empfohlen:</strong> Immer setzen, um Host-Header-Injection zu verhindern.
                    </div>
                </div>

                <div class="form-group">
                    <label for="default_slug">Standard-Startseite (Slug)</label>
                    <input type="text" id="default_slug" name="default_slug"
                           value="<?= e($cfg['default_slug']) ?>"
                           placeholder="about">
                    <div class="settings-hint">Slug der Seite, die bei Aufruf der Root-URL angezeigt wird.</div>
                </div>

                <div class="settings-actions">
                    <button type="submit" name="save_website_settings" class="btn btn-primary btn-sm">Speichern</button>
                </div>
            </form>
        </div>

        <!-- Logo & Favicon — eigener Block mit eigenen <form>-Tags, bewusst
             ausserhalb des Formulars oben: verschachtelte <form>-Elemente
             sind ungültiges HTML, und Datei-Uploads brauchen ohnehin ein
             eigenes enctype="multipart/form-data" statt des Textform-
             Formulars oben. -->
        <div class="settings-section mt-section">
            <h2>Logo &amp; Favicon</h2>

            <div class="settings-grid">
                <!-- Logo -->
                <div>
                    <h3 class="branding-subheading">Logo</h3>
                    <?php if ($site_logo !== ''): ?>
                        <div class="form-group">
                            <img src="<?= e(SITE_URL) ?>/assets/branding/<?= e($site_logo) ?>?v=<?= (int)@filemtime(BRANDING_DIR . '/' . $site_logo) ?>"
                                 alt="Aktuelles Logo" class="branding-preview-logo">
                        </div>
                        <form class="inline-form" method="post" data-confirm="Logo wirklich löschen?">
                            <?= csrf_field() ?>
                            <button type="submit" name="delete_logo" class="btn btn-outline btn-sm">Logo löschen</button>
                        </form>
                    <?php else: ?>
                        <p class="settings-hint">Noch kein Logo hochgeladen — der Text-Titel wird stattdessen angezeigt.</p>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="upload-form mt-small">
                        <?= csrf_field() ?>
                        <div class="form-group flush">
                            <label>Logo <?= $site_logo !== '' ? 'ersetzen' : 'hochladen' ?> (max. <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?> MB — JPG, PNG, GIF, WebP)</label>
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp" required>
                            <div class="settings-hint">Empfohlene Größe: zwischen 100×100 und 300×300 px.</div>
                        </div>
                        <button type="submit" name="upload_logo" class="btn btn-primary btn-sm">Hochladen</button>
                    </form>
                </div>

                <!-- Favicon -->
                <div>
                    <h3 class="branding-subheading">Favicon</h3>
                    <?php if ($site_favicon !== ''): ?>
                        <div class="form-group">
                            <img src="<?= e(SITE_URL) ?>/assets/branding/<?= e($site_favicon) ?>?v=<?= (int)@filemtime(BRANDING_DIR . '/' . $site_favicon) ?>"
                                 alt="Aktuelles Favicon" class="branding-preview-favicon">
                        </div>
                        <form class="inline-form" method="post" data-confirm="Favicon wirklich löschen?">
                            <?= csrf_field() ?>
                            <button type="submit" name="delete_favicon" class="btn btn-outline btn-sm">Favicon löschen</button>
                        </form>
                    <?php else: ?>
                        <p class="settings-hint">Noch kein Favicon hochgeladen — der Browser zeigt sein Standard-Icon.</p>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="upload-form mt-small">
                        <?= csrf_field() ?>
                        <div class="form-group flush">
                            <label>Favicon <?= $site_favicon !== '' ? 'ersetzen' : 'hochladen' ?> (max. <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?> MB — JPG, PNG, GIF, WebP; quadratisch empfohlen)</label>
                            <input type="file" name="favicon" accept="image/jpeg,image/png,image/gif,image/webp" required>
                            <div class="settings-hint">Empfohlene Größe: 48×48 px.</div>
                        </div>
                        <button type="submit" name="upload_favicon" class="btn btn-primary btn-sm">Hochladen</button>
                    </form>
                </div>
            </div>

            <?php if ($site_logo !== ''): ?>
            <form class="mt-section" method="post">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="site_logo_position">Logo-Position</label>
                        <select id="site_logo_position" name="site_logo_position">
                            <?= admin_enum_options(LogoPosition::cases(), (string)$site_logo_position) ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="site_logo_display">Anzeige</label>
                        <select id="site_logo_display" name="site_logo_display">
                            <?= admin_enum_options(LogoDisplay::cases(), (string)$site_logo_display) ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="save_branding_settings" class="btn btn-primary btn-sm">Speichern</button>
            </form>
            <?php endif; ?>
        </div>

    <?php else: // $site_tab === 'seo' ?>

        <div class="settings-section">
            <h2>SEO</h2>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="search_console_verification">Search-Console-Verifizierungscode</label>
                    <input type="text" id="search_console_verification" name="search_console_verification"
                           value="<?= e($cfg['search_console_verification'] ?? '') ?>"
                           maxlength="255" placeholder="z.B. AbCdEfGh12345...">
                    <div class="settings-hint">
                        Wird als <code>&lt;meta name="google-site-verification"&gt;</code> im <code>&lt;head&gt;</code>
                        aller Seiten ausgegeben — damit lässt sich die Property in der Google Search Console per
                        "HTML-Tag"-Methode verifizieren, ohne eine Datei hochzuladen. Google zeigt dort meist den
                        kompletten Tag zum Kopieren an — der wird ebenfalls erkannt, es reicht auch nur der Code.
                    </div>
                </div>

                <div class="form-group">
                    <label for="robots_txt">robots.txt</label>
                    <textarea class="full-width" id="robots_txt" name="robots_txt" rows="10"
                              maxlength="5000"><?= e($cfg['robots_txt'] ?? "User-agent: *\nAllow: /\n") ?></textarea>
                    <div class="settings-hint">
                        Wird 1:1 unter <code>/robots.txt</code> ausgeliefert, keine statische Datei nötig.
                        Änderungen sind sofort live.
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" name="save_seo_settings" class="btn btn-primary btn-sm">Speichern</button>
                </div>
            </form>
        </div>

    <?php endif // $site_tab ?>

