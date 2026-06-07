/**
 * companies.js — front estático del CRUD de empresas (realm /admin, F3.1-F3.5).
 *
 * Habla solo con /bff/admin/companies.php.
 * F3.1: list + drawer detalle.
 * F3.2: PATCH — edición de campos de la empresa (incluye plan, balance).
 * F3.3: DELETE — suspensión suave (soft) y eliminación permanente (hard).
 * F3.4: Billing — selector de plan, balance, historial de cpayments.
 * F3.5: Ingresar como empresa — genera _jwt_panel, abre panel en nueva pestaña.
 *
 * Patrón heredado de users.js: esc() everywhere, redirect 401/403 → /admin/login.
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
    var currentCompany = null; // empresa cargada en el drawer (detail o edit)

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
        var closeBtn = document.getElementById('closeDrawer');
        if (closeBtn) { closeBtn.focus(); }

        api('/bff/admin/companies.php?id=' + encodeURIComponent(id)).then(function (res) {
            if (!res.body.ok) {
                drawerBodyEl.innerHTML = '<p class="empty">Empresa no encontrada</p>';
                return;
            }
            currentCompany = res.body.data;
            renderDetail(currentCompany);
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

    /* ── Edit mode (F3.2) ──────────────────────────────────────────────── */

    function renderEdit(c) {
        drawerTitleEl.textContent = 'Editar empresa';

        var expiresVal = '';
        if (c.expiresAt) {
            try { expiresVal = new Date(c.expiresAt).toISOString().slice(0, 16); } catch (e) {}
        }

        function buildForm(planOptions) {
            var planSelect = '<select id="ef_plan" name="plan">' +
                planOptions.map(function (p) {
                    var sel = (p.code === c.plan) ? ' selected' : '';
                    return '<option value="' + esc(p.code) + '"' + sel + '>' +
                           esc(p.name) + ' — $' + esc((+p.price || 0).toFixed(2)) + '</option>';
                }).join('') +
                '</select>';

            drawerBodyEl.innerHTML =
                '<form class="edit-form" id="editForm" novalidate>' +
                '<div class="form-group">' +
                    '<label for="ef_name">Nombre de la empresa</label>' +
                    '<input id="ef_name" name="settingName" type="text" value="' + esc(c.settingName || c.name) + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_country">País</label>' +
                    '<input id="ef_country" name="settingCountry" type="text" maxlength="10" value="' + esc(c.country || '') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_status">Estado</label>' +
                    '<select id="ef_status" name="status">' +
                        ['active', 'suspended', 'cancelled'].map(function (v) {
                            return '<option value="' + v + '"' + (c.status === v ? ' selected' : '') + '>' + v + '</option>';
                        }).join('') +
                    '</select>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_plan">Plan</label>' +
                    (planOptions.length ? planSelect : '<input id="ef_plan" name="plan" type="number" min="0" step="1" value="' + esc(c.plan != null ? c.plan : '') + '">') +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_balance">Balance ($)</label>' +
                    '<input id="ef_balance" name="balance" type="number" step="0.01" value="' + esc((+c.balance || 0).toFixed(2)) + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_sms">Crédito SMS</label>' +
                    '<input id="ef_sms" name="smsCredit" type="number" min="0" step="1" value="' + esc(c.smsCredit != null ? c.smsCredit : 0) + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_discount">Descuento (%)</label>' +
                    '<input id="ef_discount" name="discount" type="number" min="0" step="0.01" value="' + esc(c.discount != null ? c.discount : 0) + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label for="ef_expires">Vencimiento (opcional)</label>' +
                    '<input id="ef_expires" name="expiresAt" type="datetime-local" value="' + esc(expiresVal) + '">' +
                '</div>' +
                '<div class="form-group check-row">' +
                    '<input id="ef_blocked" name="blocked" type="checkbox"' + (c.blocked ? ' checked' : '') + '>' +
                    '<label for="ef_blocked">Bloqueada</label>' +
                '</div>' +
                '<div class="form-group check-row">' +
                    '<input id="ef_planExpired" name="planExpired" type="checkbox"' + (c.planExpired ? ' checked' : '') + '>' +
                    '<label for="ef_planExpired">Plan expirado</label>' +
                '</div>' +
                '<div class="form-group check-row">' +
                    '<input id="ef_isTrial" name="isTrial" type="checkbox"' + (c.isTrial ? ' checked' : '') + '>' +
                    '<label for="ef_isTrial">Es trial</label>' +
                '</div>' +
                '<div class="form-actions">' +
                    '<button type="submit" class="btn-primary" id="saveEditBtn">Guardar</button>' +
                    '<button type="button" class="btn-secondary" id="cancelEditBtn">Cancelar</button>' +
                '</div>' +
                '</form>';

            document.getElementById('cancelEditBtn').addEventListener('click', function () {
                renderDetail(currentCompany);
            });
            document.getElementById('editForm').addEventListener('submit', function (e) {
                e.preventDefault();
                saveCompany(c.id, this);
            });
        }

        // Cargar planes del servidor para el selector; si falla, fallback a input numérico.
        drawerBodyEl.innerHTML = '<p class="loading">Cargando…</p>';
        api('/bff/admin/companies.php?plans=1').then(function (res) {
            buildForm((res.body.ok && Array.isArray(res.body.data)) ? res.body.data : []);
        }).catch(function () {
            buildForm([]);
        });
    }

    function saveCompany(id, form) {
        var saveBtn = document.getElementById('saveEditBtn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Guardando…';

        var payload = {
            settingName:  form.settingName.value.trim(),
            settingCountry: form.settingCountry.value.trim(),
            status:       form.status.value,
            plan:         parseInt(form.plan.value, 10) || 0,
            balance:      parseFloat(form.balance.value) || 0,
            smsCredit:    parseInt(form.smsCredit.value, 10) || 0,
            discount:     parseFloat(form.discount.value) || 0,
            expiresAt:    form.expiresAt.value || null,
            blocked:      form.blocked.checked ? 1 : 0,
            planExpired:  form.planExpired.checked,
            isTrial:      form.isTrial.checked,
        };

        fetch('/bff/admin/companies.php?id=' + encodeURIComponent(id), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (r) {
            if (r.status === 401 || r.status === 403) { redirectToLogin(); return Promise.reject('auth'); }
            return r.json();
        }).then(function (j) {
            if (!j.ok) {
                toast('Error: ' + esc(j.error || 'no se pudo guardar'));
                saveBtn.disabled = false;
                saveBtn.textContent = 'Guardar';
                return;
            }
            toast('Empresa actualizada');
            openDrawer(id);   // recarga detalle fresco
            load(lastQuery);  // actualiza la tabla
        }).catch(function () {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Guardar';
        });
    }

    /* ── Billing (F3.4) ────────────────────────────────────────────────── */

    function renderBilling(id) {
        drawerTitleEl.textContent = 'Facturación';
        drawerBodyEl.innerHTML = '<p class="loading">Cargando…</p>';

        api('/bff/admin/companies.php?id=' + encodeURIComponent(id) + '&billing=1').then(function (res) {
            if (!res.body.ok) {
                drawerBodyEl.innerHTML = '<p class="empty">Error al cargar facturación</p>';
                return;
            }
            var b = res.body.data;
            var payments = b.payments || [];

            var rows = payments.length
                ? payments.map(function (p) {
                    var statusLabel = p.status === 1 ? '<span class="pill active">Pagado</span>'
                        : p.status === 2 ? '<span class="pill blocked">Reembolsado</span>'
                        : '<span class="pill pending">Pendiente</span>';
                    var date = p.date ? new Date(p.date).toLocaleDateString('es', { year: '2-digit', month: 'short', day: 'numeric' }) : '—';
                    return '<tr>' +
                        '<td>' + esc(date) + '</td>' +
                        '<td class="num">$' + esc((+p.amount || 0).toFixed(2)) + '</td>' +
                        '<td class="num">' + esc(p.order || '—') + '</td>' +
                        '<td class="num">' + esc(p.invoice || '—') + '</td>' +
                        '<td>' + statusLabel + '</td>' +
                        '</tr>';
                }).join('')
                : '<tr><td colspan="5" class="empty" style="text-align:center;padding:16px">Sin registros</td></tr>';

            drawerBodyEl.innerHTML =
                '<div class="drawer-toolbar">' +
                    '<button class="btn-secondary" id="backToDetailBtn" style="padding:5px 12px;font-size:12px">← Volver</button>' +
                '</div>' +
                '<div class="billing-stat">' +
                    '<div class="billing-stat-item">' +
                        '<span class="label">Balance</span>' +
                        '<span class="value">$' + esc((+b.balance || 0).toFixed(2)) + '</span>' +
                    '</div>' +
                    '<div class="billing-stat-item">' +
                        '<span class="label">Plan</span>' +
                        '<span class="value">' + esc(b.planName || '—') + '</span>' +
                        '<span class="sub">$' + esc((+b.planPrice || 0).toFixed(2)) + ' / mes</span>' +
                    '</div>' +
                '</div>' +
                '<div class="section-title">Historial de pagos</div>' +
                '<table class="billing-table">' +
                    '<thead><tr>' +
                        '<th>Fecha</th><th class="num">Monto</th>' +
                        '<th class="num">Orden</th><th class="num">Factura</th><th>Estado</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>';

            document.getElementById('backToDetailBtn').addEventListener('click', function () {
                renderDetail(currentCompany);
            });
        }).catch(function () {
            drawerBodyEl.innerHTML = '<p class="empty">Error de red</p>';
        });
    }

    /* ── Delete (F3.3) ─────────────────────────────────────────────────── */

    function renderDeleteConfirm(c) {
        var name = (c.settingName || c.name || '').trim();
        drawerTitleEl.textContent = 'Eliminar empresa';

        drawerBodyEl.innerHTML =
            '<div class="danger-notice">' +
                '<strong>⚠ Acción irreversible</strong>' +
                '<p>Se eliminarán permanentemente todos los datos de esta empresa: ' +
                'transacciones, contactos, ítems, inventario, sucursales, usuarios y configuración. ' +
                'No es posible recuperar los datos.</p>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Escribe <strong>' + esc(name) + '</strong> para confirmar</label>' +
                '<input id="deleteConfirmInput" type="text" autocomplete="off" ' +
                    'placeholder="' + esc(name) + '" style="margin-top:6px">' +
            '</div>' +
            '<div class="form-actions" style="margin-top:14px">' +
                '<button class="btn-danger-primary" id="confirmDeleteBtn" disabled>Eliminar permanentemente</button>' +
                '<button class="btn-secondary" id="cancelDeleteBtn">Cancelar</button>' +
            '</div>';

        var input      = document.getElementById('deleteConfirmInput');
        var confirmBtn = document.getElementById('confirmDeleteBtn');

        input.addEventListener('input', function () {
            confirmBtn.disabled = (input.value !== name);
        });
        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Eliminando…';
            doDeleteCompany(c.id, 'hard', name);
        });
        document.getElementById('cancelDeleteBtn').addEventListener('click', function () {
            renderDetail(currentCompany);
        });
    }

    function doDeleteCompany(id, type, confirmName) {
        var url = '/bff/admin/companies.php?id=' + encodeURIComponent(id) +
                  '&type=' + encodeURIComponent(type);
        var body = (type === 'hard')
            ? JSON.stringify({ confirm: confirmName })
            : '{}';

        fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: body,
        }).then(function (r) {
            if (r.status === 401 || r.status === 403) { redirectToLogin(); return Promise.reject('auth'); }
            return r.json();
        }).then(function (j) {
            if (!j.ok) {
                toast('Error: ' + esc(j.error || 'no se pudo completar'));
                // Para soft: volver al detalle; para hard: volver al form de confirmación (ya está ahí)
                if (type === 'soft') { openDrawer(id); }
                return;
            }
            if (type === 'hard') {
                toast('Empresa eliminada permanentemente');
                closeDrawer();
            } else {
                toast('Empresa suspendida');
                openDrawer(id);   // recarga detalle con nuevo status
            }
            load(lastQuery);
        }).catch(function () {
            toast('Error de red al intentar eliminar');
        });
    }

    /* ── Detail mode ───────────────────────────────────────────────────── */

    function renderDetail(c) {
        currentCompany = c;
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

        var planDisplay = (c.planName ? esc(c.planName) + ' <span class="pill inactive">' + esc(c.plan) + '</span>' : planLabel(c.plan));

        drawerBodyEl.innerHTML =
            '<div class="drawer-toolbar">' +
                '<button class="btn-link" id="enterBtn">Ingresar</button>' +
                '<button class="btn-link" id="billingBtn">Facturación</button>' +
                '<button class="btn-link" id="editBtn">Editar</button>' +
            '</div>' +
            '<dl class="kv">' +
                '<dt>ID</dt><dd><code>' + esc(c.id) + '</code></dd>' +
                '<dt>Nombre</dt><dd>' + esc(c.settingName || c.name) + '</dd>' +
                '<dt>Slug</dt><dd>' + esc(c.slug) + '</dd>' +
                '<dt>Estado</dt><dd>' + statusPill(c.status, c.blocked) + '</dd>' +
                '<dt>Plan</dt><dd>' + planDisplay + '</dd>' +
                '<dt>Balance</dt><dd>$' + esc((+c.balance || 0).toFixed(2)) + '</dd>' +
                '<dt>Descuento</dt><dd>' + esc(c.discount != null ? c.discount + '%' : '—') + '</dd>' +
                '<dt>SMS credit</dt><dd>' + esc(c.smsCredit != null ? c.smsCredit : '—') + '</dd>' +
                '<dt>País</dt><dd>' + esc(c.country || '—') + '</dd>' +
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
                : '') +

            '<div class="section-title">Zona de peligro</div>' +
            '<div class="danger-zone">' +
                '<div class="danger-item">' +
                    '<div class="danger-info">' +
                        '<strong>Suspender empresa</strong>' +
                        '<span>Cambia estado a "cancelled" y bloquea el acceso. Reversible desde Editar.</span>' +
                    '</div>' +
                    '<button class="btn-warn" id="suspendBtn">Suspender</button>' +
                '</div>' +
                '<div class="danger-item">' +
                    '<div class="danger-info">' +
                        '<strong>Eliminar permanentemente</strong>' +
                        '<span>Borra todos los datos de la empresa. Irreversible.</span>' +
                    '</div>' +
                    '<button class="btn-danger" id="hardDeleteBtn">Eliminar</button>' +
                '</div>' +
            '</div>';

        document.getElementById('enterBtn').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Ingresando…';
            fetch('/bff/admin/companies.php?id=' + encodeURIComponent(c.id) + '&action=enter', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            }).then(function (r) {
                if (r.status === 401 || r.status === 403) { redirectToLogin(); return Promise.reject('auth'); }
                return r.json();
            }).then(function (j) {
                btn.disabled = false;
                btn.textContent = 'Ingresar';
                if (!j.ok) {
                    toast('Error: ' + esc(j.error || 'no se pudo ingresar'));
                    return;
                }
                // Abre el panel de la empresa en nueva pestaña (el _jwt_panel ya fue
                // inyectado en la cookie por el BFF). El admin sigue en /admin.
                window.open(j.redirectUrl || '/@#dashboard', '_blank', 'noopener');
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Ingresar';
            });
        });

        document.getElementById('billingBtn').addEventListener('click', function () {
            renderBilling(c.id);
        });

        document.getElementById('editBtn').addEventListener('click', function () {
            renderEdit(currentCompany);
        });

        document.getElementById('suspendBtn').addEventListener('click', function () {
            var name = (c.settingName || c.name || c.id).trim();
            if (!confirm('¿Suspender "' + name + '"?\n\nEsto cambia el estado a "cancelled" y bloquea el acceso.\nSe puede revertir desde Editar.')) {
                return;
            }
            doDeleteCompany(c.id, 'soft', null);
        });

        document.getElementById('hardDeleteBtn').addEventListener('click', function () {
            renderDeleteConfirm(c);
        });
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
