/**
 * Expand/collapse toggle for event cards whose description overflows the clamp.
 *
 * On init, scan for `.lrob-event-excerpt.lrob-cal-clampable` elements, measure
 * whether their scrollHeight exceeds the visible clampHeight, and if so insert
 * a "Show more" toggle button after them. Clicking the button toggles the
 * `.lrob-cal-expanded` class which removes the max-height and fade overlay.
 *
 * Exposed as `window.LRobCalExpand.init(root)` so the AJAX pagination handler
 * can re-bind after swapping the wrapper's innerHTML.
 */
(function () {
    'use strict';

    var BOUND_FLAG = 'lrobExpandChecked';

    function getLabel(key, fallback) {
        var i18n = window.lrobCalCardI18n || {};
        return i18n[key] || fallback;
    }

    function init(root) {
        root = root || document;
        var excerpts = root.querySelectorAll('.lrob-event-excerpt.lrob-cal-clampable');

        Array.prototype.forEach.call(excerpts, function (el) {
            if (el.dataset[BOUND_FLAG]) return;
            el.dataset[BOUND_FLAG] = '1';

            // Defer to next frame so layout has settled — scrollHeight needs the
            // browser to have laid out the content first.
            requestAnimationFrame(function () {
                if (el.scrollHeight - el.clientHeight > 2) {
                    addToggle(el);
                } else {
                    // Content fits the clamp — strip the class so we don't
                    // render the fade-out gradient over text that isn't truncated.
                    el.classList.remove('lrob-cal-clampable');
                }
            });
        });
    }

    function addToggle(excerptEl) {
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
        icon.textContent = '▾';

        btn.appendChild(label);
        btn.appendChild(icon);
        excerptEl.insertAdjacentElement('afterend', btn);

        btn.addEventListener('click', function () {
            var expanded = excerptEl.classList.toggle('lrob-cal-expanded');
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            label.textContent = expanded
                ? getLabel('showLess', 'Show less')
                : getLabel('showMore', 'Show more');
            icon.textContent = expanded ? '▴' : '▾';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.LRobCalExpand = { init: init };
})();
