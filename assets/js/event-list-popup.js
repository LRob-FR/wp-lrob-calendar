/**
 * Events-list "View details" → inline popup card.
 *
 * Each event row optionally has a `.lrob-event-details-btn` button followed
 * by a `<div class="lrob-cal-popup lrob-events-list-popup" data-popup-id="N">`
 * sibling holding the full event card. Clicking the button toggles its
 * matching popup; ESC / backdrop / close button hide it.
 *
 * Independent of the calendar block's view.js — the events-list block doesn't
 * have access to that runtime. Re-uses the .lrob-cal-popup CSS classes for a
 * visually identical popup card.
 */
(function () {
    'use strict';

    var openPopup = null;

    function findPopupFor(id) {
        return document.querySelector(
            '.lrob-events-list-popup[data-popup-id="' + id + '"]'
        );
    }

    function show($popup) {
        if (!$popup) return;
        // Close any other one first — only one popup open at a time.
        if (openPopup && openPopup !== $popup) hide(openPopup);
        $popup.style.display = 'flex';
        // Force a reflow so the opacity transition triggers.
        // eslint-disable-next-line no-unused-expressions
        $popup.offsetHeight;
        $popup.classList.add('is-shown');
        $popup.setAttribute('aria-hidden', 'false');
        document.body.classList.add('lrob-cal-popup-open');
        openPopup = $popup;
    }

    function hide($popup) {
        if (!$popup) return;
        $popup.classList.remove('is-shown');
        $popup.setAttribute('aria-hidden', 'true');
        // Match the CSS opacity transition before display:none.
        setTimeout(function () {
            if (!$popup.classList.contains('is-shown')) {
                $popup.style.display = 'none';
            }
        }, 200);
        document.body.classList.remove('lrob-cal-popup-open');
        if (openPopup === $popup) openPopup = null;
    }

    function isMobile() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
    }

    function init() {
        // Capture-phase listener for the image-as-trigger on mobile. Runs
        // BEFORE the bubble-phase lightbox handler from event-card-lightbox.js,
        // and uses stopImmediatePropagation() to suppress the lightbox so the
        // image opens the details popup instead.
        document.addEventListener('click', function (e) {
            var thumb = e.target.closest('[data-mobile-popup-for]');
            if (!thumb || !isMobile()) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            show(findPopupFor(thumb.getAttribute('data-mobile-popup-for')));
        }, true);

        // Open: any click on a "View details" button.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.lrob-event-details-btn');
            if (btn) {
                e.preventDefault();
                var id = btn.getAttribute('data-popup-for');
                show(findPopupFor(id));
                return;
            }

            // Close: click on the close X (delegated, since each popup has its own).
            var closeBtn = e.target.closest('.lrob-events-list-popup .lrob-cal-popup-close');
            if (closeBtn) {
                e.preventDefault();
                hide(closeBtn.closest('.lrob-events-list-popup'));
                return;
            }

            // Close: click outside the popup card (on the backdrop area).
            if (openPopup
                && e.target.closest('.lrob-events-list-popup') === openPopup
                && !e.target.closest('.lrob-cal-popup-content')) {
                hide(openPopup);
            }
        });

        // ESC closes whichever popup is open.
        document.addEventListener('keyup', function (e) {
            if (e.key === 'Escape' && openPopup) hide(openPopup);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
