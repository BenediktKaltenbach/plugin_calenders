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
| `Bild:` | image URL | featured image of the event's page/post (see below) |
| `Anzeige:` | free text, e.g. `regelmäßig` | shown instead of the date (list, infobox, map popup); listed first, followed by the other events by date, recurring events listed once |
| `Koordinaten:` | `50.7151, 7.1393` or a Google Maps / OpenStreetMap link | exact position on the map (see below) |

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

- **Every upcoming event automatically gets a published page.** The page is the target of the "Infos" button in all event lists, taking precedence over an external `Infos:` URL from the calendar.
- **Exception, no duplicate pages:** if `Infos:` already points to a page on this website (e.g. `https://dtrv.net/osterchallenge/`), that page *is* the event's page. No extra page is created, and "Infos" links there. `Detail: Seite` forces an extra page anyway. Optionally, all event pages live under a parent page (setting "Übergeordnete Seite").
- **A post is optional** and only exists so the event appears on the home page. It is always created as a **draft**:
  - automatically, if enabled for the category,
  - via `Detail: Beitrag` in the event,
  - or manually via the row action "Beitrag anlegen".

  The post's infobox has an "Alle Infos" button linking to the page. In the infobox, an `Infos:` link is labelled "Website des Veranstalters" (other sites) or "Ausführliche Infos" (a page on this website).
- The `Detail:` line controls this per event: `keine` gives neither page nor post, `Seite` only the page, `Beitrag` both.

Each type is created automatically only **once** per event. A deleted page or post is never recreated automatically, but can be recreated via the row actions. The content starts with a **Termin-Infobox** block (date, location, class, register button), followed by the event description. Pages created before 0.6.0 get the infobox moved to the top once, unless they were rearranged by hand. The category's default image becomes the featured image, and posts get the configured post category. If the event is removed from Google, the infobox says so.

## Featured images from the calendar

Attach a photo to the Google Calendar event, or add a line `Bild: https://…`. The plugin then uses that image as the featured image of the event's page and post. The plugin uses no Google API, so **Drive files must be shared as "Anyone with the link"**. The easiest way is a Drive folder shared that way: every photo placed in it is public automatically.

- Source: the `Bild:` line wins, otherwise the first image attachment (JPG/PNG/WebP; other attachments such as PDFs are ignored).
- Each image is downloaded **once** into the media library and reused on later syncs. A URL pointing to the site's own media library is used directly without downloading.
- It replaces only an empty featured image, the category default or a previous calendar image. **An image chosen by hand is never overwritten.**
- If the download fails (e.g. the file is not shared publicly, not an image, or over 15 MB), the event list shows the reason in the "Bild" column. The download is retried at most every 6 hours.

## Coordinates (map position)

Every event gets coordinates for the map. They are resolved in this order, first match wins:

1. **Chosen by hand** in the event's admin screen: box "Ort auf der Karte" › "Ort suchen" › pick one of the OpenStreetMap matches. This survives every sync and can be reset to automatic.
2. **Key line `Koordinaten:`** in the Google event, either numbers (`50.7151, 7.1393`) or a link. **A link is the most reliable way to get the exact spot:** in Google Maps or OpenStreetMap, find the start point, click "Share" and paste the link. Short links like `maps.app.goo.gl/…` work too.
3. The **GEO** field of the calendar entry, if present.
4. The **location text**, looked up automatically at OpenStreetMap (Nominatim). This only happens for upcoming events and at most 15 lookups per sync. Results are cached for 30 days, with at most one request per second, in line with the Nominatim usage policy.

The event list shows the coordinates in the "Karte" column with a link to check them on OpenStreetMap, plus a warning if a place was not found or a `Koordinaten:` line could not be read.

The **suggestion form** has a "Ort suchen" button next to the location field. It shows up to five OpenStreetMap matches to choose from; there is no search-as-you-type, which Nominatim does not allow. A Google Maps / OpenStreetMap / Apple Maps link pasted into the same field works too: the coordinates are taken from it and the link is passed on to the board. The coordinates are included in the notification mail and are put into the pre-filled Google event as a `Koordinaten:` line.

## Writing to Google Calendar (optional)

By default the plugin only reads the calendars. To let **Annehmen** create events directly, give it a Google **service account**, a technical identity inside the association's own Google Cloud project. It needs no login, its access never expires, and it can only touch the calendars shared with it. One-time setup, about 10 minutes, all with the association's Google account:

1. Open [console.cloud.google.com](https://console.cloud.google.com/) and create a project, e.g. "DTRV Website".
2. **APIs & Services › Library**: search "Google Calendar API" and click **Enable**.
3. **IAM & Admin › Service Accounts › Create service account**: name e.g. `dtrv-termine`. Skip the optional role and access steps and click **Done**.
4. Open the new service account › **Keys › Add key › Create new key › JSON**. A `.json` file is downloaded. Keep it secret, it is the account's password.
5. In **Google Calendar**, for each of the four calendars: Settings › **Share with specific people** › add the service account's email (`…@….iam.gserviceaccount.com`) with **"Make changes to events"**.
6. Upload the JSON file to the web server **outside the public web folder** (with All-Inkl e.g. via FTP one level above the WordPress folder). Then add to `wp-config.php`:
   ```php
   define( 'DTRV_TERMINE_GOOGLE_KEY_FILE', '/www/htdocs/w0123456/dtrv-google.json' );
   ```
7. Termine › Einstellungen › "Google Kalender": click **Verbindung testen**. All four calendars should show ✅.

The key never goes into the database or this repository. If the website were compromised, an attacker could change these four calendars, but nothing else in the Google account. To revoke access, delete the key in Google Cloud or unshare the calendars.

If step 4 is blocked ("Service account key creation is disabled"), the account belongs to a Google Workspace organisation with a key policy. An admin can allow keys for this project under IAM › Organisation policies.

## Events on the Tretrollerkarte

If the [My Map Plugin](https://github.com/BenediktKaltenbach/map_plugin) (1.2.0 or newer) is active, upcoming events with coordinates appear on its map. They show as calendar-style markers in a separate legend group "Termine", with one category per calendar and its own colour. The popup shows date, location, class, status and links to "Infos" (the event page) and "Anmelden".

- No copies are created: the map reads the mirrored events through the filters `mmp_categories` / `mmp_markers`. Past, removed or cancelled events disappear automatically.
- For recurring events only the next date is shown.
- Which categories appear is set under Termine › Einstellungen › Detailseiten › "Auf der Tretrollerkarte" (all by default).
- The map's "📅 Termin einreichen" button automatically points to the published page containing the suggestion form (block or shortcode). Following that link opens the collapsed form right away.

## Event suggestions

Anyone can suggest an event via the **DTRV-Terminvorschlag** block. It starts collapsed as a "+ Termin einreichen" button with a short hint line ("× Formular schließen" when open; opening it scrolls to the form and focuses the first field); next to it, "Zur Tretrollerkarte" links to the page with the map (found automatically: the published page containing `[my_map]`, hidden if there is none). Fields: title, category, date range, optional time, location, class (type F/A for Breitensport, W automatically for Wettkampf; Bergfaktor for both; neither for Ausbildung/Verband), info and registration links, description, an image (JPG/PNG/WebP, max. 8 MB), name and email, privacy consent.

- **Spam protection without reCAPTCHA:** hidden honeypot field, minimum fill-in time of 5 seconds, at most 5 suggestions per IP per hour.
- **Email** to the configured address with all details and **approve** / **reject** links. The links open a confirmation page with a button first, so email security scanners cannot trigger them. No WordPress login is needed.
- **Termine › Vorschläge** is the review list, with tabs **Offen** (default: new and accepted), **Eingetragen**, **Abgelehnt** and **Alle**.
- **Annehmen** (with Google write access, see below): the plugin creates the event directly in the right Google Calendar, with all key lines (class, coordinates, links, image). The same happens with the "Annehmen" link in the notification mail. Without write access, **Eintragen** opens Google Calendar pre-filled instead, and you pick the calendar and save.
- **Ablehnen** archives the suggestion and deletes email and image.
- **Thank-you mail only once the event is live:** after every sync, open suggestions whose event now exists on the website are marked "Eingetragen" and the submitter gets a thank-you mail with a link to the event's page. Events created via "Annehmen" are matched exactly by their Google event ID. Otherwise the date must match, plus either exactly the same title (only extra spaces are ignored) or the only event of the suggestion's category on that day. For special cases there is "Erledigt (ohne Dankes-Mail)".
- Each suggestion shows whether WordPress handed the notification mail to the mail server (column "Mail"). Settings has a "Test-Mail senden" button and counts suggestions dropped by spam protection.
- **Privacy:** name and email are stored only until the suggestion is processed. The IP address is only used hashed, for one hour, as a spam counter.

## REST endpoints

All endpoints accept the secret key shown on the settings page (`?schluessel=…`).

| Endpoint | Purpose |
| --- | --- |
| `GET/POST /wp-json/dtrv-termine/v1/abgleich` | Run a sync (for the cron job). |
| `GET /wp-json/dtrv-termine/v1/termine` | All events with detail page status, image status and warnings (also used by the infobox block in the editor). |
| `GET /wp-json/dtrv-termine/v1/orte?q=…` | OpenStreetMap place search used by the form and admin (public, max. 30 searches per IP per hour, cached). |
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
