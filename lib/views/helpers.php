<?php
declare(strict_types=1);

/**
 * Helfer für die Templates unter lib/views/.
 *
 * Enthält:
 *   - admin_render_auth()  Seite vor dem Login im Auth-Layout ausgeben
 *   - admin_css_link()     <link> auf assets/admin.css mit Cache-Buster
 *   - admin_nav_icon()     Inline-SVG für Sidebar und Topbar
 *   - admin_enum_options() <option>-Liste aus Enum-Fällen
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Gibt eine Seite vor dem Login aus: lib/views/auth/<$view>.php im
 * gemeinsamen Auth-Layout. $vars landen als Variablen im Template.
 * Eigener Funktions-Scope, damit die Templates nur sehen, was sie bekommen.
 */
function admin_render_auth(string $view, string $title, array $vars = []): void {
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/auth/layout.php';
}

/**
 * <link> auf das Admin-Stylesheet.
 *
 * Das CSS lag früher als Heredoc in dieser Datei und wurde in jede
 * Admin- und jede Login-Seite eingebettet — 14 KB pro Seitenaufruf, die der
 * Browser nie zwischenspeichern konnte. Als eigene Datei wird es einmal
 * geladen und danach aus dem Browser-Cache bedient.
 *
 * Relativer Pfad, nicht SITE_URL: die Seite kann über einen anderen Host
 * erreicht werden als den in site_domain eingetragenen (Staging, IP-Aufruf,
 * www vs. ohne www), und bei einer Installation in einem Unterverzeichnis
 * zeigte eine absolute URL am Verzeichnis vorbei. Relativ löst der Browser
 * immer gegen das aktuelle Dokument auf und die Quelle ist damit immer
 * 'self' — die CSP braucht deshalb keine Anpassung.
 *
 * ?v=<mtime> als Cache-Buster: ohne ihn liefert der Browser nach einem
 * Update weiter das alte Stylesheet zu neuem Markup aus. Der Wert ist ein
 * Integer aus filemtime(), nichts davon kommt von außen — kein Escaping nötig.
 */
function admin_css_link(): string {
    $mtime = @filemtime(dirname(__DIR__, 2) . '/assets/admin.css');
    $href  = 'assets/admin.css' . ($mtime !== false ? '?v=' . (int)$mtime : '');
    return '<link rel="stylesheet" href="' . $href . '">';
}

/**
 * Kleine Inline-SVG-Icons für Sidebar-Nav und Profil-Icon. Bewusst
 * handgeschrieben statt aus einer Icon-Bibliothek per CDN geladen — das Repo
 * hat keinen Build-Step und keine externen Laufzeit-Abhängigkeiten (siehe
 * .claude/CLAUDE.md), ein `<script src="…icons…">` wäre die erste Ausnahme.
 * Größe/Farbe kommen komplett aus CSS (`currentColor`, feste width/height
 * auf dem <svg> in .admin-nav/.admin-topbar-profile).
 */
function admin_nav_icon(string $name): string {
    $paths = [
        'pages'    => '<path d="M6 2 H15 L20 7 V22 H6 Z"/><path d="M15 2 L15 7 L20 7"/><line x1="9" y1="13" x2="16" y2="13"/><line x1="9" y1="17" x2="16" y2="17"/>',
        'media'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15 L16 10 L5 21"/>',
        'ai'       => '<path d="M13 2 L3 14 H12 L11 22 L21 10 H12 Z"/>',
        'site'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2 C7 7 7 17 12 22 C17 17 17 7 12 2 Z"/>',
        'settings' => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="18" r="2"/>',
        'users'    => '<path d="M17 21 V19 C17 16.8 15.2 15 13 15 H5 C2.8 15 1 16.8 1 19 V21"/><circle cx="9" cy="7" r="4"/><path d="M23 21 V19 C23 17.1 21.7 15.5 20 15.1"/><path d="M16 3.1 C17.7 3.5 19 5.1 19 7 C19 8.9 17.7 10.5 16 10.9"/>',
        'profile'  => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M6.5 19 C7.5 16.5 9.5 15 12 15 C14.5 15 16.5 16.5 17.5 19"/>',
    ];
    $inner = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/**
 * <option>-Liste aus Enum-Fällen, Beschriftung aus deren label(). Die
 * Reihenfolge ist die von $cases, nicht die der Enum-Deklaration: beim
 * Einladen soll die Rolle mit den wenigsten Rechten vorausgewählt sein.
 *
 * @param list<BackedEnum> $cases
 */
function admin_enum_options(array $cases, string $selected): string {
    $html = '';
    foreach ($cases as $case) {
        $value = (string)$case->value;
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
            . e($case->label()) . '</option>';
    }
    return $html;
}
