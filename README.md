# ClaudyCMS

Ein dateibasiertes Website-CMS in PHP: ohne Datenbank, ohne Build-Schritt, ohne Composer. Inhalte liegen als Markdown-Dateien im Ordner `content/`, die Installation besteht aus einem FTP-Upload und einem Formular.

ClaudyCMS ist Open Source (MIT). Es gibt keine Bezahlvariante und kein Lizenz-Gating, die KI-Anbindung ist Teil des Kerns.

## Für wen

Für Portfolio-Seiten, kleine Unternehmens- und Freelancer-Websites und alle Projekte, bei denen Einfachheit und Kontrolle über den eigenen Content wichtiger sind als ein großer Plugin-Baukasten. Das System läuft auf klassischem Shared Hosting.

## Funktionen

- **Markdown mit Frontmatter.** Seiten sind `.md`-Dateien mit Metadaten (Titel, Beschreibung, Slug, Navigation, Robots, Canonical, Open Graph). Der Markdown-Parser ist eingebaut.
- **Hierarchische Navigation.** Unterordner in `content/` erzeugen verschachtelte URLs und Dropdown-Menüs.
- **Admin-Backend** unter `/admin.php`: Seiten anlegen, bearbeiten, veröffentlichen, als Entwurf speichern und löschen, Seitenliste mit Sortierung und Filter, Medienverwaltung, Live-Vorschau.
- **Kategorien** für Seiten, mit dem Shortcode `[category-list category="slug"]`, der alle Seiten einer Kategorie verlinkt.
- **Landingpages** mit eigenem HTML statt Markdown.
- **Design** über Design-Tokens (Farben, Schriften, Layout) und freies CSS, ohne Dateien zu bearbeiten.
- **Redirects** (301) mit Schutz davor, bestehende Seiten zu verdecken.
- **SEO:** Sitemap, `robots.txt`, Canonical, Open Graph und Twitter Cards, Search-Console-Verifizierung, Lazy Loading für Bilder, eigene 404-Seite.
- **Benutzer und Rollen:** Admin und Content Creator, Einladung per Mail, Passwort-Reset.
- **Kontaktformular** mit SMTP-Versand (PHPMailer).
- **Datenschutz:** selbst gehostete Schriftarten, kein externes Tracking.
- **KI-Anbindung (MCP).** Über `mcp.php` kann ein MCP-fähiger Client wie Claude Seiten, Kategorien, Bilder, Design und Redirects pflegen. Der Zugang läuft über einen personengebundenen Connector-Token. Änderungen der KI landen standardmäßig als Entwurf, die Vorversion wird unter `content/.history/` gesichert, Schreibzugriffe sind ratenbegrenzt.
- **REST-API** unter `api.php` für externe Clients (Bearer-Token).
- **Erweiterungen:** Eigener Code kann sich über `extensions/<name>/bootstrap.php` an zwei Haken (`before_routing`, `page_content`) einhängen.

## Sicherheit

Passwort-Hashing mit bcrypt, CSRF-Schutz auf allen Formularen, Schutz vor Session Fixation, Brute-Force-Schutz (je Sitzung, IP und Konto), Path-Traversal-Schutz, Dateiendungs-Whitelist, Bildprüfung über den tatsächlichen Dateiinhalt und ein Admin-Bereich ohne `'unsafe-inline'` in der Content-Security-Policy. Schwachstellen bitte wie in [SECURITY.md](SECURITY.md) beschrieben melden.

## Installation

Voraussetzungen: PHP 8.1 oder neuer, Apache mit `mod_rewrite` (nginx mit entsprechender Konfiguration geht auch), keine Datenbank.

1. Alle Dateien auf den Webserver hochladen. Die Ordner `config/`, `content/` und `assets/images/` müssen für PHP beschreibbar sein.
2. `https://deine-domain.tld/install.php` aufrufen. Das Formular legt `config/config.php` und den ersten Admin-Account an.
3. `install.php` vom Server löschen. Der Abschlussbildschirm weist darauf hin.
4. Unter `/admin.php` anmelden.

Wer lieber von Hand konfiguriert: `config/config.example.php` nach `config/config.php` kopieren und die Werte anpassen. Die Datei `config/config.php` enthält Zugangsdaten und gehört nie ins Repository.

## Updates

ClaudyCMS aktualisiert sich nicht selbst. Neue Versionen erscheinen als [GitHub-Releases](../../releases). Zum Aktualisieren die Dateien per FTP über die bestehende Installation kopieren und dabei `config/`, `content/` und `assets/images/` nicht überschreiben. Ältere Dateien, die in einer neuen Version weggefallen sind, bleiben liegen und können von Hand gelöscht werden. Vor jedem Update ein Backup anlegen.

## KI-Anbindung einrichten

1. Im Admin unter „Mein Profil" die eigene Connector-URL erzeugen. Der Token gehört zu deinem Benutzerkonto, die Rolle bestimmt, was die KI darf.
2. Die angezeigte Connector-URL in den MCP-Client eintragen. Bei Claude: Einstellungen, Connectors, benutzerdefinierten Connector hinzufügen.
3. Mit „Liste alle Seiten meiner Website auf." testen.

Unter „KI-Anbindung" findest du außerdem die Schritt-für-Schritt-Anleitung und stellst ein, ob Änderungen der KI als Entwurf gespeichert (Standard) oder sofort veröffentlicht werden. Ein Token lässt sich jederzeit neu erzeugen, die alte URL ist dann sofort ungültig.

## Projektstruktur

```
ClaudyCMS/
├── index.php          Frontend: Routing, Markdown, HTML-Ausgabe, Kontaktformular
├── admin.php          Admin-Backend (Bootstrap, Dispatch, Views)
├── api.php            REST-API
├── mcp.php            MCP-Server für die KI-Anbindung
├── install.php        Installationsroutine (nach der Installation löschen)
├── lib/
│   ├── content.php    Frontmatter, Pfad-Validierung, Seiten-CRUD, Historie
│   ├── security.php   Session, CSRF, Rate-Limiting, Security-Header
│   ├── users.php      Benutzer, Rollen, Token
│   ├── media.php, styles.php, redirects.php, categories.php, branding.php
│   ├── enums.php      Rollen, Status und weitere feste Wertebereiche
│   ├── admin/         POST-Handler des Admin-Backends
│   ├── views/         Templates (Admin und Anmeldung)
│   └── phpmailer/     SMTP-Versand
├── config/            config.example.php, zur Laufzeit config.php und users.json
├── content/           Markdown-Seiten
├── assets/            style.css, core.css, admin.css, Schriftarten, Bilder
├── extensions/        optionale Erweiterungen
└── tests/             Testskripte
```

## Inhalte

Für eine neue Seite genügt eine `.md`-Datei in `content/`. Unterordner erzeugen verschachtelte URLs: `content/work/projekt.md` ergibt `/work/projekt`.

```yaml
---
title: "Seitentitel"           # <title>-Tag
description: "Beschreibung"    # Meta-Description
slug: "about"                  # URL-Pfad
navLabel: "Über uns"           # Text im Menü (weglassen = nicht im Menü)
order: 1                       # Reihenfolge im Menü
heading: "Über uns"            # sichtbare H1
robots: "index, follow"
categories: "seo, wordpress"   # Kategorie-Slugs, kommagetrennt
---
```

## Tests

Es gibt keinen Composer und kein Testframework. Die Testskripte starten einen eigenen PHP-Server mit einer Wegwerf-Kopie und lassen deine Inhalte unberührt:

```
for t in tests/*.php; do php "$t" || exit 1; done
```

Jedes Skript beendet sich mit Exit-Code 0 (grün) oder 1.

## Mitmachen

Siehe [CONTRIBUTING.md](CONTRIBUTING.md). Fehler und Wünsche gehören in die Issues.

## Hinweis

ClaudyCMS ist ein eigenständiges Projekt und steht in keiner Verbindung zu Anthropic oder Claude. „Claude" ist eine Marke von Anthropic und wird hier nur beschreibend für die MCP-Kompatibilität genannt.

## Lizenz

[MIT](LICENSE)
