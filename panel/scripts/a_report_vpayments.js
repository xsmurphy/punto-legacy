/**
 * Front de Pagos ePOS / vPayments (a_report_vpayments) — BFF de 3 niveles.
 *
 *   - config              ← GET /bff/bootstrap.php
 *   - datos (rows + kpi)   ← GET /bff/reports/vpayments.php?from&to   (gateway externo en la API)
 *
 * El BFF/API devuelven registros CRUDOS + totales; este JS formatea TODO + arma la tabla, los 3
 * KPIs y el donut. Read-only. El click en una fila abre el comprobante en transactions (legacy).
 */
(function () {

	var BFF       = '/bff/reports/vpayments.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY_TX = '/a_report_transactions';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };
	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');
	var donut = null;

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function num(v) { return parseFloat(v) || 0; }
	function dateOnly(iso) { var m = moment(iso); return m.isValid() ? m.format('YYYY-MM-DD') : '-'; }

	function statusLabel(r) {
		var color = 'light', name = 'Pendiente';
		if (r.deposited) { color = 'info'; name = 'Acreditado'; }
		if (r.status === 'REVIEW') { name = 'En Revisión'; }
		return '<span class="label bg-' + color + ' lter text-u-c">' + name + '</span>';
	}
	function medio(r) {
		var m = '-';
		if (r.accountType === 'TC') { m = 'T. Crédito'; }
		else if (r.accountType === 'TD') { m = 'T. Débito'; }
		else if (r.accountType === 'DC') { m = 'Débito'; }
		else if (r.brand) { m = r.brand; }
		return '<span class="badge bg-light lter">' + esc(m) + '</span>';
	}
	function source(r) {
		var s = 'Físico';
		if (r.source === 'bancardQROnline' || r.source === 'dinelcoVPOS') { s = 'Online'; }
		else if (r.source === 'bancardQR') { s = 'QR'; }
		return '<span class="badge bg-light lter">' + esc(s) + '</span>';
	}

	function buildTable(rows) {
		var head = '<thead><tr>' +
			'<th>Estado</th><th>Fecha</th><th>Fecha de Acreditación</th><th>Cod. de Autorización</th>' +
			'<th>Nro. de Operación</th><th>Fuente</th><th>Medio</th><th>Sucursal</th>' +
			'<th class="text-center">Total</th><th class="text-center">Acreditar</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var load = (r.eUID && r.eUID.length > 3) ? (LEGACY_TX + '?action=edit&uid=' + esc(r.eUID) + '&ro=1') : '';
			body += '<tr data-load="' + load + '" class="' + (load ? 'clickrow pointer' : '') + '">' +
				'<td>' + statusLabel(r) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + esc(r.date) + '</td>' +
				'<td data-order="' + esc(r.payoutDate) + '">' + (r.payoutDate ? dateOnly(r.payoutDate) : '-') + '</td>' +
				'<td>' + esc(r.authCode) + '</td>' +
				'<td>' + esc(r.operationNo) + '</td>' +
				'<td>' + source(r) + '</td>' +
				'<td>' + medio(r) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td class="text-right" data-order="' + num(r.amount) + '" data-format="money">' + fmt(r.amount) + '</td>' +
				'<td class="text-right" data-order="' + num(r.payoutAmount) + '" data-format="money">' + fmt(r.payoutAmount) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="8" class="font-bold">Total</th><th class="font-bold text-right"></th><th class="font-bold text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function renderKpis(k) {
		if (!k) { return; }
		$('.approvedChart').text(fmt(k.sold));
		$('.depositedChart').text(fmt(k.deposited));
		$('.pendingDepositChart').text(fmt(k.pendingDeposit));
		$('.totalsChart').text(k.count || 0);
		var ctx = document.getElementById('chart-contado');
		if (!ctx || typeof Chart === 'undefined') { return; }
		Chart.defaults.global.responsive = true;
		Chart.defaults.global.maintainAspectRatio = false;
		if (Chart.defaults.global.legend) { Chart.defaults.global.legend.display = false; }
		if (donut) { donut.destroy(); }
		donut = new Chart(ctx.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: ['Vendido', 'Acreditado', 'Pendiente'],
				datasets: [{ data: [num(k.sold), num(k.deposited), num(k.pendingDeposit)], backgroundColor: ['#6BC0D1', '#778490', '#d9e4e6'] }]
			},
			options: { cutoutPercentage: 85, maintainAspectRatio: false }
		});
	}

	function load() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#generalTable", "url": url, "iniData": buildTable(res.data.rows || []),
					"table": ".table1", "sort": 1, "footerSumCol": [8, 9],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableVPayments', "fileTitle": 'Listado ePOS',
					"ncmTools": { left: '', right: '' },
					"colsFilter": { name: 'vPayments7', menu: [
						{ index: 0, name: 'Estado', visible: true }, { index: 1, name: 'Fecha', visible: true },
						{ index: 2, name: 'Fecha de Acreditación', visible: false }, { index: 3, name: 'Cod. Autorización', visible: true },
						{ index: 4, name: 'Nro. Operación', visible: false }, { index: 5, name: 'Fuente', visible: false },
						{ index: 6, name: 'Medio', visible: true }, { index: 7, name: 'Sucursal', visible: false },
						{ index: 8, name: 'Venta', visible: true }, { index: 9, name: 'Acreditación', visible: false }
					] },
					"clickCB": function (event, tis) {
						var load = tis.data('load');
						if (!load) { return; }
						loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
					}
				});
				renderKpis(res.data.kpi);
			}
		});
		window.xhrs.push(xhr);
	}

	$(document).ready(function () {
		ncmHelpers.load({
			url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					$('.bff-company-name').text(res.data.companyName || '');
				}
				load();
			}
		});

		dateRangePickerForReports(moment(FROM, 'YYYY-MM-DD HH:mm:ss'), moment(TO, 'YYYY-MM-DD HH:mm:ss'));
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			load();
		});
	});

})();
