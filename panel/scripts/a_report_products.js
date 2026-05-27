/**
 * Front del reporte de Artículos / Productos (a_report_products) — BFF de 3 niveles.
 *
 *   - config                         ← GET /bff/bootstrap.php
 *   - general (rows+kpi+chart)       ← GET /bff/reports/products.php?view=general&from&to[&filtros]
 *   - detail (rows)                  ← GET /bff/reports/products.php?view=detail&...
 *   - combos (rows)                  ← GET /bff/reports/products.php?view=combos&...
 *
 * El BFF manda datos crudos + utilidad/KPIs/chart (REGLA RAÍZ 2); este JS formatea TODO, arma
 * las 3 tablas (tabs), los KPIs con flechas de comparación y el chart. esc() en datos.
 *
 * Filtros de drill-down (desde otros reportes) por query/hash: ii=itemId, ci=cusId, ui=usrId,
 * m=month, y=year.
 */
(function () {

	var BFF       = '/bff/reports/products.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot', taxName: 'IVA' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var loaded = { detail: false, combos: false };

	function param(name) {
		var s = window.location.search || '';
		var h = window.location.hash || '';
		var q = s;
		if (h.indexOf('?') !== -1) { q += '&' + h.slice(h.indexOf('?') + 1); }
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1]) : '';
	}
	// Filtros desde la URL (vacíos en el caso general).
	var FILT = { itmId: param('ii'), cusId: param('ci'), usrId: param('ui'), month: param('m'), year: param('y') };
	var SRC = '';   // búsqueda por artículo/código en la tab Detallado

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}
	function money(n) { return esc(RS.currency) + ' ' + fmt(n); }
	function niceDate(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : ''; }

	// Flecha de comparación vs período anterior. inverted=true → bajar es bueno (verde).
	function cmpArrow(now, prev, inverted) {
		now = parseFloat(now) || 0; prev = parseFloat(prev) || 0;
		if (!prev) { return ''; }
		var pct = ((now - prev) / Math.abs(prev)) * 100;
		var up  = pct >= 0;
		var good = inverted ? !up : up;
		var icon = up ? 'arrow_upward' : 'arrow_downward';
		var cls  = good ? 'text-success' : 'text-danger';
		return '<span class="text-xs ' + cls + '"><i class="material-icons" style="font-size:14px;vertical-align:middle">' + icon +
			'</i> ' + Math.abs(pct).toFixed(0) + '%</span>';
	}

	function qstr(view) {
		var p = { view: view, from: FROM, to: TO };
		if (FILT.itmId) { p.itmId = FILT.itmId; }
		if (FILT.cusId) { p.cusId = FILT.cusId; }
		if (FILT.usrId) { p.usrId = FILT.usrId; }
		if (FILT.month) { p.month = FILT.month; }
		if (FILT.year)  { p.year  = FILT.year; }
		if (view === 'detail' && SRC) { p.src = SRC; }
		return BFF + '?' + $.param(p);
	}

	// ── tablas ──
	function productRows(rows) {
		var body = '';
		$.each(rows, function (i, r) {
			var name = r.deleted ? '<i class="text-muted">Artículo Eliminado</i>' : esc(r.name);
			body +=
				'<tr class="clickrow pointer">' +
				'<td>' + name + '</td>' +
				'<td>' + esc(r.sku) + '</td>' +
				'<td>' + esc(r.brand) + '</td>' +
				'<td>' + esc(r.category) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.price) || 0) + '">' + fmt(r.price) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.usold) || 0) + '">' + fmtQty(r.usold) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.comission) || 0) + '" data-format="money">' + fmt(r.comission) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.cogs) || 0) + '" data-format="money">' + fmt(r.cogs) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.tax) || 0) + '" data-format="money">' + fmt(r.tax) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.discount) || 0) + '" data-format="money">' + fmt(r.discount) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.utility) || 0) + '" data-format="money">' + fmt(r.utility) + '</td>' +
				'<td class="tdNumeric text-right bg-light lter" data-order="' + (parseFloat(r.total) || 0) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'</tr>';
		});
		return body;
	}
	function productHead() {
		return '<thead class="text-u-c"><tr>' +
			'<th>Nombre</th><th>Código/SKU</th><th>Marca</th><th>Categoría</th>' +
			'<th class="text-center">Precio Uni.</th><th class="text-center">Cantidad</th>' +
			'<th class="text-center">Comisión</th><th class="text-center">Costo</th>' +
			'<th class="text-center">' + esc(RS.taxName) + '</th><th class="text-center">Descuentos</th>' +
			'<th class="text-center">Utilidad</th><th class="text-center">Total</th>' +
			'</tr></thead><tbody>';
	}
	function productFoot() {
		return '</tbody><tfoot><tr><th colspan="4">TOTALES</th><th colspan="8"></th></tr></tfoot>';
	}
	function buildProductTable(rows) { return productHead() + productRows(rows) + productFoot(); }

	function buildDetailTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th>Sucursal</th><th>Caja</th><th># Documento</th><th>Usuario</th><th>Cliente</th>' +
			'<th>Fecha</th><th>Nombre</th><th>Código/SKU</th><th>Marca</th><th>Categoría</th>' +
			'<th class="text-center">Cantidad</th><th class="text-center">Comisión</th><th class="text-center">Costo</th>' +
			'<th class="text-center">' + esc(RS.taxName) + '</th><th class="text-center">Descuentos</th>' +
			'<th class="text-center">Utilidad</th><th class="text-center">Total</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var name = r.deleted ? '<i class="text-muted">Artículo Eliminado</i>' : esc(r.name);
			body +=
				'<tr class="clickrow pointer" data-id="' + esc(r.transactionId) + '">' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.registerName) + '</td>' +
				'<td>' + esc(r.invoiceNo) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + name + '</td>' +
				'<td>' + esc(r.sku) + '</td>' +
				'<td>' + esc(r.brand) + '</td>' +
				'<td>' + esc(r.category) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.usold) || 0) + '">' + fmtQty(r.usold) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.comission) || 0) + '" data-format="money">' + fmt(r.comission) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.cogs) || 0) + '" data-format="money">' + fmt(r.cogs) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.tax) || 0) + '" data-format="money">' + fmt(r.tax) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.discount) || 0) + '" data-format="money">' + fmt(r.discount) + '</td>' +
				'<td class="tdNumeric text-right" data-order="' + (parseFloat(r.utility) || 0) + '" data-format="money">' + fmt(r.utility) + '</td>' +
				'<td class="tdNumeric text-right bg-light lter" data-order="' + (parseFloat(r.total) || 0) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'</tr>';
		});
		return head + body + '</tbody><tfoot><tr><th colspan="10">TOTALES</th><th colspan="7"></th></tr></tfoot>';
	}

	function renderKpis(k) {
		if (!k) { return; }
		$('#globalSubtotal').html(fmt(k.subtotal.now));
		$('#globalSubtotalB').html(cmpArrow(k.subtotal.now, k.subtotal.prev, k.subtotal.inverted));
		$('#globalCogs').html(fmt(k.cogs.now));
		$('#globalCogsB').html(cmpArrow(k.cogs.now, k.cogs.prev, k.cogs.inverted));
		$('#globalDiscount').html(fmt(k.otros.now));
		$('#globalDiscountB').html(cmpArrow(k.otros.now, k.otros.prev, k.otros.inverted));
		$('#globalUtility').html(fmt(k.utility.now));
		$('#globalUtilityB').html(cmpArrow(k.utility.now, k.utility.prev, k.utility.inverted));
	}

	function drawChart(chart) {
		if (!chart || !chart.data || !chart.data.length) { return; }
		var ctx = document.getElementById('myChart').getContext('2d');
		var grad = ctx.createLinearGradient(300, 0, 100, 0);
		grad.addColorStop(0, '#4cb6cb'); grad.addColorStop(1, '#54cfc7');
		Chart.defaults.global.responsive = true;
		Chart.defaults.global.maintainAspectRatio = false;
		if (Chart.defaults.global.legend) { Chart.defaults.global.legend.display = false; }
		new Chart(ctx, {
			type: 'bar',
			data: {
				labels: chart.label,
				datasets: [
					{ label: 'Cant. Anterior', data: chart.dataPrev.map(function (x) { return parseFloat(x) || 0; }), type: 'line', borderColor: '#cfcfcf', borderDash: [10, 5], borderWidth: 2, pointRadius: 3, fill: false },
					{ label: 'Cantidad', data: chart.data.map(function (x) { return parseFloat(x) || 0; }), backgroundColor: grad }
				]
			},
			options: { maintainAspectRatio: false }
		});
	}

	function loadGeneral() {
		var url = qstr('general');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var d = res.data || {};
				ncmDataTables({
					"container": "#generalTable", "url": url, "iniData": buildProductTable(d.rows || []),
					"table": ".table1", "sort": 5, "footerSumCol": [6, 7, 8, 9, 10, 11],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableSummary', "fileTitle": 'Reporte de Articulos Resumen',
					"ncmTools": { left: '', right: '' }
				}, function () {
					$('[data-toggle="tooltip"]').tooltip();
				});
				renderKpis(d.kpi);
				drawChart(d.chart);
			}
		});
		window.xhrs.push(xhr);
	}

	function loadDetail() {
		var url = qstr('detail');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#detailTable", "url": url, "iniData": buildDetailTable(res.data.rows || []),
					"table": ".table2", "sort": 5, "footerSumCol": [10, 11, 12, 13, 14, 15, 16],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "tableName": 'tableDetail', "fileTitle": 'Reporte de Articulos Detallado',
					"ncmTools": { left: '', right: '' }
				}, function () {
					onClickWrap('#tableDetail tr.clickrow', function (event, tis) {
						var id = tis.data('id');
						if (!id) { return; }
						loadForm('/a_report_transactions?action=edit&id=' + id + '&ro=1', '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
					}, false, true);
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadCombos() {
		var url = qstr('combos');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#combosTable", "url": url, "iniData": buildProductTable(res.data.rows || []),
					"table": ".table3", "sort": 5, "footerSumCol": [6, 7, 8, 9, 10, 11],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableCombos', "fileTitle": 'Reporte de Combos',
					"ncmTools": { left: '', right: '' }
				});
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
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadGeneral();
			}
		});

		$('#detailTabLink').on('shown.bs.tab', function () { if (!loaded.detail) { loaded.detail = true; loadDetail(); } });
		$('#combosTabLink').on('shown.bs.tab', function () { if (!loaded.combos) { loaded.combos = true; loadCombos(); } });

		// Búsqueda en Detallado (Enter): vacío → todo el período; ≥1 char → filtra por nombre/SKU.
		$('#detailSearch').on('keyup', function (e) {
			if ((e.keyCode || e.which) !== 13) { return; }
			SRC = $.trim($(this).val());
			loadDetail();
		});

		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loaded.detail = false; loaded.combos = false;
			loadGeneral();
		});
	});

})();
