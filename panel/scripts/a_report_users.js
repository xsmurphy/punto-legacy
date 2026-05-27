/**
 * Front del reporte Ventas por Usuarios / Recursos (a_report_users) — BFF de 3 niveles.
 *
 *   - config (currency/decimal/thousand/companyId) ← GET /bff/bootstrap.php
 *   - datos (filas por usuario + totales, CRUDOS)   ← GET /bff/reports/users.php
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS formatea, arma KPIs + chart + tabla,
 * y escapa los campos de datos. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/users.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', companyId: '', publicUrl: '' };

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
			'<thead class="text-u-c"><tr>' +
			'<th>Nombre</th><th class="text-center">Ventas</th><th class="text-center">Cantidad</th>' +
			'<th class="text-center">Comisiones</th><th class="text-center">Descuentos</th>' +
			'<th class="text-center">Subtotal</th><th class="text-center">Total</th><th></th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var count = parseFloat(r.count) || 0, usold = parseFloat(r.usold) || 0,
			    comm  = parseFloat(r.comission) || 0, disc = parseFloat(r.discount) || 0,
			    total = parseFloat(r.total) || 0;
			var subtotal = total + disc;
			var hasComm  = comm > 0;
			var link = 'javascript:;', linkColor = 'text-muted', linkOff = 'disabled';
			if (hasComm) {
				var s = btoa(RS.companyId + ',' + r.userId + ',' + FROM + ',' + TO);
				link = RS.publicUrl + '/userItemsSold?s=' + s; // PUBLIC_URL = <host>/screens
				linkColor = 'text-info';
				linkOff = '';
			}

			body +=
				'<tr class="pointer clickrow" data-url="#report_user_comissions?ui=' + esc(r.userId) + '">' +
				'<td class="font-bold" data-id="' + esc(r.userId) + '"> ' + esc(r.name) + ' </td>' +
				'<td class="text-right" data-order="' + count + '"> ' + fmtQty(count) + ' </td>' +
				'<td class="text-right" data-order="' + usold + '"> ' + fmtQty(usold) + ' </td>' +
				'<td class="text-right bg-light lter" data-order="' + comm + '" data-format="money"> ' + fmt(comm) + ' </td>' +
				'<td class="text-right bg-light lter" data-order="' + disc + '" data-format="money"> ' + fmt(disc) + ' </td>' +
				'<td class="text-right bg-light lter" data-order="' + subtotal + '" data-format="money"> ' + fmt(subtotal) + ' </td>' +
				'<td class="text-right bg-light lter" data-order="' + total + '" data-format="money"> ' + fmt(total) + ' </td>' +
				'<td class="text-center"><a href="' + link + '" class="openLink hidden-print noxls ' + linkOff + '" target="_blank" data-toggle="tooltip" title="Detalle de Comisiones"><i class="material-icons ' + linkColor + '">open_in_new</i></a></td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th>TOTALES:</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'</tr></tfoot>';

		return head + body + foot;
	}

	function drawChart(rows) {
		var labels = [], data = [];
		$.each(rows, function (i, r) {
			if ((parseFloat(r.total) || 0) > 0) { labels.push(r.name); data.push(parseFloat(r.total)); }
		});
		if (!data.length) { return; }

		$('#myChart').removeClass('hidden');
		$('#loadingChart').addClass('hidden');

		Chart.defaults.global.legend.display = false;
		Chart.defaults.global.responsive = true;
		Chart.defaults.global.maintainAspectRatio = false;

		var ctx = $('#myChart')[0].getContext('2d');
		var gradientStroke = ctx.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, '#4cb6cb');
		gradientStroke.addColorStop(1, '#54cfc7');

		setTimeout(function () {
			new Chart(ctx, {
				type: 'bar', animation: true, options: chartBarStackedGraphOptions,
				data: { labels: labels, datasets: [{ label: 'Total', data: data, backgroundColor: gradientStroke }] }
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
					"iniData": buildTable(rows), "table": ".table1", "sort": 0,
					"footerSumCol": [1, 2, 3, 4, 5, 6],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 2000, "nolimit": false, "noMoreBtn": true,
					"tableName": 'tableUsers', "fileTitle": 'Reporte de Usuarios',
					"ncmTools": { left: '', right: '' },
					"clickCB": function (event, tis) { window.location = tis.data('url'); }
				}, function () {
					onClickWrap('.openLink', function (event, tis) {
						var u = tis.attr('href');
						if (u && u !== 'javascript:;') { window.open(u, '_blank'); }
					}, false, true);
				});

				$('.globalSales').text(fmtQty(t.count));
				$('.globalQty').text(fmtQty(t.usold));
				$('.globalDiscount').html('<span class="text-muted text-lg">' + esc(RS.currency) + '</span> ' + fmt(t.discount));
				$('.globalTotal').html('<span class="text-muted text-lg">' + esc(RS.currency) + '</span> ' + fmt(t.total));

				drawChart(rows);
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
					RS.currency  = res.data.currency || '';
					RS.decimal   = res.data.decimal  || 'no';
					RS.thousand  = res.data.thousand || 'dot';
					RS.companyId = res.data.companyId || '';
					RS.publicUrl = res.data.publicUrl || '';
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
