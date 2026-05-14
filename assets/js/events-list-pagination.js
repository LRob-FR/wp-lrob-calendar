/**
 * AJAX-replace the events-list block when a pagination link is clicked.
 *
 * Wrappers are matched by INDEX (Nth on the current page ↔ Nth in the fetched
 * response), not by id — IDs generated with uniqid() change every PHP request
 * and would never line up. Index-based matching works as long as the same set
 * of events-list blocks appears on the response page (which it does, since we
 * re-fetch the same URL with only the page query param changed).
 *
 * Falls back to a regular navigation if anything goes wrong.
 *
 * Known limitation: multiple paginated events-list blocks on the same page
 * share the global `?lrob_calendar_page` query param. v1.0 assumes one
 * paginated block per page.
 */
(function () {
    'use strict';

    var WRAPPER_SELECTOR = '.lrob-cal-events-list-wrapper';
    var LINK_SELECTOR    = '.lrob-cal-events-pagination a.page-numbers, .lrob-cal-events-pagination a.lrob-cal-page-arrow';

    // Module-scoped in-memory cache of preloaded responses: url → response text.
    var preloadCache = Object.create(null);

    function init() {
        var wrappers = document.querySelectorAll(WRAPPER_SELECTOR);
        if (wrappers.length === 0) return;

        wrappers.forEach(function (wrapper) {
            wrapper.addEventListener('click', function (e) {
                var link = e.target.closest(LINK_SELECTOR);
                if (!link) return;
                if (link.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey) return;
                e.preventDefault();
                loadPage(wrapper, link.href, true);
            });

            // Schedule a preload of the "next" page link after this wrapper is idle.
            schedulePreload(wrapper);
        });

        window.addEventListener('popstate', function () {
            document.querySelectorAll(WRAPPER_SELECTOR).forEach(function (wrapper) {
                loadPage(wrapper, window.location.href, false);
            });
        });
    }

    /**
     * Find the wrapper's index among all wrappers, fetch the URL, then take
     * the wrapper at the same index from the response.
     */
    function loadPage(wrapper, url, push) {
        wrapper.classList.add('lrob-cal-loading');

        var allWrappers = document.querySelectorAll(WRAPPER_SELECTOR);
        var idx = Array.prototype.indexOf.call(allWrappers, wrapper);

        var pending = preloadCache[url]
            ? Promise.resolve(preloadCache[url])
            : fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                });

        pending
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var freshList = doc.querySelectorAll(WRAPPER_SELECTOR);
                var fresh = freshList[idx];
                if (!fresh) throw new Error('matching wrapper not found in response');

                wrapper.innerHTML = fresh.innerHTML;
                if (push) history.pushState({ lrobCalPage: url }, '', url);
                wrapper.classList.remove('lrob-cal-loading');

                rebindLightbox(wrapper);
                if (window.LRobCalExpand && window.LRobCalExpand.init) {
                    window.LRobCalExpand.init(wrapper);
                }
                schedulePreload(wrapper);

                // Smooth-scroll only when the block is off-screen above.
                var rect = wrapper.getBoundingClientRect();
                if (rect.top < 0) {
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            })
            .catch(function () {
                wrapper.classList.remove('lrob-cal-loading');
                window.location.href = url;
            });
    }

    /**
     * After the swap, prefetch the "next" pagination link in the background so the
     * next click is instant. Uses requestIdleCallback when available, otherwise a
     * small timeout. Result lives in `preloadCache` for the page lifetime.
     */
    function schedulePreload(wrapper) {
        var nextLink = wrapper.querySelector('.lrob-cal-events-pagination a.next, .lrob-cal-events-pagination a.lrob-cal-page-arrow--next');
        if (!nextLink) return;
        var url = nextLink.href;
        if (preloadCache[url]) return;

        var run = function () {
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (text) { if (text) preloadCache[url] = text; })
                .catch(function () { /* ignore */ });
        };

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(run, { timeout: 2000 });
        } else {
            setTimeout(run, 500);
        }
    }

    /**
     * After innerHTML swap, the new thumbnail buttons need fresh click handlers
     * for the shared lightbox. Marker attribute prevents double-binding.
     */
    function rebindLightbox(root) {
        if (!window.LRobLightbox) return;
        var thumbs = root.querySelectorAll('.lrob-event-thumbnail--clickable');
        Array.prototype.forEach.call(thumbs, function (btn) {
            if (btn.dataset.lrobBound) return;
            btn.dataset.lrobBound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var img = btn.querySelector('img');
                var alt = img ? (img.getAttribute('alt') || '') : '';
                window.LRobLightbox.open(btn.getAttribute('data-full-url') || '', alt);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
