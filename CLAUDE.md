# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

WordPress plugin: **LRob - Calendar** — event calendar with recurring events, categories, locations, and import/export. Standalone plugin, no build tooling, no test suite, no Composer/npm. Requires PHP 7.4+ and WordPress 5.8+.

## Build / lint / test

There are none. Changes ship by copying the directory into a WP install's `wp-content/plugins/`. There is no `composer.json`, `package.json`, PHPUnit, or PHPCS configuration — do not invent commands.

## Release / translations

`./release.sh` is the single build entry point. **Run it yourself whenever needed** — no need to ask the user. It:
1. Regenerates `languages/lrob-calendar.pot` via `wp i18n make-pot` (covers PHP + the blocks JS, since the editor uses `wp.i18n.__`).
2. Compiles every `languages/*.po` → `.mo` with `msgfmt`.
3. Generates `languages/*.json` from each `.po` via `wp i18n make-json --no-purge` (required for `wp.i18n.__` strings to be translated in the block editor).
4. Zips the plugin into `../releases/lrob-calendar-<version>.zip` (excludes `.sh`, `.po`, `.pot`, `.claude/`, `CLAUDE.md`).

**JS translations require both**: (a) `wp_set_script_translations($handle, 'lrob-calendar', LROB_CALENDAR_PATH . 'languages')` called after the script is enqueued — already done in `LRob_Calendar::enqueue_block_editor_assets()`; (b) the matching JSON file shipped in `languages/`. The JSON filename is `lrob-calendar-<locale>-<md5-of-source-path>.json` — `wp i18n make-json` handles the hashing automatically.

Translation workflow when strings change:
- Add/modify strings using `__('...', 'lrob-calendar')` (PHP) or `wp.i18n.__('...', 'lrob-calendar')` (blocks JS) — text domain is always `lrob-calendar`.
- Run `./release.sh` to refresh the `.pot`.
- Update `languages/lrob-calendar-fr_FR.po` to add French translations for any new `msgid` entries (the project is developed in English and translated to French).
- Run `./release.sh` again to compile the updated `.po`.

## Architecture

### Entry point and autoloader

`lrob-calendar.php` registers a custom autoloader that maps `LRob_Calendar_X_Y` → `includes/class-lrob-calendar-x-y.php`. The main `LRob_Calendar` singleton (loaded on `plugins_loaded`) explicitly `require_once`s every class anyway — the autoloader is a fallback. Admin-only classes (`Admin`, `Meta_Boxes`) only load when `is_admin()`.

### Storage model — this is the unusual part

The plugin does **not** use WordPress `postmeta` for event fields. It uses three custom tables created in `LRob_Calendar_Database::create_tables()`:

- **`{prefix}lrob_events`** — one row per event, keyed by `post_id`. Holds all event fields (start/end timestamps, timezone, recurrence rules, location, contact, cost, iCal metadata). Mirrors the `LRob_Calendar_Event::$defaults` array.
- **`{prefix}lrob_event_instances`** — materialized occurrences for recurring events. Rebuilt by `Event::update_instances()` on every save: deletes all rows for `post_id`, then inserts every computed occurrence. Indexed on `(post_id, start)` for calendar range queries.
- **`{prefix}lrob_event_category_meta`** — color + image per category term (keyed by `term_id`). Read/written directly by `Admin` and `Blocks`; not exposed through `get_term_meta`.

`LRob_Calendar_Event::get_events()` joins `lrob_events` with `wp_posts` (and optionally `term_relationships`) — it bypasses `WP_Query` entirely for date-range filtering. When adding event fields, update both `$defaults` in `class-lrob-calendar-event.php` and the schema in `class-lrob-calendar-database.php`.

### Post type and capabilities

`lrob_event` post type uses `map_meta_cap` with the custom `lrob_event`/`lrob_events` capability set, which `Post_Types::add_capabilities()` grants to `administrator` and `editor` roles on every `register()` call (currently runs every request). Two taxonomies: `lrob_event_category` (hierarchical) and `lrob_event_tag`.

### Recurrence engine

`LRob_Calendar_Recurrence` is a hand-rolled RFC 5545 RRULE parser — there is no `sabre/vobject` or similar dependency. Supports FREQ, INTERVAL, COUNT, UNTIL, BYDAY (with nth prefix like `2MO`/`-1FR`), BYMONTH, BYMONTHDAY, plus RDATE/EXDATE lists. Hard caps: 500 instances, 5 years out. Output is consumed by `Event::update_instances()` to populate the instances table.

### Rendering — blocks, not shortcodes

Three Gutenberg blocks under `blocks/<name>/`, each registered with `register_block_type(__DIR__ . '/blocks/<name>')` so WordPress reads `block.json` for metadata and conditional asset loading:

- `blocks/calendar/` — month grid with click-to-preview popup + lightbox, or chronological agenda layout. Inlines ±2 months in `data-events`; fetches more via REST as the user navigates. Frontend behavior in `view.js`.
- `blocks/events-list/` — list/grid card layout (uses shared `assets/css/event-card.css`). Supports optional AJAX pagination via `?lrob_calendar_page=N`.
- `blocks/single-event/` — render a specific event (also uses the shared card styles).

> Removed in this round: the `upcoming` block. Use `events-list` with `template=minimal`, `showPast=false`, and a small `limit` to replicate the same look.

Each block directory holds: `block.json` (metadata + asset handles), `edit.js` (editor component, plain ES5 — no JSX, no build step), `render.php` (server-side render), and (for most) a `style.css`. Render callbacks delegate to `LRob_Calendar_Block_Helpers::render_event_card()` for the shared card output.

**Conditional asset loading.** All styles and scripts are pre-registered in `LRob_Calendar_Blocks::register_assets()` (init priority 5) with predictable handles and dependency chains; `block.json` references them by handle. WordPress enqueues only the assets for blocks present on the current page — no always-on `wp_enqueue_scripts` for the frontend. The lone exception is the `the_content` meta injection for the single-event page (in `LRob_Calendar_Single_Event`), whose CSS is conditionally enqueued via `is_singular(POST_TYPE)`.

Shared assets live in `assets/css/{tokens,event-card,single-event-page,blocks-editor}.css` and `assets/js/blocks-shared.js`. The blocks-shared script is empty by design — its purpose is to carry the inline `lrobCalendarBlocks` global (categories + tags lookup) via `wp_localize_script`, since every block's `editorScript` lists it as a dependency.

### REST API

`/wp-json/lrob-calendar/v1/events` and `/events/{id}` — public (`permission_callback = __return_true`), used by the front-end calendar JS for dynamic month loading.

### Import format compatibility

`LRob_Calendar_Import` detects All-in-One Event Calendar (AI1EC) exports via `is_ai1ec_format()` and remaps their nested `location`/`contact`/`cost`/`ical` structures plus alternate field names (`term_color` vs `color`, `uid` vs `ical_uid`, etc.). When adding new event fields to import/export, support both flat and nested input keys, matching the existing pattern in `import_event()`.

### Cleanup

- Post deletion → `Admin::delete_event_data()` (on `before_delete_post`) removes the `lrob_events` and `lrob_event_instances` rows.
- Plugin uninstall → `uninstall.php` drops all three custom tables, deletes all `lrob_event` posts, term taxonomy rows, and `lrob_calendar_*` options.
