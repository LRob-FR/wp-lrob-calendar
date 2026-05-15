# LRob - Calendar

A clean, performant event calendar plugin for WordPress with recurring events, click-to-preview month grid, agenda view, AJAX-paginated event lists, and an OpenStreetMap embed on single-event pages.

**Plugin homepage:** [lrob.fr/wordpress/plugins/lrob-calendar](https://www.lrob.fr/wordpress/plugins/lrob-calendar/)

## Features

- **Month grid calendar** — click any event for an in-page preview with image, date range, location, excerpt, and prev/next event navigation
- **Agenda layout** — chronological list view of upcoming events, alternative to the grid
- **Recurring events** — hand-rolled RFC 5545 RRULE engine: daily / weekly / monthly / yearly, BYDAY with ordinals (e.g. "2nd Tuesday"), exception dates, multi-day continuous bars across the month grid
- **Events list block** with list / grid / minimal templates, per-block image display options (contain / cover, small / medium / large), and optional **AJAX pagination** (arrows or numbered)
- **Single event block** to embed one specific event anywhere
- **Single event pages** with date, location, OpenStreetMap embed, cost, ticket link, and contact info — toggleable site-wide if you only use the calendar widgets
- **Image lightbox** on every event thumbnail (click to expand, ESC / outside / image click to close)
- **Card "Show more" toggle** for long event descriptions
- **Categories & tags** with per-category colors and images
- **Import / Export** — JSON, with automatic detection and field-mapping for [All-in-One Event Calendar](https://wordpress.org/plugins/all-in-one-event-calendar/) exports
- **i18n** — French translation included; gettext + JSON for the Gutenberg editor
- **Performance** — conditional asset loading per block, REST endpoint cache, primed post caches, configurable max event age, configurable recurrence limits

## Prerequisites

- **PHP 8.0+**
- **WordPress 6.0+**
- **Gutenberg block editor** — the plugin ships blocks only, no shortcodes

## Installation

1. Download the latest release zip from the [releases page](../../releases) — or run `./release.sh` locally to build one.
2. WordPress admin → *Plugins* → *Add New* → *Upload Plugin* → upload the zip.
3. Activate.
4. Go to *Calendar → Settings* to configure timezone, first day of the week, public-pages toggle, and performance limits.

## Usage

### Creating an event

1. *Calendar → Add Event*
2. Title and description (description appears in card excerpts and popup, expandable via "Show more")
3. **Date & Time** meta box: set start, end, timezone, and event type:
   - **Standard** — has a start and end time (default)
   - **All day** — spans the entire day(s), no specific times
   - **No end time (instant event)** — single moment, no duration
4. **Recurrence** (optional): pick a frequency, set the interval, optionally cap with "After N occurrences" or "On date". Exclude specific dates if needed. Advanced users can paste a raw RRULE.
5. **Location** (optional): venue name, address, city/postal/state/country, lat/lng. Tick "Show map" to embed an OpenStreetMap view on the event page.
6. **Contact & Cost** (optional): contact name/email/phone/URL, cost, "Free event" toggle, ticket URL.
7. **Featured Image** (optional but recommended): shown in cards, popup, and the lightbox.

### Categories and tags

*Calendar → Categories* and *Calendar → Tags* work like standard WordPress taxonomies, with two additions per category:

- **Color** — used for category badges on event cards
- **Image** — for future visual features

### Display: the three blocks

#### Event Calendar (month grid or agenda)

Add via *+ → Widgets → Event Calendar*. Inspector controls:

- **Layout**: Month grid (default) or Agenda list
- **Filter by Category** / **Filter by Tag** — limit the calendar to one taxonomy
- **Link Text** — customizes the popup CTA (default: "View event")
- **Popup Display** panel:
  - Popup size: Compact / Standard / Spacious
  - Show / hide images in the popup
  - Image display: contain (show whole image) or cover (crop to fill)
  - Image height: small / medium / large

Clicking an event opens an in-page popup. Use the popup's left/right arrows (or swipe on mobile) to flick through events chronologically — the calendar auto-switches months as needed.

#### Events List

Add via *+ → Widgets → Events List*. Inspector controls:

- **Events per page** (or **Number of Events** if pagination off)
- **Template**: List / Grid / Minimal
- **Order**:
  - *Auto* (default): upcoming-first when past events are hidden, recent-first when they're shown
  - *Ascending* — oldest first
  - *Descending* — newest first
- **Show Past Events** toggle
- **Enable pagination** + **Pagination style** (arrows with page indicator, or numbered)
- **Display Options**: show/hide images, excerpt, categories. Image display + height settings.
- **Filters**: category and tag

#### Single Event

Embed one specific event anywhere on the site. Search by title or date in the inspector, pick from results, choose template (Full / Compact / Minimal) and image display settings.

### Settings

*Calendar → Settings*:

- **Default Timezone** — used for new events
- **First day of the week** — Auto (Sunday for English locales, Monday otherwise) / Sunday / Monday / Saturday
- **Public event pages** — disable to hide event single pages and taxonomy archives. The popup becomes the only event view. Direct URLs return 404; frontend links are stripped from blocks.
- **Maximum event age** — months. Queries that don't set their own date range ignore events older than this. `0` = no limit. Useful for sites with thousands of historical events.
- **Recurrence limits** — max instances per event (default 500) and max years ahead (default 5). Raise for daily events over long spans.

### Import / Export

*Calendar → Import / Export*:

- **Export** — downloads a JSON file of all events, categories, and tags. Optionally include recurring event instances.
- **Import** — accepts the plugin's own JSON format or an All-in-One Event Calendar export. Skip-existing toggle prevents duplicates by title match.

Image URLs in the import that point to private / localhost hosts are flagged (non-blocking) so you know what to check after migrating to production.

## Development

### Building a release

```bash
git clone git@github.com:LRob-FR/wp-lrob-calendar.git
cd wp-lrob-calendar
./release.sh
```

Output zip lands in `../releases/wp-lrob-calendar-<version>.zip`.

Requirements: `php` (8.0+), `wp` (WP-CLI), `msgfmt` (gettext), `zip`.

The release script:

1. Generates / refreshes `languages/lrob-calendar.pot`
2. Merges new source references into existing `.po` files (`msgmerge`)
3. Compiles each `.po` → `.mo`
4. Generates per-edit.js JSON translation files
5. Bundles everything into a clean, installable zip

### Translation

The plugin ships with English and French. Adding a new locale:

```bash
cp languages/lrob-calendar-fr_FR.po languages/lrob-calendar-de_DE.po
# Edit the file and translate each msgstr value
./release.sh   # regenerates the .mo and .json automatically
```

`.po` and `.pot` files are tracked in git; `.mo` and `.json` are build artifacts and regenerated by `release.sh`.

### Architectural notes

See [CLAUDE.md](CLAUDE.md) for a deeper architectural overview. Highlights:

- **Custom DB schema** — events live in `{prefix}lrob_events`, occurrences in `{prefix}lrob_event_instances`, per-category color/image in `{prefix}lrob_event_category_meta`. Not `postmeta`-based.
- **DB migrations** — `LRob_Calendar_Database::maybe_upgrade()` runs on every load, idempotent, version-gated. Adding a new column = edit `apply_schema()`; renaming = write a migration in `get_migrations()`.
- **Blocks** — three under `blocks/<name>/`, each registered via `register_block_type(__DIR__ . '/blocks/<name>')` reading `block.json`. Conditional asset loading via WordPress's block system.
- **Hand-rolled RRULE engine** — no `sabre/vobject` dependency. RFC 5545 subset.

## Technical Details

| | |
|---|---|
| **WordPress** | 6.0+ |
| **PHP** | 8.0+ |
| **Text domain** | `lrob-calendar` |
| **Custom tables** | `{prefix}lrob_events`, `{prefix}lrob_event_instances`, `{prefix}lrob_event_category_meta` |
| **Custom post type** | `lrob_event` |
| **Taxonomies** | `lrob_event_category`, `lrob_event_tag` |
| **REST namespace** | `lrob-calendar/v1` |

## Support and contributions

Bug reports, feature requests, and pull requests welcome — please [open an issue](https://github.com/LRob-FR/wp-lrob-calendar/issues) first to discuss bigger changes. Direct contact via [lrob.fr/contact](https://www.lrob.fr/contact/).

## Credits

**Developed by [LRob, hébergeur web spécialiste WordPress](https://www.lrob.fr/)**.

## License

GPL-2.0-or-later — same as WordPress. See [LICENSE](LICENSE) for the full text.

## Changelog

### 1.1.1 — Self-hosted updates

- Plugin now checks GitHub releases for new versions and surfaces them as standard WordPress update notices. Hits `api.github.com/repos/LRob-FR/wp-lrob-calendar/releases/latest`, compares against `LROB_CALENDAR_VERSION`, injects the release zip URL into WP's update transient. "View version details" shows the release notes (Markdown → HTML).
- 12h transient cache keeps the API call rate well under GitHub's 60/hour unauthenticated limit even on shared hosting.
- No external library, ~200 lines in `class-lrob-calendar-updater.php`.

### 1.1.0 — Frontend overhaul

A visual + UX rebuild. No feature removals, no DB migrations, no breaking changes to imports — installs over 1.0.x cleanly.

**Design language**
- Flat / modern / breathable. New design-token system (`tokens.css`): 8px spacing scale, radius scale, surface palette, soft elevated shadow, primary / primary-soft / primary-hover / primary-fg derived via `color-mix()`. Old `--lrob-*` names kept as aliases so theme overrides still work.
- New "Appearance" section in the plugin settings page: configurable primary + secondary brand colors (WP color picker). Per-category colors untouched.

**Month grid**
- Crisp light grid, no filled cells. Today indicator is a small primary-colored pill behind the day number, not a full cell tint.
- Events render as colored dot + title pill (one dot per event, tinted by category color).
- Multi-day spans become soft tinted bars.
- Ghost chevron buttons for prev/next month.

**Popup card**
- New layout: date block (large day number over uppercase short month) on the left of the header, title centered, ghost prev/next + close on the right.
- Meta rows with stroke icons (Lucide-style).
- Featured image moved BELOW meta as supporting content, not the hero.
- Primary CTA filled button at bottom-right (sticky to viewport bottom on mobile, `safe-area-inset` respected).

**Events list templates**
- `list` / `full`: date block on the left, content on the right; image moved lower.
- `grid`: image-on-top with a small date-badge overlay in the top-left corner.
- `minimal`: single-line row — date pill + title + optional time.
- Ghost-style pagination (no boxed paginator look).

**Mobile**
- Popup is now a full-screen modal: edge-to-edge, sticky header with the close button, sticky CTA at the bottom.
- Month grid: smaller cells, events become pure colored dots (no titles).
- **Tap a day with events → opens a day-agenda list** of that day's events; tap an event in the list to open its full card.
- Minimum 44px tap targets enforced on interactive elements.

**Icons**
- Stroke-style SVG icon set replacing the previous filled icons. Single source of truth in `class-lrob-calendar-icons.php`.
- New icons: `chevron-left`, `chevron-right`, `x`, `arrow-right`.

### 1.0.1 — Bug fixes, UX polish, mobile popup overhaul

See [v1.0.1 release notes](https://github.com/LRob-FR/wp-lrob-calendar/releases/tag/v1.0.1).

### 1.0.0 — Initial release

**Core**
- Event custom post type with categories and tags (per-category color / image)
- Custom-table storage model with versioned DB migrations
- RFC 5545 RRULE recurrence engine (daily / weekly / monthly / yearly, BYDAY with ordinals, exception dates, multi-day spans)
- Three Gutenberg blocks: calendar, events-list, single-event
- Single-event content injection with OpenStreetMap embed
- Site-wide toggle to disable public event pages
- JSON import / export with All-in-One Event Calendar compatibility

**Calendar block**
- Month grid + agenda layouts
- Click-to-preview popup with image, date range, location, excerpt
- Popup keyboard / swipe / arrow navigation across events (auto-changes month)
- Image lightbox at full resolution
- Per-instance popup customization (size, image display, image height)
- Responsive popup (mobile modal under 600px)

**Events list block**
- List / grid / minimal templates
- Optional AJAX pagination (arrows + indicator, or numbered)
- Next-page preload on idle
- Per-block image display options
- "Show more / less" toggle on long descriptions
- Smart "auto" sort order that follows the past-events toggle

**Admin**
- Live date-format preview honoring WordPress locale
- Conditional form fields (event type radios, free / cost link, map options gated by coordinates)
- Configurable timezone, first-day-of-week, max event age, recurrence limits
- "Visit LRob" link on the plugins screen

**Performance**
- Conditional asset loading via `block.json` (no global frontend JS / CSS)
- REST endpoint transient cache with version-bump invalidation
- Primed post + thumbnail caches before bulk renders
- Skipped recurrence regen when unchanged
- Lazy-loaded card images
- Persistent object-cache for per-category colors
- Configurable safety caps on recurrence generation

**Security**
- Nonce + capability checks on all admin actions
- All SQL via `$wpdb->prepare()`
- Output escaping (`esc_html_e`, `esc_attr`, `wp_kses_post`)
- Try / catch around date parsing in the meta-box save
- Non-blocking warnings for large imports and private-IP image URLs
- REST args schema with explicit sanitization callbacks

**i18n**
- English + French (225+ translated strings)
- Translation workflow baked into `release.sh` (`msgmerge` + `make-pot` + `make-json` + `msgfmt`)

## Roadmap

Open to suggestions — these are on my radar but not yet implemented:

- **iCal / ICS export** — per-event `.ics` download and a subscribable feed for Google / Apple Calendar
- **The Events Calendar (Modern Tribe) import format** — alongside the existing AI1EC support
- **Frontend event submission** with moderation
- **Email reminders / RSVP**
- **Modern date-picker** (Flatpickr) for the admin form — currently uses native `<input type="date">` which follows the browser locale, not the site language
- **Map options beyond OpenStreetMap** — optional Mapbox / Google Maps tile providers
- **More granular CSS class scoping** — sweep remaining bare `lrob-event-*` classes to `lrob-cal-event-*`
- **iCal feed import** — auto-sync from external calendars

PRs welcome.
