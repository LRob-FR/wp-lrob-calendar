# 📅 LRob — Calendar

### A clean, fast event calendar for WordPress — without the bloat.

> Recurring events, a beautiful month grid & agenda, click-to-preview popups, and
> a modern in-page editor. No page builder required, no SaaS, no clutter.

**Plugin homepage:** [lrob.fr/wordpress/plugins/lrob-calendar](https://www.lrob.fr/wordpress/plugins/lrob-calendar/)

---

## 🚀 Highlights

- 🗓️ **Month grid & agenda** — click any event for an in-page preview (image,
  dates, location, description) with prev/next navigation and a full-res
  lightbox.
- ✍️ **Modern event editor** — manage everything from one clean screen; create &
  edit in a dynamic modal, no page reloads, no block editor needed.
- 🔁 **Recurring events** — daily / weekly / monthly / yearly, “2nd Tuesday”, end
  after *N* or on a date, exception dates. Shown on **every** occurrence.
- 🧩 **Three blocks** — Calendar, Events List (list / grid / minimal + AJAX
  pagination), and Single Event.
- 📍 **Locations** — venue, address, coordinates, and an OpenStreetMap embed on
  event pages.
- 🏷️ **Categories & tags** — per-category colours, managed inline from the events
  screen.
- 📥 **Import / Export & migration** — JSON import/export, plus one-step migration
  from **The Events Calendar** and **All-in-One Event Calendar**.
- 🌗 **Theme-friendly** — looks good on light and dark themes; assets load only
  where a block is used.
- 🌍 **Translation-ready** — English + French included.
- 🔄 **Self-updating** — new versions arrive in your Updates screen, straight
  from GitHub.

---

## 🧩 At a glance

| | |
|---|---|
| **Requires WordPress** | 6.0+ |
| **Requires PHP** | 8.0+ |
| **Editor** | Custom screen + modal (Gutenberg optional) |
| **Text domain** | `lrob-calendar` |
| **Build step** | None — no Composer, no npm |

---

## 📦 Installation

1. Download the latest release zip from the [releases page](../../releases) — or
   run `./release.sh` locally to build one.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → upload the zip.
3. Activate.
4. Open **Calendar → Settings** to set the timezone, first day of the week, the
   public-pages toggle, and a few performance limits.

---

## ⚡ Quick start

1. Go to **Calendar** — you land on the custom management screen.
2. Click **+ New event**. The editor opens in a modal:
   - **Title** and a short **description** (simple formatting — bold, italic,
     lists, links).
   - **When** — start/end date & time, timezone, or mark it *all-day* / *instant*.
   - **Recurrence** *(optional)* — frequency, interval, the weekdays, and an end
     condition. Complex rules are preserved and editable in the WordPress editor.
   - **Location, contact, cost** *(optional)*.
   - **Categories & tags**, and a **featured image**.
3. Save — it appears instantly in the list and on your calendar.

Need the full block editor for a particular event? The **“→ WordPress editor”**
link in the modal header takes you there at any time.

---

## 🧱 The blocks

- **Event Calendar** — month grid *or* agenda layout, with the click-to-preview
  popup and lightbox.
- **Events List** — `list`, `grid`, or `minimal` template, optional AJAX
  pagination, and an optional, capped number of recent past events.
- **Single Event** — embed one specific event anywhere.

Each block loads its own CSS/JS only when present on the page.

---

## 📥 Migrating from another plugin

Already using another calendar? On **Calendar → Import / Export**, the *“Migrate
from another plugin”* box detects data from **The Events Calendar** or
**All-in-One Event Calendar**, exports it to a JSON file, and you import that
file right back — events, dates, recurrence, locations, categories and images
included.

---

## 🌍 Translations

Ships with **English** and **French**. The whole pipeline lives in `release.sh`
(`make-pot` → translate `.po` → `make-json` for the editor → `msgfmt`). Add a
locale by dropping a `languages/lrob-calendar-<locale>.po` and running the
script.

---

## 🛠️ For developers

- **No build tooling.** Plain PHP + vanilla JS; blocks use `wp.element`/`wp.i18n`
  without JSX. Ship by copying the folder into `wp-content/plugins/`.
- **Storage.** Events live in three custom tables (not postmeta) for fast
  date-range queries; recurrence occurrences are materialised into an instances
  table.
- **REST API.** `…/wp-json/lrob-calendar/v1/events` (public, read-only) powers
  the front-end; an authenticated admin namespace powers the editor.
- **Release.** `./release.sh` regenerates translations and builds the
  distributable zip.

See [`CLAUDE.md`](CLAUDE.md) for the full architecture notes.

---

## 🗺️ Roadmap

Ideas on the radar (suggestions welcome):

- **iCal / ICS** — per-event `.ics` download and a subscribable feed.
- **Frontend event submission** with moderation.
- **Email reminders / RSVP.**
- **Extra map providers** beyond OpenStreetMap.

PRs welcome.

---

## 📝 Changelog

See [**CHANGELOG.md**](CHANGELOG.md).

---

## 💬 Feedback & contributions

Bug reports, feature ideas and pull requests welcome on the
[GitHub issue tracker](https://github.com/LRob-FR/wp-lrob-calendar/issues).

---

## 📜 License & credits

Plugin code: **GPL-2.0-or-later**. See [`LICENSE`](./LICENSE).

### Built by

**[LRob](https://www.lrob.fr)** — WordPress web hosting specialist based in
Orléans, France.

- 📦 Plugin home: <https://www.lrob.fr/wordpress/plugins/lrob-calendar/>
- 🐛 Issues: <https://github.com/LRob-FR/wp-lrob-calendar/issues>
- 💼 Hosting service: <https://www.lrob.fr>

---

> *Your events. Your site. No SaaS.*
