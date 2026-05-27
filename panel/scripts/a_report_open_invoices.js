/**
 * Front de Cuentas por Cobrar/Pagar (a_report_open_invoices) — BFF de 3 niveles.
 *
 *   - config              ← GET /bff/bootstrap.php
 *   - datos (rows + kpi)   ← GET /bff/reports/open_invoices.php?state=income|outcome
 *
 * El BFF devuelve datos CRUDOS (contacto → facturas + KPIs); este JS formatea TODO + arma la tabla
 * anidada y los KPIs. Las acciones de pago/edición se sirven por el PHP legacy (purchases/
 * transactions) vía ?action= en los modales del shell; tras pagar, se recarga la tabla.
 *
 * state por query/hash: state=outcome (por pagar / proveedores) | income (por cobrar / clientes).
 */
(function () {

	var BFF        = '/bff/reports/open_invoices.php';
	var BOOTSTRAP  = '/bff/bootstrap.php';
	var LEGACY_TX  = '/a_report_transactions';
	var LEGACY_PUR = '/a_report_purchases';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', tinName: 'TIN' };

	function param(name) {
		var s = window.location.search || '', h = window.location.hash || '', q = s;
		if (h.indexOf('?') !== -1) { q += '&' + h.slice(h.indexOf('?') + 1); }
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1]) : '';
	}
	var STATE = (param('state') === 'outcome') ? 'outcome' : 'income';
	var IS_TO_PAY = (STATE === 'outcome');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function niceDate(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY') : ''; }
	function dueClass(s) { return s === 'expired' ? 'text-danger font-bold' : (s === 'toExpire' ? 'text-warning font-bold' : ''); }

	function buildTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th>' + (IS_TO_PAY ? 'Proveedor' : 'Cliente') + '</th><th>' + esc(RS.tinName) + '</th><th>Teléfono</th><th>Email</th>' +
			'<th>Total Comprado</th><th>Total Pagado</th><th>Deuda Total</th>' + (IS_TO_PAY ? '<th></th>' : '') +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, c) {
			var fam = esc(c.contactId);
			// fila del contacto (clickeable → ficha de contacto)
			body += '<tr data-load="/a_contacts?action=form&id=' + fam + '&type=wl&ro=true" class="loadCustomer pointer" data-family="' + fam + '">' +
				'<td class="font-bold">' + esc(c.name) + '</td>' +
				'<td>' + esc(c.tin || '-') + '</td>' +
				'<td>' + esc(c.phone) + '</td>' +
				'<td>' + esc(c.email) + '</td>' +
				'<td class="font-bold bg-light bg text-right">' + fmt(c.totalSales) + '</td>' +
				'<td class="font-bold bg-light bg text-right">' + fmt(c.totalPaid) + '</td>' +
				'<td class="font-bold bg-light bg text-right">' + fmt(c.totalDebt) + '</td>' +
				(IS_TO_PAY ? '<td></td>' : '') +
				'</tr>';
			// cabecera de detalle
			body += '<tr class="text-u-c font-bold OIDetails" data-family="' + fam + '">' +
				'<th># Documento</th><th>Emisión</th><th>Vencimiento</th>' +
				'<th class="text-center">Total Comprado</th><th class="text-center">Total Pagado</th><th class="text-center">Total Adeudado</th><th></th>' +
				'</tr>';
			// filas de facturas
			$.each(c.invoices || [], function (j, inv) {
				var load = (IS_TO_PAY ? LEGACY_PUR : LEGACY_TX) + '?action=edit&id=' + esc(inv.saleId) + '&ro=true';
				body += '<tr data-load="' + load + '" class="clickrow pointer OIDetails" data-family="' + fam + '">' +
					'<td class="bg-light dk">' + esc(inv.invoiceNo) + '</td>' +
					'<td>' + niceDate(inv.date) + '</td>' +
					'<td class="' + dueClass(inv.dueStatus) + '">' + niceDate(inv.dueDate) + '</td>' +
					'<td class="text-right bg-light lter">' + fmt(inv.total) + '</td>' +
					'<td class="text-right bg-light lter">' + fmt(inv.payed) + '</td>' +
					'<td class="text-right bg-light lter">' + fmt(inv.topay) + '</td>' +
					(IS_TO_PAY
						? '<td class="text-center hidden-print"><a href="#" class="addPayment" data-toggle="tooltip" data-placement="left" title="Añadir Pago" data-id="' + esc(inv.saleId) + '"><i class="material-icons text-success">payment</i></a></td>'
						: '<td></td>') +
					'</tr>';
			});
			// (sólo cobrar) link al estado de cuenta del cliente
			if (!IS_TO_PAY) {
				body += '<tr data-family="' + fam + '" class="noxls"><td colspan="7" class="text-center">' +
					'<a href="/screens/customerAccountStatus?s=' + esc(b64(window.bffCompanyId + ',' + c.contactId)) + '" target="_blank" class="text-info text-md font-bold text-u-c hidden-print">Ver detalles</a>' +
					'</td></tr>';
			}
		});
		return head + body + '</tbody>';
	}

	function b64(s) { try { return btoa(s); } catch (e) { return ''; } }

	function renderKpis(k) {
		if (!k) { return; }
		$('.globalPay').text(esc(RS.currency) + fmt(k.totalDebt));
		$('.globalAccounts').text(k.accounts || 0);
		$('.globalDue').text(k.expired || 0);
		$('.globalToDue').text(k.toExpire || 0);
	}

	function load() {
		var url = BFF + '?state=' + STATE;
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				$('#tableSummary').html(buildTable(res.data.rows || []));
				renderKpis(res.data.kpi);
				if (typeof fullScreenTextSearch === 'function') { fullScreenTextSearch('#tableSummary tr', '#textSearch'); }
				$('[data-toggle="tooltip"]').tooltip();
				wireRowActions();
			}
		});
		window.xhrs.push(xhr);
	}

	function wireRowActions() {
		onClickWrap('.hideDetail', function () { $('.OIDetails').toggleClass('hidden'); });
		onClickWrap('.exportTable', function (event, tis) {
			if (typeof table2Xlsx === 'function') { table2Xlsx(tis.data('table'), tis.data('name')); }
		});
		onClickWrap('.clickrow, .loadCustomer', function (event, tis) {
			var load = tis.data('load');
			if (!load) { return; }
			loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
		}, false, true);
		onClickWrap('.addPayment', function (event, tis) {
			var id = tis.data('id');
			loadForm(LEGACY_PUR + '?action=paymentForm&id=' + id, '#modalTiny .modal-content', function () {
				$('#modalTiny').modal('show');
				masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
				$('#payAmountField').focus();
			});
		});
		$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function () {
			$('.datetimepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
			submitForm('#addPaymentForm', function (tis, result) {
				if (result) { $('#modalTiny').modal('hide'); load(); }   // tras pagar, recargar la tabla
			});
		});
	}

	$(document).ready(function () {
		// Título + etiquetas según el modo.
		$('#pageTitle').text(IS_TO_PAY ? 'Cuentas por Pagar' : 'Cuentas por Cobrar');
		$('.lblPayCobrar').text(IS_TO_PAY ? 'Total por Pagar' : 'Total por Cobrar');
		$('.lblAccounts').text(IS_TO_PAY ? 'Cuentas por Pagar' : 'Cuentas por Cobrar');

		ncmHelpers.load({
			url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					RS.tinName  = res.data.tinName  || 'TIN';
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					window.bffCompanyId = res.data.companyId || '';
					$('.bff-company-name').text(res.data.companyName || '');
				}
				load();
			}
		});
	});

})();
