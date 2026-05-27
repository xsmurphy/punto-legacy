/**
 * Front del reporte Niveles de Stock por Día (a_report_stock_day) — BFF de 3 niveles.
 *
 *   - config (currency/decimal/thousand) ← GET /bff/bootstrap.php
 *   - niveles de stock por ítem (CRUDOS) ← GET /bff/reports/stock-day.php?date=<corte>
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS formatea y arma la tabla; esc() en datos.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/stock-day.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	// Fecha de corte (default: fin de hoy). El date-picker la actualiza.
	var DATE = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

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
			'<th>Artículo</th><th>SKU/Código</th><th class="text-center">P. Costo</th>' +
			'<th class="text-right">Stock Total</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var cogs = parseFloat(r.cogs) || 0, onHand = parseFloat(r.onHand) || 0;
			body +=
				'<tr>' +
				'<td>' + esc(r.name) + '</td>' +
				'<td class="text-muted">' + esc(r.sku) + '</td>' +
				'<td class="text-right bg-light bg" data-order="' + cogs + '" data-format="money">' + esc(RS.currency) + ' ' + fmt(cogs) + '</td>' +
				'<td class="text-right bg-light bg" data-order="' + onHand + '">' + fmtQty(onHand) + '</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th colspan="2"></th><th class="text-right"></th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function loadTable() {
		var url = BFF + '?date=' + encodeURIComponent(DATE);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(res.data || []), "table": ".table1", "sort": 0,
					"footerSumCol": [2, 3], "currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 3000, "nolimit": false, "noMoreBtn": true,
					"tableName": 'tableSummary', "fileTitle": 'Stock por Día',
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
					window.currency          = RS.currency;
					window.decimal           = RS.decimal;
					window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadTable();
			}
		});

		dateRangePickerForReports(
			moment(DATE, 'YYYY-MM-DD HH:mm:ss').startOf('day'),
			moment(DATE, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			DATE = picker.endDate.format('YYYY-MM-DD HH:mm:ss'); // corte = fin del rango elegido
			loadTable();
		});
	});

})();
