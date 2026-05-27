/**
 * Front del reporte Resumen de Ingresos (a_report_summary) — BFF de 3 niveles.
 *
 * Diseño IDÉNTICO al legacy; cambia solo la plomería de datos:
 *   - config (currency/decimal/thousand/taxName) ← GET /bff/bootstrap.php
 *   - KPIs + tablas Resumen                       ← GET /bff/reports/summary.php?view=kpis
 *   - gráfico de ingresos                         ← GET /bff/reports/summary.php?view=chart
 *   - ventas por hora                             ← GET /bff/reports/summary.php?view=hours
 *   - pestaña Por Día                             ← GET /bff/reports/summary.php?view=byday
 *
 * El front NUNCA pega a /API/v1 (siempre al BFF). El BFF manda SOLO datos crudos (números,
 * fechas ISO, comparaciones como datos, promedio crudo); este front formatea TODO lo
 * presentacional (números, fechas, %, texto de anotación) y arma el markup. REGLA RAÍZ 2
 * en context/02-arquitectura.md.
 */
(function () {

	var BFF       = '/bff/reports/summary.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	/* ───────────── formateo (presentación — vive en el front) ───────────── */

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Número crudo → string de display (currency/decimal/thousand del bootstrap).
	function fmt(n) {
		return formatNumber(n || 0, '', RS.decimal, RS.thousand);
	}

	// Cantidad: entero sin decimales, con decimales si los tiene (= formatQty del panel).
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand)
		                     : formatNumber(v, '', 'yes', RS.thousand);
	}

	var MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
	var DIAS  = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

	// Fecha corta "26 May, 2026" (= niceDate no-literal del panel) — tabla Por Día.
	function niceDateJs(date) {
		var d = moment(date);
		if (!d.isValid()) { return 'No date'; }
		return d.format('DD') + ' ' + MESES[d.month()] + ', ' + d.format('YYYY');
	}

	// Fecha literal "Lun 26, May 2026" (= niceDate literal del panel) — labels del gráfico.
	function niceDateLiteral(date) {
		var d = moment(date);
		if (!d.isValid()) { return 'No date'; }
		return DIAS[d.day()] + ' ' + d.format('DD') + ', ' + MESES[d.month()] + ' ' + d.format('YYYY');
	}

	// Span de comparación desde el objeto {dir,pct,prev(crudo),positive} del BFF.
	function buildCmpSpan(c) {
		if (!c) { return '...'; }
		var icon  = c.dir === 'up' ? 'trending_up' : (c.dir === 'down' ? 'trending_down' : 'trending_flat');
		var color = c.positive === true ? 'text-success' : (c.positive === false ? 'text-danger' : 'text-muted');
		return '<span class="' + color + ' pointer" data-toggle="tooltip" title="Periodo anterior: ' + fmt(c.prev) + '"><i class="material-icons">' + icon + '</i> ' + c.pct + '%</span>';
	}

	/* ───────────── carga de datos (BFF) ───────────── */

	function bffLoad(view, success) {
		var xhr = ncmHelpers.load({
			url         : BFF + '?view=' + view + '&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO),
			httpType    : 'GET',
			hideLoader  : true,
			type        : 'json',
			warnTimeout : false,
			success     : function (res) {
				if (res && res.ok) { success(res.data); }
			}
		});
		window.xhrs.push(xhr);
	}

	function renderKpis(d) {
		var cur = d.current || {};
		var cmp = d.compare || {};

		$('#globalSubtotal').html(fmt(cur.grossSales));
		$('#globalSubtotalB').html(buildCmpSpan(cmp.grossSales));

		$('#globalCogs').html(fmt(cur.totalReturns));
		$('#globalCogsB').html(buildCmpSpan(cmp.totalReturns));

		$('#globalDiscount').html(fmt(cur.totalDiscounts));
		$('#globalDiscountB').html(buildCmpSpan(cmp.totalDiscounts));

		$('#globalUtility').html(fmt(cur.netSales));
		$('#globalUtilityB').html(buildCmpSpan(cmp.netSales));

		// Tabla Ventas
		var salesTable =
			'<tr class="bg-light lter"><td class="font-bold"> Ventas Brutas</td><td class="text-right font-bold">' + fmt(cur.grossSales) + '</td></tr>' +
			'<tr><td> <span class="text-u-l pointer" data-toggle="tooltip" title="Pagos realizados con créditos de la empresa, Gift Cards, Crédito Interno o Puntos Loyalty">Pagos con créditos</span></td><td class="text-right">-' + fmt(cur.creditPays) + '</td></tr>' +
			'<tr><td> Devoluciones</td><td class="text-right">-' + fmt(cur.totalReturns) + '</td></tr>' +
			'<tr><td> Descuentos</td><td class="text-right">-' + fmt(cur.totalDiscounts) + '</td></tr>' +
			'<tr class="bg-light lter"><td class="font-bold"> Ventas Netas</td><td class="text-right font-bold">' + fmt(cur.netSales) + '</td></tr>' +
			'<tr><td>' + esc(RS.taxName) + '</td><td class="text-right">' + fmt(cur.totalTax) + '</td></tr>';
		$('#salesTable').html(salesTable);

		// Tabla Medios de Pago (name resuelto por la API; monto crudo → fmt; total calculado por el BFF)
		var payTable = '';
		$.each(cur.payments || [], function (i, p) {
			payTable += '<tr><td>' + esc(p.name) + '</td><td class="text-right">' + fmt(p.price) + '</td></tr>';
		});
		payTable += '<tr class="font-bold text-u-c"><td>Total</td><td class="text-right">' + fmt(cur.paymentsTotal) + '</td></tr>';
		$('#paymentsMethodsTable').html(payTable);

		// Tabla Tipos
		var typeTable =
			'<tr><td> Ventas al Contado</td><td class="text-right">' + fmt(cur.cashSales) + '</td></tr>' +
			'<tr><td> Ventas a Crédito</td><td class="text-right">' + fmt(cur.creditSales) + '</td></tr>' +
			'<tr><td class="font-bold text-u-c"> Total Bruto</td><td class="text-right font-bold">' + fmt(cur.totalBruto) + '</td></tr>';
		$('#typeSalesTable').html(typeTable);

		// Tabla Gift Cards
		var gcTable =
			'<tr><td class="font-bold text-u-c"> Vendido</td><td class="text-right font-bold">' + fmt(cur.giftcardsSold) + '</td></tr>' +
			'<tr><td> Cantidad</td><td class="text-right">' + fmtQty(cur.giftcardsCount) + '</td></tr>' +
			'<tr><td> Canjeado</td><td class="text-right">' + fmt(cur.totalGiftcardUsed) + '</td></tr>';
		$('#giftcardsTabe').html(gcTable);

		$('[data-toggle="tooltip"]').tooltip();
	}

	function renderByDay(d) {
		var rows = d.rows || [];
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Fecha</th>' +
			'<th class="text-center">Nro. de Ventas</th>' +
			'<th class="text-center">Descuentos</th>' +
			'<th class="text-center">' + esc(RS.taxName) + '</th>' +
			'<th class="text-center">Gravado</th>' +
			'<th class="text-center">Total</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			// data crudo del BFF; el front formatea el display y usa el crudo en data-order.
			body +=
				'<tr class="clickrow">' +
				' <td data-order="' + r.date + '"> ' + niceDateJs(r.date) + ' </td>' +
				' <td class="text-right" data-order="' + r.count + '"> ' + fmtQty(r.count) + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.discount + '" data-format="money"> ' + fmt(r.discount) + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.tax + '" data-format="money"> ' + fmt(r.tax) + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.subtotal + '" data-format="money"> ' + fmt(r.subtotal) + ' </td>' +
				' <td class="text-right bg-light lter" data-order="' + r.total + '" data-format="money"> ' + fmt(r.total) + ' </td>' +
				'</tr>';
		});

		var foot =
			'</tbody><tfoot><tr>' +
			'<th class="">TOTALES:</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' +
			'<th class="text-right"></th><th class="text-right"></th>' +
			'</tr></tfoot>';

		ncmDataTables({
			"container"     : "#byDayTable",
			"iniData"       : head + body + foot,
			"table"         : ".table1",
			"sort"          : 0,
			"footerSumCol"  : [1, 2, 3, 4, 5],
			"currency"      : RS.currency,
			"decimal"       : RS.decimal,
			"thousand"      : RS.thousand,
			"offset"        : 0,
			"limit"         : 100,
			"noMoreBtn"     : true,
			"tableName"     : 'tableTransactions',
			"fileTitle"     : 'Resumen Por Día',
			"ncmTools"      : { left: '', right: '' }
		});
	}

	function loadAll() {
		bffLoad('kpis',  renderKpis);
		bffLoad('chart', function (d) {
			var ch = d.chart;
			if (ch && ch.gross && ch.gross.length) {
				$('#summaryChart').removeClass('hidden');
				$('#loadingChart').addClass('hidden');
				drawChart(ch);
			} else {
				$('#summaryChart').addClass('hidden');
			}
			$('.noDayHolder').addClass(ch ? ch.noDayShow : '');
		});
		bffLoad('hours', function (d) {
			if (d.totals && d.totals.length) {
				chartByHours(d);
			} else {
				$('#hours').addClass('hidden');
			}
		});
	}

	/* ───────────── init ───────────── */

	$(document).ready(function () {

		// 1) Config de la company desde el BFF (para formatear + chrome).
		ncmHelpers.load({
			url         : BOOTSTRAP,
			httpType    : 'GET',
			hideLoader  : true,
			type        : 'json',
			warnTimeout : false,
			success     : function (res) {
				if (res && res.ok && res.data) {
					RS.currency = res.data.currency || '';
					RS.decimal  = res.data.decimal  || 'no';
					RS.thousand = res.data.thousand || 'dot';
					RS.taxName  = res.data.taxName  || 'IVA';
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadAll();
			}
		});

		// 2) Date-picker: misma UI; en vez de recargar la página, re-fetchea del BFF.
		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO,   'YYYY-MM-DD HH:mm:ss'),
			false, true
		);

		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadAll();
		});

		// 3) Pestaña "Por Día" — carga perezosa la primera vez que se abre.
		var byDayLoaded = false;
		$('#byDayTabLink').on('shown.bs.tab', function () {
			if (!byDayLoaded) {
				byDayLoaded = true;
				bffLoad('byday', renderByDay);
			}
		});

		// 4) Exportar a Excel (reusa el helper del shell).
		ncmHelpers.onClickWrap('.export', function (event, tis) {
			table2Xlsx(tis.data('table'), tis.data('name'));
		});
	});

	/* ───────────── gráficos (arman labels/anotación desde los datos crudos del BFF) ───────────── */

	function drawChart(ch){

		// Labels del eje X (presentación): se arman acá desde las fechas/horas crudas.
		var labels = ch.buckets.map(function (b, i) {
			if (ch.isDay) {
				return b + 'h del ' + niceDateLiteral(ch.periodFrom) + ' vs ' + niceDateLiteral(ch.periodFromB);
			}
			return niceDateLiteral(b) + ' vs ' + niceDateLiteral(ch.bucketsB[i]);
		});

		// Anotación del promedio (texto formateado en el front).
		var annots = [];
		if (ch.average != null) {
			annots.push({ value: ch.average, orientation: 'horizontal', text: 'Promedio ' + fmt(ch.average), color: '#1ab667', position: 'left' });
		}

		Chart.defaults.global.legend.display 		= true;
		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;

		var myChart 		= $('#summaryChart')[0].getContext("2d");
		var gradientStroke 	= myChart.createLinearGradient(1600, 0, 0, 0);
		gradientStroke.addColorStop(0, "#4cb6cb");
		gradientStroke.addColorStop(0.5, "#54cfc7");
		gradientStroke.addColorStop(1, "#54cfc7");

	    var recAnnots = [];
	    if(ncmHelpers.validity(annots)){
	      recAnnots = annots.map(function(val, index) {
	        var id        = 'vline' + index;
	        var mode      = 'vertical';
	        var scaleId   = "x-axis-0";
	        var position  = iftn(val.position,'center');

	        if(val.orientation == 'horizontal'){
	          id        = 'hline' + index;
	          mode      = 'horizontal';
	          scaleId   = "y-axis-0";
	        }

	        return {
	          type      : "line",
	          id        : id,
	          mode      : mode,
	          scaleID   : scaleId,
	          value     : val.value.toFixed(2),
	          borderColor: val.color,
	          borderWidth: 2,
	          borderDash : [2, 7],
	          borderDashOffset : 5,
	          label     : {
	            backgroundColor: 'rgba(77,93,110,0.6)',
	            enabled: true,
	            position: position,
	            content: val.text,
	            font: { size: 7 }
	          }
	        };
	      });
	    }

	    chartBarStackedGraphOptions.annotation = {
	                                              drawTime    : "afterDatasetsDraw",
	                                              annotations : recAnnots
	                                            };

		var data = {
		    labels 	: labels,
		    datasets: [
		    	{
	                label                     : "Margen",
	                data                      : ch.margin,
	                type                      : 'line',
	                borderColor               : '#FF9469',
	                pointColor                : '#FF9469',
	                pointHoverRadius          : 8,
	                pointHoverBorderColor     : "#fff",
	                pointHoverBackgroundColor : '#FF9469',
	                pointBorderColor          : '#FF9469',
	                pointBackgroundColor      : '#FF9469',
	                pointRadius               : 3,
	                pointHoverBorderWidth     : 3,
	                pointBorderWidth          : 1,
	                pointHitRadius            : 20,
	                borderWidth               : 3,
	                fill                      : false
	            },
		        {
		            label 					  	: "Ingreso Anterior",
		            data 						: ch.grossB,
		            type                      	: 'line',
		            borderColor 				: chartSecondColor,
		            pointColor 					: chartSecondColor,
		            pointHoverRadius 			: 8,
		            pointHoverBorderColor 		: "#fff",
		            pointHoverBackgroundColor 	: chartSecondColor,
		            pointBorderColor 			: chartSecondColor,
		            pointBackgroundColor 		: chartSecondColor,
		            pointRadius 				: 3,
		            pointHoverBorderWidth 		: 3,
		            pointBorderWidth 			: 3,
		            pointHitRadius 				: 20,
		            borderDash 					: [10,5],
		            borderWidth 				: 3,
		            fill 						: false
		        },
		        {
		        	type 						: 'bar',
		            label 						: "Ingreso Actual",
		            backgroundColor 			: gradientStroke,
		            data 						: ch.gross
		        },
		        {
		        	type 						: 'bar',
		            label 						: "Egresos",
		            backgroundColor 			: chartSecondColor,
		            data 						: ch.grossE
		        }
		    ]
		};

		chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
	    chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;

		var chart = new Chart(myChart, {
			type        : 'bar',
		    data 		: data,
		    animation 	: true,
		    options 	: chartBarStackedGraphOptions
		});

		chart.getDatasetMeta(3).hidden = true;
		chart.update();

		chartBarStackedGraphOptions.annotation = {};

		// Barras "Día de la semana" (solo multi-día; daysData = 7 totales Lun→Dom).
		if (ch.daysData && ch.daysData.length) {
			var days 			= $('#days')[0].getContext("2d");
			var gradientStroke2 = days.createLinearGradient(300, 0, 100, 0);
			gradientStroke2.addColorStop(0, "#4cb6cb");
			gradientStroke2.addColorStop(1, "#54cfc7");

			Chart.defaults.global.responsive 			= true;
			Chart.defaults.global.maintainAspectRatio 	= false;
			Chart.defaults.global.legend.display       	= false;

			var dataD = {
			    labels: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
			    datasets: [
			        {
			        	label: "Total " + RS.currency,
			            data: ch.daysData,
			            backgroundColor: gradientStroke2
			        }]
			};

			chartBarStackedGraphOptions.scales.xAxes[0].display = true;

			new Chart(days, {
			    type      : 'bar',
			    data      : dataD,
			    animation : true,
			    options   : chartBarStackedGraphOptions
			});

			chartBarStackedGraphOptions.scales.xAxes[0].display = false;
		}
	}

	function chartByHours(d){

		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;
		Chart.defaults.global.legend.display       	= false;

		// Labels "00 h".."23 h" (presentación) desde las horas crudas del BFF.
		var labels = d.hours.map(function (h) { return (h < 10 ? '0' + h : '' + h) + ' h'; });

		var hoursChart 		=$('#hours')[0].getContext("2d");
		var gradientStroke 	= hoursChart.createLinearGradient(300, 0, 100, 0);
		gradientStroke.addColorStop(0, "#4cb6cb");
		gradientStroke.addColorStop(1, "#54cfc7");

		var dataH = {
		    labels 		: labels,
		    datasets 	: [
		    				{
				                label                     : "Ventas",
				                data                      : d.totals,
				                type                      : 'line',
				                borderColor               : '#FF9469',
				                pointColor                : '#FF9469',
				                pointHoverRadius          : 8,
				                pointHoverBorderColor     : "#fff",
				                pointHoverBackgroundColor : '#FF9469',
				                pointBorderColor          : '#FF9469',
				                pointBackgroundColor      : '#FF9469',
				                pointRadius               : 3,
				                pointHoverBorderWidth     : 3,
				                pointBorderWidth          : 3,
				                pointHitRadius            : 20,
				                borderWidth               : 5,
				                fill                      : false
				            }
		    			]
		};

		chartLineGraphOptions.scales.xAxes[0].display = true;

		new Chart(hoursChart, {
		    type 		: 'line',
		    data 		: dataH,
		    animation 	: true,
		    options   	: chartLineGraphOptions
		 });

		chartLineGraphOptions.scales.xAxes[0].display = false;
	}

})();
