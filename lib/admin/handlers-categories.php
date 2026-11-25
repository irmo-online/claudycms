<?php
declare(strict_types=1);

/**
 * POST-Handler für die Kategorienliste (Einstellungen → Kategorien).
 *
 * Nur Rolle mit Recht "settings". Umbenennen ändert allein den Namen, der Slug
 * bleibt stabil, damit die Zuordnungen in den Seiten gültig bleiben. Löschen
 * entfernt die Kategorie nur aus der Liste und schreibt keine Seite um.
 * Dazu der sitewide Schalter für die Anzeige unter dem Seiteninhalt.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

const ADMIN_CATEGORY_NAME_MAX = 60;

function handle_create_category(): HandlerResult {
    $name = mb_substr(trim(post_str('category_name')), 0, ADMIN_CATEGORY_NAME_MAX);
    $error = guard('settings') ?? cfg_update(static function (array $cfg) use ($name): array|string {
        $slug = cms_category_make_slug($name);
        if ($name === '' || $slug === '') {
            return 'Bitte einen Namen mit Buchstaben oder Ziffern angeben.';
        }
        $list = cms_categories_load($cfg);
        foreach ($list as $existing) {
            if ($existing['slug'] === $slug) {
                return 'Die Kategorie „' . $existing['name'] . '“ gibt es schon.';
            }
        }
        $list[] = ['slug' => $slug, 'name' => $name];
        $cfg['categories'] = $list;
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Kategorie „' . $name . '“ angelegt.', 'settings');
}

function handle_rename_category(): HandlerResult {
    $slug = post_str('category_slug');
    $name = mb_substr(trim(post_str('category_name')), 0, ADMIN_CATEGORY_NAME_MAX);
    $error = guard('settings') ?? cfg_update(static function (array $cfg) use ($slug, $name): array|string {
        if ($name === '' || cms_category_make_slug($name) === '') {
            return 'Bitte einen Namen mit Buchstaben oder Ziffern angeben.';
        }
        $list  = cms_categories_load($cfg);
        $found = false;
        foreach ($list as &$entry) {
            if ($entry['slug'] === $slug) {
                $entry['name'] = $name;
                $found = true;
            }
        }
        unset($entry);
        if (!$found) {
            return 'Kategorie nicht gefunden.';
        }
        $cfg['categories'] = $list;
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Kategorie umbenannt.', 'settings');
}

function handle_delete_category(): HandlerResult {
    $slug = post_str('category_slug');
    $error = guard('settings') ?? cfg_update(static function (array $cfg) use ($slug): array|string {
        $list = cms_categories_load($cfg);
        $rest = array_values(array_filter($list, static fn(array $c): bool => $c['slug'] !== $slug));
        if (count($rest) === count($list)) {
            return 'Kategorie nicht gefunden.';
        }
        $cfg['categories'] = $rest;
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Kategorie gelöscht. Die Seiten wurden nicht verändert.', 'settings');
}

function handle_save_category_settings(): HandlerResult {
    $error = guard('settings') ?? cfg_update(static function (array $cfg): array {
        $cfg['categories_show_on_page'] = post_bool('categories_show_on_page');
        return $cfg;
    });
    return HandlerResult::fromError($error, 'Kategorien-Einstellungen gespeichert.', 'settings');
}
