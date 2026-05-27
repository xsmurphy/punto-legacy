/**
 * Front del reporte de Compras y Gastos (a_report_purchases) — BFF de 3 niveles.
 *
 *   - config                    ← GET /bff/bootstrap.php
 *   - general (rows)            ← GET /bff/reports/purchases.php?view=general&from&to[&supId&singleRow]
 *   - detail  (rows)            ← GET /bff/reports/purchases.php?view=detail&...[&src&itmId&supId]
 *   - cobros  (rows)            ← GET /bff/reports/purchases.php?view=cobros&...[&supId]
 *
 * El BFF/Service devuelven datos CRUDOS (nombres/medios de pago/deuda ya resueltos en el service);
 * este JS formatea TODO + arma las 3 tablas (tabs). esc() en datos.
 *
 * El CRUD de edición y los fiscales NO se migraron: se sirven por el PHP legacy vía
 *   /a_report_purchases?action=edit|paymentForm|update|delete|addPayment|rg90|libro-compra
 * cargados en los modales globales del shell (#modalXLarge editar, #modalTiny pago) o ventanas nuevas.
 *
 * Drill-down por query/hash: ci=supId (proveedor), ii=itmId (artículo).
 */
(function () {

	var BFF       = '/bff/reports/purchases.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY    = '/a_report_purchases';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA', tinName: 'TIN', country: '' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var loaded = { detail: false, cobros: false };

	function param(name) {
		var s = window.location.search || '';
		var h = window.location.hash || '';
		var q = s;
		if (h.indexOf('?') !== -1) { q += '&' + h.slice(h.indexOf('?') + 1); }
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1]) : '';
	}
	var FILT = { supId: param('ci'), itmId: param('ii') };
	var SRC = '';

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}
	function niceDate(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : ''; }
	function num(v) { return parseFloat(v) || 0; }

	function paymentLabels(payments) {
		if (!payments || !payments.length) { return ''; }
		var out = '';
		$.each(payments, function (i, p) {
			out += '<span class="label bg-light m-r-xs">' + esc(p.name) + '</span>';
		});
		return out;
	}

	// Etiqueta de "Tipo" (Contado/Crédito/Orden/Reposición) + filtro de búsqueda, según el legacy.
	function typeBadge(r) {
		var label = '<span class="label bg-light text-u-c">Contado</span>';
		var filter = 'tipo:contado';
		if (String(r.transactionType) === '4') {
			if (String(r.transactionComplete) === '1') {
				label = '<span class="label bg-success lter text-white text-u-c">Crédito</span>'; filter = 'tipo:crédito_conpago';
			} else {
				label = '<span class="label bg-danger lter text-u-c">Crédito</span>'; filter = 'tipo:crédito_sinpago';
			}
		}
		var st = String(r.transactionStatus);
		if (st === '2') { filter = 'tipo:anulada'; }
		else if (st === '0') { label = '<span class="label bg-dark text-u-c">Orden</span>'; filter = 'tipo:orden'; }
		else if (st === '3') { label = '<span class="label bg-dark text-u-c">Reposición</span>'; filter = 'tipo:reposición'; }
		return { label: label, filter: filter };
	}

	// ── tabla general (Compra o Gasto) ──
	function buildGeneralTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th class="ignored">Timbrado o Autorización</th><th># Documento</th><th>Fecha</th>' +
			'<th class="no-search">Vencimiento</th><th>Sucursal</th><th class="ignored">Usuario</th>' +
			'<th>Proveedor</th><th>' + esc(RS.tinName) + '</th><th class="no-order">Nota</th>' +
			'<th>Medio de Pago</th><th>Tipo</th><th>Plan de Cuentas</th>' +
			'<th>' + esc(RS.taxName) + '</th><th class="text-center no-search">Descuento</th>' +
			'<th class="text-center no-search">Total</th><th class="text-center no-search">Deuda</th>' +
			'<th class="ignored"></th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var t = typeBadge(r);
			var payBtn = r.canAddPayment
				? '<td class="text-center hidden-print"><a href="#" class="addPayment" data-toggle="tooltip" data-placement="left" title="Añadir Pago" data-id="' + esc(r.transactionId) + '"><i class="material-icons text-success">payment</i></a></td>'
				: '<td></td>';
			body +=
				'<tr data-id="' + esc(r.transactionId) + '" data-load="' + LEGACY + '?action=edit&id=' + esc(r.transactionId) + '" class="clickrow pointer">' +
				'<td>' + esc(r.authNo) + '</td>' +
				'<td>' + esc((r.prefix ? r.prefix + ' ' : '') + r.invoiceNo) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td data-order="' + esc(r.dueDate) + '">' + (r.dueDate ? niceDate(r.dueDate) : '-') + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.supplierName) + '</td>' +
				'<td>' + esc(r.supplierTIN) + '</td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td>' + paymentLabels(r.payments) + '</td>' +
				'<td data-filter="' + esc(t.filter) + '">' + t.label + '</td>' +
				'<td>' + esc(r.category) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.tax) + '" data-format="money">' + fmt(r.tax) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.discount) + '" data-format="money">' + fmt(r.discount) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.debt) + '" data-format="money">' + fmt(r.debt) + '</td>' +
				payBtn +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="12">TOTALES</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th></th>' +
			'</tr></tfoot>';
		return head + body + foot;
	}

	// ── tabla detalle ──
	function buildDetailTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th>Sucursal</th><th># Documento</th><th>Usuario</th><th>Proveedor</th><th>Fecha</th>' +
			'<th>Artículo</th><th>Categoría</th><th class="text-center">Cantidad</th>' +
			'<th class="text-center">Costo</th><th class="text-center">' + esc(RS.taxName) + '</th><th class="text-center">Total</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr data-id="' + esc(r.transactionId) + '" data-load="' + LEGACY + '?action=edit&id=' + esc(r.transactionId) + '&ro=1" class="clickrow pointer">' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td class="text-right">' + esc(r.invoiceNo) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.supplierName) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.category) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.usold) + '">' + fmtQty(r.usold) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.cogs) + '" data-format="money">' + fmt(r.cogs) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.tax) + '" data-format="money">' + fmt(r.tax) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th>TOTALES:</th><th colspan="6"></th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'</tr></tfoot>';
		return head + body + foot;
	}

	// ── tabla pagos realizados (cobros) ──
	function buildCobrosTable(rows) {
		var head = '<thead class="text-u-c pointer"><tr>' +
			'<th class="text-center"># Doc. Ref.</th><th class="text-center"># Comprobante</th><th>Fecha</th>' +
			'<th>Usuario</th><th>Sucursal</th><th>Nota</th><th>M. de Pago</th>' +
			'<th class="text-center">Pagado</th><th></th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var delUrl = LEGACY + '?action=delete&id=' + esc(r.transactionId) + '&outlet=' + esc(r.outletId) + '&type=' + esc(r.type) + '&parent=' + esc(r.parentId);
			body +=
				'<tr data-id="' + esc(r.parentId) + '" data-load="' + LEGACY + '?action=edit&id=' + esc(r.parentId) + '&ro=true" class="clickrow pointer">' +
				'<td class="text-right">' + esc(r.parentInvoice) + '</td>' +
				'<td class="text-right">' + esc(r.invoiceNo) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td>' + paymentLabels(r.payments) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-center"><a href="' + delUrl + '" class="deletePayment hidden-print"><i class="material-icons text-danger">close</i></a></td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="7">TOTALES:</th><th class="text-right"></th><th></th></tr></tfoot>';
		return head + body + foot;
	}

	function qstr(view) {
		var p = { view: view, from: FROM, to: TO };
		if (FILT.supId) { p.supId = FILT.supId; }
		if (view === 'detail' && FILT.itmId) { p.itmId = FILT.itmId; }
		if (view === 'detail' && SRC) { p.src = SRC; }
		return BFF + '?' + $.param(p);
	}

	// Carga (o re-carga) la tabla general.
	function loadGeneral() {
		var url = qstr('general');
		var tools = '';
		if (RS.country === 'PY') {
			tools = '<a class="btn r-3x b b-light font-bold" id="rg90" href="#" title="RG90">RG90</a> ' +
				'<a class="btn r-3x b b-light font-bold" id="libro-compra" href="#" title="Libro Compra">Libro Compra</a>';
		}
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#generalTable", "url": url, "iniData": buildGeneralTable(res.data.rows || []),
					"table": ".table1", "sort": 2, "footerSumCol": [12, 13, 14, 15],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableGeneral', "fileTitle": 'Reporte de Compras y Gastos',
					"ncmTools": { left: tools, right: '' },
					"colsFilter": { name: 'reportPurchase1', menu: [
						{ index: 0, name: 'Timb. o Aut.', visible: false }, { index: 1, name: '# Documento', visible: true },
						{ index: 2, name: 'Fecha', visible: true }, { index: 3, name: 'Vencimiento', visible: false },
						{ index: 4, name: 'Sucursal', visible: false }, { index: 5, name: 'Usuario', visible: false },
						{ index: 6, name: 'Proveedor', visible: true }, { index: 7, name: RS.tinName, visible: false },
						{ index: 8, name: 'Nota', visible: false }, { index: 9, name: 'M. de Pago', visible: false },
						{ index: 10, name: 'Tipo', visible: true }, { index: 11, name: 'Plan de Cuentas', visible: false },
						{ index: 12, name: RS.taxName, visible: false }, { index: 13, name: 'Descuento', visible: false },
						{ index: 14, name: 'Total', visible: true }, { index: 15, name: 'Deuda', visible: false }
					] },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				}, function () {
					$('[data-toggle="tooltip"]').tooltip();
					wireGeneralActions();
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadDetail() {
		var url = qstr('detail');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#detailTable", "url": url, "iniData": buildDetailTable(res.data.rows || []),
					"table": ".table3", "sort": 4, "footerSumCol": [7, 8, 9, 10],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableDetail', "fileTitle": 'Detalle de compras',
					"ncmTools": { left: '', right: '' },
					"colsFilter": { name: 'reportPurchaseDetail1', menu: [
						{ index: 0, name: 'Sucursal', visible: false }, { index: 1, name: '# Documento', visible: true },
						{ index: 2, name: 'Usuario', visible: false }, { index: 3, name: 'Proveedor', visible: true },
						{ index: 4, name: 'Fecha', visible: true }, { index: 5, name: 'Artículo', visible: true },
						{ index: 6, name: 'Categoría', visible: false }, { index: 7, name: 'Cantidad', visible: true },
						{ index: 8, name: 'Costo', visible: false }, { index: 9, name: RS.taxName, visible: false },
						{ index: 10, name: 'Total', visible: true }
					] },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadCobros() {
		var url = qstr('cobros');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#cobrosTable", "url": url, "iniData": buildCobrosTable(res.data.rows || []),
					"table": ".table2", "sort": 0, "footerSumCol": [7],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tablePayments', "fileTitle": 'Pagos a proveedores',
					"ncmTools": { left: '', right: '' },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				}, function (oTable) {
					onClickWrap('.deletePayment', function (event, tis) {
						var href = tis.attr('href');
						var $row = tis.closest('tr');
						ncmDialogs.confirm('¿Desea eliminar este pago?', '', 'question', function (r) {
							if (r === true) {
								$.get(href, function (data) {
									// el delete legacy responde true (texto) o {success:true}
									if (data === 'true' || data === true || (data && data.success)) {
										message('Pago eliminado.', 'success');
										oTable.row($row).remove().draw();
										loaded.detail = false;   // refrescar deuda en general al reabrir
									} else {
										message('Error, no pudimos eliminar el pago', 'danger');
									}
								});
							}
						});
					});
				});
			}
		});
		window.xhrs.push(xhr);
	}

	// Carga el form legacy de edición en el modal grande.
	function openEditModal(tis) {
		var load = tis.data('load');
		if (!load) { return; }
		loadForm(load, '#modalXLarge .modal-content', function () { $('#modalXLarge').modal('show'); });
	}

	// Handlers de los modales legacy (edición + pago) y botones fiscales. Se atan una vez tras
	// cargar la general; el contenido de los modales lo provee el PHP legacy vía ?action=.
	var actionsWired = false;
	function wireGeneralActions() {
		if (actionsWired) { return; }
		actionsWired = true;

		// Modal de edición: form #editSale (legacy) → al guardar, recargar la general.
		$('#modalXLarge').off('shown.bs.modal').on('shown.bs.modal', function () {
			if (typeof select2Ajax === 'function') {
				select2Ajax({ element: '.supplier-select', url: '/a_contacts?action=searchCustomerInputJson&t=2', type: 'contact' });
				select2Ajax({ element: '.selectItem', url: '/a_items?action=searchItemInputJson', type: 'item' });
			}
			if (typeof select2Simple === 'function') { select2Simple('.chosen-select'); }
			masksCurrency($('.units'), RS.thousand, 'no');
			masksCurrency($('.maskFloat'), RS.thousand, 'yes');
			masksCurrency($('.maskFloat3'), RS.thousand, 'yes', false, '3');
			masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
			masksCurrency($('.maskQty'), RS.thousand, 'yes', false, '2');
			$('[data-toggle="tooltip"]').tooltip();
			$('.datetimepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
			$('.datepicker').datetimepicker({ format: 'YYYY-MM-DD', showClear: true, ignoreReadonly: true });

			submitForm('#editSale', function (tis, result) {
				if (result) {
					$('#modalXLarge').modal('hide');
					loaded.detail = false; loaded.cobros = false;
					loadGeneral();
				}
			});

			onClickWrap('.deleteTransaction', function (event, tis) {
				var href = tis.attr('href');
				ncmDialogs.confirm('¿Desea eliminar esta transacción?', '', 'question', function (r) {
					if (r) {
						$.get(href, function (data) {
							if (data && data.success) {
								message('Transacción eliminada', 'success');
								$('#modalXLarge').modal('hide');
								loaded.detail = false; loaded.cobros = false;
								loadGeneral();
							} else {
								message('Error, no se pudo eliminar', 'danger');
							}
						});
					}
				});
			});

			onClickWrap('.cancelItemView', function () { $('#modalXLarge').modal('hide'); });
		});

		// Modal de pago: form #addPaymentForm (legacy) → al guardar, recargar la general.
		$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function () {
			masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
			$('.datetimepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
			$('#payAmountField').focus();
			submitForm('#addPaymentForm', function (tis, result) {
				if (result) {
					$('#modalTiny').modal('hide');
					loaded.detail = false; loaded.cobros = false;
					loadGeneral();
				}
			});
		});

		// Botón "Añadir Pago" en una fila de crédito → carga el paymentForm legacy.
		onClickWrap('.addPayment', function (event, tis) {
			var id = tis.data('id');
			loadForm(LEGACY + '?action=paymentForm&id=' + id, '#modalTiny .modal-content', function () {
				$('#modalTiny').modal('show');
			});
		});

		// Reportes fiscales (PY): se abren en ventana nueva, servidos por el PHP legacy.
		onClickWrap('#rg90', function () {
			window.open(LEGACY + '?action=rg90&from=' + FROM + '&to=' + TO);
		});
		onClickWrap('#libro-compra', function () {
			window.open(LEGACY + '?action=libro-compra&from=' + FROM + '&to=' + TO);
		});
	}

	$(document).ready(function () {
		ncmHelpers.load({
			url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					RS.taxName  = res.data.taxName  || 'IVA';
					RS.tinName  = res.data.tinName  || 'TIN';
					RS.country  = res.data.country  || '';
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadGeneral();
			}
		});

		$('#detailTabLink').on('shown.bs.tab', function () { if (!loaded.detail) { loaded.detail = true; loadDetail(); } });
		$('#cobrosTabLink').on('shown.bs.tab', function () { if (!loaded.cobros) { loaded.cobros = true; loadCobros(); } });

		$('#detailSearch').on('keyup', function (e) {
			if ((e.keyCode || e.which) !== 13) { return; }
			SRC = $.trim($(this).val());
			loadDetail();
		});

		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loaded.detail = false; loaded.cobros = false;
			loadGeneral();
		});
	});

})();
