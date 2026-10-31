# DTRV-Termine

WordPress plugin for [dtrv.net](https://dtrv.net), the website of the Deutscher Tretroller Verband e.V. (German kick scooter association). It reads events from four Google Calendars (Wettkampf, Breitensport, Ausbildung, Verband), mirrors them as a custom post type and displays them via blocks and shortcodes. Anyone can suggest new events through a form, and every event can get a draft post or page.

Google Calendar is the single source of truth. The plugin only **reads** via each calendar's secret iCal address and never writes to Google.

The plugin's admin and front-end UI is in German, because the site is German.

## Installation

1. Download `dtrv-termine.zip` from [Releases](https://github.com/BenediktKaltenbach/plugin_calenders/releases). Do not use "Source code", it lacks `vendor/`.
2. WordPress › Plugins › Add New › Upload Plugin › activate.
3. Later updates appear in the regular WordPress plugin list (via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)) and install with one click.

## Settings (Termine › Einstellungen)

- **Google Calendars:** paste the **secret address in iCal format** for each category (Google Calendar › calendar settings › "Integrate calendar"). These addresses must never be committed to this repository.
- **Sync table:** shows the name of the Google Calendar behind each address (with a warning if it does not match the category), the last sync, counts and errors. **"Jetzt abgleichen"** syncs immediately.
- **Cron job:** let your host call the displayed URL hourly (All-Inkl: KAS › Tools › Cronjobs). WP-Cron only syncs as a fallback if the real cron has not run for 50 minutes.
- **Detail pages:** parent page for event pages; per category whether a draft post is created automatically, the WordPress post category and the default featured image.
- **Event suggestions:** recipient address(es) for new suggestions. If empty, the WordPress admin email is used.

**Termine › Alle Termine** lists all mirrored events with date, category, parsed fields, detail page and image status and warnings (e.g. typos in key lines). Events deleted in Google are only flagged, not deleted. If a calendar feed is empty twice in a row, its events are flagged as deleted.

## Entering events in Google Calendar

Title, date (all-day or timed) and location as usual. In the description write free text first, then a line `---`, then key lines:

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

| Key | Values | Effect |
| --- | --- | --- |
| `Anmeldung:` | URL | "Anmelden" (register) button |
| `Infos:` | URL | "Infos" button (otherwise "Infos folgen") |
| `Klasse:` | e.g. `F \| 0-5` | difficulty class linked to the explanation page |
| `Land:` | ISO code, e.g. `CZ` | flag in front of the title |
| `Detail:` | `Beitrag` / `Seite` / `keine` | page + post / page only / neither (default: page, post if enabled for the category) |
| `Status:` | `abgesagt` / `verschoben` / `vorläufig` | note in the list; cancelled events are struck through |

Keys are case- and whitespace-insensitive. Unknown keys are ignored and shown as warnings in the admin list. An event cancelled in Google (status "Cancelled") also counts as cancelled. Recurring events (RRULE) and exceptions are expanded. Moving an event to another calendar only changes its category.

**Class** (`Klasse: Art | Bergfaktor`): the letter is the type (F = Freizeit, A = Ambitioniert, W = Wettkampf), the number range is the Bergfaktor, i.e. elevation gain in metres per kilometre. Wettkampf events always show **W**, even if the line only says `Klasse: 0-5`. Breitensport uses F or A. Ausbildung and Verband events never show a class.

## Blocks and shortcodes

| Block | Shortcode | Purpose |
| --- | --- | --- |
| **DTRV-Termine** | `[dtrv_termine kategorie="breitensport" anzahl="10" monate="12" vergangene="0"]` | Event list in the look of the existing lists: title, date, location, class, Infos/Anmelden buttons. Past events disappear automatically. |
| **Termin-Infobox** | – | Date, location, class and register button of one event, for detail pages. Stays linked to the event and updates with every sync. |
| **DTRV-Terminvorschlag** | `[dtrv_termin_vorschlag]` | Public form to suggest an event. |

The button accent colour can be changed in the theme: `.dtrv-termine, .dtrv-infobox, .dtrv-vorschlag { --dtrv-akzent: #006666; }`

## Detail pages

- **Every upcoming event automatically gets a published page.** The page is the target of the "Infos" button in all event lists, taking precedence over an `Infos:` URL from the calendar. Optionally, all event pages live under a parent page (setting "Übergeordnete Seite").
- **A post is optional** and only exists so the event appears on the home page. It is always created as a **draft**:
  - automatically, if enabled for the category,
  - via `Detail: Beitrag` in the event,
  - or manually via the row action "Beitrag anlegen".

  The post's infobox has an "Alle Infos" button linking to the page.
- The `Detail:` line controls this per event: `keine` gives neither page nor post, `Seite` only the page, `Beitrag` both.

Each type is created automatically only **once** per event. A deleted page or post is never recreated automatically, but can be recreated via the row actions. The content is the event description plus a **Termin-Infobox** block. The category's default image becomes the featured image, and posts get the configured post category. If the event is removed from Google, the infobox says so.

## Event suggestions

Anyone can suggest an event via the **DTRV-Terminvorschlag** block: title, category, date range, optional time, location, class (type F/A for Breitensport, W automatically for Wettkampf; Bergfaktor for both; neither for Ausbildung/Verband), info and registration links, description, an image (JPG/PNG/WebP, max. 8 MB), name and email, privacy consent.

- **Spam protection without reCAPTCHA:** hidden honeypot field, minimum fill-in time of 5 seconds, at most 5 suggestions per IP per hour.
- **Email** to the configured address with all details and **approve** / **reject** links. The links open a confirmation page with a button first, so email security scanners cannot trigger them. No WordPress login is needed.
- **Termine › Vorschläge** is the review list with three actions, in this order:
  1. **Eintragen** opens Google Calendar with everything pre-filled, including key lines, and marks the suggestion as approved. The plugin itself never writes to Google, so pick the right calendar and save there.
  2. **Erledigt – Dankes-Mail senden** sends the submitter a thank-you email and then deletes their email address.
  3. **Ablehnen** archives the suggestion and deletes email and image.
- The approve link in the email only marks the suggestion as approved, so someone else can enter it later.
- Each suggestion shows whether WordPress handed the notification mail to the mail server (column "Mail"). Settings has a "Test-Mail senden" button and counts suggestions dropped by spam protection.
- **Privacy:** name and email are stored only until the suggestion is processed. The IP address is only used hashed, for one hour, as a spam counter.

## REST endpoints

All endpoints accept the secret key shown on the settings page (`?schluessel=…`).

| Endpoint | Purpose |
| --- | --- |
| `GET/POST /wp-json/dtrv-termine/v1/abgleich` | Run a sync (for the cron job). |
| `GET /wp-json/dtrv-termine/v1/termine` | All events with detail page status, image status and warnings (also used by the infobox block in the editor). |
| `GET /wp-json/dtrv-termine/v1/vorschlaege` | Open and approved suggestions without contact data, each with a pre-filled Google Calendar link. |

## Release process

1. Test new versions on a copy of the site first (local or staging).
2. Bump the version in `dtrv-termine.php` (plugin header `Version:` **and** `const VERSION`) and in `blocks/*/index.asset.php`.
3. Commit, push, then tag:
   ```
   git tag v0.4.0
   git push origin v0.4.0
   ```
4. The "Release" GitHub Action checks that tag and version match, runs the tests, builds `dtrv-termine.zip` and attaches it to the release. Shortly after, WordPress shows "update available".

## Development

- `includes/Feed.php` reads iCal (sabre/vobject) and expands recurring events. `Parser.php` handles key lines, `Format.php` the date format, `Sync.php` the sync into the post type.
- `Renderer.php` renders the list and infobox, `Detailseiten.php` the draft posts/pages.
- `Vorschlaege.php` and `Formular.php` handle suggestions. `Settings.php`, `PostType.php` and `Rest.php` cover settings, admin list and endpoints.
- Blocks live in `blocks/` and need no build step (plain JS, server-side rendering).
- Tests without WordPress: `composer install`, then `php tests/run.php`. The "Prüfen" action runs them on every push with PHP 8.1 and 8.4.
