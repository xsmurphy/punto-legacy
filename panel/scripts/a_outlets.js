/**
 * Front del módulo Sucursales (a_outlets) — BFF de 3 niveles + Alpine (1er CRUD del panel).
 *
 *   - config            ← GET /bff/bootstrap.php
 *   - lista              ← GET /bff/outlets.php           (ncmDataTables, jQuery dueño de la tabla)
 *   - editar (1 sucursal) ← GET /bff/outlets.php?id=<uuid> (hidrata el form Alpine x-model)
 *   - guardar            ← POST /bff/outlets.php (action=update)
 *
 * Crear (blank-insert que cascadea register+inventory) y eliminar (cascading) van al PHP legacy
 * vía /a_outlets?action=insert|delete; tras crear se abre el form Alpine con el id nuevo.
 * DIFERIDO (sin pérdida de dato — el update preserva esas claves del `data` JSONB): horarios
 * operativos (widget jQuery businessHours) y depósitos (adm() infra compartida). Ver §17/roadmap.
 *
 * §17.2: Alpine es dueño del estado/form; ncmDataTables y el plugin modal BS3 son jQuery (su DOM).
 * Init determinista §17: clon DETACHED + Alpine.initTree (1×) + mountUI() para lo que toca el DOM.
 */
(function () {

	var BFF       = '/bff/outlets.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY    = '/a_outlets';

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function blankEditing() {
		return {
			id: '', name: '', billingName: '', ruc: '', phone: '', email: '', whatsApp: '',
			address: '', latLng: '', description: '', purchaseOrderNo: '', taxId: '',
			ecom: false, taxIncluded: false, statusOn: true
		};
	}

	function outletsComponent() {
		return {
			cfg: { currency: '', tinName: 'RUC', taxName: 'IVA' },
			currentOutletId: '',
			outlets: [],
			availableTaxes: [],
			editing: blankEditing(),
			saving: false,

			// Alpine lo llama al iniciar (nodo DETACHED → nada que toque el documento acá).
			init: function () {},

			// Setup que requiere el nodo en el documento (red + ncmDataTables). Lo llama $(ready).
			mountUI: function () {
				var self = this;
				if (window.FastClick) { FastClick.attach(document.body); }
				ncmHelpers.load({
					url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) {
						if (res && res.ok && res.data) {
							self.cfg.currency = res.data.currency || '';
							self.cfg.tinName  = res.data.tinName || res.data.tin || 'RUC';
							self.cfg.taxName  = res.data.taxName || 'IVA';
							self.currentOutletId = res.data.outletId || '';
							window.currency          = self.cfg.currency;
							window.decimal           = res.data.decimal || 'no';
							window.thousandSeparator = res.data.thousand || 'dot';
							$('.bff-tin-name').text(self.cfg.tinName);
							$('.bff-tax-name').text(self.cfg.taxName);
						}
						self.loadList();
					}
				});
			},

			loadList: function () {
				var self = this;
				var xhr = ncmHelpers.load({
					url: BFF, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) {
						if (!res || !res.ok) { return; }
						self.outlets = (res.data && res.data.rows) || [];
						ncmDataTables({
							container: '.tableContainer', url: BFF, iniData: self.buildTable(self.outlets),
							table: '.table', sort: 0, footerSumCol: [],
							currency: self.cfg.currency, decimal: window.decimal, thousand: window.thousandSeparator,
							offset: 0, limit: 500, noMoreBtn: true, tableName: 'tableOutlets', fileTitle: 'Sucursales',
							ncmTools: { left: '', right: '' }, colsFilter: { name: 'outlets', menu: [] },
							clickCB: function (event, tis) { self.openEdit(tis.data('id')); }
						});
					}
				});
				window.xhrs.push(xhr);
			},

			buildTable: function (rows) {
				var self = this;
				var head = '<thead class="text-u-c"><tr>' +
					'<th>Nombre</th><th>Razón Social</th><th>' + esc(self.cfg.tinName) + '</th>' +
					'<th>Teléfono</th><th>Dirección</th><th>Online</th><th>Estado</th>' +
					'</tr></thead><tbody>';
				var body = '';
				rows.forEach(function (o) {
					var status = (o.status == 1)
						? '<span class="label bg-success">Activado</span>'
						: '<span class="label bg-danger">Desactivado</span>';
					var online = o.ecom ? '<i class="material-icons text-success">check</i>' : '';
					body += '<tr data-id="' + esc(o.id) + '" class="clickrow">' +
						'<td class="font-bold">' + esc(o.name) + '</td>' +
						'<td>' + esc(o.billingName) + '</td>' +
						'<td>' + esc(o.ruc) + '</td>' +
						'<td>' + esc(o.phone) + '</td>' +
						'<td>' + esc(o.address) + '</td>' +
						'<td class="text-center">' + online + '</td>' +
						'<td>' + status + '</td></tr>';
				});
				return head + body + '</tbody><tfoot><tr><td colspan="7"></td></tr></tfoot>';
			},

			openEdit: function (id) {
				var self = this;
				var xhr = ncmHelpers.load({
					url: BFF + '?id=' + encodeURIComponent(id), httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) {
						if (!res || !res.ok || !res.data) { return; }
						var d = res.data;
						self.availableTaxes = d.availableTaxes || [];
						self.editing = {
							id: d.id, name: d.name, billingName: d.billingName, ruc: d.ruc, phone: d.phone,
							email: d.email, whatsApp: d.whatsApp, address: d.address, latLng: d.latLng,
							description: d.description,
							purchaseOrderNo: (d.purchaseOrderNo == null ? '' : d.purchaseOrderNo),
							taxId: d.taxId || '', ecom: !!d.ecom, taxIncluded: !!d.taxIncluded, statusOn: (d.status == 1)
						};
						$('#outletEditModal').modal('show');
					}
				});
				window.xhrs.push(xhr);
			},

			save: function () {
				var self = this;
				if (self.saving) { return; }
				self.saving = true;
				var e = self.editing;
				var xhr = ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
					data: {
						action: 'update', id: e.id, name: e.name, address: e.address, phone: e.phone,
						email: e.email, description: e.description, billingName: e.billingName, ruc: e.ruc,
						whatsApp: e.whatsApp, purchaseOrderNo: e.purchaseOrderNo, latLng: e.latLng, tax: e.taxId,
						ecom: e.ecom ? 1 : '', taxIncluded: e.taxIncluded ? 1 : '', status: e.statusOn ? 1 : ''
					},
					success: function (res) {
						self.saving = false;
						if (res && res.ok) {
							$('#outletEditModal').modal('hide');
							if (typeof message === 'function') { message('Sucursal actualizada', 'success'); }
							self.loadList();
						} else if (typeof message === 'function') {
							message('No se pudo guardar', 'danger');
						}
					},
					error: function () { self.saving = false; }
				});
				window.xhrs.push(xhr);
			},

			createOutlet: function () {
				var self = this;
				var xhr = ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: false, warnTimeout: false,
					data: { action: 'create' },
					success: function (res) {
						if (res && res.ok && res.data && res.data.id) {
							self.openEdit(res.data.id);
						} else if (typeof message === 'function') {
							message('No se pudo crear la sucursal', 'danger');
						}
					},
					error: function () {
						if (typeof message === 'function') { message('No se pudo crear la sucursal', 'danger'); }
					}
				});
				window.xhrs.push(xhr);
			},

			removeOutlet: function () {
				var self = this;
				var id = self.editing.id;
				ncmDialogs.confirm('¿Seguro que desea continuar?', 'TODO lo relacionado a esta sucursal será eliminado para siempre', 'warning', function (a) {
					if (!a) { return; }
					ncmDialogs.confirm('¿Seguro que desea continuar?', 'Se eliminarán reportes, transacciones, usuarios, cajas, inventario y más', 'warning', function (b) {
						if (!b) { return; }
						if (typeof spinner === 'function') { spinner('body', 'show'); }
						var xhr = ncmHelpers.load({
							url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
							data: { action: 'delete', id: id },
							success: function (res) {
								if (typeof spinner === 'function') { spinner('body', 'hide'); }
								if (res && res.ok) {
									$('#outletEditModal').modal('hide');
									if (typeof message === 'function') { message('Sucursal eliminada', 'success'); }
									self.loadList();
								} else if (typeof message === 'function') {
									message('Error al eliminar', 'danger');
								}
							},
							error: function () {
								if (typeof spinner === 'function') { spinner('body', 'hide'); }
								if (typeof message === 'function') { message('Error al eliminar', 'danger'); }
							}
						});
						window.xhrs.push(xhr);
					});
				});
			},

			closeModal: function () { $('#outletEditModal').modal('hide'); }
		};
	}

	// Componente global (lo resuelve x-data="outletsModule()").
	window.outletsModule = outletsComponent;

	// Init determinista (§17): clonar a nodo FRESCO, x-data, Alpine.initTree DETACHED (1×), reinsertar.
	$(function () {
		var root = document.getElementById('outletsRoot');
		if (!root || !window.Alpine) { return; }
		var fresh = root.cloneNode(true);
		fresh.setAttribute('x-data', 'outletsModule()');
		Alpine.initTree(fresh);
		root.parentNode.replaceChild(fresh, root);
		Alpine.$data(fresh).mountUI();
	});

})();
