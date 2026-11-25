<?php
declare(strict_types=1);

/**
 * Kategorien für Seiten.
 *
 * Die Liste liegt in config/config.php (Schlüssel "categories", Einträge
 * {slug, name}), die Zuordnung in der Seite als flacher Frontmatter-Schlüssel
 * "categories" mit kommagetrennten Slugs. Der Frontmatter-Parser kennt keine
 * Listen, deshalb bleibt es bei einem String.
 *
 *   - cms_categories_load()          Liste aus der Config, bereinigt
 *   - cms_category_make_slug()       Slug aus einem Namen
 *   - cms_category_slugs_parse()     "a, b" → ['a', 'b']
 *   - cms_category_slugs_join()      ['a', 'b'] → "a, b"
 *   - cms_categories_usage()         Anzahl Seiten je Kategorie-Slug
 *   - cms_category_names()           Anzeigenamen zu einem Frontmatter-Wert
 *   - cms_category_pages()           veröffentlichte Seiten einer Kategorie
 *   - cms_category_list_html()       Liste oder Karten als HTML
 *   - cms_category_shortcode_apply() ersetzt [category-list ...] im Seiten-HTML
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Die Kategorienliste als [{slug, name}], ohne kaputte oder doppelte Einträge.
 * Eine von Hand veränderte Config kann alles Mögliche enthalten, die Anzeige
 * soll daran nicht scheitern.
 *
 * @return list<array{slug: string, name: string}>
 */
function cms_categories_load(?array $cfg = null): array {
    $raw = ($cfg ?? load_config())['categories'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out  = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !is_string($entry['name'] ?? null)) {
            continue;
        }
        $slug = $entry['slug'];
        if ($slug === '' || isset($seen[$slug]) || $slug !== cms_category_make_slug($slug)) {
            continue;
        }
        $seen[$slug] = true;
        $out[] = ['slug' => $slug, 'name' => $entry['name']];
    }
    return $out;
}

/**
 * Macht aus einem Namen einen Kategorie-Slug: Kleinbuchstaben, Ziffern und
 * Bindestriche. Anders als cms_sanitize_slug() ohne "/" und "_", weil
 * Kategorien keine verschachtelten URLs sind und das Komma der Trenner ist.
 */
function cms_category_make_slug(string $name): string {
    $slug = mb_strtolower(trim($name), 'UTF-8');
    $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $slug = preg_replace('/[\s_\/]+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Zerlegt den Frontmatter-Wert in eine Liste eindeutiger Slugs.
 *
 * @return list<string>
 */
function cms_category_slugs_parse(mixed $value): array {
    // Der Content-Index castet Frontmatter-Werte, ein rein numerischer Slug
    // ("2024") kommt dort als int an.
    if (is_int($value)) {
        $value = (string)$value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $slugs = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '' && !in_array($part, $slugs, true)) {
            $slugs[] = $part;
        }
    }
    return $slugs;
}

/** @param list<string> $slugs */
function cms_category_slugs_join(array $slugs): string {
    return implode(', ', $slugs);
}

/**
 * Wie viele Seiten (Entwürfe eingeschlossen) jede Kategorie verwenden,
 * auch Slugs, die in der Liste nicht mehr vorkommen.
 *
 * @return array<string, int>
 */
function cms_categories_usage(): array {
    $usage = [];
    foreach (cms_build_content_index(null, '', true) as $entry) {
        foreach (cms_category_slugs_parse($entry['meta']['categories'] ?? '') as $slug) {
            $usage[$slug] = ($usage[$slug] ?? 0) + 1;
        }
    }
    return $usage;
}

// ─── Frontend: Anzeige und Shortcode ────────────────────────────────────────

/**
 * Die Anzeigenamen zu den Kategorien einer Seite, in der Reihenfolge des
 * Frontmatter-Werts. Slugs, die in der Liste nicht mehr vorkommen, fallen weg.
 *
 * @param list<array{slug: string, name: string}> $categories
 * @return list<string>
 */
function cms_category_names(mixed $value, array $categories): array {
    $by_slug = array_column($categories, 'name', 'slug');
    $names   = [];
    foreach (cms_category_slugs_parse($value) as $slug) {
        if (isset($by_slug[$slug])) {
            $names[] = $by_slug[$slug];
        }
    }
    return $names;
}

/**
 * Die Seiten einer Kategorie für die öffentliche Ausgabe, sortiert nach
 * order, dann nach Titel.
 *
 * Erwartet den Index ohne Entwürfe. Die aufrufende Seite selbst und die
 * 404-Seite bleiben draussen. Als Titel dient die sichtbare Überschrift, weil
 * der HTML-Title oft für Suchmaschinen formuliert und als Linktext zu lang ist.
 *
 * @return list<array{slug: string, title: string, description: string, image: string, order: int}>
 */
function cms_category_pages(string $category, array $content_index, string $current_slug): array {
    $pages = [];
    foreach ($content_index as $slug => $entry) {
        $slug = (string)$slug;
        if ($slug === $current_slug || $slug === '404') {
            continue;
        }
        $meta = $entry['meta'] ?? [];
        if (!in_array($category, cms_category_slugs_parse($meta['categories'] ?? ''), true)) {
            continue;
        }
        $title = trim((string)($meta['heading'] ?? ''));
        if ($title === '') {
            $title = trim((string)($meta['title'] ?? ''));
        }
        $pages[] = [
            'slug'        => $slug,
            'title'       => $title !== '' ? $title : $slug,
            'description' => trim((string)($meta['description'] ?? '')),
            'image'       => trim((string)($meta['headerImage'] ?? '')),
            'order'       => isset($meta['order']) ? (int)$meta['order'] : 99,
        ];
    }
    usort($pages, static fn(array $a, array $b): int =>
        [$a['order'], mb_strtolower($a['title'], 'UTF-8')] <=> [$b['order'], mb_strtolower($b['title'], 'UTF-8')]);
    return $pages;
}

/**
 * Macht aus einem headerImage-Wert eine absolute Bild-URL. Der Wert steht im
 * Frontmatter relativ zum Webroot und würde auf einer verschachtelten Seite
 * ("/work/alpha") sonst ins Leere zeigen. Fremde Schemata (javascript:, data:)
 * und protokoll-relative URLs ergeben '', die Karte erscheint dann ohne Bild.
 */
function cms_category_image_url(string $image, string $site_url): string {
    if ($image === '' || str_starts_with($image, '//') || preg_match('/[\x00-\x20\x7F]/', $image) === 1) {
        return '';
    }
    if (preg_match('~^https?://~i', $image) === 1) {
        return $image;
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $image) === 1) {
        return '';
    }
    return $site_url . '/' . ltrim($image, '/');
}

/**
 * Rendert die Seiten als Linkliste ("list") oder als Karten ("cards"). Ohne
 * Seiten gibt es keine Ausgabe, auch kein leeres Gerüst.
 *
 * @param list<array{slug: string, title: string, description: string, image: string, order: int}> $pages
 */
function cms_category_list_html(array $pages, string $style, string $site_url): string {
    if ($pages === []) {
        return '';
    }
    $html = '';
    if ($style !== 'cards') {
        foreach ($pages as $page) {
            $html .= '<li><a href="' . e($site_url . '/' . $page['slug']) . '">' . e($page['title']) . "</a></li>\n";
        }
        return "<ul class=\"category-list\">\n" . $html . '</ul>';
    }
    foreach ($pages as $page) {
        $image = cms_category_image_url($page['image'], $site_url);
        $html .= '<a class="category-card" href="' . e($site_url . '/' . $page['slug']) . '">';
        if ($image !== '') {
            $html .= '<img class="category-card-image" src="' . e($image) . '" alt="" loading="lazy">';
        }
        $html .= '<span class="category-card-title">' . e($page['title']) . '</span>';
        if ($page['description'] !== '') {
            $html .= '<span class="category-card-text">' . e($page['description']) . '</span>';
        }
        $html .= "</a>\n";
    }
    return "<div class=\"category-cards\">\n" . $html . '</div>';
}

/**
 * Ersetzt [category-list category="slug" style="list|cards"] im fertigen
 * Seiten-HTML. Läuft nach markdown_to_html(), deshalb sind die
 * Anführungszeichen dort schon zu &quot; geworden, in einer Landingpage
 * stehen sie roh. Beide Formen gelten.
 *
 * Fehlende oder unbekannte Kategorie: der Shortcode verschwindet ohne
 * Ausgabe, auf der öffentlichen Seite steht kein Fehlertext. Ein ungültiger
 * style fällt auf die Liste zurück.
 *
 * @param list<array{slug: string, name: string}> $categories
 */
function cms_category_shortcode_apply(string $html, array $categories, array $content_index, string $current_slug, string $site_url): string {
    if (stripos($html, '[category-list') === false) {
        return $html;
    }
    $known  = array_column($categories, 'slug');
    $quote  = '(?:"|&quot;)';
    $attr   = '([a-z]+)=' . $quote . '([a-z0-9-]*)' . $quote;
    $code   = '\[category-list((?:\s+' . $attr . ')*)\s*\]';
    $render = static function (array $m) use ($attr, $known, $content_index, $current_slug, $site_url): string {
        $params = [];
        preg_match_all('~' . $attr . '~i', $m[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }
        $category = $params['category'] ?? '';
        if ($category === '' || !in_array($category, $known, true)) {
            return '';
        }
        $style = ($params['style'] ?? '') === 'cards' ? 'cards' : 'list';
        return cms_category_list_html(cms_category_pages($category, $content_index, $current_slug), $style, $site_url);
    };
    // Erst die Variante im eigenen Absatz, damit kein <ul> oder <div> in
    // einem <p> landet, dann jede übrige Fundstelle.
    $html = (string)preg_replace_callback('~<p>\s*' . $code . '\s*</p>~i', $render, $html);
    return (string)preg_replace_callback('~' . $code . '~i', $render, $html);
}
