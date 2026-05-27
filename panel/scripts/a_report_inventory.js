/**
 * Front del reporte Historial de Stock (a_report_inventory) — BFF de 3 niveles.
 *
 *   - config (currency/decimal/thousand) ← GET /bff/bootstrap.php
 *   - movimientos de stock (crudos)       ← GET /bff/reports/inventory.php?dataset=movements
 *   - KPIs valor de inventario (crudos)   ← GET /bff/reports/inventory.php?dataset=widget
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS formatea (números/fechas), traduce
 * `source`, arma la tabla (modo general o byDay) + los KPIs y escapa los campos de datos.
 * Params byDay/itemId se leen del hash (#report_inventory&bd=1&ii=<uuid>).
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/inventory.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	// Params del hash (#report_inventory&bd=1&ii=<uuid>).
	function hashParam(name) {
		var m = (window.location.hash || '').match(new RegExp('[&#]' + name + '=([^&]+)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	var ITEM_ID = hashParam('ii');
	var BY_DAY  = hashParam('bd') === '1' || hashParam('bd') === 'true';

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	// Traducción de la fuente del movimiento (presentación → front).
	var SRC = {
		production: 'Producción', adjustment: 'Ajuste', transfer: 'Transferencia',
		void: 'Anulación', sale: 'Venta', purchase: 'Compra', other: 'Otro',
		return: 'Devolución', count: 'Conteo'
	};

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	// Cantidad con hasta `dec` decimales (stock usa 3); entero → sin decimales.
	function fmtQty(v, dec) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand)
		                     : formatNumber(v, '', 'yes', RS.thousand, false, dec || 2);
	}
	var MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
	function niceDate(date, withHour) {
		var d = moment(date);
		if (!d.isValid()) { return 'No date'; }
		var s = d.format('DD') + ' ' + MESES[d.month()] + ', ' + d.format('YYYY');
		return withHour ? (s + ' a las ' + d.format('HH:mm')) : s;
	}

	// Celda "Fuente": traduce, agrega tooltip de nota y link al documento (markup en el front).
	function sourceCell(r) {
		var label = esc(SRC[r.source] || r.source);
		if (r.note) {
			label = '<span class="text-u-l pointer" data-toggle="tooltip" title="' + esc(r.note) + '">' + label + '</span>';
		}
		if (r.transactionId) {
			var href = (r.source === 'purchase' ? '/a_report_purchases' : '/a_report_transactions') +
				'?action=edit&id=' + esc(r.transactionId) + '&ro=1';
			label = '<a class="doc hidden-print" href="' + href + '"><span class="text-info">' + label + '</span></a>';
		}
		return label;
	}

	/* ───────────── markup de la tabla ───────────── */

	function buildGeneralTable(rows) {
		var head =
			'<thead><tr>' +
			'<th>Fecha</th><th>Artículo</th><th>Código</th><th>Sucursal</th><th>Depósito</th>' +
			'<th>Usuario</th><th>Fuente</th>' +
			'<th class="text-center">Ingreso</th><th class="text-center">Egreso</th>' +
			'<th class="text-center">Existencia</th><th class="text-center">Costo Uni.</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var inRaw  = r.count > 0 ? r.count : 0;
			var outRaw = r.count < 0 ? r.count : 0;
			var inTxt  = r.count > 0 ? '+' + fmtQty(r.count, 3) : '-';
			var outTxt = r.count < 0 ? fmtQty(r.count, 3) : '-';
			var itemLink = '<a href="/@#items&i=' + esc(r.itemId) + '" target="_blank" class="hidden-print">' + esc(r.itemName) + '</a>' +
				'<span class="visible-print">' + esc(r.itemName) + '</span>';

			body +=
				'<tr>' +
				'<td data-order="' + r.stockDate + '" data-filter="' + r.stockDate + '">' + niceDate(r.stockDate, true) + '</td>' +
				'<td>' + itemLink + '</td>' +
				'<td>' + esc(r.itemSKU) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.locationName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + sourceCell(r) + '</td>' +
				'<td class="bg-light lter text-right" data-order="' + inRaw + '">' + inTxt + '</td>' +
				'<td class="bg-light lter text-right" data-order="' + outRaw + '">' + outTxt + '</td>' +
				'<td class="bg-light lter text-right ' + (r.onHand <= 0 ? 'text-danger' : '') + '" data-filter="' + (r.onHand === 0 ? 'quiebre' : '') + '">' + fmtQty(r.onHand, 3) + '</td>' +
				'<td class="bg-light lter text-right" data-order="' + r.cogs + '" data-format="money">' + fmt(r.cogs) + '</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th colspan="7">TOTAL</th>' +
			'<th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function buildByDayTable(rows) {
		var head =
			'<thead><tr>' +
			'<th>Fecha</th><th>Artículo</th><th>Código</th><th>Sucursal</th><th>Usuario</th>' +
			'<th class="text-center">Existencia</th><th class="text-center">Costo Uni.</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var itemLink = '<a href="/@#items&i=' + esc(r.itemId) + '" target="_blank" class="hidden-print">' + esc(r.itemName) + '</a>' +
				'<span class="visible-print">' + esc(r.itemName) + '</span>';
			body +=
				'<tr>' +
				'<td data-order="' + r.stockDate + '">' + niceDate(r.stockDate) + '</td>' +
				'<td>' + itemLink + '</td>' +
				'<td>' + esc(r.itemSKU) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td class="bg-light lter text-right ' + (r.onHand <= 0 ? 'text-danger' : '') + '" data-filter="' + (r.onHand === 0 ? 'quiebre' : '') + '">' + fmtQty(r.onHand, 3) + '</td>' +
				'<td class="bg-light lter text-right" data-order="' + r.cogs + '" data-format="money">' + fmt(r.cogs) + '</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th colspan="5">TOTAL</th>' +
			'<th class="text-right"></th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	/* ───────────── carga ───────────── */

	function loadTable() {
		var url = BFF + '?dataset=movements&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO) +
			(BY_DAY ? '&byDay=1' : '') + (ITEM_ID ? '&itemId=' + encodeURIComponent(ITEM_ID) : '');

		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var rows = res.data || [];

				// Modo item: título desde el primer movimiento.
				if (ITEM_ID && rows.length) {
					var t = '<div class="text-md text-right font-default">Historial de</div> ' + esc(rows[0].itemName);
					$('#pageTitle').html(t);
					$('#pageTitlePrint').html(t);
				}

				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": BY_DAY ? buildByDayTable(rows) : buildGeneralTable(rows),
					"table": ".table1", "sort": 0,
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 200, "nolimit": true,
					"tableName": 'tableTransactions', "fileTitle": 'Historial de Stock',
					"ncmTools": { left: '', right: '' }
				});
				$('[data-toggle="tooltip"]').tooltip();
			}
		});
		window.xhrs.push(xhr);
	}

	function loadWidget() {
		if (ITEM_ID) { return; } // el modo item no muestra KPIs
		var xhr = ncmHelpers.load({
			url: BFF + '?dataset=widget', httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				$('#stockCOGS').text(RS.currency + ' ' + fmt(res.data.cost));
				$('#stockSell').text(RS.currency + ' ' + fmt(res.data.sell));
				$('#stockTotal').text(fmtQty(res.data.total, 3));
			}
		});
		window.xhrs.push(xhr);
	}

	$(document).ready(function () {

		// Modo item: ocultar date-picker y KPIs.
		if (ITEM_ID) {
			$('#invDateWrap').addClass('hidden');
			$('#invKpis').addClass('hidden');
		}

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
				loadWidget();
				loadTable();
			}
		});

		if (!ITEM_ID) {
			dateRangePickerForReports(
				moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
				moment(TO,   'YYYY-MM-DD HH:mm:ss')
			);
			$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
				FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
				TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
				loadTable();
			});
		}
	});

})();
