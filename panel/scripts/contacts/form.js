/**
 * contactFormV2 — editform de contactos (rol customer) con templates Mustache +
 * hidratación JSON desde la API canónica /API/v1/contacts.
 *
 * Espejo de scripts/items/form.js, con dos diferencias:
 *   - los datos salen de window.contactsApi.get(id) (no de ?action=editform)
 *   - el submit persiste vía contactsApi.create/update (no por POST al handler legacy)
 *
 * Uso:
 *   contactFormV2.open('<contactId>', { onSaved: fn, onError: fn });   // editar
 *   contactFormV2.open(null,          { onSaved: fn, onError: fn });   // crear
 *
 * onSaved(savedContact, isNew) se invoca tras un guardado OK (para refrescar el listado).
 * onError(err) permite caer al form legacy si el v2 falla (fallback).
 *
 * Reusa Mustache (global), window.tin_name / window.companyId y los helpers
 * globales spinner() / message() / ncmDialogs de a_contacts.php.
 */
(function (window) {
	'use strict';

	var TPL_BASE = '/contacts/templates/';
	var cache = {};

	function loadTpl(name) {
		if (cache[name]) return Promise.resolve(cache[name]);
		// cache-bust en dev para no servir templates viejos del cache del browser
		return fetch(TPL_BASE + name + '.html?v=' + Date.now(), { credentials: 'include' })
			.then(function (r) { return r.text(); })
			.then(function (t) { cache[name] = t; return t; });
	}

	// El JSON trae contactStatus como int (smallint PG). Aceptar varias formas.
	function truthy(v) {
		return v === true || v === 1 || v === '1' || parseInt(v, 10) > 0;
	}

	function buildViewModel(c) {
		c = c || {};
		var isNew  = !c.id;
		var id     = c.id || '';
		var active = isNew ? true : truthy(c.status);

		return {
			isNew:     isNew,
			id:        id,
			name:      c.name || '',
			fullname:  c.fullname || '',
			tin:       c.tin || '',
			ci:        c.ci || '',
			bday:      c.bday || '',
			phone:     c.phone || '',
			phone2:    c.phone2 || '',
			email:     c.email || '',
			note:      c.note || '',
			address:   c.address || '',
			address2:  c.address2 || '',
			location:  c.location || '',
			city:      c.city || '',
			active:    active,
			saveLabel: active ? 'Guardar' : 'Re Activar',
			tinName:   window.tin_name || 'RUC',
			reportTransactionsUrl: '/@#report_transactions?ci=' + encodeURIComponent(id),
			reportProductsUrl:     '/@#report_products?ci=' + encodeURIComponent(id),
		};
	}

	// Junta los campos del form en el shape público que espera contactsApi.
	function collect($form) {
		var out = {};
		$form.find('input, textarea, select').each(function () {
			var $el  = $(this);
			var name = $el.attr('name');
			if (!name || name === 'id') return;
			if ($el.attr('type') === 'checkbox') {
				out[name] = $el.is(':checked') ? 1 : 0;
				return;
			}
			out[name] = $el.val();
		});
		return out;
	}

	function bindSubmit(modalSel, id, onSaved) {
		var $form = $(modalSel + ' #contactFormV2');

		$form.off('submit.cv2').on('submit.cv2', function (e) {
			e.preventDefault();

			var payload = collect($form);
			if (!payload.fiscalName && !payload.name) {
				if (window.ncmDialogs) ncmDialogs.alert('La Razón Social o el Nombre son obligatorios');
				return;
			}

			if (window.spinner) spinner('body', 'show');
			var p = id ? window.contactsApi.update(id, payload) : window.contactsApi.create(payload);

			p.then(function (saved) {
				if (window.spinner) spinner('body', 'hide');
				$(modalSel).modal('hide');
				if (window.message) message(id ? 'Contacto actualizado' : 'Contacto creado', 'success');
				if (typeof onSaved === 'function') onSaved(saved, !id);
			}).catch(function (err) {
				if (window.spinner) spinner('body', 'hide');
				var msg = (err && err.message) || 'No se pudo guardar el contacto';
				if (window.ncmDialogs) ncmDialogs.alert(msg); else alert(msg);
			});
		});

		// Archivar (soft-delete) — solo en modo edición.
		$(modalSel + ' .contactFormV2Archive').off('click.cv2').on('click.cv2', function (e) {
			e.preventDefault();
			if (!id) return;
			var doArchive = function () {
				if (window.spinner) spinner('body', 'show');
				window.contactsApi.archive(id).then(function () {
					if (window.spinner) spinner('body', 'hide');
					$(modalSel).modal('hide');
					if (window.message) message('Contacto archivado', 'success');
					if (typeof onSaved === 'function') onSaved(null, false);
				}).catch(function (err) {
					if (window.spinner) spinner('body', 'hide');
					var msg = (err && err.message) || 'No se pudo archivar';
					if (window.ncmDialogs) ncmDialogs.alert(msg); else alert(msg);
				});
			};
			if (window.confirmation) confirmation('¿Archivar este contacto?', function (ok) { if (ok) doArchive(); });
			else doArchive();
		});
	}

	var contactFormV2 = {
		open: function (id, opts) {
			opts = opts || {};
			var modalSel = opts.modalSel || '#modalLarge';

			var loadData = id
				? window.contactsApi.get(id)
				: Promise.resolve({}); // modo crear: form vacío

			return loadData.then(function (c) {
				var vm = buildViewModel(c);
				return Promise.all([
					loadTpl('shell'),
					loadTpl('header'),
					loadTpl('basicTab'),
					loadTpl('addressTab'),
					loadTpl('notesTab'),
				]).then(function (tpls) {
					var html = Mustache.render(tpls[0], vm, {
						header:     tpls[1],
						basicTab:   tpls[2],
						addressTab: tpls[3],
						notesTab:   tpls[4],
					});

					$(modalSel + ' .modal-content').first().empty().html(html);
					$(modalSel + ' .modal-dialog').first().addClass('modal-lg');
					$(modalSel).modal('show');
					$(modalSel + ' .matchCols').matchHeight();

					bindSubmit(modalSel, id, opts.onSaved);
					return vm;
				});
			}).catch(function (e) {
				// Fallback: si el v2 falla (ej. contacto no es customer, o error de red),
				// dejamos que el caller abra el form legacy.
				if (typeof opts.onError === 'function') { opts.onError(e); return; }
				if (window.ncmDialogs) ncmDialogs.alert('contactFormV2: ' + (e.message || e));
				else console.error('contactFormV2', e);
			});
		},
	};

	window.contactFormV2 = contactFormV2;
})(window);
