<?php
declare(strict_types=1);

/**
 * Daten für den Tab "Alle Seiten": Zeilen einlesen, filtern, sortieren.
 *
 * Enthält:
 *   - admin_page_list_state()     Sortierung und Filter aus der URL
 *   - admin_page_list_rows()      eine Zeile je Seite, fertig für die View
 *   - admin_page_list_edited()    Änderungsdatum einer Seite
 *   - admin_page_list_filter()    nur die Seiten einer Kategorie
 *   - admin_page_list_sort()      nach Seitenname oder Datum
 *   - admin_page_list_url()       Link auf die Liste mit Sortierung und Filter
 *   - admin_page_list_sort_url()  Link eines Spaltenkopfs
 *   - admin_page_list_sort_th()   Spaltenkopf als <th> mit Sortier-Link
 *
 * Sortiert und gefiltert wird serverseitig über die URL, damit die Liste ohne
 * JavaScript funktioniert und ein Link auf eine Ansicht weitergegeben werden
 * kann.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Zustand aus der URL ────────────────────────────────────────────────────

/**
 * Liest sort, dir und category. Unbekannte Werte fallen still auf den
 * Standard zurück: ohne sort bleibt die Liste in der Reihenfolge der
 * Dateipfade, eine unbekannte Kategorie filtert nicht.
 *
 * Fehlt dir, gilt die Richtung, die man bei der Spalte zuerst erwartet:
 * Namen von A nach Z, beim Datum das Neueste oben.
 *
 * @param list<array{slug: string, name: string}> $categories
 * @return array{sort: ?PageListSort, dir: SortDirection, category: string}
 */
function admin_page_list_state(array $categories): array {
    $sort = PageListSort::tryFrom(get_str('sort'));
    $dir  = SortDirection::tryFrom(get_str('dir')) ?? ($sort?->defaultDirection() ?? SortDirection::Asc);

    $category = get_str('category');
    if (!in_array($category, array_column($categories, 'slug'), true)) {
        $category = '';
    }
    return ['sort' => $sort, 'dir' => $dir, 'category' => $category];
}

// ─── Zeilen ─────────────────────────────────────────────────────────────────

/**
 * Liest alle Seiten ein, in der Reihenfolge der Dateipfade.
 *
 * @param list<array{slug: string, name: string}> $categories
 * @return list<array{
 *     rel: string, title: string, slug: string, is_draft: bool, is_landingpage: bool,
 *     edited_by: string, edited: ?DateTimeImmutable, edited_from_file: bool,
 *     category_slugs: list<string>, category_names: list<string>
 * }>
 */
function admin_page_list_rows(array $categories): array {
    $rows = [];
    foreach (cms_list_content_files() as $rel) {
        $full = CONTENT_DIR . '/' . $rel;
        $data = cms_parse_md_file($full, false) ?: ['meta' => [], 'body' => ''];
        $meta = $data['meta'];

        $edited = admin_page_list_edited($meta, $full);
        $rows[] = [
            'rel'              => $rel,
            'title'            => (string)($meta['title'] ?? pathinfo($rel, PATHINFO_FILENAME)),
            'slug'             => (string)($meta['slug'] ?? ''),
            // Ein Entwurf ist öffentlich nicht erreichbar, weder als Seite
            // noch als rohes Markdown.
            'is_draft'         => cms_is_draft($meta),
            'is_landingpage'   => ContentType::fromMeta($meta) === ContentType::Html,
            'edited_by'        => (string)($meta['lastEditedBy'] ?? ''),
            'edited'           => $edited['at'],
            'edited_from_file' => $edited['from_file'],
            'category_slugs'   => cms_category_slugs_parse($meta['categories'] ?? ''),
            'category_names'   => cms_category_names($meta['categories'] ?? '', $categories),
        ];
    }
    return $rows;
}

/**
 * Das Änderungsdatum einer Seite: lastEditedAt aus dem Frontmatter, und nur
 * wenn das fehlt oder unlesbar ist, die Dateizeit.
 *
 * Die Dateizeit allein taugt nicht. Veröffentlichen und "Auf Entwurf"
 * schreiben die Datei neu, ohne dass sich der Inhalt ändert, und ein Upload
 * per FTP setzt sie auf den Zeitpunkt des Uploads. Sie bleibt als Rückfall
 * für Seiten, die nie über das Backend oder die KI gespeichert wurden.
 *
 * Der Frontmatter-Zeitstempel bringt seinen UTC-Offset mit und behält ihn:
 * angezeigt wird die Uhrzeit, zu der die Änderung passiert ist, nicht die in
 * die Zeitzone des Servers umgerechnete.
 *
 * @return array{at: ?DateTimeImmutable, from_file: bool}
 */
function admin_page_list_edited(array $meta, string $full): array {
    $raw = trim((string)($meta['lastEditedAt'] ?? ''));
    if ($raw !== '') {
        try {
            return ['at' => new DateTimeImmutable($raw), 'from_file' => false];
        } catch (Exception $e) {
            // unlesbarer Wert: weiter mit der Dateizeit
        }
    }
    $mtime = @filemtime($full);
    if ($mtime === false) {
        return ['at' => null, 'from_file' => true];
    }
    $at = (new DateTimeImmutable('@' . $mtime))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return ['at' => $at, 'from_file' => true];
}

// ─── Filtern und sortieren ──────────────────────────────────────────────────

/** Nur die Seiten der Kategorie, bei leerem Slug alle. */
function admin_page_list_filter(array $rows, string $category): array {
    if ($category === '') {
        return $rows;
    }
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => in_array($category, $row['category_slugs'], true)
    ));
}

/**
 * Sortiert nach Seitenname oder Datum. Ohne $sort bleibt die Reihenfolge der
 * Dateipfade.
 *
 * Gleichstand entscheidet immer der Dateipfad aufsteigend, auch bei
 * absteigender Sortierung, damit die Reihenfolge von Aufruf zu Aufruf dieselbe
 * ist. Seiten ohne Datum stehen in beiden Richtungen am Ende.
 */
function admin_page_list_sort(array $rows, ?PageListSort $sort, SortDirection $dir): array {
    if ($sort === null) {
        return $rows;
    }
    $factor = $dir === SortDirection::Desc ? -1 : 1;
    usort($rows, static function (array $a, array $b) use ($sort, $factor): int {
        if ($sort === PageListSort::Date) {
            if (($a['edited'] === null) !== ($b['edited'] === null)) {
                return $a['edited'] === null ? 1 : -1;
            }
            $cmp = ($a['edited']?->getTimestamp() ?? 0) <=> ($b['edited']?->getTimestamp() ?? 0);
        } else {
            $cmp = mb_strtolower($a['title'], 'UTF-8') <=> mb_strtolower($b['title'], 'UTF-8');
        }
        return $cmp !== 0 ? $cmp * $factor : $a['rel'] <=> $b['rel'];
    });
    return $rows;
}

// ─── Links ──────────────────────────────────────────────────────────────────

/** Link auf die Liste. Was dem Standard entspricht, bleibt aus der URL. */
function admin_page_list_url(?PageListSort $sort, SortDirection $dir, string $category): string {
    $query = [];
    if ($sort !== null) {
        $query['sort'] = $sort->value;
        $query['dir']  = $dir->value;
    }
    if ($category !== '') {
        $query['category'] = $category;
    }
    return 'admin.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

/**
 * Link eines Spaltenkopfs: die aktive Spalte dreht ihre Richtung, jede andere
 * beginnt mit ihrer Standardrichtung. Der Kategorie-Filter bleibt erhalten.
 *
 * @param array{sort: ?PageListSort, dir: SortDirection, category: string} $state
 */
function admin_page_list_sort_url(PageListSort $column, array $state): string {
    $dir = $state['sort'] === $column ? $state['dir']->flipped() : $column->defaultDirection();
    return admin_page_list_url($column, $dir, $state['category']);
}

/**
 * Ein Spaltenkopf, über den sortiert werden kann. Die aktive Spalte trägt
 * aria-sort und einen Pfeil für die Richtung.
 *
 * @param array{sort: ?PageListSort, dir: SortDirection, category: string} $state
 */
function admin_page_list_sort_th(string $label, PageListSort $column, array $state): string {
    $is_active = $state['sort'] === $column;
    $arrow     = $state['dir'] === SortDirection::Asc ? '▲' : '▼';
    return '<th scope="col" class="page-table-sortable"' . ($is_active ? ' aria-sort="' . $state['dir']->aria() . '"' : '') . '>'
        . '<a href="' . e(admin_page_list_sort_url($column, $state)) . '"' . ($is_active ? ' class="is-active"' : '') . '>'
        . e($label)
        . ($is_active ? ' <span aria-hidden="true">' . $arrow . '</span>' : '')
        . '</a></th>';
}
