/**
 * Shared fullscreen image lightbox.
 *
 * Exposes window.LRobLightbox.open(imageUrl, altText). Used by:
 *   - the calendar block popup (blocks/calendar/view.js)
 *   - the auto-wirer for event-card thumbnails (assets/js/event-card-lightbox.js)
 *
 * No jQuery, no other deps. Translations come from inline `lrobLightboxI18n`
 * injected via wp_localize_script (close-button label).
 */
(function () {
    'use strict';

    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function getCloseLabel() {
        return (window.lrobLightboxI18n && window.lrobLightboxI18n.close) || 'Close';
    }

    function open(imageUrl, altText) {
        if (!imageUrl) return;

        var closeLabel = getCloseLabel();
        var prevOverflow = document.body.style.overflow;

        var lightbox = document.createElement('div');
        lightbox.className = 'lrob-cal-lightbox';
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.innerHTML =
            '<button class="lrob-cal-lightbox-close" type="button" aria-label="' + escapeAttr(closeLabel) + '">&times;</button>' +
            '<img class="lrob-cal-lightbox-image" src="' + escapeAttr(imageUrl) + '" alt="' + escapeAttr(altText || '') + '">';

        document.body.appendChild(lightbox);
        document.body.style.overflow = 'hidden';

        function close() {
            if (lightbox.parentNode) {
                lightbox.parentNode.removeChild(lightbox);
            }
            document.body.style.overflow = prevOverflow;
            document.removeEventListener('keyup', onKey);
        }

        function onKey(e) {
            if (e.key === 'Escape') close();
        }

        // Any click inside the lightbox closes it — backdrop, close button, or the image itself.
        lightbox.addEventListener('click', function () {
            close();
        });

        document.addEventListener('keyup', onKey);
    }

    window.LRobLightbox = { open: open };
})();
