/**
 * Categories & tags manager (v1.2) — a panel mounted in the Manage Events
 * screen's side column (no modal, no button) so authors never need the stock
 * WordPress taxonomy screens. Lists, creates, renames, recolors (categories)
 * and deletes terms over the admin REST endpoints.
 */
(function (i18n) {
    var __ = (i18n && i18n.__) ? i18n.__ : function (s) { return s; };
    function cfg() { return window.lrobCalendarManage || {}; }
    function termsUrl() { return (cfg().restRoot || '').replace(/\/events$/, '/terms'); }

    var root = null;
    var onChangeCb = null;
    var data = { category: [], tag: [] };

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
    function q(s) { return root.querySelector(s); }

    function req(method, path, body) {
        var headers = { 'X-WP-Nonce': cfg().nonce };
        if (body) headers['Content-Type'] = 'application/json';
        return fetch(termsUrl() + path, {
            method: method, headers: headers, credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
    }

    function mount(container, onChange) {
        root = container;
        onChangeCb = onChange;
        root.innerHTML =
            '<h2 class="lrob-side-title">' + esc(__('Categories & tags', 'lrob-calendar')) + '</h2>' +
            sectionHtml('category', __('Categories', 'lrob-calendar')) +
            sectionHtml('tag', __('Tags', 'lrob-calendar')) +
            '<p class="lrob-side-msg" aria-live="polite"></p>';

        root.querySelectorAll('.lrob-terms-create').forEach(function (b) {
            b.addEventListener('click', function () { createTerm(this.closest('.lrob-terms-section').getAttribute('data-tax')); });
        });
        // Enter-to-add in the name field.
        root.querySelectorAll('.lrob-terms-newname').forEach(function (inp) {
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); createTerm(this.closest('.lrob-terms-section').getAttribute('data-tax')); }
            });
        });
        root.addEventListener('click', onListClick);

        loadAll();
    }

    function changed() { if (typeof onChangeCb === 'function') onChangeCb(); }

    function sectionHtml(tax, title) {
        var isCat = (tax === 'category');
        return '<section class="lrob-terms-section" data-tax="' + tax + '">' +
            '<h3 class="lrob-side-section">' + esc(title) + '</h3>' +
            '<ul class="lrob-terms-list"></ul>' +
            '<div class="lrob-terms-add">' +
                (isCat ? '<input type="color" class="lrob-terms-newcolor" value="#3a87ad">' : '') +
                '<input type="text" class="lrob-terms-newname" placeholder="' + esc(isCat ? __('New category', 'lrob-calendar') : __('New tag', 'lrob-calendar')) + '">' +
                (isCat ? '<select class="lrob-terms-newparent"></select>' : '') +
                '<button type="button" class="button lrob-terms-create">' + esc(__('Add', 'lrob-calendar')) + '</button>' +
            '</div>' +
        '</section>';
    }

    /* ── Load + render ───────────────────────────────────────────────────── */

    function loadAll() {
        message(__('Loading…', 'lrob-calendar'));
        Promise.all([
            req('GET', '?taxonomy=category'),
            req('GET', '?taxonomy=tag')
        ]).then(function (res) {
            data.category = Array.isArray(res[0]) ? res[0] : [];
            data.tag = Array.isArray(res[1]) ? res[1] : [];
            message('');
            renderSection('category');
            renderSection('tag');
        }).catch(function () { message(__('Could not load terms.', 'lrob-calendar'), true); });
    }

    function renderSection(tax) {
        var ul = q('.lrob-terms-section[data-tax="' + tax + '"] .lrob-terms-list');
        var terms = data[tax];
        var html = '';
        if (tax === 'category') {
            var byParent = {};
            terms.forEach(function (t) { (byParent[t.parent] = byParent[t.parent] || []).push(t); });
            (function walk(parent, depth) {
                (byParent[parent] || []).forEach(function (t) { html += rowHtml(tax, t, depth); walk(t.id, depth + 1); });
            })(0, 0);
            refreshParentSelect();
        } else {
            html = terms.map(function (t) { return rowHtml(tax, t, 0); }).join('');
        }
        ul.innerHTML = html || '<li class="lrob-terms-empty">' + esc(__('None yet.', 'lrob-calendar')) + '</li>';
    }

    function rowHtml(tax, t, depth) {
        var isCat = (tax === 'category');
        return '<li class="lrob-terms-row" data-id="' + esc(t.id) + '" data-tax="' + tax + '" style="padding-left:' + (8 + depth * 16) + 'px">' +
            (isCat ? '<span class="lrob-terms-color" style="background:' + esc(t.color || '#cccccc') + '"></span>' : '') +
            '<span class="lrob-terms-name">' + esc(t.name) + '</span>' +
            '<span class="lrob-terms-count">(' + esc(t.count) + ')</span>' +
            '<span class="lrob-terms-row-actions">' +
                iconBtn('edit', 'edit', __('Edit', 'lrob-calendar')) +
                iconBtn('del', 'trash', __('Delete', 'lrob-calendar')) +
            '</span>' +
        '</li>';
    }

    function iconBtn(act, icon, label) {
        return '<button type="button" class="button button-small lrob-terms-iconbtn" data-act="' + act + '" ' +
            'title="' + esc(label) + '" aria-label="' + esc(label) + '"><span class="dashicons dashicons-' + icon + '"></span></button>';
    }

    function refreshParentSelect() {
        var sel = q('.lrob-terms-section[data-tax="category"] .lrob-terms-newparent');
        if (!sel) return;
        var current = sel.value;
        sel.innerHTML = '<option value="0">' + esc(__('— No parent —', 'lrob-calendar')) + '</option>' +
            data.category.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>'; }).join('');
        sel.value = current || '0';
    }

    /* ── Actions ─────────────────────────────────────────────────────────── */

    function createTerm(tax) {
        var section = q('.lrob-terms-section[data-tax="' + tax + '"]');
        var name = section.querySelector('.lrob-terms-newname').value.trim();
        if (!name) { section.querySelector('.lrob-terms-newname').focus(); return; }
        var body = { taxonomy: tax, name: name };
        if (tax === 'category') {
            body.parent = parseInt(section.querySelector('.lrob-terms-newparent').value, 10) || 0;
            body.color = section.querySelector('.lrob-terms-newcolor').value;
        }
        message(__('Saving…', 'lrob-calendar'));
        req('POST', '', body).then(function () {
            section.querySelector('.lrob-terms-newname').value = '';
            loadAll();
            changed();
        }).catch(function () { message(__('Could not save.', 'lrob-calendar'), true); });
    }

    function onListClick(e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var row = btn.closest('.lrob-terms-row');
        if (!row) return;
        var id = row.getAttribute('data-id');
        var tax = row.getAttribute('data-tax');
        var act = btn.getAttribute('data-act');

        if (act === 'del') {
            if (!window.confirm(__('Delete this term? Events keep their other terms.', 'lrob-calendar'))) return;
            message(__('Deleting…', 'lrob-calendar'));
            req('DELETE', '/' + id).then(function () { loadAll(); changed(); }).catch(function () { message(__('Could not delete.', 'lrob-calendar'), true); });
        } else if (act === 'edit') {
            editRow(row, tax, id);
        } else if (act === 'save') {
            saveRow(row, tax, id);
        } else if (act === 'cancel') {
            renderSection(tax);
        }
    }

    function editRow(row, tax, id) {
        var term = data[tax].filter(function (t) { return String(t.id) === String(id); })[0];
        if (!term) return;
        var isCat = (tax === 'category');
        row.innerHTML =
            (isCat ? '<input type="color" class="lrob-terms-editcolor" value="' + esc(term.color || '#3a87ad') + '">' : '') +
            '<input type="text" class="lrob-terms-editname" value="' + esc(term.name) + '">' +
            '<span class="lrob-terms-row-actions">' +
                iconBtn('save', 'yes', __('Save', 'lrob-calendar')) +
                iconBtn('cancel', 'no-alt', __('Cancel', 'lrob-calendar')) +
            '</span>';
        var nameInput = row.querySelector('.lrob-terms-editname');
        nameInput.focus();
        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); saveRow(row, tax, id); }
            else if (e.key === 'Escape') { renderSection(tax); }
        });
    }

    function saveRow(row, tax, id) {
        var body = { name: row.querySelector('.lrob-terms-editname').value.trim() };
        if (tax === 'category') {
            var c = row.querySelector('.lrob-terms-editcolor');
            if (c) body.color = c.value;
        }
        if (!body.name) { row.querySelector('.lrob-terms-editname').focus(); return; }
        message(__('Saving…', 'lrob-calendar'));
        req('PUT', '/' + id, body).then(function () { loadAll(); changed(); }).catch(function () { message(__('Could not save.', 'lrob-calendar'), true); });
    }

    /* ── Utils ───────────────────────────────────────────────────────────── */

    function message(text, isError) {
        var m = q('.lrob-side-msg');
        if (!m) return;
        m.textContent = text || '';
        m.classList.toggle('is-error', !!isError);
    }

    window.LrobTermsManager = { mount: mount };
})(window.wp && window.wp.i18n);
