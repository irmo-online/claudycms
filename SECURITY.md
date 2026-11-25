# Sicherheitshinweise

## Schwachstellen melden

Bitte melde Sicherheitslücken **nicht** in einem öffentlichen Issue. Nutze stattdessen auf GitHub unter „Security" den Eintrag „Report a vulnerability" (private Schwachstellenmeldung). Beschreibe, was du gefunden hast, wie es sich nachstellen lässt und welche Version betroffen ist.

Das Projekt wird in der Freizeit gepflegt. Ich bemühe mich, innerhalb weniger Tage zu antworten und eine bestätigte Lücke zeitnah zu beheben. Eine Veröffentlichung stimmen wir gemeinsam ab.

## Unterstützte Versionen

Sicherheitsfixes gibt es für die jeweils neueste Version (siehe Releases).

## Hinweise für den Betrieb

- `config/config.php` und `config/users.json` enthalten Zugangsdaten und Hashes. Sie dürfen nie in ein Repository und nie öffentlich erreichbar sein (die mitgelieferten `.htaccess`-Dateien sperren den Zugriff, das setzt Apache voraus).
- `install.php` nach der Installation löschen.
- Der Connector-Token der KI-Anbindung wirkt wie ein Passwort. Wer die URL kennt, hat die Rechte des zugehörigen Benutzers. Bei Verdacht den Token im Admin neu erzeugen.
