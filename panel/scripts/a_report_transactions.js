/**
 * Front del reporte de Pagos y Transacciones (a_report_transactions) — BFF de 3 niveles.
 *
 *   - config                     ← GET /bff/bootstrap.php
 *   - detail (rows)              ← GET /bff/reports/transactions.php?view=detail&from&to[&cusId&src&singleRow]
 *   - cobros (rows)             ← GET /bff/reports/transactions.php?view=cobros&...[&cusId&src]
 *   - quotes (rows)             ← GET /bff/reports/transactions.php?view=quotes&...[&cusId&src]
 *   - FE (HTML legacy)           ← GET /a_report_transactions?action=feTable  (API externa, no migrada)
 *
 * El BFF/Service devuelven datos CRUDOS (nombres/medios de pago/deuda/comprobante ya resueltos);
 * este JS formatea TODO + arma las tablas (tabs). esc() en datos.
 *
 * El CRUD de edición y los fiscales NO se migraron: se sirven por el PHP legacy vía
 *   /a_report_transactions?action=edit|update|delete|paymentForm|rg90|libro-ventas|download-report
 * cargados en el modal global del shell (#modalXLarge) o ventanas nuevas.
 *
 * Drill-down por query/hash: ci=cusId (cliente).
 */
(function () {

	var BFF       = '/bff/reports/transactions.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY    = '/a_report_transactions';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA', tinName: 'TIN', country: '' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var loaded = { cobros: false, quotes: false, fe: false };

	function param(name) {
		var s = window.location.search || '';
		var h = window.location.hash || '';
		var q = s;
		if (h.indexOf('?') !== -1) { q += '&' + h.slice(h.indexOf('?') + 1); }
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1]) : '';
	}
	var FILT = { cusId: param('ci') };
	var SRC = { detail: '', cobros: '', quotes: '' };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function niceDate(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : ''; }
	function num(v) { return parseFloat(v) || 0; }

	function paymentLabels(payments) {
		if (!payments || !payments.length) { return ''; }
		var out = '';
		$.each(payments, function (i, p) { out += '<span class="label bg-light m-r-xs">' + esc(p.name) + '</span>'; });
		return out;
	}
	function tagLabels(tags) {
		if (!tags || !tags.length) { return ''; }
		var out = '';
		$.each(tags, function (i, t) { out += '<span class="label bg-info m-r-xs">' + esc(t) + '</span>'; });
		return out;
	}

	// Badge "Tipo" de la vista detalle, según el legacy. Crédito: color por deuda restante.
	function detailTypeBadge(r) {
		var type = r.transactionType;
		if (type === 6) { return { label: '<span class="label bg-dark lter text-u-c">Devolución</span>', filter: 'tipo:devolución' }; }
		if (type === 7) { return { label: '<span class="label bg-dark text-u-c">Anulado</span>', filter: 'tipo:anulado' }; }
		if (type === 3) {
			var net = num(r.netTotal), topay = num(r.topay), complete = String(r.transactionComplete) === '1';
			var cls = 'bg-danger', op = '_sinpago';
			if (topay <= net / 2) { cls = 'bg-warning'; op = '_conpago'; }
			if (topay <= 0 || complete) { cls = 'bg-success text-white'; op = '_pagado'; }
			var due = r.dueDate ? niceDate(r.dueDate) : '';
			var overdue = (!complete && r.dueDate && moment(r.dueDate).isValid() && moment(r.dueDate).isBefore(moment()))
				? ' <span class="badge badge-sm bg-danger up">!</span>' : '';
			return {
				label: '<span class="label ' + cls + ' lter text-u-c" data-toggle="tooltip" data-placement="top" title="Vencimiento (' + esc(due) + ')">Crédito</span>' + overdue,
				filter: 'tipo:crédito' + op
			};
		}
		return { label: '<span class="label bg-light text-u-c">Contado</span>', filter: 'tipo:contado' };
	}

	// ── tabla detalle (Transacciones) ──
	function buildDetailTable(rows) {
		var head = '<thead class="text-u-c pointer"><tr>' +
			'<th>ID</th><th class="text-center ignored"># Autorización</th><th class="text-center"># Documento</th>' +
			'<th>Fecha</th><th>Hora</th><th class="no-search">Vencimiento</th><th>Cliente</th>' +
			'<th>' + esc(RS.tinName) + '</th><th>Usuario</th><th class="ignored">Sucursal</th><th>Caja</th>' +
			'<th>M. de Pago</th><th class="ignored">Nota</th><th>Etiquetas</th><th>Tipo</th>' +
			'<th class="text-center no-search">Descuento</th><th class="text-center ignored">Subtotal</th>' +
			'<th class="text-center ignored">' + esc(RS.taxName) + '</th><th class="text-center ignored">Total Gravado</th>' +
			'<th class="text-center no-search">Total</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var t = detailTypeBadge(r);
			var custFilter = (r.customerName ? esc(r.customerName) + ' con:cliente' : 'sin:cliente');
			body +=
				'<tr data-id="' + esc(r.transactionId) + '" class="clickrow pointer">' +
				'<td class="bg-light dk">' + esc(r.transactionId) + '</td>' +
				'<td class="text-right bg-light dk">' + esc(r.authNo) + '</td>' +
				'<td class="text-right bg-light dk" data-order="' + esc(r.invoiceNo) + '">' + esc(r.docNo) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + (moment(r.date).isValid() ? moment(r.date).format('HH:mm') : '') + '</td>' +
				'<td data-order="' + esc(r.dueDate) + '">' + (r.dueDate ? niceDate(r.dueDate) : '') + '</td>' +
				'<td data-filter="' + custFilter + '">' + esc(r.customerName) + '</td>' +
				'<td>' + esc(r.customerTIN || '-') + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.registerName) + '</td>' +
				'<td>' + paymentLabels(r.payments) + '</td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td>' + tagLabels(r.tags) + '</td>' +
				'<td data-filter="' + esc(t.filter) + '">' + t.label + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.discount) + '" data-format="money">' + fmt(r.discount) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.subtotal) + '" data-format="money">' + fmt(r.subtotal) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.tax) + '" data-format="money">' + fmt(r.tax) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.totalGravado) + '" data-format="money">' + fmt(r.totalGravado) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="15">TOTALES:</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'</tr></tfoot>';
		return head + body + foot;
	}

	// ── tabla pagos recibidos (cobros) ──
	function buildCobrosTable(rows) {
		var head = '<thead class="text-u-c pointer"><tr>' +
			'<th class="text-center">Doc. Ref. #</th><th class="text-center">Documento #</th><th>Fecha</th>' +
			'<th>Cliente</th><th>Usuario</th><th>Sucursal</th><th>Caja</th><th>M. de Pago</th>' +
			'<th class="text-center">Cobrado</th><th></th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr data-id="' + esc(r.parentId) + '" class="clickrow pointer" data-ro="true">' +
				'<td class="text-right">' + esc(r.parentInvoice) + '</td>' +
				'<td class="text-right">' + esc(r.invoiceNo) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.registerName) + '</td>' +
				'<td>' + paymentLabels(r.payments) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-center"><a href="#" class="deletePayment hidden-print" data-id="' + esc(r.transactionId) + '" data-parent="' + esc(r.parentId || '') + '"><i class="material-icons text-danger">close</i></a></td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="8">TOTALES:</th><th class="text-right"></th><th></th></tr></tfoot>';
		return head + body + foot;
	}

	// ── tabla cotizaciones (quotes) ──
	function quoteStatus(s) {
		s = String(s);
		if (s === '2') { return '<span class="label bg-info lter">Aprobado</span>'; }
		if (s === '4') { return '<span class="label bg-success lt">Finalizado</span>'; }
		if (s === '5') { return '<span class="label bg-danger lter">Rechazado</span>'; }
		if (s === '6') { return '<span class="label bg-dark lter">Otro</span>'; }
		return '<span class="label bg-light">Pendiente</span>';
	}
	function buildQuotesTable(rows) {
		var head = '<thead class="text-u-c pointer"><tr>' +
			'<th class="text-center">Documento #</th><th>Fecha</th><th>Estado</th><th>Cliente</th>' +
			'<th>' + esc(RS.tinName) + '</th><th>Usuario</th><th>Sucursal</th><th class="text-center">Valor</th><th></th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr data-id="' + esc(r.transactionId) + '" class="clickrow pointer" data-ro="true">' +
				'<td class="text-right">' + esc(r.invoiceNo) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + quoteStatus(r.transactionStatus) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td>' + esc(r.customerTIN) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-center"><a href="#" class="deleteQuote hidden-print" data-id="' + esc(r.transactionId) + '"><i class="material-icons text-danger">close</i></a></td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="7">TOTALES:</th><th class="text-right"></th><th></th></tr></tfoot>';
		return head + body + foot;
	}

	function qstr(view) {
		var p = { view: view, from: FROM, to: TO };
		if (FILT.cusId) { p.cusId = FILT.cusId; }
		if (SRC[view]) { p.src = SRC[view]; }
		return BFF + '?' + $.param(p);
	}

	// Carga el form legacy de edición en el modal grande.
	function openEditModal(tis) {
		var ro = tis.data('ro') ? '&ro=1' : '';
		var load = LEGACY + '?action=edit&id=' + tis.data('id') + ro;
		loadForm(load, '#modalXLarge .modal-content', function () { $('#modalXLarge').modal('show'); });
	}

	function loadDetail() {
		var url = qstr('detail');
		var tools = '';
		if (RS.country === 'PY') {
			tools = '<a class="btn r-3x b b-light font-bold" id="rg90" href="#" title="RG90">RG90</a> ' +
				'<a class="btn r-3x b b-light font-bold" id="libro-ventas" href="#" title="Libro Ventas">Libro Ventas</a> ';
		}
		tools += '<a class="btn r-3x b b-light font-bold" id="reportDownload" href="#" title="Reporte detallado en CSV"><i class="material-icons">file_download</i></a>';
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#detailTable", "url": url, "iniData": buildDetailTable(res.data.rows || []),
					"table": ".table1", "sort": 3, "footerSumCol": [15, 16, 17, 18, 19],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableTransactions', "fileTitle": 'Transacciones',
					"ncmTools": { left: tools, right: '<input type="text" class="form-control rounded" placeholder="Buscar por Nombre, ' + esc(RS.tinName) + ' o # Doc." id="detailTableSearch">' },
					"colsFilter": { name: 'reportTransactions11', menu: [
						{ index: 0, name: 'ID', visible: false }, { index: 1, name: 'Nro. Autorización', visible: false },
						{ index: 2, name: 'Nro. Documento', visible: false }, { index: 3, name: 'Fecha', visible: true },
						{ index: 4, name: 'Hora', visible: false }, { index: 5, name: 'Vencimiento', visible: false },
						{ index: 6, name: 'Cliente', visible: true }, { index: 7, name: RS.tinName, visible: false },
						{ index: 8, name: 'Usuario', visible: false }, { index: 9, name: 'Sucursal', visible: false },
						{ index: 10, name: 'Caja', visible: false }, { index: 11, name: 'M. de Pagos', visible: false },
						{ index: 12, name: 'Nota', visible: false }, { index: 13, name: 'Etiquetas', visible: false },
						{ index: 14, name: 'Tipo', visible: true }, { index: 15, name: 'Descuento', visible: false },
						{ index: 16, name: 'Subtotal', visible: false }, { index: 17, name: RS.taxName, visible: false },
						{ index: 18, name: 'Total Gravado', visible: false }, { index: 19, name: 'Total', visible: true }
					] },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				}, function () {
					$('[data-toggle="tooltip"]').tooltip();
					wireEditModal();
					$('#rg90').off('click').on('click', function (e) { e.preventDefault(); window.open(LEGACY + '?action=rg90&from=' + FROM + '&to=' + TO); });
					$('#libro-ventas').off('click').on('click', function (e) { e.preventDefault(); window.open(LEGACY + '?action=libro-ventas&from=' + FROM + '&to=' + TO); });
					$('#reportDownload').off('click').on('click', function (e) { e.preventDefault(); window.open(LEGACY + '?action=download-report&from=' + FROM + '&to=' + TO); });
					$('#detailTableSearch').off('keyup').on('keyup', function (e) {
						if ((e.keyCode || e.which) !== 13) { return; }
						SRC.detail = $.trim($(this).val());
						loadDetail();
					});
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
					"table": ".table2", "sort": 0, "footerSumCol": [8],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tablePayments', "fileTitle": 'Pagos',
					"ncmTools": { left: '', right: '<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o ' + esc(RS.tinName) + '" id="paymentTableSearch">' },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				}, function (oTable) {
					onClickWrap('.deletePayment', function (event, tis) {
						var $row = tis.closest('tr');
						ncmDialogs.confirm('¿Desea eliminar este pago?', 'Esta acción no se puede revertir.', 'warning', function (conf) {
							if (conf) {
								ncmHelpers.load({
									url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
									data: { action: 'deletePayment', id: tis.data('id'), parent: tis.data('parent') || '' },
									success: function (resp) {
										if (resp && resp.ok) { message('Pago eliminado.', 'success'); oTable.row($row).remove().draw(); }
										else { message('No se pudo eliminar', 'danger'); }
									},
									fail: function () { message('No se pudo eliminar', 'danger'); }
								});
							}
						});
					});
					$('#paymentTableSearch').off('keyup').on('keyup', function (e) {
						if ((e.keyCode || e.which) !== 13) { return; }
						SRC.cobros = $.trim($(this).val());
						loadCobros();
					});
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadQuotes() {
		var url = qstr('quotes');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#quotesTable", "url": url, "iniData": buildQuotesTable(res.data.rows || []),
					"table": ".table3", "sort": 0, "footerSumCol": [7],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableQuotes', "fileTitle": 'Cotizaciones',
					"ncmTools": { left: '', right: '<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o ' + esc(RS.tinName) + '" id="quotesTableSearch">' },
					"clickCB": function (event, tis) { return openEditModal(tis); }
				}, function (oTable) {
					onClickWrap('.deleteQuote', function (event, tis) {
						var $row = tis.closest('tr');
						ncmDialogs.confirm('¿Desea eliminar esta cotización?', 'Esta acción no se puede revertir.', 'warning', function (conf) {
							if (conf) {
								ncmHelpers.load({
									url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
									data: { action: 'deleteQuote', id: tis.data('id') },
									success: function (resp) {
										if (resp && resp.ok) { message('Cotización eliminada.', 'success'); oTable.row($row).remove().draw(); }
										else { message('No se pudo eliminar', 'danger'); }
									},
									fail: function () { message('No se pudo eliminar', 'danger'); }
								});
							}
						});
					});
					$('#quotesTableSearch').off('keyup').on('keyup', function (e) {
						if ((e.keyCode || e.which) !== 13) { return; }
						SRC.quotes = $.trim($(this).val());
						loadQuotes();
					});
				});
			}
		});
		window.xhrs.push(xhr);
	}

	// Factura electrónica: gateway a una API externa, NO migrada → el PHP legacy devuelve el HTML.
	function loadFE() {
		var url = LEGACY + '?action=feTable';
		$.get(url, function (result) {
			if (!result || !result.table) { return; }
			ncmDataTables({
				"container": "#feTable", "url": url, "iniData": result.table, "table": "#tableFE", "sort": 0,
				"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
				"nolimit": true, "tableName": 'tableFE', "fileTitle": 'Facturas Electrónicas',
				"ncmTools": { left: '', right: '' }
			});
		});
	}

	// Wiring del modal de edición legacy (#modalXLarge). Se ata una vez.
	var editWired = false;
	function wireEditModal() {
		if (editWired) { return; }
		editWired = true;

		$('#modalXLarge').off('shown.bs.modal').on('shown.bs.modal', function () {
			if (typeof select2Ajax === 'function') {
				select2Ajax({ element: '.selectCustomer', url: '/a_contacts?action=searchCustomerInputJson', type: 'contact' });
				select2Ajax({ element: '.selectItem', url: '/a_items?action=searchItemInputJson', type: 'item' });
			}
			if (typeof select2Simple === 'function') {
				select2Simple('.selectSimple', $('#modalXLarge'));
				select2Simple('.selectUser', $('#modalXLarge'));
				select2Simple($('select.selectTags'), $('#modalXLarge'));
			}
			$('.datepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
			masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
			masksCurrency($('.maskQty'), RS.thousand, 'yes', false, '2');

			submitForm('#editSale', function (tis, result) {
				if (result) {
					$('#modalXLarge').modal('hide');
					loaded.cobros = false; loaded.quotes = false;
					loadDetail();
				}
			});

			onClickWrap('.deleteTransaction', function (event, tis) {
				var href = tis.attr('href');
				ncmDialogs.confirm('¿Desea eliminar la transacción?', 'Esta acción no se puede revertir.', 'warning', function (conf) {
					if (conf) {
						$.get(href, function (data) {
							if (data && data.success) {
								message('Eliminado', 'success'); $('#modalXLarge').modal('hide');
								loaded.cobros = false; loaded.quotes = false; loadDetail();
							} else { message('No se pudo eliminar', 'danger'); }
						});
					}
				});
			});

			onClickWrap('.voidTransaction', function (event, tis) {
				var href = tis.attr('href');
				ncmDialogs.confirm('¿Desea anular la transacción?', '', 'warning', function (conf) {
					if (conf) {
						$.get(href, function (data) {
							if (data === 'true' || data === true || (data && data.success)) {
								message('Anulado', 'success'); $('#modalXLarge').modal('hide');
								loaded.cobros = false; loaded.quotes = false; loadDetail();
							} else { message('No se pudo anular', 'danger'); }
						});
					}
				});
			});

			onClickWrap('.cancelItemView', function () { $('#modalXLarge').modal('hide'); });
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
					if (RS.country === 'PY') { $('#feTabLink').removeClass('hidden'); }
				}
				loadDetail();
			}
		});

		$('#cobrosTabLink').on('shown.bs.tab', function () { if (!loaded.cobros) { loaded.cobros = true; loadCobros(); } });
		$('#quotesTabLink').on('shown.bs.tab', function () { if (!loaded.quotes) { loaded.quotes = true; loadQuotes(); } });
		$('#feTabLink').on('shown.bs.tab', function () { if (!loaded.fe) { loaded.fe = true; loadFE(); } });

		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loaded.cobros = false; loaded.quotes = false; loaded.fe = false;
			loadDetail();
		});
	});

})();
