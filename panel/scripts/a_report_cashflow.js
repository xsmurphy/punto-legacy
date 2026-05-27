/**
 * Front del reporte de Flujo de Caja (a_report_cashflow) — BFF de 3 niveles.
 *
 *   - config              ← GET /bff/bootstrap.php
 *   - flujo (números)      ← GET /bff/reports/cashflow.php?from&to
 *
 * El BFF devuelve los totales CRUDOS; este JS formatea TODO + arma los 4 KPIs y la tabla de flujo.
 * Read-only (sin writes ni acciones legacy).
 */
(function () {

	var BFF       = '/bff/reports/cashflow.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };
	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }

	function row(label, value, opts) {
		opts = opts || {};
		var cls = opts.rowClass ? ' class="' + opts.rowClass + '"' : '';
		var tag = opts.header ? 'th' : 'td';
		var lcls = opts.bold ? ' class="font-bold' + (opts.uc ? ' text-u-c' : '') + '"' : (opts.uc ? ' class="text-u-c"' : '');
		var vcls = 'text-right' + (opts.bold ? ' font-bold' : '') + (opts.valClass ? ' ' + opts.valClass : '');
		return '<tr' + cls + '><' + tag + lcls + '>' + label + '</' + tag + '><' + tag + ' class="' + vcls + '">' + (value === null ? '' : fmt(value)) + '</' + tag + '></tr>';
	}

	function buildTable(d) {
		var initClr = (d.initialCash <= 0) ? 'text-danger' : '';
		var remClr  = (d.remains <= 0) ? 'text-danger' : '';
		return row('Saldo Inicial', d.initialCash, { rowClass: 'bg-light bg', bold: true, uc: true, valClass: initClr }) +
			row('Ingresos', null, { header: true, uc: true }) +
			row('Ingresos por Ventas', d.cashSales) +
			row('Cobros de deudas', d.cashPayments) +
			row('Total de Ingresos', d.incomeTotal, { rowClass: 'bg-light lter', bold: true }) +
			row('Egresos', null, { header: true, uc: true }) +
			row('Compra de mercadería', d.stockPurchase) +
			row('Gastos', d.expensesPurchase) +
			row('Pagos de deudas', d.outPayment) +
			row('Total de Egresos', d.outcomeTotal, { rowClass: 'bg-light lter', bold: true }) +
			row('Saldo Final', d.remains, { rowClass: 'bg-light bg', bold: true, uc: true, valClass: remClr }) +
			row('Saldo Acumulado', d.accumulated, { rowClass: 'bg-light dk', bold: true, uc: true, valClass: remClr });
	}

	function renderKpis(d) {
		$('#globalUtility').text(fmt(d.initialCash));
		$('#globalSubtotal').text(fmt(d.incomeTotal));
		$('#globalCogs').text(fmt(d.outcomeTotal));
		$('#globalDiscount').text(fmt(d.remains));
	}

	function load() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var d = res.data || {};
				renderKpis(d);
				$('#salesTable').html(buildTable(d));
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
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					$('.bff-currency').text(RS.currency);
					$('.bff-company-name').text(res.data.companyName || '');
				}
				load();
			}
		});

		onClickWrap('#export', function () { if (typeof table2Xlsx === 'function') { table2Xlsx('salesTable', 'flujo_de_caja'); } });

		dateRangePickerForReports(moment(FROM, 'YYYY-MM-DD HH:mm:ss'), moment(TO, 'YYYY-MM-DD HH:mm:ss'));
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			load();
		});
	});

})();
