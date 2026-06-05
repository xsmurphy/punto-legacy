/**
 * companies.js — front estático del CRUD de empresas (realm /admin, F3.1).
 *
 * Habla solo con /bff/admin/companies.php. Lectura por ahora (list + drawer detalle).
 * Write ops (update/delete) vienen en F3.2/F3.3.
 *
 * Patrón heredado de users.js: esc() everywhere, redirect 401 → /admin/login.
 */
(function () {
    'use strict';

    var rowsEl    = document.getElementById('rows');
    var statsEl   = document.getElementById('stats');
    var searchEl  = document.getElementById('searchInput');
    var overlayEl = document.getElementById('overlay');
    var drawerEl  = document.getElementById('drawer');
    var drawerBodyEl  = document.getElementById('drawerBody');
    var drawerTitleEl = document.getElementById('drawerTitle');
    var toastEl   = document.getElementById('toast');

    var debounceTimer = null;
    var lastQuery = '';

    function redirectToLogin() { window.location.href = '/admin/login'; }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(msg) {
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        setTimeout(function () { toastEl.classList.remove('show'); }, 2500);
    }

    function fmtDate(s) {
        if (!s) { return '—'; }
        var d = new Date(s);
        if (isNaN(d.getTime())) { return '—'; }
        return d.toLocaleDateString('es', { year: '2-digit', month: 'short', day: 'numeric' });
    }

    function fmtDateTime(s) {
        if (!s) { return '—'; }
        var d = new Date(s);
        if (isNaN(d.getTime())) { return '—'; }
        return d.toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' });
    }

    function api(path) {
        return fetch(path, { credentials: 'same-origin' }).then(function (r) {
            if (r.status === 401) { redirectToLogin(); return Promise.reject('401'); }
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        });
    }

    function statusPill(status, blocked) {
        if (blocked) { return '<span class="pill blocked">Bloqueado</span>'; }
        if (status === 'Active')   { return '<span class="pill active">Activo</span>'; }
        if (status === 'Pending')  { return '<span class="pill pending">Pendiente</span>'; }
        if (status === 'Deactivate') { return '<span class="pill inactive">Inactivo</span>'; }
        return '<span class="pill inactive">' + esc(status || '—') + '</span>';
    }

    function planLabel(p) {
        if (p === null || p === undefined || p === '') { return '—'; }
        return esc(String(p));
    }

    function load(q) {
        var url = '/bff/admin/companies.php';
        if (q) { url += '?q=' + encodeURIComponent(q); }

        rowsEl.innerHTML = '<tr><td colspan="11" class="loading">Cargando…</td></tr>';

        api(url).then(function (res) {
            if (!res.body.ok) {
                rowsEl.innerHTML = '<tr><td colspan="11" class="empty">Error al cargar</td></tr>';
                return;
            }
            render(res.body.data);
        }).catch(function () {});
    }

    function render(data) {
        var rows = (data && data.rows) || [];
        var total = (data && data.total) != null ? data.total : rows.length;

        statsEl.textContent = rows.length + ' de ' + total + ' empresa' + (total === 1 ? '' : 's');

        if (!rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="11" class="empty">Sin resultados</td></tr>';
            return;
        }

        rowsEl.innerHTML = rows.map(function (c) {
            var epos = (+c.epos) ? '<span class="pill epos">Activado</span>' : '<span class="pill inactive">—</span>';
            var owner = (c.owner && c.owner.name) || '—';
            var phone = (c.owner && c.owner.phone) ? ' · ' + esc(c.owner.phone) : '';
            var email = (c.owner && c.owner.email) || '—';
            var outlets = +((c.counts && c.counts.outlets) || 0);
            var registers = +((c.counts && c.counts.registers) || 0);
            return '<tr data-id="' + esc(c.id) + '">' +
                '<td>' + esc(c.name || c.companyName || '—') + '</td>' +
                '<td>' + esc(owner) + phone + '</td>' +
                '<td>' + esc(email) + '</td>' +
                '<td>' + esc(c.country || '—') + '</td>' +
                '<td>' + statusPill(c.status, c.blocked) + '</td>' +
                '<td>' + planLabel(c.plan) + '</td>' +
                '<td class="num">' + outlets + '</td>' +
                '<td class="num">' + registers + '</td>' +
                '<td>' + epos + '</td>' +
                '<td>' + fmtDate(c.createdAt) + '</td>' +
                '<td>' + fmtDate(c.customersLastUpdate) + '</td>' +
                '</tr>';
        }).join('');
    }

    var lastFocused = null;

    function openDrawer(id) {
        lastFocused = document.activeElement;
        drawerEl.classList.add('open');
        overlayEl.classList.add('open');
        drawerEl.setAttribute('aria-hidden', 'false');
        drawerTitleEl.textContent = 'Detalle';
        drawerBodyEl.innerHTML = '<p class="loading">Cargando…</p>';
        // Focus al botón de cierre para que Esc/Tab funcione desde el drawer.
        var closeBtn = document.getElementById('closeDrawer');
        if (closeBtn) { closeBtn.focus(); }

        api('/bff/admin/companies.php?id=' + encodeURIComponent(id)).then(function (res) {
            if (!res.body.ok) {
                drawerBodyEl.innerHTML = '<p class="empty">Empresa no encontrada</p>';
                return;
            }
            renderDetail(res.body.data);
        }).catch(function () {});
    }

    function closeDrawer() {
        drawerEl.classList.remove('open');
        overlayEl.classList.remove('open');
        drawerEl.setAttribute('aria-hidden', 'true');
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
            lastFocused = null;
        }
    }

    function renderDetail(c) {
        drawerTitleEl.textContent = c.settingName || c.name || 'Detalle';

        var owner = c.owner || {};
        var counts = c.counts || {};
        var moduleData = c.moduleData || {};
        var eposData = c.eposData || {};

        var moduleEntries = '';
        if (moduleData && typeof moduleData === 'object') {
            Object.keys(moduleData).forEach(function (k) {
                var v = moduleData[k];
                if (v && typeof v === 'object') {
                    v = JSON.stringify(v);
                }
                moduleEntries +=
                    '<dt>' + esc(k) + '</dt>' +
                    '<dd>' + esc(v) + '</dd>';
            });
        }

        var eposEntries = '';
        if (eposData && typeof eposData === 'object') {
            Object.keys(eposData).forEach(function (k) {
                var v = eposData[k];
                if (v && typeof v === 'object') {
                    v = JSON.stringify(v);
                }
                eposEntries +=
                    '<dt>' + esc(k) + '</dt>' +
                    '<dd>' + esc(v) + '</dd>';
            });
        }

        drawerBodyEl.innerHTML =
            '<dl class="kv">' +
                '<dt>ID</dt><dd><code>' + esc(c.id) + '</code></dd>' +
                '<dt>Nombre</dt><dd>' + esc(c.settingName || c.name) + '</dd>' +
                '<dt>Slug</dt><dd>' + esc(c.slug) + '</dd>' +
                '<dt>Estado</dt><dd>' + statusPill(c.status, c.blocked) + '</dd>' +
                '<dt>Plan</dt><dd>' + planLabel(c.plan) + '</dd>' +
                '<dt>Descuento</dt><dd>' + esc(c.discount || '—') + '</dd>' +
                '<dt>SMS credit</dt><dd>' + esc(c.smsCredit || '—') + '</dd>' +
                '<dt>País</dt><dd>' + esc(c.country) + '</dd>' +
                '<dt>ID externo</dt><dd>' + esc(c.externalCustomerId || '—') + '</dd>' +
                '<dt>Bloqueada</dt><dd>' + (c.blocked ? 'Sí' : 'No') + '</dd>' +
                '<dt>Plan expirado</dt><dd>' + (c.planExpired ? 'Sí' : 'No') + '</dd>' +
                '<dt>Creada</dt><dd>' + fmtDateTime(c.createdAt) + '</dd>' +
                '<dt>Último uso</dt><dd>' + fmtDateTime(c.customersLastUpdate) + '</dd>' +
            '</dl>' +

            '<div class="section-title">Propietario</div>' +
            '<dl class="kv">' +
                '<dt>Nombre</dt><dd>' + esc((owner.name || '') + ' ' + (owner.secondName || '')) + '</dd>' +
                '<dt>Email</dt><dd>' + esc(owner.email || '—') + '</dd>' +
                '<dt>Teléfono</dt><dd>' + esc(owner.phone || '—') + '</dd>' +
            '</dl>' +

            '<div class="section-title">Volumen</div>' +
            '<dl class="kv">' +
                '<dt>Sucursales</dt><dd>' + (counts.outlets || 0) + '</dd>' +
                '<dt>Cajas</dt><dd>' + (counts.registers || 0) + '</dd>' +
                '<dt>Usuarios</dt><dd>' + (counts.users || 0) + '</dd>' +
                '<dt>Clientes</dt><dd>' + (counts.customers || 0) + '</dd>' +
            '</dl>' +

            (moduleEntries
                ? '<div class="section-title">Módulos (moduleData)</div><dl class="kv">' + moduleEntries + '</dl>'
                : '') +

            (eposEntries
                ? '<div class="section-title">ePOS (eposData)</div><dl class="kv">' + eposEntries + '</dl>'
                : '');
    }

    // --- listeners ---------------------------------------------------------

    rowsEl.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-id]');
        if (tr) { openDrawer(tr.getAttribute('data-id')); }
    });

    overlayEl.addEventListener('click', closeDrawer);
    document.getElementById('closeDrawer').addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawerEl.classList.contains('open')) { closeDrawer(); }
    });

    searchEl.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            var q = searchEl.value.trim();
            if (q === lastQuery) { return; }
            lastQuery = q;
            load(q);
        }, 250);
    });

    document.getElementById('logoutBtn').addEventListener('click', function (e) {
        e.preventDefault();
        fetch('/bff/admin/logout.php', { method: 'POST', credentials: 'same-origin' })
            .then(function () { redirectToLogin(); });
    });

    load('');
})();
