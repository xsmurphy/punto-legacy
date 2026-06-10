/**
 * Front del reporte Resumen Anual de Ingresos y Egresos (a_report_summary_year) — BFF 3 niveles.
 *
 *   - config                                   ← GET /bff/bootstrap.php
 *   - { year, years, months, average } (CRUDO) ← GET /bff/reports/summary_year.php?y=
 *
 * El BFF ya derivó netTotal/revenue/margen + promedio (cross-data, números crudos); este JS
 * formatea, mapea mes→nombre, arma la tabla + el chart (barras Ingresos + líneas Egresos/Margen
 * con anotaciones promedio/COVID/fin de año) y construye el selector de año desde years[].
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/summary_year.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
		'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

	var YEAR = (function () {
		var m = (location.hash || '').match(/[?&]y=(\d{4})/);
		return m ? m[1] : String(new Date().getFullYear());
	})();

	function mesName(n) { return MESES[(parseInt(n, 10) || 1) - 1] || ''; }
	function fmt(n)     { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}

	function buildTable(months) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Mes</th><th class="text-center">Nuevos Clientes</th><th class="text-center">Ventas</th>' +
			'<th class="text-center">Descuentos</th><th class="text-center">Ingresos</th>' +
			'<th class="text-center">Egresos</th><th class="text-center">Margen</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(months, function (i, m) {
			var mNo = parseInt(m.month, 10) || 1;
			body +=
				'<tr class="clickrow pointer" data-month="' + mNo + '">' +
				'<td class="font-bold" data-order="' + (mNo - 1) + '">' + mesName(mNo) + '</td>' +
				'<td data-order="' + (parseInt(m.customers, 10) || 0) + '" class="text-right bg-light lter"> ' + fmtQty(m.customers) + ' </td>' +
				'<td data-order="' + (parseInt(m.count, 10) || 0) + '" class="text-right bg-light lter"> ' + fmtQty(m.count) + ' </td>' +
				'<td data-order="' + (parseFloat(m.discount) || 0) + '" class="text-right bg-light lter"> ' + esc(RS.currency) + ' ' + fmt(m.discount) + ' </td>' +
				'<td data-order="' + (parseFloat(m.netTotal) || 0) + '" class="text-right bg-light lter"> ' + esc(RS.currency) + ' ' + fmt(m.netTotal) + ' </td>' +
				'<td data-order="' + (parseFloat(m.expensesTotal) || 0) + '" class="text-right bg-light lter"> ' + esc(RS.currency) + ' ' + fmt(m.expensesTotal) + ' </td>' +
				'<td data-order="' + (parseFloat(m.margin) || 0) + '" class="text-right bg-light lter"> ' + fmtQty(m.margin) + '% </td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th>TOTAL</th><th colspan="6"></th></tr></tfoot>';
		return head + body + foot;
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function buildYearPicker(years, year) {
		years = years || [];
		if (years.length <= 1) {
			return '<a href="#" class="btn btn-default btn-rounded text-u-c font-bold disabled" disabled>' +
				'<span class="material-icons m-r-xs">insert_chart_outlined</span>' + esc(year) + '</a>';
		}
		var items = '';
		$.each(years, function (i, y) {
			items += '<li><a class="text-default yearOpt" href="#" data-year="' + esc(y) + '">' + esc(y) + '</a></li>';
		});
		return '<span class="btn-group">' +
			'<span class="dropdown" title="Año">' +
			'<a href="#" class="btn dropdown-toggle b b-light bg-white font-bold r-3x" data-toggle="dropdown" aria-expanded="false" id="yearPickerBtn">' +
			'<span class="material-icons m-r-xs">insert_chart_outlined</span>' + esc(year) +
			'</a>' +
			'<ul class="dropdown-menu animated fadeIn speed-4x" role="menu">' + items + '</ul>' +
			'</span></span>';
	}

	function drawChart(months, average, year) {
		if (!months.length) { return; }

		$('#summaryYearChart').removeClass('hidden');
		$('#loadingChart').addClass('hidden');

		var labels = months.map(function (m) { return mesName(m.month); });
		var data   = months.map(function (m) { return parseFloat(m.netTotal) || 0; });       // Ingresos (barras)
		var dataB  = months.map(function (m) { return parseFloat(m.expensesTotal) || 0; });   // Egresos (línea)
		var dataC  = months.map(function (m) { return parseFloat(m.revenue) || 0; });         // Margen (línea)

		// Anotaciones (texto = presentación, se arma acá).
		var annots = [];
		annots.push({ type: 'line', id: 'hlineAvg', mode: 'horizontal', scaleID: 'y-axis-0',
			value: average, borderColor: '#1ab667', borderWidth: 2, borderDash: [2, 7], borderDashOffset: 5,
			label: { backgroundColor: 'rgba(77,93,110,0.6)', enabled: true, position: 'left',
				content: 'Promedio ' + esc(RS.currency) + ' ' + fmt(average) } });
		// COVID-19 (sólo 2020, marca marzo si hay datos).
		if (String(year) === '2020' && months.some(function (m) { return parseInt(m.month, 10) === 3; })) {
			annots.push({ type: 'line', id: 'vlineCovid', mode: 'vertical', scaleID: 'x-axis-0',
				value: 'Marzo', borderColor: '#f05050', borderWidth: 2, borderDash: [2, 7], borderDashOffset: 5,
				label: { backgroundColor: 'rgba(77,93,110,0.6)', enabled: true, position: 'end', content: 'COVID-19' } });
		}
		annots.push({ type: 'line', id: 'vlineYearEnd', mode: 'vertical', scaleID: 'x-axis-0',
			value: 'Diciembre', borderColor: '#1ab667', borderWidth: 2, borderDash: [2, 7], borderDashOffset: 5,
			label: { backgroundColor: 'rgba(77,93,110,0.6)', enabled: true, position: 'right', content: 'Fin de Año' } });

		var dataD = {
			labels: labels,
			datasets: [
				{ label: 'Egresos', data: dataB, type: 'line', backgroundColor: chartSecondColor, borderColor: chartSecondColor,
					pointColor: chartSecondColor, pointHoverRadius: 8, pointHoverBorderColor: '#fff',
					pointHoverBackgroundColor: chartSecondColor, pointBorderColor: chartSecondColor, pointBackgroundColor: chartSecondColor,
					pointRadius: 3, pointHoverBorderWidth: 3, pointBorderWidth: 1, pointHitRadius: 20, borderWidth: 3, fill: false },
				{ label: 'Margen', data: dataC, type: 'line', borderColor: '#FF9469',
					pointColor: '#FF9469', pointHoverRadius: 8, pointHoverBorderColor: '#fff',
					pointHoverBackgroundColor: '#FF9469', pointBorderColor: '#FF9469', pointBackgroundColor: '#FF9469',
					pointRadius: 3, pointHoverBorderWidth: 3, pointBorderWidth: 1, pointHitRadius: 20, borderWidth: 3, fill: false },
				{ label: 'Ingresos', data: data, backgroundColor: '#01D7A1' }
			]
		};

		Chart.defaults.global.responsive          = true;
		Chart.defaults.global.maintainAspectRatio = false;
		Chart.defaults.global.legend.display       = false;

		setTimeout(function () {
			chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
			chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;
			chartBarStackedGraphOptions.annotation = { drawTime: 'afterDatasetsDraw', annotations: annots };
			new Chart($('#summaryYearChart'), { type: 'bar', data: dataD, animation: true, options: chartBarStackedGraphOptions });
			chartBarStackedGraphOptions.annotation = {};
		}, 200);
	}

	function loadYear(year) {
		var url = BFF + '?y=' + encodeURIComponent(year);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var d = res.data || {};
				var months = d.months || [];

				$('#yearPickerContainer').html(buildYearPicker(d.years, d.year || year));
				bindYearPicker();

				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(months), "table": ".table1", "sort": 0,
					"footerSumCol": [1, 2, 3, 4, 5],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "noMoreBtn": true,
					"tableName": 'tableYear', "fileTitle": 'Resumen de ventas anual',
					"ncmTools": { left: '', right: '' }
				}, function () {
					onClickWrap('.clickrow', function (event, tis) {
						window.location.href = '/@#report_summary';
					}, false, true);
				});

				drawChart(months, parseFloat(d.average) || 0, d.year || year);
			}
		});
		window.xhrs.push(xhr);
	}

	function bindYearPicker() {
		onClickWrap('.yearOpt', function (event, tis) {
			var y = tis.data('year');
			if (y) { YEAR = String(y); loadYear(YEAR); }
		}, false, true);
	}

	$(document).ready(function () {
		ncmHelpers.load({
			url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadYear(YEAR);
			}
		});
	});

})();
