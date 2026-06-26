/**
 * Events-list "View details" trigger.
 *
 * Each row's trigger (the .lrob-event-details-btn ghost button AND/OR the
 * clickable thumbnail) carries the full event JSON in its `data-event`
 * attribute plus a `data-popup-for` event-id. Clicking it hands the JSON
 * off to the shared event-popup module (assets/js/event-popup.js), which
 * renders the card and owns the rest of the UX.
 *
 * Sibling navigation (prev/next inside the popup) walks the trigger
 * elements in DOM order — i.e. the order of events as they appear in the
 * list. Bounded by the events on the current page (pagination doesn't
 * cross page boundaries).
 *
 * Depends on event-popup.js exposing window.LRobEventPopup.
 */
(function () {
    'use strict';

    function getPopupContainer(triggerEl) {
        // Each events-list block has its own shared popup container at the
        // wrapper level (rendered server-side, see events-list/render.php).
        var wrapper = triggerEl.closest('.lrob-cal-events-list-wrapper');
        if (!wrapper) return null;
        return wrapper.querySelector('.lrob-events-list-popup');
    }

    function parseEvent(triggerEl) {
        var raw = triggerEl.getAttribute('data-event');
        if (!raw) return null;
        try { return JSON.parse(raw); } catch (_e) { return null; }
    }

    /**
     * All trigger elements (buttons OR clickable thumbs) in the list,
     * in DOM order. Used to compute prev/next siblings.
     */
    function siblingsAroundId(triggerEl, eventId) {
        var wrapper = triggerEl.closest('.lrob-cal-events-list-wrapper');
        if (!wrapper) return { prev: null, next: null };
        // Use only the row-level details buttons as the canonical sibling
        // sequence (the image trigger is a duplicate of the same event, so
        // counting both would double-count).
        var triggers = wrapper.querySelectorAll('.lrob-event-details-btn[data-event]');
        var sorted = [];
        for (var i = 0; i < triggers.length; i++) {
            var ev = parseEvent(triggers[i]);
            if (ev) sorted.push(ev);
        }
        var idx = -1;
        for (var j = 0; j < sorted.length; j++) {
            if (String(sorted[j].id) === String(eventId)) { idx = j; break; }
        }
        if (idx === -1) return { prev: null, next: null };
        return {
            prev: idx > 0 ? sorted[idx - 1] : null,
            next: idx < sorted.length - 1 ? sorted[idx + 1] : null,
        };
    }

    function openFromTrigger(triggerEl) {
        if (!window.LRobEventPopup) return;
        var event = parseEvent(triggerEl);
        var container = getPopupContainer(triggerEl);
        if (!event || !container) return;
        var siblings = siblingsAroundId(triggerEl, event.id);
        window.LRobEventPopup.open(container, event, {
            siblings: siblings,
            onNavigate: function (targetId, direction) {
                // Find the trigger whose data-event id matches targetId, then
                // navigate to its event. Walks the same canonical button list.
                var wrapper = container.closest('.lrob-cal-events-list-wrapper');
                if (!wrapper) return;
                var triggers = wrapper.querySelectorAll('.lrob-event-details-btn[data-event]');
                for (var i = 0; i < triggers.length; i++) {
                    var ev = parseEvent(triggers[i]);
                    if (ev && String(ev.id) === String(targetId)) {
                        var newSiblings = siblingsAroundId(triggers[i], targetId);
                        window.LRobEventPopup.navigateTo(container, ev, newSiblings, direction);
                        return;
                    }
                }
            },
        });
    }

    function init() {
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-popup-for][data-event]');
            if (!trigger) return;
            e.preventDefault();
            openFromTrigger(trigger);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
