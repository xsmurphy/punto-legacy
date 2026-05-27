/**
 * Front del reporte de Producción (a_report_production) — BFF de 3 niveles.
 *
 *   - config                       ← GET /bff/bootstrap.php
 *   - general (rows + totals)       ← GET /bff/reports/production.php?view=general&from&to
 *   - detail (rows + totals)        ← GET /bff/reports/production.php?view=detail&...
 *   - compound (rows)               ← GET /bff/reports/production.php?view=compound&...[&byDay=1]
 *
 * El BFF/Service devuelven datos CRUDOS (utilidad/totales ya computados); este JS formatea TODO +
 * arma las tablas/KPIs. esc() en datos. El modal de receta, el export y el delete NO se migraron:
 * se sirven por el PHP legacy vía /a_report_production?action=recipe|export|delete (modal del shell).
 */
(function () {

	var BFF       = '/bff/reports/production.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY    = '/a_report_production';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var loaded = { detail: false, compound: false };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function fmtQty(v) {
		v = parseFloat(v) || 0;
		return (v % 1 === 0) ? formatNumber(v, '', 'no', RS.thousand) : formatNumber(v, '', 'yes', RS.thousand);
	}
	function niceDate(iso, withTime) { var m = moment(iso); return m.isValid() ? m.format(withTime ? 'DD-MM-YYYY HH:mm' : 'DD-MM-YYYY') : ''; }
	function num(v) { return parseFloat(v) || 0; }
	// "-" para órdenes (type 2) en detalle; "~" en costo unitario.
	function money(r, val, tilde) {
		if (r.isOrder) { return '-'; }
		return (tilde ? '~' : '') + fmt(val);
	}

	function buildTable(rows, detail) {
		var head = '<thead class="text-u-c"><tr>' +
			(detail ? '<th>Fecha</th><th>Usuario</th>' : '') +
			'<th>Producto</th><th>Código/SKU</th><th>Categoría</th><th>Sucursal</th><th>Tipo</th>' +
			'<th class="text-center">Unidades</th><th class="text-center">Costo Unitario</th>' +
			'<th class="text-center">Costo Total</th><th class="text-center">Valor de Merma</th>' +
			'<th class="text-center">Utilidad</th>' + (detail ? '<th></th>' : '') +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var load = LEGACY + '?action=recipe&id=' + esc(r.itemId) + '&cant=' + num(r.units) + '&date=' + esc(r.date);
			body += '<tr' + (detail ? ' class="clickrow pointer" data-load="' + load + '"' : '') + '>' +
				(detail ? '<td data-order="' + esc(r.date) + '">' + niceDate(r.date, true) + '</td><td>' + esc(r.userName) + '</td>' : '') +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.sku) + '</td>' +
				'<td>' + esc(r.category) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td><span class="badge">' + esc(r.typeLabel) + '</span></td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.units) + '">' + fmtQty(r.units) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.average) + '" data-format="money">' + money(r, r.average, true) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.cogs) + '" data-format="money">' + money(r, r.cogs) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.wasteValue) + '" data-format="money">' + money(r, r.wasteValue) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.utility) + '" data-format="money">' + money(r, r.utility) + '</td>' +
				(detail ? '<td class="text-center"><a href="' + LEGACY + '?action=delete&id=' + esc(r.itemId) + '" data-id="' + esc(r.itemId) + '" class="delete hidden-print"><i class="material-icons text-danger">close</i></a></td>' : '') +
				'</tr>';
		});
		var span = detail ? 7 : 5;
		var tail = detail ? '<th></th>' : '';
		var foot = '</tbody><tfoot><tr><th colspan="' + span + '">TOTALES:</th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th>' + tail + '</tr></tfoot>';
		return head + body + foot;
	}

	function buildCompoundTable(rows, byDay) {
		var head = '<thead class="text-u-c"><tr>' +
			(byDay ? '<th>Fecha</th>' : '') +
			'<th>Compuesto</th><th>Código/SKU</th><th class="text-center">Cantidad</th><th class="text-center">Costo</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body += '<tr>' +
				(byDay ? '<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' : '') +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.sku) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.count) + '">' + fmtQty(r.count) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.cost) + '" data-format="money">' + fmt(r.cost) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th' + (byDay ? ' colspan="3"' : ' colspan="2"') + '>TOTALES</th><th class="text-right"></th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function renderKpis(t) {
		if (!t) { return; }
		$('.globalQty').text(fmtQty(t.qty));
		$('.globalCogs').text(fmt(t.cogs));
		$('.globalUtility').text(fmt(t.utility));
	}

	function loadGeneral() {
		var url = BFF + '?view=general&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#generalTable", "url": url, "iniData": buildTable(res.data.rows || [], false),
					"table": ".table1", "sort": 5, "footerSumCol": [5, 7, 9],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'table1', "fileTitle": 'Produccion General',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="table1" data-name="Produccion">Exportar Listado</a>', right: '' }
				});
				renderKpis(res.data.totals);
			}
		});
		window.xhrs.push(xhr);
	}

	function loadDetail() {
		var url = BFF + '?view=detail&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#detailTable", "url": url, "iniData": buildTable(res.data.rows || [], true),
					"table": ".table2", "sort": 0, "footerSumCol": [7, 9, 11],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'table2', "fileTitle": 'Produccion Detallado',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="table2" data-name="Produccion Detallado">Exportar Listado</a>', right: '' }
				}, function (oTable) {
					onClickWrap('#table2 .clickrow', function (event, tis) {
						var load = tis.data('load');
						if (!load) { return; }
						loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
					}, false, true);
					onClickWrap('.delete', function (event, tis) {
						var href = tis.attr('href');
						var $row = tis.closest('tr');
						ncmDialogs.confirm('¿Desea eliminar este registro de producción?', '', 'warning', function (conf) {
							if (conf) {
								$.get(href, function (data) {
									if (data === 'true' || data === true) { message('Eliminado', 'success'); oTable.row($row).remove().draw(); }
									else { message('No se pudo eliminar', 'danger'); }
								});
							}
						});
					});
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadCompound() {
		var byDay = $('#compoundByDay').is(':checked');
		var url = BFF + '?view=compound&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO) + (byDay ? '&byDay=1' : '');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#compoundTable", "url": url, "iniData": buildCompoundTable(res.data.rows || [], byDay),
					"table": ".table3", "sort": 0, "footerSumCol": byDay ? [3, 4] : [2, 3],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'table3', "fileTitle": 'Compuestos',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="table3" data-name="Compuestos">Exportar Listado</a>', right: '' }
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
					window.currency = RS.currency; window.decimal = RS.decimal; window.thousandSeparator = RS.thousand;
					$('.bff-company-name').text(res.data.companyName || '');
				}
				loadGeneral();
			}
		});

		$('#detailTabLink').on('shown.bs.tab', function () { if (!loaded.detail) { loaded.detail = true; loadDetail(); } });
		$('#compoundTabLink').on('shown.bs.tab', function () { if (!loaded.compound) { loaded.compound = true; loadCompound(); } });
		$('#compoundByDay').on('change', function () { loadCompound(); });

		dateRangePickerForReports(moment(FROM, 'YYYY-MM-DD HH:mm:ss'), moment(TO, 'YYYY-MM-DD HH:mm:ss'));
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loaded.detail = false; loaded.compound = false;
			loadGeneral();
		});
	});

})();
