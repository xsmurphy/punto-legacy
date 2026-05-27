/**
 * Front del reporte Niveles de Stock (multi-depósito) (a_report_stock) — BFF de 3 niveles.
 *
 *   - config                         ← GET /bff/bootstrap.php
 *   - { needsOutlet, rows } (CRUDOS) ← GET /bff/reports/stock.php
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS formatea, CALCULA los colores/% de las
 * barras de alerta y arma la tabla anidada (ítem + depósitos + principal). esc() en datos.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/stock.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}

	// Color/ancho de la barra de alerta a partir de (min, count) — presentación, en el front.
	function bar(min, count) {
		var bg = '', pct = 0;
		if (min > 1) {
			if (count > min)            { bg = 'bg-success'; pct = 100; }
			else if (count > 0)         { bg = 'bg-warning'; pct = 50; }
			else                        { bg = 'bg-danger';  pct = 20; }
		} else {
			if (count > 1)              { bg = 'bg-success'; pct = 100; }
			else if (count < 1)         { bg = 'bg-danger';  pct = 20; }
		}
		return '<div class="progress progress-xs dker progress-striped hidden" style="max-width:80px!important;">' +
			'<div class="progress-bar ' + bg + '" style="width: ' + pct + '%"></div></div>';
	}

	function depotRow(family, name, min, count) {
		return '<tr data-family="' + family + '" class="fullSearchHide">' +
			'<td>' + bar(min, count) + '</td>' +
			'<td class="">' + esc(name) + '</td>' +
			'<td class="bg-light lter text-right">' + fmtQty(min) + '</td>' +
			'<td class="text-right bg-light lter">' + fmtQty(count) + '</td>' +
			'</tr>';
	}

	function buildTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Artículo</th><th>SKU/Código</th><th class="text-center">P. Costo</th><th class="text-right">Stock Total</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var fam = esc(r.itemId);
			// Fila principal del ítem
			body += '<tr data-load="" class="clickrow itemInCat" style="page-break-after: always;" data-family="' + fam + '">' +
				'<th>' + esc(r.name) + '</th>' +
				'<th class="text-muted">' + esc(r.sku) + '</th>' +
				'<th class="text-right bg-light bg">' + esc(RS.currency) + ' ' + fmt(r.cogs) + '</th>' +
				'<th class="text-right bg-light bg">' + fmtQty(r.onHand) + '</th>' +
				'</tr>';
			// Cabecera de depósitos
			body += '<tr data-family="' + fam + '" class="fullSearchHide">' +
				'<th style="max-width:80px!important;"></th><th class="text-muted">Depósito</th>' +
				'<th class="text-center text-muted">Stock Mínimo</th><th class="text-center text-muted">Cantidad</th></tr>';
			// Una fila por depósito
			$.each(r.depots || [], function (j, d) {
				body += depotRow(fam, d.locationName, parseFloat(d.min) || 0, parseFloat(d.count) || 0);
			});
			// Principal (lo no asignado a depósitos)
			body += depotRow(fam, 'Principal', parseFloat(r.principal.min) || 0, parseFloat(r.principal.count) || 0);
			// Espaciador
			body += '<tr class="fullSearchHide" data-family="' + fam + '"><td colspan="4" class="hidden-print noxls">&nbsp;</td></tr>';
		});

		var foot = '</tbody><tfoot><tr><th colspan="4"></th></tr></tfoot>';
		return head + body + foot;
	}

	function loadTable() {
		var xhr = ncmHelpers.load({
			url: BFF, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }

				if (res.data.needsOutlet) {
					$('.table1').html('<tbody><tr><td><div class="text-center"><img src="/assets/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> Debe seleccionar una sucursal</div><div>Por favor seleccione una sucursal y vuelva a intentar</div></div></td></tr></tbody>');
					return;
				}

				ncmDataTables({
					"container": ".tableContainer", "url": BFF, "rawUrl": BFF,
					"iniData": buildTable(res.data.rows || []), "table": ".table1", "sort": 0,
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 2000, "nolimit": true, "noMoreBtn": true,
					"tableName": 'tableSummary', "fileTitle": 'Niveles de Stock',
					"ncmTools": { left: '', right: '' }
				}, function () {
					if (ncmHelpers.fullScreenTextSearch) { ncmHelpers.fullScreenTextSearch('.itemInCat', '#textSearch'); }
					onClickWrap('.exportTable', function (event, tis) { table2Xlsx(tis.data('table'), tis.data('name')); }, false, true);
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
	});

})();
