(function () {
    'use strict';

    var rowsEl = document.getElementById('rows');
    var overlay = document.getElementById('overlay');
    var form = document.getElementById('adminForm');
    var modalTitle = document.getElementById('modalTitle');
    var modalError = document.getElementById('modalError');
    var passHint = document.getElementById('passHint');
    var saveBtn = document.getElementById('saveBtn');
    var toastEl = document.getElementById('toast');

    var fId = document.getElementById('f_id');
    var fName = document.getElementById('f_name');
    var fEmail = document.getElementById('f_email');
    var fPassword = document.getElementById('f_password');

    function redirectToLogin() {
        window.location.href = '/admin/login';
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function toast(msg) {
        toastEl.textContent = msg;
        toastEl.classList.add('is-show');
        setTimeout(function () { toastEl.classList.remove('is-show'); }, 2500);
    }

    function fmtDate(s) {
        if (!s) { return '—'; }
        var d = new Date(s);
        if (isNaN(d.getTime())) { return '—'; }
        return d.toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' });
    }

    function api(method, body) {
        var opts = { method: method, credentials: 'same-origin' };
        if (body) {
            opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
            opts.body = new URLSearchParams(body);
        }
        return fetch('/bff/admin/users.php', opts).then(function (r) {
            if (r.status === 401) { redirectToLogin(); return Promise.reject('401'); }
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        });
    }

    function load() {
        api('GET').then(function (res) {
            if (!res.body.ok) { rowsEl.innerHTML = '<tr><td colspan="5" class="ds-table__empty">Error al cargar</td></tr>'; return; }
            render(res.body.data.rows || []);
        }).catch(function () {});
    }

    function render(rows) {
        if (!rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="5" class="ds-table__empty">No hay administradores</td></tr>';
            return;
        }
        rowsEl.innerHTML = rows.map(function (a) {
            var pill = a.status === 1
                ? '<span class="ds-pill ds-pill--ok">Activo</span>'
                : '<span class="ds-pill ds-pill--muted">Inactivo</span>';
            var toggleLabel = a.status === 1 ? 'Desactivar' : 'Activar';
            var toggleClass = a.status === 1 ? 'ds-link-btn ds-link-btn--danger' : 'ds-link-btn';
            return '<tr>' +
                '<td>' + esc(a.name || '—') + '</td>' +
                '<td>' + esc(a.email) + '</td>' +
                '<td>' + pill + '</td>' +
                '<td>' + esc(fmtDate(a.lastLoginAt)) + '</td>' +
                '<td><div class="ds-row-actions">' +
                '<button class="ds-link-btn" data-edit="' + esc(a.id) + '">Editar</button>' +
                '<button class="' + toggleClass + '" data-toggle="' + esc(a.id) + '" data-next="' + (a.status === 1 ? 0 : 1) + '">' + toggleLabel + '</button>' +
                '</div></td>' +
                '</tr>';
        }).join('');
    }

    function openModal(admin) {
        modalError.textContent = '';
        if (admin) {
            modalTitle.textContent = 'Editar admin';
            fId.value = admin.id;
            fName.value = admin.name || '';
            fEmail.value = admin.email || '';
            fPassword.value = '';
            passHint.textContent = 'Dejá en blanco para mantener la contraseña actual.';
        } else {
            modalTitle.textContent = 'Nuevo admin';
            fId.value = '';
            fName.value = '';
            fEmail.value = '';
            fPassword.value = '';
            passHint.textContent = 'Mínimo 8 caracteres.';
        }
        overlay.classList.add('is-open');
        fName.focus();
    }

    function closeModal() {
        overlay.classList.remove('is-open');
    }

    // --- eventos ------------------------------------------------------------

    document.getElementById('newBtn').addEventListener('click', function () { openModal(null); });
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });

    document.getElementById('logoutBtn').addEventListener('click', function (e) {
        e.preventDefault();
        fetch('/bff/admin/logout.php', { method: 'POST', credentials: 'same-origin' })
            .then(redirectToLogin).catch(redirectToLogin);
    });

    rowsEl.addEventListener('click', function (e) {
        var editId = e.target.getAttribute('data-edit');
        var toggleId = e.target.getAttribute('data-toggle');
        if (editId) {
            fetch('/bff/admin/users.php?id=' + encodeURIComponent(editId), { credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 401) { redirectToLogin(); return null; }
                    return r.json();
                })
                .then(function (j) { if (j && j.ok) { openModal(j.data); } });
            return;
        }
        if (toggleId) {
            var next = parseInt(e.target.getAttribute('data-next'), 10);
            var verb = next === 1 ? 'activar' : 'desactivar';
            if (!confirm('¿Seguro que querés ' + verb + ' este admin?')) { return; }
            api('POST', { action: 'setStatus', id: toggleId, status: next }).then(function (res) {
                if (res.body.ok) { toast('Estado actualizado'); load(); }
                else { toast(res.body.error || 'No se pudo actualizar'); }
            }).catch(function () {});
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        modalError.textContent = '';
        saveBtn.disabled = true;

        var isEdit = fId.value !== '';
        var body = {
            action: isEdit ? 'update' : 'create',
            name: fName.value,
            email: fEmail.value,
            password: fPassword.value,
        };
        if (isEdit) { body.id = fId.value; }

        api('POST', body).then(function (res) {
            saveBtn.disabled = false;
            if (res.body.ok) {
                closeModal();
                toast(isEdit ? 'Admin actualizado' : 'Admin creado');
                load();
            } else {
                modalError.textContent = res.body.error || 'No se pudo guardar';
            }
        }).catch(function () { saveBtn.disabled = false; });
    });

    // --- init ---------------------------------------------------------------
    load();
})();
