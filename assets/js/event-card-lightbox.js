/**
 * Auto-wirer for event-card thumbnails (events-list block, single-event block).
 *
 * Finds every `.lrob-event-thumbnail--clickable` button on the page and binds
 * a click handler that opens the shared lightbox with the URL stored in
 * `data-full-url` (the WP `large` size).
 *
 * Depends on assets/js/lightbox.js (window.LRobLightbox).
 */
(function () {
    'use strict';

    function init() {
        if (!window.LRobLightbox) return;
        var thumbs = document.querySelectorAll('.lrob-event-thumbnail--clickable');
        Array.prototype.forEach.call(thumbs, function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var url = btn.getAttribute('data-full-url') || '';
                var img = btn.querySelector('img');
                var alt = img ? (img.getAttribute('alt') || '') : '';
                window.LRobLightbox.open(url, alt);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
