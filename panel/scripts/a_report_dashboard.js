/**
 * Front del Dashboard del panel (a_dashboard) — BFF de 3 niveles.
 *
 * Diseño IDÉNTICO al legacy; cambia solo la plomería de datos:
 *   - config (currency/decimal/thousand)   ← GET /bff/bootstrap.php
 *   - cada widget                          ← GET /bff/reports/dashboard.php?widget=…
 *   - gráfico de ingresos + sparkline      ← GET /bff/reports/summary.php?view=chart
 *
 * El front NUNCA pega a /API/v1 (siempre al BFF). El service manda datos CRUDOS (números sin
 * formatear); este front formatea TODO lo presentacional (currency, %, flechas de comparación)
 * y arma el markup reusando las plantillas Mustache del fragmento. REGLA RAÍZ 2.
 *
 * Widgets gateados por módulo (satisfaction/tables/schedule): el service devuelve [] cuando el
 * módulo está apagado → el loader deja la card oculta. Sólo se revela si trae datos.
 *
 * El tour iguider del legacy queda fuera (seguimiento en context/10-roadmap → iguider→driver.js).
 */
(function () {

	var BFF       = '/bff/reports/dashboard.php';
	var SUMMARY   = '/bff/reports/summary.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var SUCCESS = '<span class="text-success m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
	var FAIL    = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_down</i></span>';
	var EVEN    = '<span class="font-bold m-r-xs m-l-xs"><i class="material-icons">trending_flat</i></span>';

	/* ───────────── formateo (presentación — vive en el front) ───────────── */

	// Número crudo → string currency (decimal/thousand del bootstrap).
	function fmt(n) {
		return formatNumber(n || 0, '', RS.decimal, RS.thousand);
	}

	// Cantidad/entero con separador de miles, sin decimales.
	function fmtInt(v) {
		return formatNumber(parseFloat(v) || 0, '', 'no', RS.thousand);
	}

	// Flecha de tendencia: para ventas/ingresos, subir es bueno (verde); para egresos, invertido.
	function arrowUpGood(now, prev) {
		if (now > prev) { return SUCCESS; }
		if (now < prev) { return FAIL; }
		return EVEN;
	}
	function arrowDownGood(now, prev) {
		if (now < prev) { return SUCCESS; }
		if (now > prev) { return FAIL; }
		return EVEN;
	}

	/* ───────────── carga de datos (BFF) ───────────── */

	function widget(name, extra, success) {
		var url = BFF + '?widget=' + name +
			'&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO) +
			(extra || '');
		var xhr = ncmHelpers.load({
			url         : url,
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

	/* ───────────── widgets ───────────── */

	function loadIncomeOutcome() {
		widget('incomeOutcomeStats', '', function (result) {
			var currency = '<span class="text-muted">' + RS.currency + '</span> ';

			$('.salesRevenue').html(currency + fmt(result.revenue));
			$('.salesMargin').html(result.margin + '%');
			$('.salesCount').html(fmtInt(result.count));
			$('.customerAverage').html(currency + fmt(result.customerAverage));

			ncmHelpers.mustacheIt($('#totalIncomeTpl'), { 'total': currency + fmt(result.total) }, $('#totalIncome'));
			ncmHelpers.mustacheIt($('#totalOutcomeTpl'), { 'total': currency + fmt(result.expenses) }, $('#totalOutcome'));

			widget('incomeOutcomeStats', '&prev=true', function (prev) {
				$('.salesTotalArrow').html(arrowUpGood(result.total, prev.total));
				$('.salesExpensesArrow').html(arrowDownGood(result.expenses, prev.expenses));
				$('.salesRevenueArrow').html(arrowUpGood(result.revenue, prev.revenue));
				$('.salesMarginArrow').html(arrowUpGood(result.margin, prev.margin));
				$('.salesCountArrow').html(arrowUpGood(result.count, prev.count));
				$('.customerAverageArrow').html(arrowUpGood(result.customerAverage, prev.customerAverage));
			});
		});
	}

	function loadPaymentStatus() {
		widget('paymentStatus', '', function (result) {
			if (result.creditoCount > 0 || result.cobradoCount > 0 || result.porcobrarCount > 0) {
				$('#paymentStatusWidget').removeClass('hidden');
			}

			ncmHelpers.mustacheIt($('#salesTypeTpl'), {
				'creditCount': result.creditoCount,
				'creditTotal': fmt(result.credito),
				'cashTotal'  : fmt(result.contado)
			}, $('#salesType'));

			ncmHelpers.mustacheIt($('#creditSalesTpl'), {
				'pendingCount': result.porcobrarCount,
				'pendingTotal': fmt(result.porcobrar),
				'payedTotal'  : fmt(result.cobrado)
			}, $('#creditSales'));

			var darkBg = (window.ncmUI && ncmUI.setDarkMode && ncmUI.setDarkMode.isSet) ? '#3b464d' : '#d7e5e8';

			var ctxA = $('#chart-contado')[0].getContext('2d');
			var gradA = ctxA.createLinearGradient(500, 0, 100, 0);
			gradA.addColorStop(0, '#4cb6cb');
			gradA.addColorStop(1, '#54cfc7');
			new Chart(ctxA, {
				type      : 'doughnut',
				data      : {
					labels  : ['Contado', 'Crédito'],
					datasets: [{ data: [result.contado, result.credito], backgroundColor: [darkBg, gradA] }]
				},
				animation : true,
				options   : { cutoutPercentage: 85, tooltips: chartTooltipStyle.tooltips }
			});

			var ctxB = $('#chart-porcobrar')[0].getContext('2d');
			var gradB = ctxB.createLinearGradient(500, 0, 100, 0);
			gradB.addColorStop(0, '#4cb6cb');
			gradB.addColorStop(1, '#54cfc7');
			new Chart(ctxB, {
				type      : 'doughnut',
				data      : {
					labels  : ['Por Cobrar', 'Cobrado'],
					datasets: [{ data: [result.porcobrar, result.cobrado], backgroundColor: [gradB, darkBg] }]
				},
				animation : true,
				options   : { cutoutPercentage: 85, tooltips: chartTooltipStyle.tooltips }
			});
		});
	}

	function loadInfo() {
		widget('info', '', function (result) {
			if (!result || result.itemsCount == null) { return; }
			$('.giftCards').text(result.giftCardsCount);
			$('.registersCount').text(result.openDrawersCount);
			$('.outletsCount').text(result.outletsCount);
			$('.planName').text(result.plan);
			$('.usersCount').text(result.usersCount);
			$('.itemsCount').text(result.itemsCount);
			$('.transactionsCount').text(result.transactionsCount);
		});
	}

	function loadSatisfaction() {
		widget('satisfaction', '', function (result) {
			if (!result || result.detractors == null) { return; }
			$('#customerSatisfactionLevel').removeClass('hidden');

			$('.satisfactionBarDetractors').attr('data-original-title', 'Detractores: ' + result.detractors.count + ' voto(s)')
				.css('width', result.detractors.percent + '%');
			$('.satisfactionBarPassives').attr('data-original-title', 'Pasivos: ' + result.passives.count + ' voto(s)')
				.css('width', result.passives.percent + '%');
			$('.satisfactionBarPromoters').attr('data-original-title', 'Promotores: ' + result.promoters.count + ' voto(s)')
				.css('width', result.promoters.percent + '%');
		});
	}

	function loadSchedule() {
		widget('schedule', '', function (result) {
			if (!ncmHelpers.validity(result) || result.scheduledCount == null) { return; }
			ncmHelpers.mustacheIt($('#scheduleTpl'), result, $('#schedule'));
			$('#schedule').removeClass('hidden');
		});
		ncmHelpers.onClickWrap('#schedule', function () { window.location.hash = '#report_schedule'; });
	}

	function loadTables() {
		widget('tables', '', function (result) {
			if (!ncmHelpers.validity(result) || result.tablesCount == null) { return; }
			ncmHelpers.mustacheIt($('#tablesTpl'), result, $('#tables'));
			$('#tables').removeClass('hidden');
		});
	}

	function loadOrders() {
		widget('orders', '', function (result) {
			if (!result || result.ordersCount == null) { return; }
			ncmHelpers.mustacheIt($('#ordersTpl'), result, $('#orders'));
			$('#orders').removeClass('hidden');
		});
		ncmHelpers.onClickWrap('#tables,#orders', function () { window.location.hash = '#report_orders'; });
	}

	function loadTopItems() {
		widget('topItems', '', function (result) {
			var items = (result || []).map(function (it) {
				return { name: it.name, count: fmtInt(it.count), total: fmt(it.total) };
			});
			ncmHelpers.mustacheIt($('#topItemsTpl'), { 'items': items }, $('#topItems table.table'));
		});
	}

	function loadCustomersRates() {
		widget('customersRates', '', function (result) {
			if (!result) { return; }
			var ret = Number(result.retention_rate) || 0;
			var grw = Number(result.customer_growth_rate) || 0;
			var chu = Number(result.churn_rate) || 0;

			$('.retentionRate').text(ret + '%');
			$('.growthRate').text(grw + '%');
			$('.churnRate').text(chu + '%');

			$('.retentionRateImg').attr('src', gaugeUrl(ret, '%2362bcce'));
			$('.growthRateImg').attr('src', gaugeUrl(grw, '%2362bcce'));
			$('.churnRateImg').attr('src', gaugeUrl(chu, '%23f06a6a'));
		});
	}

	function gaugeUrl(value, color) {
		return 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' +
			value + ', ' + (value - 100) + '], backgroundColor: [ "' + color + '", "%23e8eff0" ] } ] }, ' +
			'options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';
	}

	function loadCustomers() {
		widget('customers', '', function (result) {
			if (!result || result.total == null) { return; }
			$('.customersTotal').text(result.total);
			$('.customersNew').text(result.new);
			$('.customersOld').text(result.old);

			widget('customers', '&prev=true', function (prev) {
				$('.customersNewArrow').html(arrowUpGood(result.new, prev.new));
				$('.customersOldArrow').html(arrowUpGood(result.old, prev.old));
			});
		});
	}

	function loadTopCategories() {
		widget('topCategories', '', function (result) {
			if (!ncmHelpers.validity(result)) {
				$('#topCategories').html('<div class="text-center font-bold text-muted"><img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-xl"></div>');
				return;
			}
			var ctx  = $('#topCategoriesChart')[0].getContext('2d');
			var grad = ctx.createLinearGradient(500, 0, 100, 0);
			grad.addColorStop(0, '#4cb6cb');
			grad.addColorStop(1, '#54cfc7');

			var tips = ncmHelpers.cloneObj(chartTooltipStyle);
			tips.tooltips.callbacks.title = function () { return false; };
			tips.tooltips.callbacks.label = function (item, data) {
				var dataItem = data.datasets[item.datasetIndex].data[item.index];
				return dataItem.g + ': ' + dataItem.v;
			};

			new Chart(ctx, {
				type : 'treemap',
				data : {
					datasets: [{
						tree           : result,
						data           : result.amount,
						backgroundColor: grad,
						spacing        : 3,
						borderWidth    : 0,
						borderColor    : 'rgba(180,180,180, 0.15)',
						key            : 'total',
						groups         : ['title'],
						fontColor      : '#fff',
						fontFamily     : 'Source Sans Pro'
					}]
				},
				options: {
					maintainAspectRatio: false,
					title  : { display: false },
					legend : { display: false },
					tooltips: tips.tooltips
				}
			});
		});
	}

	function loadTopHours() {
		widget('topHours', '', function (result) {
			if (!result || !ncmHelpers.validity(result.hour)) {
				$('#topHours').html('<div class="text-center font-bold text-muted"><img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-xl"></div>');
				return;
			}
			var ctx  = $('#topHoursChart')[0].getContext('2d');
			var grad = ctx.createLinearGradient(300, 0, 100, 0);
			grad.addColorStop(0, '#4cb6cb');
			grad.addColorStop(1, '#54cfc7');

			new Chart(ctx, {
				type      : 'polarArea',
				data      : {
					labels  : result.hour,
					datasets: [{
						data: result.total,
						backgroundColor: [grad, grad, '#2f3940', '#2f3940', '#405161', '#405161', '#778490', '#778490', '#d7e5e8', '#d7e5e8', '#e8eff0', '#e8eff0']
					}]
				},
				animation : true,
				options   : chartTooltipStyle
			});
		});
	}

	/* ───────────── gráfico de ingresos (reusa el chart view del BFF de summary) ───────────── */

	function loadIncomeChart() {
		var url = SUMMARY + '?view=chart&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url         : url,
			httpType    : 'GET',
			hideLoader  : true,
			type        : 'json',
			warnTimeout : false,
			success     : function (res) {
				if (!res || !res.ok || !res.data || !res.data.chart) { return; }
				var ch = res.data.chart;
				if (ch.gross && ch.gross.length) {
					$('#myChartHolder').removeClass('hidden');
					drawChart(ch);
					drawIncomeSparkline(ch.gross);
				}
			}
		});
		window.xhrs.push(xhr);
	}

	// Sparkline de fondo en la card de Ingresos (quickchart.io), igual al legacy.
	function drawIncomeSparkline(gross) {
		var total = gross.reduce(function (a, b) { return parseInt(a) + parseInt(b); }, 0);
		if (!total) { return; }
		var pct = gross.map(function (v) { return parseInt((v * 100) / total); });
		var url = encodeURI('https://quickchart.io/chart?cht=lc&chd=t:' + pct.toString() + '&chco=ffffff4d&chf=a,s,000000&chls=4.0&chs=400x80');
		$('#totalIncome').removeClass('gradBgBlue').addClass('text-white')
			.attr('style', 'background:url(' + url + ') no-repeat center center / cover, linear-gradient(314deg, #54CFC7,#6BC0D1);');
	}

	function drawChart(ch) {
		var labels = ch.buckets.map(function (b, i) {
			if (ch.isDay) { return b + 'h'; }
			return moment(b).format('DD MMM');
		});

		Chart.defaults.global.legend.display      = true;
		Chart.defaults.global.responsive          = true;
		Chart.defaults.global.maintainAspectRatio = false;

		var ctx  = $('#summaryChart')[0].getContext('2d');
		var grad = ctx.createLinearGradient(1600, 0, 0, 0);
		grad.addColorStop(0, '#4cb6cb');
		grad.addColorStop(0.5, '#54cfc7');
		grad.addColorStop(1, '#54cfc7');

		var data = {
			labels  : labels,
			datasets: [
				{
					label                     : 'Margen',
					data                      : ch.margin,
					type                      : 'line',
					borderColor               : '#FF9469',
					pointColor                : '#FF9469',
					pointHoverRadius          : 8,
					pointHoverBorderColor     : '#fff',
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
				{ type: 'bar', label: 'Ingresos', backgroundColor: grad, data: ch.gross },
				{ type: 'bar', label: 'Egresos', backgroundColor: chartSecondColor, data: ch.grossE }
			]
		};

		new Chart(ctx, { type: 'bar', data: data, animation: true, options: chartBarStackedGraphOptions });
		Chart.defaults.global.legend.display = false;
	}

	/* ───────────── carga + init ───────────── */

	function loadAll() {
		loadIncomeOutcome();
		loadPaymentStatus();
		loadIncomeChart();
		loadSchedule();
		loadTables();
		loadOrders();
		loadInfo();
		loadSatisfaction();
		loadTopItems();
		loadCustomersRates();
		loadCustomers();
		loadTopCategories();
		loadTopHours();
	}

	$(document).ready(function () {

		Chart.defaults.global.responsive          = true;
		Chart.defaults.global.maintainAspectRatio  = false;
		Chart.defaults.global.legend.display       = false;

		$('[data-toggle="tooltip"]').tooltip();
		if (window.FastClick) { FastClick.attach(document.body); }

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
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadAll();
			}
		});

		// 2) Date-picker: misma UI; re-fetchea del BFF en vez de recargar.
		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO,   'YYYY-MM-DD HH:mm:ss'),
			'left', false, true
		);

		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadAll();
		});
	});

})();
