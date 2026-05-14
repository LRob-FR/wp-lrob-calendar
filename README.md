# LRob Calendar

A clean event calendar plugin for WordPress with recurring events, categories, locations, import/export, and a click-to-preview month grid.

By [LRob](https://www.lrob.fr).

## Features

- **Month grid** with click-to-preview popups (image, date range, location, excerpt, prev/next nav, swipe on mobile)
- **Agenda layout** alternative — chronological list, no grid
- **Events list block** with list / grid / minimal templates, per-block image display options, and optional AJAX pagination (arrows or numbered)
- **Single event block** for embedding a specific event anywhere
- **Single event pages** with date / location / map (OpenStreetMap embed) / cost / contact sections — togglable site-wide if you only use the calendar widgets
- **Recurring events** via a hand-rolled RFC 5545 RRULE engine (no external lib): daily/weekly/monthly/yearly, BYDAY with ordinals, exception dates, multi-day continuous bars across the month grid
- **Image lightbox** on event thumbnails (click to expand, ESC / outside-click / image-click to close)
- **Import/Export** to JSON, with automatic detection and field-mapping for [All-in-One Event Calendar](https://wordpress.org/plugins/all-in-one-event-calendar/) exports
- **i18n** — French translation included; gettext + JSON for the Gutenberg side
- **Public pages can be disabled** — the calendar popup becomes the only event view, useful for content-light event listings
- **Performance settings** — configurable maximum event age, conditional asset loading per block

## Requirements

- PHP 8.0+
- WordPress 6.0+

## Installation

1. Download the latest release zip from the [releases page](../../releases) — or run `./release.sh` locally to build one.
2. WordPress admin → *Plugins* → *Add New* → *Upload Plugin* → upload the zip.
3. Activate.
4. *Calendar → Settings* to configure timezone, first day of the week, public-pages toggle, and max event age.

## Development

```bash
git clone git@github.com:LRob-FR/lrob-calendar.git
cd lrob-calendar
./release.sh   # builds .pot, compiles .po → .mo, generates JS translation JSONs, zips to ../releases/
```

`release.sh` is idempotent — run it any time strings change. It expects `wp` (WP-CLI), `msgfmt` (gettext), `zip`, and `php` on `$PATH`.

The plugin's storage model uses three custom MySQL tables (`{prefix}lrob_events`, `{prefix}lrob_event_instances`, `{prefix}lrob_event_category_meta`) rather than WordPress `postmeta`. Schema migrations are handled by `LRob_Calendar_Database::maybe_upgrade()`.

See [CLAUDE.md](CLAUDE.md) for an architectural overview.

## License

GPL-2.0+ — same as WordPress.
