/**
 * Custom event management screen (v1.2, Phase 1).
 *
 * REST-driven list with live search, filters, sorting, paging and inline trash —
 * no full page reloads. The toolbar is mounted once and never rebuilt, so the
 * search field keeps focus while results refresh live. Create/edit currently
 * bridge to the native editor; the dynamic modal lands in a later phase.
 */
(function (cfg, i18n) {
    if (!cfg) return;
    var __ = (i18n && i18n.__) ? i18n.__ : function (s) { return s; };

    var root = document.getElementById('lrob-cal-manage');
    if (!root) return;

    var state = {
        search: '',
        status: 'any',
        category: 0,
        tag: 0,
        orderby: 'start',
        order: 'ASC',
        past: false,
        paged: 1,
        perPage: 20
    };

    var data = { events: [], total: 0, pages: 0, paged: 1 };
    var searchTimer = null;

    /* ── REST ─────────────────────────────────────────────────────────────── */

    function apiUrl() {
        var p = new URLSearchParams({
            search: state.search,
            status: state.status,
            category: state.category,
            tag: state.tag,
            orderby: state.orderby,
            order: state.order,
            past: state.past ? 1 : 0,
            paged: state.paged,
            per_page: state.perPage
        });
        return cfg.restRoot + '?' + p.toString();
    }

    function fetchEvents() {
        markLoading(true);
        fetch(apiUrl(), {
            headers: { 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (json) {
                data = json;
                state.paged = json.paged || 1;
                renderList();
            })
            .catch(function () { renderError(); });
    }

    function deleteEvent(id, permanent) {
        return fetch(cfg.restRoot + '/' + id + (permanent ? '?force=1' : ''), {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin'
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
    }

    /* ── Helpers ──────────────────────────────────────────────────────────── */

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    function optionList(items, selected) {
        return items.map(function (o) {
            return '<option value="' + esc(o.value) + '"' +
                (String(o.value) === String(selected) ? ' selected' : '') + '>' +
                esc(o.label) + '</option>';
        }).join('');
    }

    function statusLabel(status) {
        var map = {
            publish: __('Published', 'lrob-calendar'),
            draft: __('Draft', 'lrob-calendar'),
            pending: __('Pending', 'lrob-calendar'),
            future: __('Scheduled', 'lrob-calendar'),
            private: __('Private', 'lrob-calendar'),
            trash: __('Trash', 'lrob-calendar')
        };
        return map[status] || status;
    }

    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear() &&
            a.getMonth() === b.getMonth() &&
            a.getDate() === b.getDate();
    }

    function formatWhen(ev) {
        if (!ev.start) return '—';
        var start = new Date(ev.start * 1000);
        var end = ev.end ? new Date(ev.end * 1000) : null;
        var dateFmt = new Intl.DateTimeFormat(cfg.locale, { day: 'numeric', month: 'short', year: 'numeric' });
        var timeFmt = new Intl.DateTimeFormat(cfg.locale, { hour: '2-digit', minute: '2-digit' });

        var lastDay = end;
        if (ev.allday && end) lastDay = new Date(end.getTime() - 1000);

        if (lastDay && !sameDay(start, lastDay)) {
            return dateFmt.format(start) + ' – ' + dateFmt.format(lastDay);
        }
        if (ev.allday) {
            return dateFmt.format(start) + ' · ' + __('All day', 'lrob-calendar');
        }
        return dateFmt.format(start) + ' · ' + timeFmt.format(start);
    }

    function locationText(ev) {
        var parts = [];
        if (ev.venue) parts.push(ev.venue);
        if (ev.city) parts.push(ev.city);
        if (ev.country) parts.push(ev.country);
        return parts.join(', ');
    }

    /* ── Chrome (built once) ──────────────────────────────────────────────── */

    function chromeHtml() {
        var catOpts = [{ value: 0, label: __('All categories', 'lrob-calendar') }].concat(cfg.categories || []);
        var tagOpts = [{ value: 0, label: __('All tags', 'lrob-calendar') }].concat(cfg.tags || []);
        var orderOpts = [
            { value: 'start|ASC', label: __('Date (soonest first)', 'lrob-calendar') },
            { value: 'start|DESC', label: __('Date (latest first)', 'lrob-calendar') },
            { value: 'title|ASC', label: __('Title (A→Z)', 'lrob-calendar') },
            { value: 'modified|DESC', label: __('Recently modified', 'lrob-calendar') }
        ];

        return '' +
            '<div class="lrob-manage-header">' +
                '<h1 class="lrob-manage-title">' + esc(__('Events', 'lrob-calendar')) + '</h1>' +
                '<div class="lrob-manage-header-actions">' +
                    '<button type="button" class="button lrob-manage-terms" data-action="terms">' +
                        esc(__('Categories & tags', 'lrob-calendar')) +
                    '</button>' +
                    '<button type="button" class="button button-primary lrob-manage-new" data-action="new">' +
                        esc(__('+ New event', 'lrob-calendar')) +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="lrob-manage-toolbar">' +
                '<div class="lrob-manage-search-wrap">' +
                    '<input type="text" class="lrob-manage-search" placeholder="' +
                        esc(__('Search events…', 'lrob-calendar')) + '" value="' + esc(state.search) + '">' +
                    '<button type="button" class="lrob-manage-search-clear" data-action="clear-search" aria-label="' +
                        esc(__('Clear search', 'lrob-calendar')) + '">&times;</button>' +
                '</div>' +
                '<select class="lrob-manage-filter" data-filter="status">' + optionList(cfg.statuses || [], state.status) + '</select>' +
                '<select class="lrob-manage-filter" data-filter="category">' + optionList(catOpts, state.category) + '</select>' +
                '<select class="lrob-manage-filter" data-filter="tag">' + optionList(tagOpts, state.tag) + '</select>' +
                '<select class="lrob-manage-filter" data-filter="order">' +
                    optionList(orderOpts, state.orderby + '|' + state.order) + '</select>' +
                '<label class="lrob-manage-pasttoggle"><input type="checkbox" class="lrob-manage-past"' +
                    (state.past ? ' checked' : '') + '> ' + esc(__('Show past events', 'lrob-calendar')) + '</label>' +
            '</div>' +
            '<div class="lrob-manage-body"></div>';
    }

    function iconAction(action, icon, label, id, extra) {
        return '<button type="button" class="button button-small lrob-manage-icon-btn' + (extra || '') + '" ' +
            'data-action="' + action + '" data-id="' + esc(id) + '" ' +
            'title="' + esc(label) + '" aria-label="' + esc(label) + '">' +
            '<span class="dashicons dashicons-' + icon + '"></span></button>';
    }

    function rowHtml(ev) {
        var loc = locationText(ev);
        var cats = (ev.categories || []).map(function (c) {
            return '<span class="lrob-manage-chip">' + esc(c) + '</span>';
        }).join('');

        return '' +
            '<li class="lrob-manage-card" data-id="' + esc(ev.id) + '">' +
                '<div class="lrob-manage-thumb">' +
                    (ev.thumbnail ? '<img src="' + esc(ev.thumbnail) + '" alt="">' : '<span class="lrob-manage-thumb-empty dashicons dashicons-calendar-alt"></span>') +
                '</div>' +
                '<div class="lrob-manage-main">' +
                    '<div class="lrob-manage-card-title">' +
                        esc(ev.title || __('(no title)', 'lrob-calendar')) +
                        (ev.recurring ? ' <span class="lrob-manage-badge-rec dashicons dashicons-update" title="' + esc(__('Recurring', 'lrob-calendar')) + '"></span>' : '') +
                        '<span class="lrob-manage-status lrob-status-' + esc(ev.status) + '">' + esc(statusLabel(ev.status)) + '</span>' +
                    '</div>' +
                    '<div class="lrob-manage-meta">' +
                        '<span class="lrob-manage-when">' + esc(formatWhen(ev)) + '</span>' +
                        (loc ? '<span class="lrob-manage-loc">' + esc(loc) + '</span>' : '') +
                    '</div>' +
                    (cats ? '<div class="lrob-manage-chips">' + cats + '</div>' : '') +
                '</div>' +
                '<div class="lrob-manage-actions">' +
                    iconAction('edit', 'edit', __('Edit', 'lrob-calendar'), ev.id) +
                    iconAction('duplicate', 'admin-page', __('Duplicate', 'lrob-calendar'), ev.id) +
                    iconAction('delete', 'trash', __('Move to trash', 'lrob-calendar'), ev.id, ' lrob-manage-del') +
                '</div>' +
            '</li>';
    }

    function paginationHtml() {
        if (data.pages <= 1) return '';
        return '<div class="lrob-manage-pagination">' +
            '<button type="button" class="button" data-action="prev"' + (state.paged <= 1 ? ' disabled' : '') + '>‹ ' + esc(__('Previous', 'lrob-calendar')) + '</button>' +
            '<span class="lrob-manage-pageinfo">' +
                esc(__('Page', 'lrob-calendar')) + ' ' + esc(data.paged) + ' / ' + esc(data.pages) +
                ' · ' + esc(data.total) + ' ' + esc(__('events', 'lrob-calendar')) +
            '</span>' +
            '<button type="button" class="button" data-action="next"' + (state.paged >= data.pages ? ' disabled' : '') + '>' + esc(__('Next', 'lrob-calendar')) + ' ›</button>' +
            '</div>';
    }

    /* ── Render (body only — toolbar persists) ────────────────────────────── */

    function markLoading(on) {
        var body = root.querySelector('.lrob-manage-body');
        if (body) body.classList.toggle('is-loading', !!on);
    }

    function renderList() {
        var body = root.querySelector('.lrob-manage-body');
        if (!body) return;
        body.classList.remove('is-loading');
        body.innerHTML = data.events.length
            ? '<ul class="lrob-manage-list">' + data.events.map(rowHtml).join('') + '</ul>' + paginationHtml()
            : '<p class="lrob-manage-empty">' + esc(__('No events found.', 'lrob-calendar')) + '</p>';
    }

    function renderError() {
        var body = root.querySelector('.lrob-manage-body');
        if (body) body.innerHTML = '<p class="lrob-manage-empty">' + esc(__('Could not load events.', 'lrob-calendar')) + '</p>';
    }

    function syncSearchClear() {
        var wrap = root.querySelector('.lrob-manage-search-wrap');
        if (wrap) wrap.classList.toggle('has-value', state.search !== '');
    }

    /* ── Wiring ───────────────────────────────────────────────────────────── */

    function mount() {
        root.innerHTML = chromeHtml();

        var search = root.querySelector('.lrob-manage-search');
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var v = this.value;
            syncSearchClearValue(v);
            searchTimer = setTimeout(function () {
                state.search = v;
                state.paged = 1;
                fetchEvents();
            }, 220);
        });

        root.querySelectorAll('.lrob-manage-filter').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var f = this.getAttribute('data-filter');
                if (f === 'order') {
                    var parts = this.value.split('|');
                    state.orderby = parts[0];
                    state.order = parts[1];
                } else {
                    state[f] = (f === 'status') ? this.value : parseInt(this.value, 10);
                }
                state.paged = 1;
                fetchEvents();
            });
        });

        var pastBox = root.querySelector('.lrob-manage-past');
        if (pastBox) {
            pastBox.addEventListener('change', function () {
                state.past = this.checked;
                state.paged = 1;
                fetchEvents();
            });
        }

        syncSearchClear();
    }

    // Toggle the clear (×) affordance live as the user types, before the
    // debounced fetch commits the value to state.
    function syncSearchClearValue(v) {
        var wrap = root.querySelector('.lrob-manage-search-wrap');
        if (wrap) wrap.classList.toggle('has-value', v !== '');
    }

    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');
        var id = btn.getAttribute('data-id');

        if (action === 'new') {
            if (window.LrobEventModal) {
                window.LrobEventModal.open(null, fetchEvents);
            } else {
                window.location.href = cfg.newLink; // fallback
            }
        } else if (action === 'terms') {
            if (window.LrobTermsManager) {
                window.LrobTermsManager.open(fetchEvents); // refresh chips/counts on close
            }
        } else if (action === 'edit') {
            if (window.LrobEventModal) {
                window.LrobEventModal.open(parseInt(id, 10), fetchEvents);
            } else {
                var ev = data.events.filter(function (x) { return String(x.id) === String(id); })[0];
                if (ev && ev.editLink) window.location.href = ev.editLink;
            }
        } else if (action === 'duplicate') {
            btn.disabled = true;
            fetch(cfg.restRoot + '/' + id + '/duplicate', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function () { fetchEvents(); })
                .catch(function () {
                    btn.disabled = false;
                    window.alert(__('Could not duplicate the event.', 'lrob-calendar'));
                });
        } else if (action === 'delete') {
            if (!window.confirm(__('Move this event to the trash?', 'lrob-calendar'))) return;
            btn.disabled = true;
            deleteEvent(id, false).then(function () {
                fetchEvents();
            }).catch(function () {
                btn.disabled = false;
                window.alert(__('Could not delete the event.', 'lrob-calendar'));
            });
        } else if (action === 'clear-search') {
            var input = root.querySelector('.lrob-manage-search');
            if (input) { input.value = ''; input.focus(); }
            clearTimeout(searchTimer);
            state.search = '';
            state.paged = 1;
            syncSearchClear();
            fetchEvents();
        } else if (action === 'prev' && state.paged > 1) {
            state.paged--; fetchEvents();
        } else if (action === 'next' && state.paged < data.pages) {
            state.paged++; fetchEvents();
        }
    });

    mount();
    fetchEvents();

    // Arrived via the classic "Add New" → pop the new-event modal, then drop the
    // flag from the URL so a refresh doesn't reopen it. Read the URL directly
    // (robust regardless of how the localized flag is serialized).
    var qp = new URLSearchParams(window.location.search);
    if ((qp.get('lrob_new') || cfg.openNew) && window.LrobEventModal) {
        window.LrobEventModal.open(null, fetchEvents);
        if (window.history && window.history.replaceState) {
            qp.delete('lrob_new');
            var base = window.location.pathname + (qp.toString() ? '?' + qp.toString() : '');
            window.history.replaceState({}, '', base);
        }
    }
})(window.lrobCalendarManage, window.wp && window.wp.i18n);
