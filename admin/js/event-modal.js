/**
 * Dynamic event editor modal (v1.2).
 *
 * window.LrobEventModal.open(id|null, onSaved) — loads an event over REST (or a
 * blank form), edits every field in-page, and saves via create/update. Timezone
 * math lives on the server: the modal sends wall-clock parts + the timezone and
 * PHP computes the stored timestamps (identical to the classic meta box).
 *
 * The description uses a deliberately minimal rich-text editor (bold/italic/
 * lists/link + a raw-HTML code view). No headings, no images — the guardrail
 * against authors faking "big text" or pasting giant images. Server-side
 * wp_kses enforces the same allow-list, so this is UX, not security.
 */
(function (i18n) {
    var __ = (i18n && i18n.__) ? i18n.__ : function (s) { return s; };
    function cfg() { return window.lrobCalendarManage || {}; }
    function termsUrl() { return (cfg().restRoot || '').replace(/\/events$/, '/terms'); }

    var overlay = null;
    var ed = null;
    var featuredId = 0;
    var dirty = false;
    var recurrenceRaw = '';   // original RRULE, preserved when too complex to edit here
    var current = { id: null, onSaved: null };

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }
    function q(sel) { return overlay.querySelector(sel); }

    function defaults() {
        var now = new Date();
        now.setMinutes(0, 0, 0);
        now.setHours(now.getHours() + 1);
        var end = new Date(now.getTime() + 3600000);
        function d(x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()); }
        function t(x) { return pad(x.getHours()) + ':' + pad(x.getMinutes()); }
        return {
            id: null, title: '', content: '', status: 'publish',
            datetime: {
                type: 'standard', timezone: cfg().defaultTimezone || 'UTC',
                start_date: d(now), start_time: t(now), end_date: d(end), end_time: t(end)
            },
            fields: {}, categories: [], tags: [], featuredImage: null, editLink: null
        };
    }

    /* ── Minimal rich-text editor (+ code view) ──────────────────────────── */

    var ALLOWED = ['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'A'];

    function cleanHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html || '';
        (function walk(node) {
            var child = node.firstChild;
            while (child) {
                var next = child.nextSibling;
                if (child.nodeType === 8) {
                    node.removeChild(child);
                } else if (child.nodeType === 1) {
                    var tag = child.tagName;
                    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'IMG') {
                        node.removeChild(child);
                    } else if (tag === 'DIV') {
                        var p = document.createElement('p');
                        while (child.firstChild) p.appendChild(child.firstChild);
                        node.replaceChild(p, child);
                        walk(p);
                    } else if (ALLOWED.indexOf(tag) === -1) {
                        while (child.firstChild) node.insertBefore(child.firstChild, child);
                        node.removeChild(child);
                    } else {
                        var keep = (tag === 'A') ? child.getAttribute('href') : null;
                        while (child.attributes.length) child.removeAttribute(child.attributes[0].name);
                        if (keep) {
                            child.setAttribute('href', keep);
                            child.setAttribute('rel', 'noopener');
                            child.setAttribute('target', '_blank');
                        }
                        walk(child);
                    }
                }
                child = next;
            }
        })(box);
        return box.innerHTML.trim();
    }

    function createEditor(host, html) {
        host.innerHTML =
            '<div class="lrob-rte-toolbar">' +
                fbtn('bold', 'editor-bold', __('Bold', 'lrob-calendar')) +
                fbtn('italic', 'editor-italic', __('Italic', 'lrob-calendar')) +
                fbtn('insertUnorderedList', 'editor-ul', __('Bulleted list', 'lrob-calendar')) +
                fbtn('insertOrderedList', 'editor-ol', __('Numbered list', 'lrob-calendar')) +
                fbtn('createLink', 'admin-links', __('Link', 'lrob-calendar')) +
                fbtn('removeFormat', 'editor-removeformatting', __('Clear formatting', 'lrob-calendar')) +
                '<span class="lrob-rte-spacer"></span>' +
                '<button type="button" class="button lrob-rte-btn lrob-rte-code-toggle" data-toggle="code" ' +
                    'title="' + esc(__('Code view', 'lrob-calendar')) + '" aria-label="' + esc(__('Code view', 'lrob-calendar')) + '">' +
                    '<span class="dashicons dashicons-editor-code"></span></button>' +
            '</div>' +
            '<div class="lrob-rte" contenteditable="true"></div>' +
            '<textarea class="lrob-rte-code" spellcheck="false" hidden></textarea>' +
            '<div class="lrob-rte-counter"><span class="lrob-rte-count">0</span> / ' +
                esc(cfg().descRecommended || 800) + '</div>';

        var area = host.querySelector('.lrob-rte');
        var code = host.querySelector('.lrob-rte-code');
        var toolbar = host.querySelector('.lrob-rte-toolbar');
        var counterBox = host.querySelector('.lrob-rte-counter');
        var counter = host.querySelector('.lrob-rte-count');
        var max = parseInt(cfg().descRecommended, 10) || 800;
        var mode = 'rich';

        area.innerHTML = cleanHtml(html);

        // Use mousedown+preventDefault so the contenteditable keeps its selection.
        toolbar.addEventListener('mousedown', function (e) {
            var b = e.target.closest('.lrob-rte-btn[data-cmd]');
            if (!b || mode !== 'rich') return;
            e.preventDefault();
            var cmd = b.getAttribute('data-cmd');
            if (cmd === 'createLink') {
                var url = window.prompt(__('Link URL:', 'lrob-calendar'), 'https://');
                if (url) document.execCommand('createLink', false, url);
            } else {
                document.execCommand(cmd, false, null);
            }
            updateCount();
        });

        host.querySelector('.lrob-rte-code-toggle').addEventListener('click', function () {
            if (mode === 'rich') {
                code.value = cleanHtml(area.innerHTML);
                area.hidden = true; code.hidden = false;
                this.classList.add('is-active');
                mode = 'code';
            } else {
                area.innerHTML = cleanHtml(code.value);
                code.hidden = true; area.hidden = false;
                this.classList.remove('is-active');
                mode = 'rich';
                updateCount();
            }
        });

        area.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        area.addEventListener('input', updateCount);
        code.addEventListener('input', function () { counter.textContent = code.value.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim().length; });

        function updateCount() {
            var len = area.textContent.replace(/\s+/g, ' ').trim().length;
            counter.textContent = len;
            counterBox.classList.toggle('is-over', len > max);
        }
        updateCount();

        return {
            getHTML: function () { return cleanHtml(mode === 'code' ? code.value : area.innerHTML); }
        };

        function fbtn(cmd, icon, label) {
            return '<button type="button" class="button lrob-rte-btn" data-cmd="' + cmd + '" ' +
                'title="' + esc(label) + '" aria-label="' + esc(label) + '">' +
                '<span class="dashicons dashicons-' + icon + '"></span></button>';
        }
    }

    /* ── Recurrence (RRULE) parse / build ────────────────────────────────── */

    // Parse an RRULE into the simple UI model. Anything the simple builder can't
    // round-trip (BYMONTH, BYMONTHDAY, nth-weekday like "2MO", embedded RDATE/
    // EXDATE, unknown parts) is flagged complex and preserved verbatim.
    function parseRRule(raw) {
        var base = { freq: '', interval: 1, byday: [], endType: 'never', count: 10, until: '', complex: false, raw: raw || '' };
        if (!raw) return base;
        if (/[\r\n]/.test(raw)) { base.complex = true; return base; }

        var rule = raw.replace(/^RRULE:/i, '').trim();
        var parts = {};
        rule.split(';').forEach(function (p) {
            var kv = p.split('=');
            if (kv.length === 2) parts[kv[0].toUpperCase()] = kv[1];
        });

        var known = ['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL', 'WKST'];
        var hasUnknown = Object.keys(parts).some(function (k) { return known.indexOf(k) === -1; });
        var byday = parts.BYDAY ? parts.BYDAY.split(',') : [];
        var bydayNth = byday.some(function (d) { return /\d/.test(d); });
        var freq = (parts.FREQ || '').toUpperCase();

        if (hasUnknown || bydayNth || (byday.length && freq !== 'WEEKLY') ||
            ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].indexOf(freq) === -1) {
            base.complex = true;
            return base;
        }

        base.freq = freq;
        base.interval = parseInt(parts.INTERVAL, 10) || 1;
        base.byday = byday;
        if (parts.COUNT) { base.endType = 'count'; base.count = parseInt(parts.COUNT, 10) || 10; }
        else if (parts.UNTIL) {
            base.endType = 'until';
            var m = parts.UNTIL.match(/^(\d{4})(\d{2})(\d{2})/);
            if (m) base.until = m[1] + '-' + m[2] + '-' + m[3];
        }
        return base;
    }

    function buildRRule() {
        var freq = q('.lrob-r-freq').value;
        if (freq === 'custom') return recurrenceRaw || '';
        if (!freq) return '';
        var parts = ['FREQ=' + freq];
        var interval = Math.max(1, parseInt(q('.lrob-r-interval').value, 10) || 1);
        if (interval > 1) parts.push('INTERVAL=' + interval);
        if (freq === 'WEEKLY') {
            var days = checkedStr('.lrob-r-byday');
            if (days.length) parts.push('BYDAY=' + days.join(','));
        }
        var endType = (overlay.querySelector('input[name="lrob-r-end"]:checked') || {}).value || 'never';
        if (endType === 'count') {
            parts.push('COUNT=' + Math.max(1, parseInt(q('.lrob-r-count').value, 10) || 10));
        } else if (endType === 'until') {
            var u = q('.lrob-r-until').value;
            if (u) parts.push('UNTIL=' + u.replace(/-/g, '') + 'T235959Z');
        }
        return parts.join(';');
    }

    function applyRecurrence() {
        var freq = q('.lrob-r-freq').value;
        var custom = (freq === 'custom');
        q('.lrob-r-detail').hidden = (freq === '' || custom);
        q('.lrob-r-custom-note').hidden = !custom;
        q('.lrob-r-days-row').hidden = (freq !== 'WEEKLY');
        var units = {
            DAILY: __('day(s)', 'lrob-calendar'), WEEKLY: __('week(s)', 'lrob-calendar'),
            MONTHLY: __('month(s)', 'lrob-calendar'), YEARLY: __('year(s)', 'lrob-calendar')
        };
        q('.lrob-r-unit').textContent = units[freq] || '';
    }

    /* ── Field markup ────────────────────────────────────────────────────── */

    function txtRow(cls, label, ph, type) {
        return '<div class="lrob-f-row lrob-f-col">' +
            '<label class="lrob-f-label">' + esc(label) + '</label>' +
            '<input type="' + (type || 'text') + '" class="' + cls + ' widefat" placeholder="' + esc(ph || '') + '">' +
            '</div>';
    }

    function catTree() {
        var cats = (cfg().categories || []).slice();
        var byParent = {};
        cats.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
        function branch(parent, depth) {
            return (byParent[parent] || []).map(function (c) {
                return '<label class="lrob-f-check" data-term="' + esc(c.value) + '" style="padding-left:' + (depth * 16) + 'px">' +
                    '<input type="checkbox" class="lrob-f-cat" value="' + esc(c.value) + '"> ' + esc(c.label) +
                    '</label>' + branch(c.value, depth + 1);
            }).join('');
        }
        return branch(0, 0);
    }

    function tagChecks() {
        return (cfg().tags || []).map(function (t) {
            return '<label class="lrob-f-check" data-term="' + esc(t.value) + '"><input type="checkbox" class="lrob-f-tag" value="' + esc(t.value) + '"> ' + esc(t.label) + '</label>';
        }).join('');
    }

    function statusOptions() {
        return (cfg().statuses || []).filter(function (s) { return s.value !== 'any'; })
            .map(function (s) { return '<option value="' + esc(s.value) + '">' + esc(s.label) + '</option>'; }).join('');
    }

    function section(title) { return '<h3 class="lrob-modal-section">' + esc(title) + '</h3>'; }

    function dayChecks() {
        var days = [
            ['MO', __('Mon', 'lrob-calendar')], ['TU', __('Tue', 'lrob-calendar')], ['WE', __('Wed', 'lrob-calendar')],
            ['TH', __('Thu', 'lrob-calendar')], ['FR', __('Fri', 'lrob-calendar')], ['SA', __('Sat', 'lrob-calendar')],
            ['SU', __('Sun', 'lrob-calendar')]
        ];
        return days.map(function (d) {
            return '<label class="lrob-r-day"><input type="checkbox" class="lrob-r-byday" value="' + d[0] + '"> ' + esc(d[1]) + '</label>';
        }).join('');
    }

    function recurrenceHtml() {
        return section(__('Recurrence', 'lrob-calendar')) +
        '<div class="lrob-f-row">' +
            '<select class="lrob-r-freq">' +
                '<option value="">' + esc(__('Does not repeat', 'lrob-calendar')) + '</option>' +
                '<option value="DAILY">' + esc(__('Daily', 'lrob-calendar')) + '</option>' +
                '<option value="WEEKLY">' + esc(__('Weekly', 'lrob-calendar')) + '</option>' +
                '<option value="MONTHLY">' + esc(__('Monthly', 'lrob-calendar')) + '</option>' +
                '<option value="YEARLY">' + esc(__('Yearly', 'lrob-calendar')) + '</option>' +
            '</select>' +
        '</div>' +
        '<div class="lrob-r-detail" hidden>' +
            '<div class="lrob-f-row lrob-r-interval-row">' +
                '<label class="lrob-f-label">' + esc(__('Every', 'lrob-calendar')) + '</label>' +
                '<input type="number" min="1" value="1" class="lrob-r-interval"> <span class="lrob-r-unit"></span>' +
            '</div>' +
            '<div class="lrob-f-row lrob-r-days-row" hidden>' +
                '<label class="lrob-f-label">' + esc(__('On days', 'lrob-calendar')) + '</label>' +
                '<div class="lrob-r-days">' + dayChecks() + '</div>' +
            '</div>' +
            '<div class="lrob-f-row">' +
                '<label class="lrob-f-label">' + esc(__('Ends', 'lrob-calendar')) + '</label>' +
                '<div class="lrob-r-ends">' +
                    '<label class="lrob-f-check"><input type="radio" name="lrob-r-end" value="never" checked> ' + esc(__('Never', 'lrob-calendar')) + '</label>' +
                    '<label class="lrob-f-check"><input type="radio" name="lrob-r-end" value="count"> ' + esc(__('After', 'lrob-calendar')) +
                        ' <input type="number" min="1" value="10" class="lrob-r-count"> ' + esc(__('occurrences', 'lrob-calendar')) + '</label>' +
                    '<label class="lrob-f-check"><input type="radio" name="lrob-r-end" value="until"> ' + esc(__('On date', 'lrob-calendar')) +
                        ' <input type="date" class="lrob-r-until"></label>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<p class="lrob-r-custom-note" hidden>' +
            esc(__('This recurrence is too advanced to edit here — use the WordPress editor to change it.', 'lrob-calendar')) +
            ' <code class="lrob-r-raw"></code></p>' +
        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Exception dates', 'lrob-calendar')) +
                ' <span class="lrob-f-opt">' + esc(__('(optional)', 'lrob-calendar')) + '</span></label>' +
            '<input type="text" class="lrob-x-exception_dates widefat" placeholder="2026-12-25, 2027-01-01">' +
        '</div>';
    }

    function bodyHtml() {
        return '' +
        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Title', 'lrob-calendar')) + '</label>' +
            '<input type="text" class="lrob-f-title widefat" placeholder="' + esc(__('Event title', 'lrob-calendar')) + '">' +
        '</div>' +

        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Type', 'lrob-calendar')) + '</label>' +
            '<div class="lrob-f-types">' +
                typeRadio('standard', __('Standard', 'lrob-calendar')) +
                typeRadio('allday', __('All day', 'lrob-calendar')) +
                typeRadio('instant', __('Instant', 'lrob-calendar')) +
            '</div>' +
        '</div>' +

        '<div class="lrob-f-row lrob-f-when">' +
            '<label class="lrob-f-label">' + esc(__('When', 'lrob-calendar')) + '</label>' +
            '<div class="lrob-f-when-grid">' +
                '<div class="lrob-f-dt">' +
                    '<span class="lrob-f-sub">' + esc(__('Start', 'lrob-calendar')) + '</span>' +
                    '<input type="date" class="lrob-f-sd"><input type="time" class="lrob-f-st">' +
                '</div>' +
                '<div class="lrob-f-dt lrob-f-end">' +
                    '<span class="lrob-f-sub">' + esc(__('End', 'lrob-calendar')) + '</span>' +
                    '<input type="date" class="lrob-f-ed"><input type="time" class="lrob-f-et">' +
                '</div>' +
                '<div class="lrob-f-dt lrob-f-tz-wrap">' +
                    '<span class="lrob-f-sub">' + esc(__('Timezone', 'lrob-calendar')) + '</span>' +
                    '<select class="lrob-f-tz">' + (cfg().timezoneChoice || '') + '</select>' +
                '</div>' +
            '</div>' +
        '</div>' +

        recurrenceHtml() +

        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Description', 'lrob-calendar')) + '</label>' +
            '<div class="lrob-f-desc"></div>' +
            '<p class="lrob-desc-note" hidden><span class="lrob-desc-note-text"></span> <a href="#" class="lrob-desc-note-link"></a></p>' +
        '</div>' +

        section(__('Location', 'lrob-calendar')) +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-venue', __('Venue', 'lrob-calendar'), '') +
            txtRow('lrob-x-address', __('Address', 'lrob-calendar'), '') +
        '</div>' +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-city', __('City', 'lrob-calendar'), '') +
            txtRow('lrob-x-province', __('State / Province', 'lrob-calendar'), '') +
        '</div>' +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-postal_code', __('Postal code', 'lrob-calendar'), '') +
            txtRow('lrob-x-country', __('Country', 'lrob-calendar'), '') +
        '</div>' +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-latitude', __('Latitude', 'lrob-calendar'), '') +
            txtRow('lrob-x-longitude', __('Longitude', 'lrob-calendar'), '') +
        '</div>' +
        '<div class="lrob-f-row">' +
            '<label class="lrob-f-check"><input type="checkbox" class="lrob-x-show_map"> ' + esc(__('Show map', 'lrob-calendar')) + '</label>' +
            '<label class="lrob-f-check"><input type="checkbox" class="lrob-x-show_coordinates"> ' + esc(__('Show coordinates', 'lrob-calendar')) + '</label>' +
        '</div>' +

        section(__('Contact', 'lrob-calendar')) +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-contact_name', __('Contact name', 'lrob-calendar'), '') +
            txtRow('lrob-x-contact_phone', __('Phone', 'lrob-calendar'), '', 'tel') +
        '</div>' +
        '<div class="lrob-f-cols">' +
            txtRow('lrob-x-contact_email', __('Email', 'lrob-calendar'), '', 'email') +
            txtRow('lrob-x-contact_url', __('Website', 'lrob-calendar'), 'https://', 'url') +
        '</div>' +

        section(__('Cost', 'lrob-calendar')) +
        '<div class="lrob-f-cols">' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Price', 'lrob-calendar')) + '</label>' +
                '<input type="text" class="lrob-x-cost widefat" placeholder="' + esc(__('e.g. 10 €', 'lrob-calendar')) + '">' +
                '<label class="lrob-f-check"><input type="checkbox" class="lrob-x-is_free"> ' + esc(__('Free event', 'lrob-calendar')) + '</label>' +
            '</div>' +
            txtRow('lrob-x-ticket_url', __('Ticket URL', 'lrob-calendar'), 'https://', 'url') +
        '</div>' +

        section(__('Organization', 'lrob-calendar')) +
        '<div class="lrob-f-cols">' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Categories', 'lrob-calendar')) + ' <span class="lrob-f-opt">' + esc(__('(optional)', 'lrob-calendar')) + '</span></label>' +
                '<div class="lrob-f-checks lrob-f-cats">' + catTree() + '</div>' +
                '<button type="button" class="button-link lrob-f-add-term" data-tax="category">+ ' + esc(__('Add category', 'lrob-calendar')) + '</button>' +
            '</div>' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Tags', 'lrob-calendar')) + ' <span class="lrob-f-opt">' + esc(__('(optional)', 'lrob-calendar')) + '</span></label>' +
                '<div class="lrob-f-checks lrob-f-tags">' + tagChecks() + '</div>' +
                '<button type="button" class="button-link lrob-f-add-term" data-tax="tag">+ ' + esc(__('Add tag', 'lrob-calendar')) + '</button>' +
            '</div>' +
        '</div>' +

        '<div class="lrob-f-cols">' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Status', 'lrob-calendar')) + '</label>' +
                '<select class="lrob-f-status">' + statusOptions() + '</select>' +
            '</div>' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Featured image', 'lrob-calendar')) + '</label>' +
                '<div class="lrob-f-image">' +
                    '<div class="lrob-f-image-preview"></div>' +
                    '<button type="button" class="button lrob-f-image-set">' + esc(__('Set image', 'lrob-calendar')) + '</button>' +
                    '<button type="button" class="button-link lrob-f-image-remove" hidden>' + esc(__('Remove', 'lrob-calendar')) + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function typeRadio(value, label) {
        return '<label class="lrob-f-type"><input type="radio" name="lrob-type" value="' + value + '"> ' + esc(label) + '</label>';
    }

    /* ── Open / populate / collect ───────────────────────────────────────── */

    function open(id, onSaved) {
        current.id = id;
        current.onSaved = onSaved;
        buildShell();
        document.body.appendChild(overlay);
        document.body.classList.add('lrob-modal-open');

        if (id) {
            setTitle(__('Edit event', 'lrob-calendar'));
            setBusy(true);
            fetch(cfg().restRoot + '/' + id, { headers: { 'X-WP-Nonce': cfg().nonce }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (data) { setBusy(false); populate(data); })
                .catch(function () { setBusy(false); message(__('Could not load the event.', 'lrob-calendar'), true); });
        } else {
            setTitle(__('New event', 'lrob-calendar'));
            populate(defaults());
        }
    }

    function buildShell() {
        overlay = el(
            '<div class="lrob-modal-overlay">' +
                '<div class="lrob-modal" role="dialog" aria-modal="true">' +
                    '<header class="lrob-modal-head">' +
                        '<h2 class="lrob-modal-title"></h2>' +
                        '<div class="lrob-modal-head-actions">' +
                            '<a class="lrob-modal-advanced" hidden>' + esc(__('→ WordPress editor', 'lrob-calendar')) + '</a>' +
                            '<button type="button" class="lrob-modal-close" aria-label="' + esc(__('Close', 'lrob-calendar')) + '">&times;</button>' +
                        '</div>' +
                    '</header>' +
                    '<div class="lrob-modal-body">' + bodyHtml() + '</div>' +
                    '<footer class="lrob-modal-foot">' +
                        '<span class="lrob-modal-msg" aria-live="polite"></span>' +
                        '<div class="lrob-modal-foot-actions">' +
                            '<button type="button" class="button lrob-modal-cancel">' + esc(__('Cancel', 'lrob-calendar')) + '</button>' +
                            '<button type="button" class="button button-primary lrob-modal-save">' + esc(__('Save', 'lrob-calendar')) + '</button>' +
                        '</div>' +
                    '</footer>' +
                '</div>' +
            '</div>'
        );

        ed = createEditor(q('.lrob-f-desc'), '');

        q('.lrob-modal-close').addEventListener('click', requestClose);
        q('.lrob-modal-cancel').addEventListener('click', requestClose);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) requestClose(); });
        document.addEventListener('keydown', onKey);
        q('.lrob-modal-save').addEventListener('click', save);

        q('.lrob-modal-advanced').addEventListener('click', function (e) {
            e.preventDefault();
            if (this.hidden || !this.href) return;
            if (dirty && !window.confirm(__('You have unsaved changes that will be lost. Open the WordPress editor anyway?', 'lrob-calendar'))) {
                return;
            }
            window.location.href = this.href;
        });

        overlay.querySelectorAll('input[name="lrob-type"]').forEach(function (r) {
            r.addEventListener('change', applyTypeVisibility);
        });
        q('.lrob-r-freq').addEventListener('change', applyRecurrence);

        // The block-editor note's link opens the WP editor.
        q('.lrob-desc-note-link').addEventListener('click', function (e) { e.preventDefault(); q('.lrob-modal-advanced').click(); });

        // Keep the end from preceding the start; default end to match the start.
        q('.lrob-f-sd').addEventListener('change', syncEndMin);
        q('.lrob-f-ed').addEventListener('change', syncEndMin);

        // Native date/time inputs format per the element's lang — force the site
        // locale so a FR site shows 24h, not AM/PM.
        q('.lrob-modal').lang = cfg().locale || '';

        overlay.querySelectorAll('.lrob-f-add-term').forEach(function (b) {
            b.addEventListener('click', function () { openTermPopup(this.getAttribute('data-tax')); });
        });

        // Dirty tracking: any user edit flips the flag (drives the unsaved warning).
        q('.lrob-modal-body').addEventListener('input', markDirty);
        q('.lrob-modal-body').addEventListener('change', markDirty);

        bindImage();
    }

    function populate(d) {
        current.id = d.id || current.id;
        var f = d.fields || {};

        q('.lrob-f-title').value = d.title || '';
        var dt = d.datetime || defaults().datetime;
        setRadio('lrob-type', dt.type || 'standard');
        q('.lrob-f-sd').value = dt.start_date || '';
        q('.lrob-f-st').value = dt.start_time || '';
        q('.lrob-f-ed').value = dt.end_date || '';
        q('.lrob-f-et').value = dt.end_time || '';
        if (dt.timezone) q('.lrob-f-tz').value = dt.timezone;
        q('.lrob-f-status').value = d.status && d.status !== 'auto-draft' ? d.status : 'publish';

        // Location / contact / cost.
        setVal('.lrob-x-venue', f.venue);
        setVal('.lrob-x-address', f.address);
        setVal('.lrob-x-city', f.city);
        setVal('.lrob-x-province', f.province);
        setVal('.lrob-x-postal_code', f.postal_code);
        setVal('.lrob-x-country', f.country);
        setVal('.lrob-x-latitude', f.latitude);
        setVal('.lrob-x-longitude', f.longitude);
        setChk('.lrob-x-show_map', f.show_map);
        setChk('.lrob-x-show_coordinates', f.show_coordinates);
        setVal('.lrob-x-contact_name', f.contact_name);
        setVal('.lrob-x-contact_phone', f.contact_phone);
        setVal('.lrob-x-contact_email', f.contact_email);
        setVal('.lrob-x-contact_url', f.contact_url);
        setVal('.lrob-x-cost', f.cost);
        setChk('.lrob-x-is_free', f.is_free);
        setVal('.lrob-x-ticket_url', f.ticket_url);

        // Recurrence.
        var rec = parseRRule(f.recurrence_rules || '');
        recurrenceRaw = rec.raw || '';
        var freqSel = q('.lrob-r-freq');
        if (rec.complex) {
            var opt = document.createElement('option');
            opt.value = 'custom';
            opt.textContent = __('Custom (advanced)', 'lrob-calendar');
            freqSel.appendChild(opt);
            freqSel.value = 'custom';
            q('.lrob-r-raw').textContent = rec.raw;
        } else {
            freqSel.value = rec.freq || '';
            q('.lrob-r-interval').value = rec.interval || 1;
            rec.byday.forEach(function (dy) { var c = q('.lrob-r-byday[value="' + dy + '"]'); if (c) c.checked = true; });
            setRadio('lrob-r-end', rec.endType || 'never');
            if (rec.count) q('.lrob-r-count').value = rec.count;
            if (rec.until) q('.lrob-r-until').value = rec.until;
        }
        setVal('.lrob-x-exception_dates', f.exception_dates);
        applyRecurrence();

        (d.categories || []).forEach(function (id) { var c = q('.lrob-f-cat[value="' + id + '"]'); if (c) c.checked = true; });
        (d.tags || []).forEach(function (id) { var c = q('.lrob-f-tag[value="' + id + '"]'); if (c) c.checked = true; });

        featuredId = d.featuredImage ? d.featuredImage.id : 0;
        renderImage(d.featuredImage ? d.featuredImage.url : '');

        ed = createEditor(q('.lrob-f-desc'), d.content || '');

        var adv = q('.lrob-modal-advanced');
        if (d.editLink) {
            adv.href = d.editLink;
        } else {
            // Create mode: link to the classic new-event editor for those who
            // prefer it (escape param so the redirect doesn't bounce it back).
            var nl = cfg().newLink || '';
            adv.href = nl + (nl.indexOf('?') > -1 ? '&' : '?') + 'lrob_classic=1';
        }
        adv.hidden = false;

        // Subtle inline note, only when the content still holds block markup.
        var note = q('.lrob-desc-note');
        if (d.hasBlocks) {
            q('.lrob-desc-note-text').textContent = __('Created with the block editor — editing here keeps only simple formatting.', 'lrob-calendar');
            q('.lrob-desc-note-link').textContent = __('Open in WordPress editor', 'lrob-calendar');
            note.hidden = false;
        } else {
            note.hidden = true;
        }

        applyTypeVisibility();
        syncEndMin();
        dirty = false;
        q('.lrob-f-title').focus();
    }

    function collect() {
        var type = (overlay.querySelector('input[name="lrob-type"]:checked') || {}).value || 'standard';
        return {
            title: q('.lrob-f-title').value,
            content: ed ? ed.getHTML() : '',
            status: q('.lrob-f-status').value,
            datetime: {
                type: type,
                timezone: q('.lrob-f-tz').value,
                start_date: q('.lrob-f-sd').value,
                start_time: q('.lrob-f-st').value,
                end_date: q('.lrob-f-ed').value,
                end_time: q('.lrob-f-et').value
            },
            fields: {
                venue: val('.lrob-x-venue'), address: val('.lrob-x-address'), city: val('.lrob-x-city'),
                province: val('.lrob-x-province'), postal_code: val('.lrob-x-postal_code'), country: val('.lrob-x-country'),
                latitude: val('.lrob-x-latitude'), longitude: val('.lrob-x-longitude'),
                show_map: chk('.lrob-x-show_map'), show_coordinates: chk('.lrob-x-show_coordinates'),
                contact_name: val('.lrob-x-contact_name'), contact_phone: val('.lrob-x-contact_phone'),
                contact_email: val('.lrob-x-contact_email'), contact_url: val('.lrob-x-contact_url'),
                cost: val('.lrob-x-cost'), is_free: chk('.lrob-x-is_free'), ticket_url: val('.lrob-x-ticket_url'),
                recurrence_rules: buildRRule(), exception_dates: val('.lrob-x-exception_dates')
            },
            categories: checkedValues('.lrob-f-cat'),
            tags: checkedValues('.lrob-f-tag'),
            featuredImageId: featuredId
        };
    }

    function save() {
        var payload = collect();
        if (!payload.datetime.start_date) {
            message(__('Please set a start date.', 'lrob-calendar'), true);
            return;
        }
        var saveBtn = q('.lrob-modal-save');
        saveBtn.disabled = true;
        message(__('Saving…', 'lrob-calendar'), false);

        var url = current.id ? cfg().restRoot + '/' + current.id : cfg().restRoot;
        fetch(url, {
            method: current.id ? 'PUT' : 'POST',
            headers: { 'X-WP-Nonce': cfg().nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function () {
                dirty = false;
                close();
                if (typeof current.onSaved === 'function') current.onSaved();
            })
            .catch(function () {
                saveBtn.disabled = false;
                message(__('Could not save the event.', 'lrob-calendar'), true);
            });
    }

    /* ── Quick term creation (second popup) ──────────────────────────────── */

    function openTermPopup(tax) {
        var isCat = (tax === 'category');
        var parentOpts = isCat
            ? '<option value="0">' + esc(__('— No parent —', 'lrob-calendar')) + '</option>' +
              (cfg().categories || []).map(function (c) { return '<option value="' + esc(c.value) + '">' + esc(c.label) + '</option>'; }).join('')
            : '';

        var pop = el(
            '<div class="lrob-term-overlay">' +
                '<div class="lrob-term-pop" role="dialog" aria-modal="true">' +
                    '<h3>' + esc(isCat ? __('Add category', 'lrob-calendar') : __('Add tag', 'lrob-calendar')) + '</h3>' +
                    '<label class="lrob-f-label">' + esc(__('Name', 'lrob-calendar')) + '</label>' +
                    '<input type="text" class="lrob-term-name widefat">' +
                    (isCat ? '<label class="lrob-f-label">' + esc(__('Parent', 'lrob-calendar')) + '</label><select class="lrob-term-parent widefat">' + parentOpts + '</select>' : '') +
                    '<p class="lrob-term-msg" aria-live="polite"></p>' +
                    '<div class="lrob-term-actions">' +
                        '<button type="button" class="button lrob-term-cancel">' + esc(__('Cancel', 'lrob-calendar')) + '</button>' +
                        '<button type="button" class="button button-primary lrob-term-create">' + esc(__('Create', 'lrob-calendar')) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        overlay.appendChild(pop);
        var name = pop.querySelector('.lrob-term-name');
        name.focus();

        function done() { pop.remove(); }
        pop.querySelector('.lrob-term-cancel').addEventListener('click', done);
        pop.addEventListener('mousedown', function (e) { if (e.target === pop) done(); });

        pop.querySelector('.lrob-term-create').addEventListener('click', function () {
            var nm = name.value.trim();
            if (!nm) { name.focus(); return; }
            var parent = isCat ? parseInt((pop.querySelector('.lrob-term-parent') || {}).value || 0, 10) : 0;
            var btn = this; btn.disabled = true;
            pop.querySelector('.lrob-term-msg').textContent = __('Creating…', 'lrob-calendar');

            fetch(termsUrl(), {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg().nonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ taxonomy: isCat ? 'category' : 'tag', name: nm, parent: parent })
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (term) {
                    addTermCheckbox(isCat, term);
                    done();
                })
                .catch(function () {
                    btn.disabled = false;
                    pop.querySelector('.lrob-term-msg').textContent = __('Could not create.', 'lrob-calendar');
                });
        });
    }

    function addTermCheckbox(isCat, term) {
        var listSel = isCat ? '.lrob-f-cats' : '.lrob-f-tags';
        var inputCls = isCat ? 'lrob-f-cat' : 'lrob-f-tag';
        var list = q(listSel);
        if (q('.' + inputCls + '[value="' + term.id + '"]')) {
            q('.' + inputCls + '[value="' + term.id + '"]').checked = true;
            return;
        }
        var label = el('<label class="lrob-f-check" data-term="' + esc(term.id) + '">' +
            '<input type="checkbox" class="' + inputCls + '" value="' + esc(term.id) + '" checked> ' + esc(term.name) + '</label>');
        list.appendChild(label);
        // Keep the config list in sync for any later re-render.
        (isCat ? cfg().categories : cfg().tags).push({ value: term.id, label: term.name, parent: term.parent || 0 });
        markDirty();
    }

    /* ── Featured image (wp.media) ───────────────────────────────────────── */

    function bindImage() {
        var frame = null;
        q('.lrob-f-image-set').addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.wp || !window.wp.media) return;
            if (!frame) {
                frame = window.wp.media({ title: __('Select image', 'lrob-calendar'), multiple: false });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    featuredId = att.id;
                    var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                    renderImage(url);
                    markDirty();
                });
            }
            frame.open();
        });
        q('.lrob-f-image-remove').addEventListener('click', function (e) {
            e.preventDefault();
            featuredId = 0;
            renderImage('');
            markDirty();
        });
    }

    function renderImage(url) {
        var prev = q('.lrob-f-image-preview');
        var rm = q('.lrob-f-image-remove');
        if (url) { prev.innerHTML = '<img src="' + esc(url) + '" alt="">'; rm.hidden = false; }
        else { prev.innerHTML = ''; rm.hidden = true; }
    }

    /* ── Small UI utils ──────────────────────────────────────────────────── */

    function applyTypeVisibility() {
        var type = (overlay.querySelector('input[name="lrob-type"]:checked') || {}).value || 'standard';
        var timed = (type === 'standard');
        overlay.querySelectorAll('.lrob-f-st, .lrob-f-et').forEach(function (i) { i.style.display = timed ? '' : 'none'; });
        q('.lrob-f-end').style.display = (type === 'instant') ? 'none' : '';
    }

    // Constrain the end date to the start and default it to match, so a future
    // start can't leave a stale past end date.
    function syncEndMin() {
        var sd = q('.lrob-f-sd').value;
        var edEl = q('.lrob-f-ed');
        if (!edEl) return;
        if (sd) edEl.min = sd;
        if (sd && (!edEl.value || edEl.value < sd)) edEl.value = sd;
    }

    function val(sel) { var e = q(sel); return e ? e.value : ''; }
    function chk(sel) { var e = q(sel); return e && e.checked ? 1 : 0; }
    function setVal(sel, v) { var e = q(sel); if (e) e.value = (v == null ? '' : v); }
    function setChk(sel, v) { var e = q(sel); if (e) e.checked = !!(v && v !== '0'); }
    function checkedValues(sel) {
        return Array.prototype.slice.call(overlay.querySelectorAll(sel + ':checked'))
            .map(function (c) { return parseInt(c.value, 10); });
    }
    function checkedStr(sel) {
        return Array.prototype.slice.call(overlay.querySelectorAll(sel + ':checked'))
            .map(function (c) { return c.value; });
    }
    function setRadio(name, value) { var r = overlay.querySelector('input[name="' + name + '"][value="' + value + '"]'); if (r) r.checked = true; }
    function setTitle(t) { q('.lrob-modal-title').textContent = t; }
    function setBusy(on) { q('.lrob-modal').classList.toggle('is-busy', !!on); }
    function message(text, isError) { var m = q('.lrob-modal-msg'); m.textContent = text || ''; m.classList.toggle('is-error', !!isError); }
    function markDirty() { dirty = true; }
    function onKey(e) { if (e.key === 'Escape') requestClose(); }

    function requestClose() {
        if (dirty && !window.confirm(__('Discard your changes?', 'lrob-calendar'))) return;
        close();
    }

    function close() {
        if (!overlay) return;
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        overlay = null;
        ed = null;
        featuredId = 0;
        dirty = false;
        document.body.classList.remove('lrob-modal-open');
    }

    window.LrobEventModal = { open: open };
})(window.wp && window.wp.i18n);
