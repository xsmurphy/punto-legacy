/**
 * Front del reporte Ventas por Marca (a_report_brands) — BFF de 3 niveles.
 *
 *   - config (currency/decimal/thousand/taxName) ← GET /bff/bootstrap.php
 *   - datos (filas por marca + totales, CRUDOS)   ← GET /bff/reports/brands.php
 *
 * El BFF manda datos crudos + % + subtotal por fila (REGLA RAÍZ 2); este JS formatea,
 * arma KPIs + treemap + tabla (con barra de %), y escapa los datos. Ver REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/brands.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA' };

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

	function buildTable(rows) {
		var head =
			'<thead><tr>' +
			'<th>Nombre</th><th class="text-center">Unidades</th><th class="text-center">Costo</th>' +
			'<th class="text-center">' + esc(RS.taxName) + '</th><th class="text-center">Descuentos</th>' +
			'<th class="text-center">Subtotal</th><th class="text-center">Total</th>' +
			'<th class="text-center" style="max-width:15%">Porcentaje</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var usold = parseFloat(r.usold) || 0, cogs = parseFloat(r.cogs) || 0, tax = parseFloat(r.tax) || 0,
			    disc = parseFloat(r.discount) || 0, subtotal = parseFloat(r.subtotal) || 0, total = parseFloat(r.total) || 0;
			var pct = parseInt(r.percent, 10) || 0;
			var pctTxt = pct < 1 ? '<1' : pct;
			var barColor = pct > 50 ? 'success' : 'warning';
			var bar = '<div class="progress progress-xs dker progress-striped m-b-n m-t-sm">' +
				'<div class="progress-bar bg-' + barColor + '" data-toggle="tooltip" data-original-title="' + pctTxt + '%" style="width: ' + pct + '%"></div></div>' +
				'<span class="hidden">' + pctTxt + '%</span>';

			body +=
				'<tr><td class="bg-light lter"> ' + esc(r.name) + ' </td>' +
				'<td class="text-right" data-order="' + usold + '"> ' + fmt(usold) + ' </td>' +
				'<td class="text-right" data-order="' + cogs + '" data-format="money"> ' + fmt(cogs) + ' </td>' +
				'<td class="text-right" data-order="' + tax + '" data-format="money"> ' + fmt(tax) + ' </td>' +
				'<td class="text-right" data-order="' + disc + '" data-format="money"> ' + fmt(disc) + ' </td>' +
				'<td class="text-right" data-order="' + subtotal + '" data-format="money"> ' + fmt(subtotal) + ' </td>' +
				'<td class="text-right" data-order="' + total + '" data-format="money"> ' + fmt(total) + ' </td>' +
				'<td class="text-right" data-order="' + pct + '"> ' + bar + ' </td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th>TOTALES</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right">100%</th></tr></tfoot>';
		return head + body + foot;
	}

	function drawTreemap(rows) {
		var tree = [];
		$.each(rows, function (i, r) {
			tree.push({ title: r.name === 'None' ? 'Sin marca' : r.name, total: parseFloat(r.usold) || 0 });
		});
		if (!tree.length) { return; }

		$('#myChart').removeClass('hidden');
		$('#loadingChart').addClass('hidden');

		var ctx = document.getElementById('myChart').getContext('2d');
		var gradientStroke = ctx.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, '#4cb6cb');
		gradientStroke.addColorStop(1, '#54cfc7');

		setTimeout(function () {
			var tt = ncmHelpers.cloneObj(chartTooltipStyle);
			tt.tooltips.callbacks.title = function () { return false; };
			tt.tooltips.callbacks.label = function (item, data) {
				var di = data.datasets[item.datasetIndex].data[item.index];
				return di.g + ': ' + di.v;
			};
			new Chart(ctx, {
				type: 'treemap',
				data: { datasets: [{
					tree: tree, backgroundColor: gradientStroke, spacing: 3, borderWidth: 0,
					borderColor: 'rgba(180,180,180, 0.15)', key: 'total', groups: ['title'],
					fontColor: '#fff', fontFamily: 'Source Sans Pro'
				}] },
				options: { maintainAspectRatio: false, title: { display: false }, legend: { display: false }, tooltips: tt.tooltips }
			});
		}, 200);
	}

	function loadAll() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var rows = res.data.rows || [];
				var t    = res.data.totals || {};

				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(rows), "table": ".table1", "sort": 1,
					"footerSumCol": [1, 2, 3, 4, 5, 6],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "nolimit": true,
					"tableName": 'tableBrands', "fileTitle": 'Ranking de Marcas',
					"ncmTools": { left: '', right: '' }
				});

				$('.globalUsold').html(fmtQty(t.usold));
				$('.globalDiscount').html('<span class="text-muted text-lg">' + esc(RS.currency) + '</span> ' + fmt(t.discount));
				$('.globalTotal').html('<span class="text-muted text-lg">' + esc(RS.currency) + '</span> ' + fmt(t.total));
				$('.globalTax').html('<span class="text-muted text-lg">' + esc(RS.currency) + '</span> ' + fmt(t.tax));

				drawTreemap(rows);
				$('[data-toggle="tooltip"]').tooltip();
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
					RS.taxName  = res.data.taxName  || 'IVA';
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-taxname').text(RS.taxName);
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
