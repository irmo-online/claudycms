<?php
declare(strict_types=1);

/**
 * Feste Wertebereiche als Enums, geteilt von admin.php, mcp.php und install.php.
 *
 * Enthält:
 *   - Role           Nutzerrolle samt Rechte-Tabelle
 *   - UserStatus     Konto aktiv/gesperrt
 *   - ContentType    Markdown-Seite oder HTML-Landingpage
 *   - PageListSort   Sortierspalte im Tab "Alle Seiten"
 *   - SortDirection  auf- oder absteigend
 *   - LogoPosition   Logo-Position im Header
 *   - LogoDisplay    Logo allein oder neben dem Titel
 *   - SmtpSecurity   Verschlüsselung der SMTP-Verbindung
 *
 * Die Werte sind Produktionsdaten: genau so stehen sie in config/users.json,
 * config/config.php und im Frontmatter der Seiten auf Kundeninstallationen.
 * Deshalb bleiben 'aktiv'/'gesperrt' deutsch und 'logo_und_titel' mit
 * Unterstrich. Ein Umbenennen braucht eine Migration der bestehenden Dateien.
 *
 * Eingaben von außen (Formular, users.json) immer über
 * ::tryFrom() prüfen, nie über ::from() — ein unbekannter Wert soll im
 * Aufrufer als Fehler oder Fallback landen, nicht als ValueError mit HTTP 500.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Nutzer ─────────────────────────────────────────────────────────────────

/**
 * Rollen-Rechte-Tabelle. Rechte werden zentral hier aufgelöst statt
 * verstreut über if ($role === 'admin') an vielen Stellen im Code. Eine neue
 * Rolle braucht nur einen neuen case plus ihre Zeile in permissions() und
 * label().
 *
 * Rechte-Schlüssel:
 *   content   Seiten/Ordner lesen, schreiben, löschen
 *   images    Bilder lesen, hochladen, löschen
 *   styles    Design-Tokens/Custom-CSS lesen, schreiben, wiederherstellen
 *   settings  Website-/SMTP-/Sicherheits-Einstellungen
 *   users     Tab "Benutzer": andere Nutzer anlegen/sperren/löschen
 *   redirects Tab "Redirects": 301-Weiterleitungen anlegen/löschen — wirkt
 *             site-weit auf das Routing, nicht auf einzelne Inhalte,
 *             deshalb wie users/styles nur für admin.
 */
enum Role: string {
    case Admin          = 'admin';
    case ContentCreator = 'content_creator';

    /** @return list<string> */
    public function permissions(): array {
        return match ($this) {
            self::Admin          => ['content', 'images', 'styles', 'settings', 'users', 'redirects'],
            self::ContentCreator => ['content', 'images'],
        };
    }

    public function can(string $permission): bool {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string {
        return match ($this) {
            self::Admin          => 'Admin',
            self::ContentCreator => 'Content Creator',
        };
    }
}

enum UserStatus: string {
    case Aktiv    = 'aktiv';
    case Gesperrt = 'gesperrt';
}

// ─── Inhalte ────────────────────────────────────────────────────────────────

/**
 * Seitentyp aus dem Frontmatter-Feld contentType. Markdown-Seiten tragen
 * dort in aller Regel einen leeren Wert oder gar keinen — alles außer 'html'
 * gilt deshalb als Markdown.
 */
enum ContentType: string {
    case Markdown = 'markdown';
    case Html     = 'html';

    public static function fromMeta(array $meta): self {
        return self::tryFrom((string)($meta['contentType'] ?? '')) ?? self::Markdown;
    }
}

/**
 * Sortierspalte im Tab "Alle Seiten". Die Werte stehen als sort-Parameter in
 * der URL, nicht in gespeicherten Dateien.
 */
enum PageListSort: string {
    case Name = 'name';
    case Date = 'date';

    /** Namen von A nach Z, beim Datum das Neueste oben. */
    public function defaultDirection(): SortDirection {
        return match ($this) {
            self::Name => SortDirection::Asc,
            self::Date => SortDirection::Desc,
        };
    }
}

enum SortDirection: string {
    case Asc  = 'asc';
    case Desc = 'desc';

    public function flipped(): self {
        return $this === self::Asc ? self::Desc : self::Asc;
    }

    /** Wert für das aria-sort-Attribut eines Spaltenkopfs. */
    public function aria(): string {
        return $this === self::Asc ? 'ascending' : 'descending';
    }
}

// ─── Einstellungen ──────────────────────────────────────────────────────────

enum LogoPosition: string {
    case Links  = 'links';
    case Mittig = 'mittig';
    case Rechts = 'rechts';

    public function label(): string {
        return match ($this) {
            self::Links  => 'Links',
            self::Mittig => 'Mittig',
            self::Rechts => 'Rechts',
        };
    }
}

enum LogoDisplay: string {
    case LogoOnly     = 'logo_only';
    case LogoUndTitel = 'logo_und_titel';

    public function label(): string {
        return match ($this) {
            self::LogoOnly     => 'Nur Logo',
            self::LogoUndTitel => 'Logo + Titel',
        };
    }
}

/** 'tls' ist STARTTLS auf Port 587, 'ssl' implizites TLS auf 465, leer = unverschlüsselt. */
enum SmtpSecurity: string {
    case Tls   = 'tls';
    case Ssl   = 'ssl';
    case Keine = '';

    public function label(): string {
        return match ($this) {
            self::Tls   => 'TLS (STARTTLS)',
            self::Ssl   => 'SSL',
            self::Keine => 'Keine',
        };
    }
}
