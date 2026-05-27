/**
 * Front del reporte de Agendamientos (a_report_schedule) — BFF de 3 niveles.
 *
 *   - config                      ← GET /bff/bootstrap.php
 *   - detail (rows + summary)      ← GET /bff/reports/schedule.php?view=detail&from&to[&ui]
 *   - stats / cusStats (rows)      ← GET /bff/reports/schedule.php?view=stats&...&uit=usr|cus
 *   - sessions (rows)              ← GET /bff/reports/schedule.php?view=sessions&...
 *
 * El BFF/Service devuelven datos CRUDOS; este JS formatea TODO + arma tablas/KPIs/donut. esc() en datos.
 * El modal de sesiones (detail por id) y el write (delete) NO se migraron: se sirven por el PHP legacy
 * vía /a_report_schedule?action=. El click en una fila de detalle abre el form de TRANSACTIONS legacy
 * (/a_report_transactions?action=edit). Drill-down por query/hash: ui=userId.
 */
(function () {

	var BFF        = '/bff/reports/schedule.php';
	var BOOTSTRAP  = '/bff/bootstrap.php';
	var LEGACY     = '/a_report_schedule';
	var LEGACY_TX  = '/a_report_transactions';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	var loaded = { stats: false, cusStats: false, sessions: false };
	var donutChart = null;

	function param(name) {
		var s = window.location.search || '', h = window.location.hash || '', q = s;
		if (h.indexOf('?') !== -1) { q += '&' + h.slice(h.indexOf('?') + 1); }
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1]) : '';
	}
	var UI = param('ui');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function num(v) { return parseFloat(v) || 0; }
	function dateOnly(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY') : 'Sin fecha'; }
	function timeOnly(iso) { var m = moment(iso); return m.isValid() ? m.format('HH:mm') : 'Sin fecha'; }
	function niceDate(iso) { var m = moment(iso); return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : ''; }
	function duration(a, b) {
		var ma = moment(a), mb = moment(b);
		if (!ma.isValid() || !mb.isValid()) { return '00:00'; }
		var mins = Math.max(0, mb.diff(ma, 'minutes'));
		return Math.floor(mins / 60) + ':' + ('0' + (mins % 60)).slice(-2);
	}

	// Estado → {name, icon, borderClass}, según el legacy.
	var STATUS = {
		0: { name: 'Nuevo',       icon: 'stars',                  cls: 'b-l b-light b-5x' },
		1: { name: 'Confirmado',  icon: 'thumb_up',               cls: 'b-l b-info b-5x' },
		2: { name: 'Llegó',       icon: 'keyboard_arrow_down',    cls: 'b-l b-warning b-5x' },
		3: { name: 'En proceso',  icon: 'keyboard_arrow_right',   cls: 'b-l b-success b-5x' },
		4: { name: 'Cancelado',   icon: 'block',                  cls: 'b-l b-danger b-5x' },
		5: { name: 'No show',     icon: 'person_add_disabled',    cls: 'b-l b-dark b-5x' },
		6: { name: 'Finalizado',  icon: 'check',                  cls: 'b-l b-dark b-5x' },
		7: { name: 'Bloqueado',   icon: 'block',                  cls: 'b-l b-dark b-5x' }
	};
	function statusCell(s) {
		var st = STATUS[s] || STATUS[7];
		return '<td data-filter="' + esc(st.name) + '" data-order="' + s + '" class="' + st.cls + '">' +
			'<span data-toggle="tooltip" data-placement="right" title="' + esc(st.name) + '"><i class="material-icons">' + esc(st.icon) + '</i></span></td>';
	}

	function buildDetailTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th>Estado</th><th># Doc. Venta</th><th># Agendamiento</th><th>Agendado</th><th>Sucursal</th>' +
			'<th>Asignado</th><th>Responsable</th><th>Cliente</th><th>Motivo</th><th>Descripción</th>' +
			'<th>Fecha</th><th>Inicio</th><th>Fin</th><th>Duración</th><th class="text-center">Valor</th>' +
			'<th class="text-center">Asistencia</th><th></th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			var doc = r.docInvoice
				? '<a href="#" class="clickrowDoc" data-load="' + LEGACY_TX + '?action=edit&id=' + esc(r.docTxId) + '&ro=true"><span class="text-info text-u-l">' + esc(r.docInvoice) + '</span></a>'
				: '-';
			var items = $.map(r.items || [], function (n) { return esc(n); }).join(' <strong>-</strong> ');
			var attended = r.attended ? '<i class="material-icons text-success">check</i>' : '';
			body +=
				'<tr class="clickrow pointer row' + esc(r.transactionId) + '" data-id="' + esc(r.transactionId) + '" data-load="' + LEGACY_TX + '?action=edit&id=' + esc(r.transactionId) + '&ro=true">' +
				statusCell(r.transactionStatus) +
				'<td>' + doc + '</td>' +
				'<td>' + esc(r.scheduleNo) + '</td>' +
				'<td data-order="' + esc(r.scheduledAt) + '">' + niceDate(r.scheduledAt) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.responsibleName) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td>' + items + '</td>' +
				'<td data-order="' + esc(r.fromDate) + '">' + dateOnly(r.fromDate) + '</td>' +
				'<td data-order="' + esc(r.fromDate) + '">' + timeOnly(r.fromDate) + '</td>' +
				'<td data-order="' + esc(r.toDate) + '">' + timeOnly(r.toDate) + '</td>' +
				'<td><span class="label bg-light">' + duration(r.fromDate, r.toDate) + '</span></td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.total) + '" data-format="money">' + fmt(r.total) + '</td>' +
				'<td class="text-center">' + attended + '</td>' +
				'<td class="text-center"><a href="' + LEGACY + '?action=delete&id=' + esc(r.transactionId) + '" data-id="' + esc(r.transactionId) + '" class="delete hidden-print"><i class="material-icons text-danger">close</i></a></td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="13">TOTALES:</th><th class="text-right"></th><th colspan="3"></th></tr></tfoot>';
		return head + body + foot;
	}

	function buildStatsTable(rows, firstCol) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th>' + esc(firstCol) + '</th><th class="text-center">Pendientes</th><th class="text-center">Finalizados</th>' +
			'<th class="text-center">Cancelados/Re Agendados</th><th class="text-center">No Shows</th><th class="text-center">Bloqueos de Agenda</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body += '<tr>' +
				'<td>' + esc(r.name) + '</td>' +
				'<td class="text-right">' + (r.pending || 0) + '</td>' +
				'<td class="text-right">' + (r.ended || 0) + '</td>' +
				'<td class="text-right">' + (r.cancelled || 0) + '</td>' +
				'<td class="text-right">' + (r.noshow || 0) + '</td>' +
				'<td class="text-right">' + (r.blocked || 0) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th>TOTALES:</th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function buildSessionsTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th># Documento</th><th>Adquirido</th><th>Artículo</th><th>Cliente</th>' +
			'<th class="text-center">Sesiones</th><th class="text-center">Realizadas</th><th class="text-center">Pendientes</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			body += '<tr class="pointer clickrowSession" data-id="' + esc(r.itemSoldId) + '" data-load="' + LEGACY + '?action=detail&id=' + esc(r.itemSoldId) + '&cid=' + esc(r.customerId) + '">' +
				'<td>' + esc(r.invoiceNo) + '</td>' +
				'<td data-order="' + esc(r.acquired) + '">' + dateOnly(r.acquired) + '</td>' +
				'<td>' + esc(r.itemName) + '</td>' +
				'<td>' + esc(r.customerName) + '</td>' +
				'<td class="text-right">' + (r.sessions || 0) + '</td>' +
				'<td class="text-right">' + (r.done || 0) + '</td>' +
				'<td class="text-right">' + (r.pending || 0) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="7"></th></tr></tfoot>';
		return head + body + foot;
	}

	function qstr(view, uit) {
		var p = { view: view, from: FROM, to: TO };
		if (UI) { p.ui = UI; }
		if (uit) { p.uit = uit; }
		return BFF + '?' + $.param(p);
	}

	function renderSummary(s) {
		if (!s) { return; }
		// Conteos = enteros (no formato de moneda).
		$('.new').text(s.new || 0);
		$('.ended').text(s.ended || 0);
		$('.cancelled').text(s.cancelled || 0);
		$('.noshow').text(s.noshow || 0);
		$('.totals').text(s.totals || 0);
		var ctx = document.getElementById('chart-contado');
		if (!ctx || typeof Chart === 'undefined') { return; }
		Chart.defaults.global.responsive = true;
		Chart.defaults.global.maintainAspectRatio = false;
		if (Chart.defaults.global.legend) { Chart.defaults.global.legend.display = false; }
		if (donutChart) { donutChart.destroy(); }
		donutChart = new Chart(ctx.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: ['Nuevos', 'Finalizados', 'Cancelados', 'No show'],
				datasets: [{ data: [num(s.new), num(s.ended), num(s.cancelled), num(s.noshow)], backgroundColor: ['#d9e4e6', '#778490', '#f26767', '#657789'] }]
			},
			options: { cutoutPercentage: 85, maintainAspectRatio: false }
		});
	}

	function loadDetail() {
		var url = qstr('detail');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#tableContainer", "url": url, "iniData": buildDetailTable(res.data.rows || []),
					"table": "#tableSchedule", "sort": 1, "footerSumCol": [14],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableSchedule', "fileTitle": 'Agenda',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="tableSchedule" data-name="Agenda">Exportar Listado</a>', right: '' },
					"colsFilter": { name: 'reportSchedule3', menu: [
						{ index: 0, name: 'Estado', visible: true }, { index: 1, name: '# Doc. Venta', visible: false },
						{ index: 2, name: '# Agendamiento', visible: false }, { index: 3, name: 'Agendado', visible: false },
						{ index: 4, name: 'Sucursal', visible: false }, { index: 5, name: 'Asignado', visible: true },
						{ index: 6, name: 'Responsable', visible: true }, { index: 7, name: 'Cliente', visible: true },
						{ index: 8, name: 'Motivo', visible: false }, { index: 9, name: 'Descripción', visible: false },
						{ index: 10, name: 'Fecha', visible: true }, { index: 11, name: 'Inicio', visible: false },
						{ index: 12, name: 'Fin', visible: false }, { index: 13, name: 'Duración', visible: true },
						{ index: 14, name: 'Valor', visible: true }, { index: 15, name: 'Asistencia', visible: false },
						{ index: 16, name: 'Acciones', visible: false }
					] }
				}, function (oTable) {
					$('[data-toggle="tooltip"]').tooltip();
					// Click en fila o en el # Doc. Venta → form de TRANSACTIONS legacy.
					onClickWrap('#tableSchedule .clickrow, #tableSchedule .clickrowDoc', function (event, tis) {
						var load = tis.data('load');
						if (!load) { return; }
						loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); });
					}, false, true);
					onClickWrap('.delete', function (event, tis) {
						var href = tis.attr('href');
						var $row = $('.row' + tis.data('id'));
						ncmDialogs.confirm('¿Realmente desea eliminar la transacción?', '', 'warning', function (conf) {
							if (conf) {
								oTable.row($row).remove().draw();
								$.get(href, function (data) {
									if (data === 'true' || data === true) { message('La transacción fue eliminada con éxito.', 'success'); }
									else { message('No se pudo eliminar la transacción.', 'danger'); }
								});
							}
						});
					});
				});
				renderSummary(res.data.summary);
			}
		});
		window.xhrs.push(xhr);
	}

	function loadStats(uit, container, tableName, firstCol) {
		var url = qstr('stats', uit);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": container, "url": url, "iniData": buildStatsTable(res.data.rows || [], firstCol),
					"table": '#' + tableName, "sort": 2, "footerSumCol": [1, 2, 3, 4, 5],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": tableName, "fileTitle": 'Estadisticas',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="' + tableName + '" data-name="Estadisticas">Exportar Listado</a>', right: '' }
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function loadSessions() {
		var url = qstr('sessions');
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": "#sessionsList", "url": url, "iniData": buildSessionsTable(res.data.rows || []),
					"table": "#tableSessions", "sort": 1, "footerSumCol": [],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableSessions', "fileTitle": 'Sesiones',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="tableSessions" data-name="Sesiones">Exportar Listado</a>', right: '' }
				}, function () {
					onClickWrap('.clickrowSession', function (event, tis) {
						var load = tis.data('load');
						loadForm(load, '#modalSmall .modal-content', function () { $('#modalSmall').modal('show'); });
					}, false, true);
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
				loadDetail();
			}
		});

		$('#statsTab').on('shown.bs.tab', function () { if (!loaded.stats) { loaded.stats = true; loadStats('usr', '#statsList', 'tableStats', 'Usuario'); } });
		$('#cusStatsTab').on('shown.bs.tab', function () { if (!loaded.cusStats) { loaded.cusStats = true; loadStats('cus', '#cusStatsList', 'tableCusStats', 'Cliente'); } });
		$('#sessionsTab').on('shown.bs.tab', function () { if (!loaded.sessions) { loaded.sessions = true; loadSessions(); } });

		dateRangePickerForReports(moment(FROM, 'YYYY-MM-DD HH:mm:ss'), moment(TO, 'YYYY-MM-DD HH:mm:ss'));
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loaded.stats = false; loaded.cusStats = false; loaded.sessions = false;
			loadDetail();
		});
	});

})();
