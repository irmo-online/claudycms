<?php
// Tab "KI-Anbindung". Eingebunden von lib/views/admin/layout.php im Scope von
// admin.php.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
    <!-- ─── KI-ANBINDUNG ──────────────────────────────────────────────── -->
    <?php
        $cfg = load_config();
        $ai_publish_immediately = !empty($cfg['ai_publish_immediately']);

        // Entwürfe zählen, für den Verweis ins Seiten-Listing (die Freigabe
        // bleibt dort, keine zweite Liste im Tab).
        $ai_pages = cms_list_pages(true);
        $draft_count = 0;
        foreach ($ai_pages as $p) {
            if ($p['draft']) $draft_count++;
        }

        // Undo-Kandidaten: jede Seite mit mindestens einer gesicherten
        // Vorversion, neueste Änderung zuerst. Bewusst nicht auf
        // lastEditedBy: ai beschränkt — Undo soll auch dann noch greifen,
        // wenn die Seite zwischenzeitlich manuell im Backend gespeichert wurde.
        $history_entries = [];
        foreach ($ai_pages as $p) {
            $versions = cms_history_list($p['slug']);
            if ($versions === []) continue;
            $history_entries[] = [
                'slug'   => $p['slug'],
                'title'  => $p['title'] !== '' ? $p['title'] : $p['slug'],
                'draft'  => $p['draft'],
                'latest' => $versions[0],
                'count'  => count($versions),
            ];
        }
        usort($history_entries, static fn(array $a, array $b): int => $b['latest']['time'] <=> $a['latest']['time']);
    ?>

    <div class="settings-section mt-section">
        <h2>Connector-Token</h2>
        <p class="settings-hint">
            Seit der Benutzerverwaltung hat jeder Nutzer seinen eigenen Connector-Token statt eines
            gemeinsamen — deinen eigenen erzeugst/erneuerst du unter <a href="admin.php?action=profile">Mein Profil</a>,
            fremde Tokens verwaltest du im <a href="admin.php?action=users">Tab „Benutzer"</a>.
        </p>
    </div>

    <div class="settings-section mt-section">
        <h2>Veröffentlichung</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="ai_publish_immediately" value="1" <?= $ai_publish_immediately ? 'checked' : '' ?>>
                    KI-Änderungen sofort veröffentlichen
                </label>
                <div class="settings-hint">
                    Aus (Standard): Änderungen der KI werden als Entwurf gespeichert und erscheinen erst nach Freigabe
                    im Seiten-Listing öffentlich. Das gilt auch für bereits veröffentlichte Seiten — jede KI-Änderung
                    macht sie vorübergehend zum Entwurf, bis sie freigegeben wird.<br>
                    An: Änderungen der KI gehen sofort live. Eine Vorversion wird in beiden Stellungen automatisch gesichert.
                </div>
            </div>
            <button type="submit" name="save_ai_settings" class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </div>

    <div class="settings-section mt-section">
        <h2>Entwürfe <span class="section-note"><?= $draft_count ?> wartet<?= $draft_count === 1 ? '' : 'en' ?> auf Freigabe</span></h2>
        <div class="settings-hint">
            Entwürfe der KI erscheinen im <a href="admin.php">allgemeinen Seiten-Listing</a> mit Badge „Entwurf" und
            Button „Veröffentlichen" — dort werden sie freigegeben oder auf Entwurf zurückgesetzt. Eine zweite Liste
            hier im Tab wäre doppelte Pflege derselben Daten.
        </div>
    </div>

    <div class="settings-section mt-section">
        <h2>Letzte KI-Änderungen <span class="section-note">Undo pro Seite</span></h2>
        <?php if (empty($history_entries)): ?>
            <p class="settings-hint">Noch keine gesicherten Vorversionen vorhanden.</p>
        <?php else: ?>
            <div class="file-list file-list-flat">
                <?php foreach ($history_entries as $h): ?>
                    <div class="file-item">
                        <div class="file-item-name">
                            <?= e($h['title']) ?>
                            <?php if ($h['draft']): ?><span class="badge badge-draft" title="Nicht öffentlich erreichbar">Entwurf</span><?php endif; ?>
                        </div>
                        <div class="file-item-actions">
                            <div class="file-item-meta">
                                <?= e(date('d.m.Y H:i', $h['latest']['time'])) ?> Uhr
                                · <?= $h['count'] ?> Vorversion<?= $h['count'] === 1 ? '' : 'en' ?>
                            </div>
                            <form class="inline-form" method="post"
                                  data-confirm="Die letzte gesicherte Vorversion dieser Seite wiederherstellen? Der aktuelle Stand wird davor selbst gesichert, bleibt also ebenfalls umkehrbar.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="restore_slug" value="<?= e($h['slug']) ?>">
                                <button type="submit" name="restore_history" class="btn btn-outline btn-sm">Wiederherstellen</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="settings-section mt-section">
        <h2>Einrichtung in Claude</h2>
        <ol class="steps-list">
            <li>Unter <a href="admin.php?action=profile">Mein Profil</a> die eigene Connector-URL erzeugen und kopieren.</li>
            <li>In Claude.ai (oder Claude Desktop) zu <strong>Einstellungen → Connectors</strong> gehen.</li>
            <li><strong>„Custom Connector hinzufügen"</strong> wählen.</li>
            <li>Die kopierte URL einfügen und den Connector benennen (z.B. den Website-Namen).</li>
            <li>Verbinden — kein Login, kein separates Passwort nötig, die URL selbst ist der Zugangsschlüssel.</li>
            <li>In einem neuen Chat testen, z.B. mit: „Liste alle Seiten meiner Website auf."</li>
        </ol>
        <div class="settings-hint mt-small">Screenshots ergänzen wir in einer späteren Überarbeitung dieser Anleitung.</div>
    </div>


