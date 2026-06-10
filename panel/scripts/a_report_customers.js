/**
 * Front del reporte Clientes (a_report_customers) — BFF de 3 niveles.
 *
 *   - config                          ← GET /bff/bootstrap.php
 *   - { rows, global, chart } (CRUDO) ← GET /bff/reports/customers.php?from=&to=
 *
 * El BFF deriva neto/promedio + totales + chart (cross-data, crudo); este JS formatea TODO,
 * mapea fechas/tags y arma KPIs + chart top-15 + tabla. esc() en datos. Ver REGLA RAÍZ 2.
 *
 * Alcance: núcleo (KPIs + ranking + chart). Extras exploratorios del legacy (mapa, recurrentes,
 * comportamiento) diferidos — ver reports/customers.html y 10-roadmap § Phase AI.
 */
(function () {

	var BFF       = '/bff/reports/customers.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}
	function niceDate(iso) {
		if (!iso) { return ''; }
		var m = moment(iso);
		return m.isValid() ? m.format('DD-MM-YYYY') : '';
	}
	function tagsHtml(tags) {
		if (!tags || !tags.length) { return ''; }
		return $.map(tags, function (t) { return '<span class="label bg-info m-r-xs">' + esc(t) + '</span>'; }).join(' ');
	}

	function buildTable(rows) {
		var cur = esc(RS.currency);
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Razón Social</th><th>' + esc(RS.tinName || 'RUC') + '</th><th>Nombre y Apellido</th><th>Doc. de Identidad</th>' +
			'<th>Cumpleaños</th><th>Email</th><th>Teléfono</th><th>Teléfono 2</th><th>Dirección</th>' +
			'<th>Localidad</th><th>Ciudad</th><th>Etiquetas</th>' +
			'<th class="text-center">Loyalty</th><th class="text-center">No. de Compras</th>' +
			'<th class="text-center">Gasto Promedio</th><th class="text-center hidden-xs">Unidades Adquiridas</th>' +
			'<th class="text-center hidden-xs">Descuentos Obtenidos</th><th class="text-center hidden-xs">Subtotal</th>' +
			'<th class="text-center">Total</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr class="clickrow pointer" data-id="' + esc(r.customerId) + '" data-load="/a_contacts?action=form&id=' + esc(r.customerId) + '&type=wl&ro=1">' +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.ruc) + '</td>' +
				'<td>' + esc(r.secondName) + '</td>' +
				'<td>' + esc(r.ci) + '</td>' +
				'<td>' + niceDate(r.bday) + '</td>' +
				'<td>' + esc(r.email) + '</td>' +
				'<td>' + esc(r.phone) + '</td>' +
				'<td>' + esc(r.phone2) + '</td>' +
				'<td>' + esc(r.address) + '</td>' +
				'<td>' + esc(r.location) + '</td>' +
				'<td>' + esc(r.city) + '</td>' +
				'<td>' + tagsHtml(r.tags) + '</td>' +
				'<td class="text-right" data-order="' + (parseFloat(r.loyalty) || 0) + '">' + fmt(r.loyalty) + '</td>' +
				'<td class="text-right" data-order="' + (parseInt(r.count, 10) || 0) + '">' + fmtQty(r.count) + '</td>' +
				'<td class="text-right" data-order="' + (parseFloat(r.average) || 0) + '">' + cur + ' ' + fmt(r.average) + '</td>' +
				'<td class="text-right" data-order="' + (parseFloat(r.usold) || 0) + '">' + fmtQty(r.usold) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + (parseFloat(r.discount) || 0) + '" data-format="money">' + cur + ' ' + fmt(r.discount) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + (parseFloat(r.subtotal) || 0) + '" data-format="money">' + cur + ' ' + fmt(r.subtotal) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + (parseFloat(r.net) || 0) + '" data-format="money">' + cur + ' ' + fmt(r.net) + '</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th colspan="12">TOTALES:</th><th colspan="7"></th></tr></tfoot>';
		return head + body + foot;
	}

	function renderKpis(g) {
		var cur = esc(RS.currency) + ' ';
		$('#globalSales').html(cur + fmt(g.sales));
		$('#globalQty').html(fmtQty(g.qty));
		$('#globalDiscount').html(cur + fmt(g.discount));
		$('#globalTotal').html(cur + fmt(g.total));
	}

	function drawChart(chart) {
		if (!chart || !chart.data || !chart.data.length) { return; }
		$('#customersChart').removeClass('hidden');
		$('#loadingChart').addClass('hidden');

		var ctx = document.getElementById('customersChart').getContext('2d');
		var grad = ctx.createLinearGradient(0, 0, 0, 300);
		grad.addColorStop(0, '#01D7A1');
		grad.addColorStop(1, '#54cfc7');

		Chart.defaults.global.responsive          = true;
		Chart.defaults.global.maintainAspectRatio = false;

		setTimeout(function () {
			new Chart(ctx, {
				type: 'bar',
				data: {
					labels: chart.label,
					datasets: [{ data: chart.data, backgroundColor: grad, borderWidth: 0 }]
				},
				options: {
					maintainAspectRatio: false,
					legend: { display: false },
					title: { display: false }
				}
			});
		}, 200);
	}

	function loadAll() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var d = res.data || {};

				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(d.rows || []), "table": ".table1", "sort": 18,
					"footerSumCol": [13, 15, 16, 17, 18],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 100, "nolimit": true,
					"tableName": 'tableCustomers', "fileTitle": 'Clientes',
					"ncmTools": { left: '', right: '' }
				}, function () {
					onClickWrap('.clickrow', function (event, tis) {
						var load = tis.data('load');
						if (load) { loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); }); }
					}, false, true);
					onClickWrap('.exportTable', function (event, tis) { table2Xlsx(tis.data('table'), tis.data('name')); }, false, true);
				});

				renderKpis(d.global || { sales: 0, qty: 0, discount: 0, total: 0 });
				drawChart(d.chart || { label: [], data: [] });
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
					RS.tinName  = res.data.tinName  || 'RUC';
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadAll();
			}
		});

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
