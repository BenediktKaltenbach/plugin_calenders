# DTRV-Termine

WordPress-Plugin für dtrv.net. Es liest die Termine aus vier Google Kalendern (Wettkampf, Breitensport, Ausbildung, Verband), spiegelt sie als eigenen Inhaltstyp „Termine“ und zeigt sie per Block **DTRV-Termine** oder Shortcode an.

Google Kalender ist die einzige Quelle. Das Plugin liest nur über die geheime iCal-Adresse und schreibt nie in Google.

## Installation

1. Unter [Releases](https://github.com/BenediktKaltenbach/plugin_calenders/releases) die Datei `dtrv-termine.zip` herunterladen. Nicht „Source code“ nehmen, darin fehlt `vendor/`.
2. WordPress › Plugins › Installieren › Plugin hochladen › aktivieren.
3. Spätere Updates erscheinen ganz normal unter „Plugins“ (über den Plugin Update Checker).

## Einstellungen

**Termine › Einstellungen**

- Je Kategorie die **geheime Adresse im iCal-Format** eintragen (Google Kalender › Einstellungen des Kalenders › „Kalender integrieren“). Diese Adressen gehören nie ins Repository.
- **Jetzt abgleichen** holt die Termine sofort. Die Tabelle zeigt je Kalender den letzten Abgleich und Fehler.
- **Cronjob:** Die angezeigte Adresse im KAS unter „Tools › Cronjobs“ stündlich aufrufen lassen. WP-Cron gleicht nur als Rückfall ab, wenn der Cronjob länger als 50 Minuten nicht lief.

Unter **Termine › Alle Termine** stehen alle gespiegelten Termine mit Datum, Kategorie, erkannten Angaben und Warnungen (z. B. Tippfehler in Schlüsselzeilen). Gelöschte Kalendertermine werden nur markiert, nicht gelöscht.

## Termine im Google Kalender eintragen

Titel, Datum (ganztägig oder mit Uhrzeit) und Ort wie gewohnt. In die Beschreibung zuerst freien Text, dann eine Zeile `---`, dann Schlüsselzeilen:

```
Gemeinsame Tour entlang der Sieg.
---
Anmeldung: https://my.raceresult.com/392357/
Infos: https://dtrv.net/siegtal-pur/
Klasse: F | 0-5
Land: NL
Detail: Beitrag
Status: vorläufig
```

| Schlüssel | Werte | Wirkung |
| --- | --- | --- |
| `Anmeldung:` | URL | Knopf „Anmelden“ |
| `Infos:` | URL | Knopf „Infos“ (sonst „Infos folgen“) |
| `Klasse:` | z. B. `F \| 0-5` | Klasse mit Link zur Erklärseite |
| `Land:` | ISO-Code, z. B. `CZ` | Flagge vor dem Titel |
| `Detail:` | `Beitrag` / `Seite` / `keine` | wird in Phase 2 ausgewertet |
| `Status:` | `abgesagt` / `verschoben` / `vorläufig` | Hinweis; abgesagt wird durchgestrichen |

Groß-/Kleinschreibung und Leerzeichen sind egal. Unbekannte Schlüssel werden ignoriert und in der Übersicht als Warnung angezeigt. Ein in Google abgesagter Termin (Status „Abgesagt“) gilt ebenfalls als abgesagt.

## Anzeige

**Block „DTRV-Termine“:** In der Seitenleiste Kategorien wählen (keine = alle), Anzahl, Zeitraum in Monaten und ob vergangene Termine erscheinen.

**Shortcode:**

```
[dtrv_termine kategorie="breitensport" anzahl="10" monate="12" vergangene="0"]
```

Die Akzentfarbe der Knöpfe lässt sich im Theme per CSS ändern: `.dtrv-termine { --dtrv-akzent: #006666; }`

## Terminvorschläge

**Block „DTRV-Terminvorschlag“** (oder Shortcode `[dtrv_termin_vorschlag]`) auf einer Seite einfügen. Jeder kann dann einen Termin vorschlagen: Name, Kategorie, Datum (auch mehrtägig), Uhrzeit, Ort, Links zu Infos und Anmeldung, Beschreibung, ein Bild (JPG/PNG/WebP, max. 8 MB), Name und E-Mail des Einreichers, Datenschutz-Haken.

- **Spam-Schutz ohne reCAPTCHA:** verstecktes Honigtopf-Feld, Mindest-Ausfüllzeit von 5 Sekunden, höchstens 5 Vorschläge pro IP und Stunde.
- **Mail:** Jeder Vorschlag geht an die Adresse unter Termine › Einstellungen › „E-Mail für neue Vorschläge“ (Standard: vorstand@dtrv.net), mit den Links **Freigeben** und **Ablehnen**. Die Links öffnen erst eine Bestätigungsseite mit einem Knopf, damit Mail-Scanner nichts automatisch auslösen. Eine Anmeldung in WordPress ist dafür nicht nötig.
- **Freigeben** markiert den Vorschlag nur. Das Plugin schreibt nicht in Google. Der Knopf **„In Google Kalender anlegen“** öffnet Google Kalender mit allen Angaben vorausgefüllt (inkl. Schlüsselzeilen). Dort den passenden Kalender wählen und speichern.
- **Termine › Vorschläge** ist die Prüfliste. „Eingetragen“ schickt dem Einreicher eine Dankes-Mail und löscht danach seine E-Mail-Adresse. „Ablehnen“ archiviert den Vorschlag und löscht E-Mail-Adresse und Bild.
- Hochgeladene Bilder landen in der Mediathek, angehängt an den Vorschlag.
- Für die Datenschutzerklärung: Vorschläge speichern Name und E-Mail des Einreichers nur bis zur Bearbeitung. Die IP-Adresse wird nur gehasht für eine Stunde als Spam-Zähler verwendet.

**Lese-Schnittstelle für das Briefing:** `GET /wp-json/dtrv-termine/v1/vorschlaege?schluessel=…` (gleicher Schlüssel wie beim Cronjob) liefert alle neuen und freigegebenen Vorschläge ohne Kontaktdaten, jeweils mit vorausgefülltem Google-Link.

## Release-Ablauf

1. Neue Version zuerst auf einer Kopie der Seite testen (lokal oder Staging).
2. In `dtrv-termine.php` die Version an **zwei** Stellen erhöhen (`Version:` im Kopf und `const VERSION`), ebenso in `blocks/termine/index.asset.php`.
3. Committen und pushen, dann einen Tag setzen:
   ```
   git tag v0.2.0
   git push origin v0.2.0
   ```
4. Die GitHub Action „Release“ prüft die Version, führt die Tests aus, baut `dtrv-termine.zip` und hängt es an das Release. Kurz danach zeigt WordPress „Update verfügbar“.

Ist das Repository privat, braucht der Update Checker ein GitHub-Token (`$updater->setAuthentication( '…' )`, Token in `wp-config.php`, nie im Repo).

## Entwicklung

- Aufbau: `includes/Feed.php` (iCal lesen, Serien auflösen, sabre/vobject), `Parser.php` (Schlüsselzeilen), `Format.php` (Datumsformat), `Sync.php` (Abgleich in den Inhaltstyp), `Renderer.php` (Listenansicht), `Settings.php`, `PostType.php`, `Rest.php`. Block ohne Build-Schritt unter `blocks/termine/`.
- Tests ohne WordPress: `composer install` und `php tests/run.php`. Die Action „Prüfen“ führt sie bei jedem Push mit PHP 8.1 und 8.4 aus.
