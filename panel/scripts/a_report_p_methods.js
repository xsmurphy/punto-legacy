/**
 * Front del reporte Ventas por Medios de Pago (a_report_p_methods) — BFF de 3 niveles.
 *
 * Diseño IDÉNTICO al legacy; cambia la plomería de datos:
 *   - config (currency/decimal/thousand/tinName) ← GET /bff/bootstrap.php
 *   - datos (detalle + resumen, montos CRUDOS) ← GET /bff/reports/payment-methods.php
 *
 * El front NUNCA pega a /API/v1. El BFF manda datos crudos (REGLA RAÍZ 2); este JS
 * formatea los montos (fmt) y arma el markup (2 tablas + chart), escapando los campos
 * de datos. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/payment-methods.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', tinName: 'TIN' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	// Escapa texto antes de inyectarlo como markup (el front es dueño del markup).
	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Formatea un número crudo del BFF para display (el formateo es del front).
	function fmt(n) {
		return formatNumber(n || 0, '', RS.decimal, RS.thousand);
	}

	/* ───────────── markup ───────────── */

	function buildDetailTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Documento No.</th><th>Cliente</th><th>' + esc(RS.tinName) + '</th><th>Método</th>' +
			'<th>Detalle</th><th>Sucursal</th>' +
			'<th class="text-center">Entregado</th><th class="text-center">Total</th><th class="text-center">Vendido</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr data-load="/a_report_transactions?action=edit&id=' + esc(r.transactionId) + '&ro=1" class="clickrow" data-type="' + esc(r.methodType) + '">' +
				'<td class="font-bold">' + esc(r.invoiceNo) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td>' + esc(r.customerTin) + '</td>' +
				'<td>' + esc(r.methodName) + '</td>' +
				'<td>' + esc(r.extra) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + r.price + '" data-format="money">' + fmt(r.price) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + r.total + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + r.txnTotal + '" data-format="money">' + fmt(r.txnTotal) + '</td>' +
				'</tr>';
		});

		var foot =
			'</tbody><tfoot><tr><th colspan="3">TOTALES</th><th></th><th></th><th></th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th></tr></tfoot>';

		return head + body + foot;
	}

	function buildSummaryTable(rows) {
		var head = '<thead class="text-u-c"><tr><th>Método</th><th class="text-center">Total</th></tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body += '<tr data-type="' + esc(r.type) + '"><td>' + esc(r.name) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + r.price + '" data-format="money">' + fmt(r.price) + '</td></tr>';
		});
		var foot = '</tbody><tfoot class="text-u-c"><tr><th>Total</th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function drawChart(summary) {
		if (!summary.length) { return; }
		$('#myChart').removeClass('hidden');
		$('#loadingChart').addClass('hidden');

		var ctx = document.getElementById('myChart').getContext('2d');
		var gradientStroke = ctx.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, '#4cb6cb');
		gradientStroke.addColorStop(1, '#54cfc7');

		Chart.defaults.global.responsive = true;
		Chart.defaults.global.maintainAspectRatio = false;
		Chart.defaults.global.legend.display = false;

		var data = {
			labels: summary.map(function (r) { return r.name; }),
			datasets: [{ label: 'Total', data: summary.map(function (r) { return r.price; }), backgroundColor: gradientStroke }]
		};

		setTimeout(function () {
			new Chart(ctx, { type: 'bar', data: data, animation: true, options: chartBarStackedGraphOptions });
		}, 200);
	}

	function loadAll() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var detail = res.data.detail || [];
				var summary = res.data.summary || [];

				// Tabla Resumido (.table2)
				ncmDataTables({
					"container": ".tableGeneralContainer", "url": url, "rawUrl": url,
					"iniData": buildSummaryTable(summary), "table": ".table2", "sort": 0,
					"footerSumCol": [1], "currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "nolimit": true, "tableName": 'tableMethodsGeneral',
					"fileTitle": 'Ranking de Medios de Pago',
					"ncmTools": { left: '', right: '' },
					"colsFilter": { name: 'methodsGeneral', menu: [
						{ "index": 0, "name": "Medio", "visible": true },
						{ "index": 1, "name": "Total", "visible": true }
					] }
				});

				drawChart(summary);

				// Tabla Detallado (.table1)
				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildDetailTable(detail), "table": ".table1", "sort": 0,
					"footerSumCol": [6], "currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "nolimit": true, "tableName": 'tableMethods',
					"fileTitle": 'Medios de Pago Detallado',
					"ncmTools": { left: '', right: '' },
					"colsFilter": { name: 'methodsDetails2', menu: [
						{ "index": 0, "name": "# Documento", "visible": true },
						{ "index": 1, "name": "Cliente", "visible": true },
						{ "index": 2, "name": RS.tinName, "visible": true },
						{ "index": 3, "name": "Medio", "visible": true },
						{ "index": 4, "name": 'Detalle', "visible": true },
						{ "index": 5, "name": 'Sucursal', "visible": true },
						{ "index": 6, "name": 'Entregado', "visible": true },
						{ "index": 7, "name": 'Total', "visible": true },
						{ "index": 8, "name": 'Vendido', "visible": true }
					] },
					"clickCB": function (event, tis) {
						var load = tis.data('load');
						loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
					}
				});
			}
		});
		window.xhrs.push(xhr);
	}

	$(document).ready(function () {

		// 1) Config de la company desde el BFF.
		ncmHelpers.load({
			url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					RS.tinName  = res.data.tinName  || 'TIN';
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadAll();
			}
		});

		// 2) Date-picker: re-fetchea del BFF (sin recargar la página).
		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadAll();
		});
	});

})();
