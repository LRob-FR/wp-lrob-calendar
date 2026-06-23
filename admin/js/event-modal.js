/**
 * Dynamic event editor modal (v1.2, Phase 2).
 *
 * window.LrobEventModal.open(id|null, onSaved) — loads an event over REST (or a
 * blank form), edits the core fields without leaving the page, and saves via
 * create/update. Timezone math lives entirely on the server: the modal sends
 * wall-clock date/time parts + the chosen timezone and PHP computes the stored
 * timestamps (identical to the classic meta box).
 *
 * The description uses a deliberately minimal rich-text editor — bold, italic,
 * lists and links only. No headings, no images: the guardrail against authors
 * pasting an H1 for "bigger text" or a giant image. Server-side wp_kses enforces
 * the same allow-list, so this is UX, not security.
 */
(function (i18n) {
    var __ = (i18n && i18n.__) ? i18n.__ : function (s) { return s; };
    function cfg() { return window.lrobCalendarManage || {}; }

    var overlay = null;
    var ed = null;            // active mini-editor instance
    var featuredId = 0;
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

    function defaults() {
        var now = new Date();
        now.setMinutes(0, 0, 0);
        now.setHours(now.getHours() + 1);
        var end = new Date(now.getTime() + 3600000);
        function d(x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()); }
        function t(x) { return pad(x.getHours()) + ':' + pad(x.getMinutes()); }
        return {
            id: null, title: '', content: '', status: 'draft',
            datetime: {
                type: 'standard', timezone: cfg().defaultTimezone || 'UTC',
                start_date: d(now), start_time: t(now), end_date: d(end), end_time: t(end)
            },
            categories: [], tags: [], featuredImage: null, editLink: null
        };
    }

    /* ── Minimal rich-text editor ────────────────────────────────────────── */

    var ALLOWED = ['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'A'];

    function cleanHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html || '';

        // Drop block-editor comment nodes and disallowed/empty media outright.
        (function walk(node) {
            var child = node.firstChild;
            while (child) {
                var next = child.nextSibling;
                if (child.nodeType === 8) { // comment (e.g. <!-- wp:paragraph -->)
                    node.removeChild(child);
                } else if (child.nodeType === 1) {
                    var tag = child.tagName;
                    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'IMG') {
                        node.removeChild(child);
                    } else if (tag === 'DIV') {
                        // execCommand wraps lines in <div>; promote to <p>.
                        var p = document.createElement('p');
                        while (child.firstChild) p.appendChild(child.firstChild);
                        node.replaceChild(p, child);
                        walk(p);
                    } else if (ALLOWED.indexOf(tag) === -1) {
                        // Unwrap unknown tags (H1, SPAN, FONT…), keep their text.
                        while (child.firstChild) node.insertBefore(child.firstChild, child);
                        node.removeChild(child);
                    } else {
                        // Strip every attribute except href on links.
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
                btn('bold', 'editor-bold', __('Bold', 'lrob-calendar')) +
                btn('italic', 'editor-italic', __('Italic', 'lrob-calendar')) +
                btn('insertUnorderedList', 'editor-ul', __('Bulleted list', 'lrob-calendar')) +
                btn('insertOrderedList', 'editor-ol', __('Numbered list', 'lrob-calendar')) +
                btn('createLink', 'admin-links', __('Link', 'lrob-calendar')) +
                btn('removeFormat', 'editor-removeformatting', __('Clear formatting', 'lrob-calendar')) +
            '</div>' +
            '<div class="lrob-rte" contenteditable="true"></div>' +
            '<div class="lrob-rte-counter"><span class="lrob-rte-count">0</span> / ' +
                esc(cfg().descRecommended || 800) + '</div>';

        var area = host.querySelector('.lrob-rte');
        area.innerHTML = cleanHtml(html);

        host.querySelectorAll('.lrob-rte-btn').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                area.focus();
                var cmd = this.getAttribute('data-cmd');
                if (cmd === 'createLink') {
                    var url = window.prompt(__('Link URL:', 'lrob-calendar'), 'https://');
                    if (url) document.execCommand('createLink', false, url);
                } else {
                    document.execCommand(cmd, false, null);
                }
                updateCount();
            });
        });

        // Paste as plain text — kills inherited headings, colors, images, sizes.
        area.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        area.addEventListener('input', updateCount);

        var counter = host.querySelector('.lrob-rte-count');
        var max = parseInt(cfg().descRecommended, 10) || 800;
        function updateCount() {
            var len = area.textContent.replace(/\s+/g, ' ').trim().length;
            counter.textContent = len;
            host.querySelector('.lrob-rte-counter').classList.toggle('is-over', len > max);
        }
        updateCount();

        return { getHTML: function () { return cleanHtml(area.innerHTML); } };

        function btn(cmd, icon, label) {
            return '<button type="button" class="button lrob-rte-btn" data-cmd="' + cmd + '" ' +
                'title="' + esc(label) + '" aria-label="' + esc(label) + '">' +
                '<span class="dashicons dashicons-' + icon + '"></span></button>';
        }
    }

    /* ── Field markup ────────────────────────────────────────────────────── */

    function catTree() {
        var cats = (cfg().categories || []).slice();
        var byParent = {};
        cats.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
        function branch(parent, depth) {
            return (byParent[parent] || []).map(function (c) {
                return '<label class="lrob-f-check" style="padding-left:' + (depth * 16) + 'px">' +
                    '<input type="checkbox" class="lrob-f-cat" value="' + esc(c.value) + '"> ' + esc(c.label) +
                    '</label>' + branch(c.value, depth + 1);
            }).join('');
        }
        var html = branch(0, 0);
        return html || '<p class="lrob-modal-hint">' + esc(__('No categories yet.', 'lrob-calendar')) + '</p>';
    }

    function tagList() {
        var tags = cfg().tags || [];
        if (!tags.length) return '<p class="lrob-modal-hint">' + esc(__('No tags yet.', 'lrob-calendar')) + '</p>';
        return tags.map(function (t) {
            return '<label class="lrob-f-check"><input type="checkbox" class="lrob-f-tag" value="' + esc(t.value) + '"> ' + esc(t.label) + '</label>';
        }).join('');
    }

    function statusOptions() {
        return (cfg().statuses || []).filter(function (s) { return s.value !== 'any'; })
            .map(function (s) { return '<option value="' + esc(s.value) + '">' + esc(s.label) + '</option>'; }).join('');
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
                    '<input type="date" class="lrob-f-sd">' +
                    '<input type="time" class="lrob-f-st">' +
                '</div>' +
                '<div class="lrob-f-dt lrob-f-end">' +
                    '<span class="lrob-f-sub">' + esc(__('End', 'lrob-calendar')) + '</span>' +
                    '<input type="date" class="lrob-f-ed">' +
                    '<input type="time" class="lrob-f-et">' +
                '</div>' +
            '</div>' +
        '</div>' +

        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Timezone', 'lrob-calendar')) + '</label>' +
            '<select class="lrob-f-tz">' + (cfg().timezoneChoice || '') + '</select>' +
        '</div>' +

        '<div class="lrob-f-row">' +
            '<label class="lrob-f-label">' + esc(__('Description', 'lrob-calendar')) + '</label>' +
            '<div class="lrob-f-desc"></div>' +
        '</div>' +

        '<div class="lrob-f-cols">' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Categories', 'lrob-calendar')) + '</label>' +
                '<div class="lrob-f-checks">' + catTree() + '</div>' +
            '</div>' +
            '<div class="lrob-f-row lrob-f-col">' +
                '<label class="lrob-f-label">' + esc(__('Tags', 'lrob-calendar')) + '</label>' +
                '<div class="lrob-f-checks">' + tagList() + '</div>' +
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
                            '<a class="lrob-modal-advanced" target="_blank" hidden>' + esc(__('→ WordPress editor', 'lrob-calendar')) + '</a>' +
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

        ed = createEditor(overlay.querySelector('.lrob-f-desc'), '');

        overlay.querySelector('.lrob-modal-close').addEventListener('click', close);
        overlay.querySelector('.lrob-modal-cancel').addEventListener('click', close);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.lrob-modal-save').addEventListener('click', save);

        // Type radios toggle time/end visibility.
        overlay.querySelectorAll('input[name="lrob-type"]').forEach(function (r) {
            r.addEventListener('change', applyTypeVisibility);
        });

        bindImage();
    }

    function populate(d) {
        current.id = d.id || current.id;
        var q = function (s) { return overlay.querySelector(s); };

        q('.lrob-f-title').value = d.title || '';
        var dt = d.datetime || defaults().datetime;
        setRadio('lrob-type', dt.type || 'standard');
        q('.lrob-f-sd').value = dt.start_date || '';
        q('.lrob-f-st').value = dt.start_time || '';
        q('.lrob-f-ed').value = dt.end_date || '';
        q('.lrob-f-et').value = dt.end_time || '';
        if (dt.timezone) q('.lrob-f-tz').value = dt.timezone;
        q('.lrob-f-status').value = d.status && d.status !== 'auto-draft' ? d.status : 'draft';

        (d.categories || []).forEach(function (id) {
            var c = q('.lrob-f-cat[value="' + id + '"]'); if (c) c.checked = true;
        });
        (d.tags || []).forEach(function (id) {
            var c = q('.lrob-f-tag[value="' + id + '"]'); if (c) c.checked = true;
        });

        featuredId = d.featuredImage ? d.featuredImage.id : 0;
        renderImage(d.featuredImage ? d.featuredImage.url : '');

        // Rebuild the editor with the loaded content.
        ed = createEditor(q('.lrob-f-desc'), d.content || '');

        var adv = q('.lrob-modal-advanced');
        if (d.editLink) { adv.href = d.editLink; adv.hidden = false; } else { adv.hidden = true; }

        applyTypeVisibility();
        q('.lrob-f-title').focus();
    }

    function collect() {
        var q = function (s) { return overlay.querySelector(s); };
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
        var saveBtn = overlay.querySelector('.lrob-modal-save');
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
                close();
                if (typeof current.onSaved === 'function') current.onSaved();
            })
            .catch(function () {
                saveBtn.disabled = false;
                message(__('Could not save the event.', 'lrob-calendar'), true);
            });
    }

    /* ── Featured image (wp.media) ───────────────────────────────────────── */

    function bindImage() {
        var frame = null;
        overlay.querySelector('.lrob-f-image-set').addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.wp || !window.wp.media) return;
            if (!frame) {
                frame = window.wp.media({ title: __('Select image', 'lrob-calendar'), multiple: false });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    featuredId = att.id;
                    var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                    renderImage(url);
                });
            }
            frame.open();
        });
        overlay.querySelector('.lrob-f-image-remove').addEventListener('click', function (e) {
            e.preventDefault();
            featuredId = 0;
            renderImage('');
        });
    }

    function renderImage(url) {
        var prev = overlay.querySelector('.lrob-f-image-preview');
        var rm = overlay.querySelector('.lrob-f-image-remove');
        if (url) {
            prev.innerHTML = '<img src="' + esc(url) + '" alt="">';
            rm.hidden = false;
        } else {
            prev.innerHTML = '';
            rm.hidden = true;
        }
    }

    /* ── Small UI utils ──────────────────────────────────────────────────── */

    function applyTypeVisibility() {
        var type = (overlay.querySelector('input[name="lrob-type"]:checked') || {}).value || 'standard';
        var timed = (type === 'standard');
        overlay.querySelectorAll('.lrob-f-st, .lrob-f-et').forEach(function (i) { i.style.display = timed ? '' : 'none'; });
        overlay.querySelector('.lrob-f-end').style.display = (type === 'instant') ? 'none' : '';
    }

    function checkedValues(sel) {
        return Array.prototype.slice.call(overlay.querySelectorAll(sel + ':checked'))
            .map(function (c) { return parseInt(c.value, 10); });
    }
    function setRadio(name, value) {
        var r = overlay.querySelector('input[name="' + name + '"][value="' + value + '"]');
        if (r) r.checked = true;
    }
    function setTitle(t) { overlay.querySelector('.lrob-modal-title').textContent = t; }
    function setBusy(on) { overlay.querySelector('.lrob-modal').classList.toggle('is-busy', !!on); }
    function message(text, isError) {
        var m = overlay.querySelector('.lrob-modal-msg');
        m.textContent = text || '';
        m.classList.toggle('is-error', !!isError);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    function close() {
        if (!overlay) return;
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        overlay = null;
        ed = null;
        featuredId = 0;
        document.body.classList.remove('lrob-modal-open');
    }

    window.LrobEventModal = { open: open };
})(window.wp && window.wp.i18n);
