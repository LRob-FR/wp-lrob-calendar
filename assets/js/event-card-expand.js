/**
 * Expand/collapse toggle for event cards whose description overflows the clamp.
 *
 * Scans `.lrob-event-excerpt.lrob-cal-clampable` elements, measures whether
 * scrollHeight exceeds clientHeight, and if so inserts a "Show more" toggle
 * button that adds/removes the `.lrob-cal-expanded` class.
 *
 * Why multiple passes: the measurement depends on rendered text height,
 * which depends on the font being loaded. If webfonts load AFTER initial
 * measurement, text may reflow taller and overflow — but the toggle was
 * never added because the early measurement said "fits".
 *
 * Strategy:
 *   1. Run on DOMContentLoaded — initial check.
 *   2. Re-run on document.fonts.ready — after webfonts settle (modern browsers).
 *   3. Re-run on window.load — covers images + late CSS.
 *   4. After window.load, items that STILL look like they fit get their
 *      .lrob-cal-clampable class stripped (drops the fade gradient).
 *
 * Exposed as `window.LRobCalExpand.init(root)` so the AJAX pagination
 * handler can re-bind after swapping the wrapper's innerHTML.
 */
(function () {
    'use strict';

    // Per-element marker on the dataset: 'done' once we've added a toggle.
    var DONE_FLAG = 'lrobExpandDone';

    function getLabel(key, fallback) {
        var i18n = window.lrobCalCardI18n || {};
        return i18n[key] || fallback;
    }

    function overflows(el) {
        // 2px slack avoids sub-pixel rounding false positives.
        return el.scrollHeight - el.clientHeight > 2;
    }

    /**
     * Measure one excerpt. If it overflows, add the toggle and mark done.
     * Idempotent — won't re-add if a toggle already exists.
     */
    function checkOne(el) {
        if (el.dataset[DONE_FLAG]) return;
        if (overflows(el)) addToggle(el);
    }

    /**
     * Run a measurement pass on every clampable excerpt that hasn't gotten
     * a toggle yet. Called from each timing checkpoint.
     */
    function pass(root) {
        var excerpts = (root || document).querySelectorAll(
            '.lrob-event-excerpt.lrob-cal-clampable'
        );
        Array.prototype.forEach.call(excerpts, function (el) {
            // Defer to next frame so layout has settled.
            requestAnimationFrame(function () { checkOne(el); });
        });
    }

    /**
     * Final pass — for items that still look like they fit AFTER everything
     * (fonts, images, layout settled), strip the clampable class to drop the
     * fade gradient. Anything that does overflow has already gotten a
     * toggle in earlier passes.
     */
    function finalize() {
        var excerpts = document.querySelectorAll(
            '.lrob-event-excerpt.lrob-cal-clampable'
        );
        Array.prototype.forEach.call(excerpts, function (el) {
            if (el.dataset[DONE_FLAG]) return;
            if (!overflows(el)) {
                el.classList.remove('lrob-cal-clampable');
            } else {
                // Late-blooming overflow — add the toggle now.
                addToggle(el);
            }
        });
    }

    function addToggle(excerptEl) {
        if (excerptEl.dataset[DONE_FLAG]) return;
        excerptEl.dataset[DONE_FLAG] = '1';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lrob-cal-expand-toggle';
        btn.setAttribute('aria-expanded', 'false');

        var label = document.createElement('span');
        label.className = 'lrob-cal-expand-label';
        label.textContent = getLabel('showMore', 'Show more');

        var icon = document.createElement('span');
        icon.className = 'lrob-cal-expand-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '▾'; // ▾

        btn.appendChild(label);
        btn.appendChild(icon);
        excerptEl.insertAdjacentElement('afterend', btn);

        btn.addEventListener('click', function () {
            var expanded = excerptEl.classList.toggle('lrob-cal-expanded');
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            label.textContent = expanded
                ? getLabel('showLess', 'Show less')
                : getLabel('showMore', 'Show more');
            icon.textContent = expanded ? '▴' : '▾'; // ▴ / ▾
        });
    }

    /**
     * Public entry — called once on DOM ready, then re-called from
     * `.fonts.ready` / `window.load` / by the AJAX pagination handler.
     */
    function init(root) {
        pass(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    // After webfonts settle — text may have grown.
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
        document.fonts.ready.then(function () { init(document); });
    }

    // After full load (images + late stylesheets).
    window.addEventListener('load', function () {
        init(document);
        // One more tick, then finalize: strip clampable from items still
        // fitting, so the fade gradient doesn't show over un-truncated text.
        setTimeout(finalize, 100);
    });

    window.LRobCalExpand = { init: init };
})();
