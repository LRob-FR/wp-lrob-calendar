# 📝 Changelog

All notable changes to **LRob – Calendar** are documented here.
This project follows [semantic versioning](https://semver.org/).

---

## 1.2.0 — A brand-new event editor 🎉

The biggest update yet: managing events no longer means wrestling with the
WordPress post list and the block editor.

**✨ New custom event manager**
- A clean, dynamic admin screen replaces the stock post table as the home of
  your events — search, filter, sort, duplicate and trash, all without page
  reloads.
- Create and edit events in an **in-page modal**: title, dates, recurrence,
  location, contact, cost, categories, tags and image — you never leave the
  screen.
- **Simple-by-default description editor** (bold / italic / lists / links, with a
  raw-HTML view and a gentle length hint) so event cards stay tidy. Power users
  can jump to the full WordPress editor from the modal header at any time.
- **Categories & tags side panel** — add, rename, recolour and delete right next
  to your events. The redundant taxonomy menus are gone.

**🔁 Recurring events that actually show up**
- Recurring events now appear on **every occurrence** across the calendar and
  lists (previously only the next one showed), with per-view caps so a daily or
  never-ending series can't flood the agenda or a list.

**📥 Migrate from other plugins**
- One-step migration from **The Events Calendar** and **All-in-One Event
  Calendar**: export their data to JSON from the Import/Export screen, then
  import it into LRob Calendar.

**💅 Polish**
- Dark-theme friendly: event titles inherit your theme's text colour instead of
  hard-coded black.
- 24-hour time inputs on sites configured for 24h (no more AM/PM).
- The classic “Add New” and admin-bar “+ New” now open the new modal.
- Fixes: popup prev/next navigation with recurring events, events-list ordering,
  end-date defaulting to the start, and more.

---

## 1.1.5 — Calendar cache + card fixes

- **Stale calendar after edits**: the calendar block fetches events over a REST `GET`, but neither the request nor the response forbade caching — so a browser/page-cache layer (e.g. W3 Total Cache "Browser Cache") could serve outdated events until a hard reload. The endpoint now sends `nocache_headers()` and the front-end adds a cache-buster.
- **Long links overflowing cards**: card content now wraps with `overflow-wrap: break-word`.
- **No way to see details in the minimal template**: minimal rows now carry a compact icon-only "i" trigger that opens the shared popup card.

## 1.1.4 — Snappier self-update checks

- **Update cache TTL: 12h → 1h.** New GitHub releases surface to the Updates screen within an hour.
- **Force-refresh on admin intent.** "Check again" (`?force-check=1`) and landing on `update-core.php` bypass the plugin's release cache for that request.

## 1.1.3 — Fixes: backslashes, icon alignment, "Show more", popup parity

- **Backslash bug**: text fields no longer accumulate `\` before apostrophes/quotes on save (missing `wp_unslash()`). Re-save an affected event once to clean existing values.
- **Icon alignment**: meta-row icons top-align with the first line of the label.
- **"Show more" missing on long descriptions**: the clamp now re-measures on `document.fonts.ready` and `window.load`.
- **Popup description parity**: `format_event_for_client()` always ships the full `descriptionHtml`, so the calendar and events-list popups render identically.

## 1.1.2 — Full event info everywhere + demo events + polish

- **Popup card**: full address and full contact block as separate icon rows.
- **Events list rows**: new Location and Contact-info display controls; time stacks under the date; "View details" button is the default description mode.
- **New installs**: public event pages OFF by default (avoids duplicate content for embedded calendars).
- **Demo events generator**: one-click realistic sample events, clearly marked `[DÉMO]`.
- **Branding toggle**: small "© Calendar by LRob" credit, on by default, disable from Settings → Appearance.
- **Uninstall safety**: uninstall no longer wipes plugin data.

## 1.1.1 — Self-hosted updates

- The plugin checks GitHub releases and surfaces new versions as standard WordPress update notices (no external library, ~200 lines).
- 12h transient cache keeps the API call well under GitHub's rate limit.

## 1.1.0 — Frontend overhaul

A visual + UX rebuild — no feature removals, no DB migrations, no breaking changes.

- **Design language**: flat / modern / breathable, with a new design-token system (`tokens.css`) and configurable brand colours.
- **Month grid**: crisp light grid, today as a primary pill, events as coloured dot + title pills, multi-day soft bars.
- **Popup card**: date block on the left, centered title, ghost prev/next/close, stroke icons, primary CTA.
- **Events list templates**: `list` / `full` / `grid` / `minimal`, ghost pagination.
- **Mobile**: full-screen popup modal, tap-a-day day-agenda, 44px tap targets.
- **Icons**: stroke-style SVG set, single source of truth.

## 1.0.1 — Bug fixes, UX polish, mobile popup overhaul

See the [v1.0.1 release notes](https://github.com/LRob-FR/wp-lrob-calendar/releases/tag/v1.0.1).

## 1.0.0 — Initial release

- Event custom post type with categories and tags (per-category colour / image).
- Custom-table storage with versioned DB migrations.
- RFC 5545 RRULE recurrence engine (daily / weekly / monthly / yearly, BYDAY with ordinals, exception dates, multi-day spans).
- Three Gutenberg blocks: calendar, events-list, single-event.
- Single-event pages with OpenStreetMap embed; site-wide toggle to disable them.
- JSON import / export with All-in-One Event Calendar compatibility.
- Conditional asset loading, REST transient cache, primed caches, recurrence caps.
- Security: nonces, capability checks, prepared SQL, output escaping.
- English + French translations.
