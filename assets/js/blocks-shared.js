/**
 * Shared script handle for the block editor.
 *
 * This file is intentionally near-empty: its real payload is the inline
 * `lrobCalendarBlocks` global injected via wp_localize_script() in PHP
 * (categories + tags lookup used by every block's inspector dropdowns).
 *
 * Each block.json declares `["lrob-calendar-blocks-shared", "file:./edit.js"]`
 * so the data is on the page before any edit.js runs.
 */
(function () {
    'use strict';
    // Reserved namespace for future shared editor helpers.
    window.lrobCalendar = window.lrobCalendar || {};
})();
