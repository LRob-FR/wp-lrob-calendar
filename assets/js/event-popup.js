/**
 * LRob Calendar — Shared event-popup module.
 *
 * Single source of truth for the event detail popup card used by the
 * calendar block and the events-list block. Renders identical HTML +
 * handles the same UX (close X, prev/next arrows, swipe nav, two-card
 * slide animation, body scroll lock, ESC).
 *
 * The MODULE is stateless about which "list" of events it belongs to —
 * the caller passes a sibling pair (prev/next event objects) and an
 * onNavigate callback. When the user clicks prev/next or swipes, the
 * module calls back; the caller resolves the new event/siblings and
 * calls navigateTo() to run the two-card slide.
 *
 * Public surface (window.LRobEventPopup):
 *   .configure({ icons, i18n, monthNames, siteLocale, publicPagesEnabled,
 *                showImage, imageFit, linkText, dayListMode })
 *   .open(containerEl, eventData, options?)
 *   .navigateTo(containerEl, eventData, siblings, direction)   // animated
 *   .close(containerEl)
 *   .renderCard(eventData, siblings?)                          // returns HTML string
 *
 * The module reads its default config from `window.lrobCalEventPopup`
 * on first use (set up by wp_localize_script), but callers can override
 * per-open via configure().
 *
 * Container contract:
 *   - Any element with .lrob-cal-popup class. Default display: none; the
 *     module manages opacity/display via .is-shown.
 *   - The events-list popup variant adds .lrob-events-list-popup for its
 *     fullscreen-modal styling.
 *
 * No jQuery — vanilla DOM.
 */
(function () {
    'use strict';

    /* ─── Default configuration ──────────────────────────────────────── */

    var config = {
        icons: {},
        i18n: {
            close:      'Close',
            viewImage:  'View image',
            prevEvent:  'Previous event',
            nextEvent:  'Next event',
            recurring:  'Recurring',
            free:       'Free',
            getTickets: 'Get tickets',
            viewEvent:  'View event',
        },
        monthNames: [],
        siteLocale: undefined,
        publicPagesEnabled: true,
        showImage: true,
        imageFit:  'contain',   // 'contain' | 'cover'
        linkText:  '',          // "View event"-style CTA label
    };

    var configInitialized = false;

    function configure(overrides) {
        if (!overrides) return;
        for (var k in overrides) {
            if (!Object.prototype.hasOwnProperty.call(overrides, k)) continue;
            if (k === 'icons' || k === 'i18n') {
                var dest = config[k];
                var src  = overrides[k] || {};
                for (var j in src) {
                    if (Object.prototype.hasOwnProperty.call(src, j)) dest[j] = src[j];
                }
            } else {
                config[k] = overrides[k];
            }
        }
        configInitialized = true;
    }

    function ensureConfig() {
        if (configInitialized) return;
        if (window.lrobCalEventPopup) configure(window.lrobCalEventPopup);
        configInitialized = true;
    }

    /* ─── Per-container state (siblings, onNavigate callback) ────────── */

    var containerStates = (typeof WeakMap === 'function') ? new WeakMap() : null;
    function setState(container, state) {
        if (containerStates) containerStates.set(container, state);
        else container.__lrobPopupState = state;
    }
    function getState(container) {
        return containerStates ? containerStates.get(container) : container.__lrobPopupState;
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */

    function escapeHtml(text) {
        if (text == null) return '';
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function shortMonthName(monthIndex) {
        var name = (config.monthNames && config.monthNames[monthIndex]) || '';
        return name.substring(0, 3).toUpperCase();
    }

    /**
     * Returns { date, time } for the meta block. Time is '' for all-day.
     * No "at" / "à" preposition — stack on two lines.
     */
    function formatEventDateAndTime(event) {
        var start = new Date(event.start);
        var end   = event.end ? new Date(event.end) : null;
        var dateOpts = { day: 'numeric', month: 'long', year: 'numeric' };
        var timeOpts = { hour: '2-digit', minute: '2-digit' };
        var dateFmt = new Intl.DateTimeFormat(config.siteLocale, dateOpts);
        var timeFmt = new Intl.DateTimeFormat(config.siteLocale, timeOpts);

        if (event.instant || !end) {
            return {
                date: dateFmt.format(start),
                time: event.allDay ? '' : timeFmt.format(start),
            };
        }

        var sameDay = start.getFullYear() === end.getFullYear()
            && start.getMonth() === end.getMonth()
            && start.getDate() === end.getDate();

        if (event.allDay) {
            return {
                date: sameDay ? dateFmt.format(start) : dateFmt.format(start) + ' – ' + dateFmt.format(end),
                time: '',
            };
        }

        if (sameDay) {
            return {
                date: dateFmt.format(start),
                time: timeFmt.format(start) + ' – ' + timeFmt.format(end),
            };
        }

        return {
            date: dateFmt.format(start) + ' – ' + dateFmt.format(end),
            time: timeFmt.format(start) + ' – ' + timeFmt.format(end),
        };
    }

    /* ─── HTML builders ──────────────────────────────────────────────── */

    function buildArrowsHtml(siblings) {
        siblings = siblings || {};
        var html = '';
        var chevLeft  = config.icons.chevronLeft  || '&lsaquo;';
        var chevRight = config.icons.chevronRight || '&rsaquo;';
        if (siblings.prev) {
            html += '<button class="lrob-cal-popup-nav lrob-cal-popup-nav--prev" type="button" '
                  + 'aria-label="' + escapeAttr(config.i18n.prevEvent) + '" '
                  + 'data-target-id="' + escapeAttr(siblings.prev.id) + '">' + chevLeft + '</button>';
        }
        if (siblings.next) {
            html += '<button class="lrob-cal-popup-nav lrob-cal-popup-nav--next" type="button" '
                  + 'aria-label="' + escapeAttr(config.i18n.nextEvent) + '" '
                  + 'data-target-id="' + escapeAttr(siblings.next.id) + '">' + chevRight + '</button>';
        }
        return html;
    }

    /**
     * Build the .lrob-cal-popup-content card HTML for a single event.
     * No event handlers attached here — clicks are delegated on the popup
     * container in bindHandlers().
     */
    function renderCard(event, siblings, linkText) {
        ensureConfig();
        siblings  = siblings || { prev: null, next: null };
        linkText  = linkText || config.linkText || config.i18n.viewEvent;
        var when      = formatEventDateAndTime(event);
        var closeIcon = config.icons.close || '&times;';
        var arrowIcon = config.icons.arrowRight || '';

        var startDate = new Date(event.start);
        var dayNum    = startDate.getDate();
        var monthStr  = shortMonthName(startDate.getMonth());

        // Date-block color pair — same per-event hashed/category pastel pair
        // the events-list cards use (PHP-computed, in event.pillBg/pillText).
        var pillStyle = '';
        if (event.pillBg || event.pillText) {
            pillStyle = ' style="background-color: ' + escapeAttr(event.pillBg || '')
                      + '; color: ' + escapeAttr(event.pillText || '') + '"';
        }

        var html = '<div class="lrob-cal-popup-content" data-event-id="' + escapeAttr(event.id) + '">';

        // Header: date block + title + nav/close
        html += '<div class="lrob-cal-popup-header">';
        html += '<div class="lrob-cal-date-block" aria-hidden="true"' + pillStyle + '>';
        html += '<span class="lrob-cal-date-block-day">' + dayNum + '</span>';
        html += '<span class="lrob-cal-date-block-month">' + escapeHtml(monthStr) + '</span>';
        html += '</div>';

        if (config.publicPagesEnabled && event.url) {
            html += '<h4 class="lrob-cal-popup-title"><a href="' + escapeAttr(event.url) + '">' + escapeHtml(event.title) + '</a></h4>';
        } else {
            html += '<h4 class="lrob-cal-popup-title">' + escapeHtml(event.title) + '</h4>';
        }

        html += '<div class="lrob-cal-popup-actions">';
        html += buildArrowsHtml(siblings);
        html += '<button class="lrob-cal-popup-close" type="button" aria-label="' + escapeAttr(config.i18n.close) + '">' + closeIcon + '</button>';
        html += '</div>';
        html += '</div>';

        // Body
        html += '<div class="lrob-cal-popup-body">';

        // Meta rows — date + time stacked, then location, recurring, cost
        html += '<div class="lrob-cal-popup-meta-list">';
        html += '<p class="lrob-cal-popup-meta lrob-cal-popup-date">'
              + (config.icons.calendar || '')
              + '<span class="lrob-cal-popup-meta-stack">'
              + '<span class="lrob-cal-popup-date-date">' + escapeHtml(when.date) + '</span>'
              + (when.time
                  ? '<span class="lrob-cal-popup-date-time">' + escapeHtml(when.time) + '</span>'
                  : '')
              + '</span>'
              + '</p>';

        // Location block — full multi-line address when the event has one.
        // Lines: venue / address / postal_code city / province / country.
        // Empty fields are skipped; we never show stray commas.
        var locLines = [];
        if (event.venue)   locLines.push(escapeHtml(event.venue));
        if (event.address) locLines.push(escapeHtml(event.address));
        var postalCity = [];
        if (event.postalCode) postalCity.push(escapeHtml(event.postalCode));
        if (event.city)       postalCity.push(escapeHtml(event.city));
        if (postalCity.length) locLines.push(postalCity.join(' '));
        if (event.province) locLines.push(escapeHtml(event.province));
        if (event.country)  locLines.push(escapeHtml(event.country));
        if (locLines.length) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-location">'
                  + (config.icons.location || '')
                  + '<span class="lrob-cal-popup-meta-stack">'
                  + locLines.map(function (line) {
                        return '<span>' + line + '</span>';
                    }).join('')
                  + '</span>'
                  + '</p>';
        }

        if (event.recurring) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-recurring">'
                  + (config.icons.recurring || '')
                  + '<span>' + escapeHtml(config.i18n.recurring) + '</span>'
                  + '</p>';
        }

        if (event.isFree) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-cost lrob-cal-popup-cost--free">'
                  + (config.icons.ticket || '')
                  + '<span>' + escapeHtml(config.i18n.free) + '</span>'
                  + '</p>';
        } else if (event.cost) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-cost">'
                  + (config.icons.ticket || '')
                  + '<span>' + escapeHtml(event.cost) + '</span>'
                  + '</p>';
        }

        // Contact block — one row per provided field, each with its own icon.
        // If someone entered the info, they want it surfaced.
        if (event.contactName) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-contact lrob-cal-popup-contact--name">'
                  + (config.icons.person || '')
                  + '<span>' + escapeHtml(event.contactName) + '</span>'
                  + '</p>';
        }
        if (event.contactEmail) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-contact lrob-cal-popup-contact--email">'
                  + (config.icons.email || '')
                  + '<span><a href="mailto:' + escapeAttr(event.contactEmail) + '">'
                  + escapeHtml(event.contactEmail) + '</a></span>'
                  + '</p>';
        }
        if (event.contactPhone) {
            // Strip everything except digits and a leading + for the tel: URI.
            var telHref = String(event.contactPhone).replace(/[^+\d]/g, '');
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-contact lrob-cal-popup-contact--phone">'
                  + (config.icons.phone || '')
                  + '<span><a href="tel:' + escapeAttr(telHref) + '">'
                  + escapeHtml(event.contactPhone) + '</a></span>'
                  + '</p>';
        }
        if (event.contactUrl) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-contact lrob-cal-popup-contact--url">'
                  + (config.icons.link || '')
                  + '<span><a href="' + escapeAttr(event.contactUrl) + '" target="_blank" rel="noopener">'
                  + escapeHtml(event.contactUrl) + '</a></span>'
                  + '</p>';
        }
        html += '</div>';

        // Description — full content for events-list, short excerpt for calendar.
        if (event.descriptionHtml) {
            html += '<div class="lrob-cal-popup-description">' + event.descriptionHtml + '</div>';
        } else if (event.excerpt) {
            html += '<p class="lrob-cal-popup-excerpt">' + escapeHtml(event.excerpt) + '</p>';
        }

        // Featured image — natural aspect, capped at 60vh. Honors the
        // global "show image" + "image fit" settings.
        if (config.showImage && event.thumbnail) {
            html += '<div class="lrob-cal-popup-thumb lrob-cal-popup-thumb--static lrob-cal-popup-thumb--fit-' + (config.imageFit === 'cover' ? 'cover' : 'contain') + '">';
            html += '<img src="' + escapeAttr(event.thumbnail) + '" alt="" loading="lazy">';
            html += '</div>';
        }

        // CTA row — bottom of the body
        html += '<div class="lrob-cal-popup-cta">';
        if (event.ticketUrl) {
            html += '<a href="' + escapeAttr(event.ticketUrl) + '" class="lrob-cal-popup-link lrob-cal-popup-link--ticket" target="_blank" rel="noopener">'
                  + escapeHtml(config.i18n.getTickets) + '</a>';
        }
        if (config.publicPagesEnabled && event.url) {
            html += '<a href="' + escapeAttr(event.url) + '" class="lrob-cal-popup-link">'
                  + escapeHtml(linkText) + arrowIcon + '</a>';
        }
        html += '</div>';

        html += '</div>'; // body
        html += '</div>'; // content
        return html;
    }

    /* ─── Open / close / navigate ────────────────────────────────────── */

    function open(container, event, options) {
        ensureConfig();
        if (!container || !event) return;
        options = options || {};
        var siblings   = options.siblings   || { prev: null, next: null };
        var onNavigate = options.onNavigate || null;
        var onClose    = options.onClose    || null;
        var linkText   = options.linkText   || null;

        container.innerHTML = '<div class="lrob-cal-popup-stage">' + renderCard(event, siblings, linkText) + '</div>';
        showContainer(container);

        setState(container, {
            event: event,
            siblings: siblings,
            onNavigate: onNavigate,
            onClose: onClose,
            linkText: linkText,
        });

        bindHandlers(container);
    }

    /**
     * Open the popup with arbitrary HTML inside the stage. Used by callers
     * that render their own content (e.g. the calendar block's day-list view).
     * The container's standard show/close/swipe/ESC handlers still apply.
     */
    function openHtml(container, html, options) {
        ensureConfig();
        if (!container) return;
        options = options || {};
        container.innerHTML = '<div class="lrob-cal-popup-stage">' + html + '</div>';
        showContainer(container);
        setState(container, {
            event: null,
            siblings: null,
            onNavigate: null,
            onClose: options.onClose || null,
            linkText: null,
        });
        bindHandlers(container);
    }

    function showContainer(container) {
        container.style.display = '';
        // eslint-disable-next-line no-unused-expressions
        container.offsetHeight; // reflow so the opacity transition kicks in
        container.classList.add('is-shown');
        container.setAttribute('aria-hidden', 'false');
        document.body.classList.add('lrob-cal-popup-open');
    }

    function close(container) {
        if (!container) return;
        var state = getState(container);
        container.classList.remove('is-shown');
        container.setAttribute('aria-hidden', 'true');
        // Match the CSS opacity transition before display:none.
        setTimeout(function () {
            if (!container.classList.contains('is-shown')) {
                container.style.display = 'none';
            }
        }, 200);
        // Only drop the body lock if no OTHER popup container is open.
        var stillOpen = document.querySelector('.lrob-cal-popup.is-shown');
        if (!stillOpen) document.body.classList.remove('lrob-cal-popup-open');
        if (state && state.onClose) state.onClose();
    }

    /**
     * Animated transition to a new event. Two-card slide — the outgoing
     * card moves off in the gesture direction while the incoming card
     * slides in from the opposite side, simultaneously.
     *
     * Direction: 'left' = NEXT (current slides off LEFT, new in from RIGHT)
     *            'right' = PREV
     */
    function navigateTo(container, event, siblings, direction) {
        ensureConfig();
        if (!container || !event) return;
        var prev = getState(container) || {};
        var stage = container.querySelector('.lrob-cal-popup-stage');
        if (!stage) {
            // No stage yet — fall back to a regular open.
            open(container, event, {
                siblings:   siblings,
                onNavigate: prev.onNavigate,
                onClose:    prev.onClose,
                linkText:   prev.linkText,
            });
            return;
        }

        var newCard = document.createElement('div');
        newCard.innerHTML = renderCard(event, siblings || { prev: null, next: null }, prev.linkText);
        var newCardEl = newCard.firstChild;
        newCardEl.classList.add('is-incoming');
        stage.appendChild(newCardEl);

        // Disable the outgoing card's nav buttons so a stray double-click
        // doesn't fire navigation again mid-transition.
        var outgoingNavs = stage.querySelectorAll('.lrob-cal-popup-content:not(.is-incoming) .lrob-cal-popup-nav');
        for (var i = 0; i < outgoingNavs.length; i++) outgoingNavs[i].setAttribute('disabled', 'disabled');

        // eslint-disable-next-line no-unused-expressions
        stage.offsetHeight; // reflow
        var navClass = direction === 'left' ? 'is-navigating-left' : 'is-navigating-right';
        stage.classList.add(navClass);

        setState(container, {
            event: event,
            siblings: siblings,
            onNavigate: prev.onNavigate,
            onClose:    prev.onClose,
            linkText:   prev.linkText,
        });

        setTimeout(function () {
            // Drop the outgoing card, clean up animation classes.
            var leaving = stage.querySelectorAll('.lrob-cal-popup-content:not(.is-incoming)');
            for (var j = 0; j < leaving.length; j++) leaving[j].parentNode.removeChild(leaving[j]);
            newCardEl.classList.remove('is-incoming');
            stage.classList.remove('is-navigating-left', 'is-navigating-right');
        }, 260);
    }

    /* ─── Event delegation ───────────────────────────────────────────── */

    function bindHandlers(container) {
        if (container.__lrobHandlersBound) return;
        container.__lrobHandlersBound = true;

        container.addEventListener('click', function (e) {
            // Close button
            if (e.target.closest('.lrob-cal-popup-close')) {
                e.preventDefault();
                e.stopPropagation();
                close(container);
                return;
            }
            // Prev/next nav
            var nav = e.target.closest('.lrob-cal-popup-nav');
            if (nav) {
                e.preventDefault();
                e.stopPropagation();
                var state = getState(container);
                if (!state || !state.onNavigate) return;
                var targetId = parseInt(nav.getAttribute('data-target-id'), 10);
                var direction = nav.classList.contains('lrob-cal-popup-nav--next') ? 'left' : 'right';
                state.onNavigate(targetId, direction);
                return;
            }
            // Backdrop click (events-list popup variant — fullscreen with backdrop)
            // Only close if the click landed on the container itself (not inside content).
            if (e.target === container) {
                close(container);
            }
        });

        // Swipe nav
        var swipeStartX = 0;
        var swipeStartY = 0;
        var tracking = false;
        container.addEventListener('touchstart', function (e) {
            if (!e.touches || e.touches.length !== 1) { tracking = false; return; }
            swipeStartX = e.touches[0].clientX;
            swipeStartY = e.touches[0].clientY;
            tracking = true;
        }, { passive: true });
        container.addEventListener('touchend', function (e) {
            if (!tracking) return;
            tracking = false;
            var t = e.changedTouches[0];
            var dx = t.clientX - swipeStartX;
            var dy = t.clientY - swipeStartY;
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.3) {
                var state = getState(container);
                if (!state || !state.onNavigate) return;
                var sib = state.siblings || {};
                var target = dx < 0 ? sib.next : sib.prev;
                if (target) state.onNavigate(target.id, dx < 0 ? 'left' : 'right');
            }
        }, { passive: true });
    }

    // ESC closes whichever popup container is currently shown.
    document.addEventListener('keyup', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.lrob-cal-popup.is-shown');
        if (open) close(open);
    });

    /* ─── Public API ────────────────────────────────────────────────── */

    window.LRobEventPopup = {
        configure:   configure,
        open:        open,
        openHtml:    openHtml,
        navigateTo:  navigateTo,
        close:       close,
        renderCard:  renderCard,
        // Exposed for callers that need to keep their own clock formatting.
        formatEventDateAndTime: formatEventDateAndTime,
    };
})();
