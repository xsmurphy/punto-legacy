/**
 * Front del reporte Cierres de Caja (a_report_drawers) — BFF de 3 niveles.
 *
 *   - config                       ← GET  /bff/bootstrap.php
 *   - { rows } (CRUDO)             ← GET  /bff/reports/drawers.php?from=&to=
 *   - { detail } (CRUDO)          ← GET  /bff/reports/drawers.php?id=<uuid>
 *   - cerrar/corregir/eliminar     ← POST /bff/reports/drawers.php (action=&id=…)
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS formatea montos/fechas, arma la tabla, el
 * detalle (desglose por medio de pago + totales) y calcula el total de caja + diferencia + colores.
 * El detalle se pide por drawerId (NO se confía en datos del cliente). esc() en datos.
 */
(function () {

	var BFF       = '/bff/reports/drawers.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function money(n) { return esc(RS.currency) + ' ' + fmt(n); }
	function niceDate(iso) {
		if (!iso) { return '—'; }
		var m = moment(iso);
		return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : '—';
	}
	// Inverso de fmt para el POST: locale → número plano (parseo presentacional en el front).
	function parseAmount(str) {
		str = String(str == null ? '' : str);
		if (RS.thousand === 'dot') { str = str.replace(/\./g, '').replace(',', '.'); }
		else                       { str = str.replace(/,/g, ''); }
		str = str.replace(/[^0-9.\-]/g, '');
		var n = parseFloat(str) || 0;
		if (RS.decimal === 'no') { n = Math.trunc(n); }
		return n;
	}

	// Total de caja esperado = (vendido + apertura + ingresos) − extracciones.
	function totalDrawerOf(r) {
		return (parseFloat(r.sold) + parseFloat(r.openAmount) + parseFloat(r.income)) - parseFloat(r.expense);
	}
	// Diferencia cierre vs total esperado → {html, raw}.
	function diffInfo(closeAmount, totalDrawer) {
		var ca = parseFloat(closeAmount) || 0;
		var td = parseFloat(totalDrawer) || 0;
		if (ca < td) { return { html: '<span class="text-danger">-' + fmt(td - ca) + '</span>', raw: ca - td }; }
		if (ca > td) { return { html: '<span>' + fmt(ca - td) + '</span>', raw: ca - td }; }
		return { html: '<i class="material-icons text-success">check</i>', raw: 0 };
	}

	function buildTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Sucursal</th><th>Caja</th><th>Apertura</th><th>Por</th><th>Monto</th>' +
			'<th>Cierre</th><th>Por</th><th>Monto</th><th class="text-center">Diferencia</th><th></th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var closeCells;
			if (r.isClosed) {
				var td   = totalDrawerOf(r);
				var diff = diffInfo(r.closeAmount, td);
				closeCells =
					'<td data-order="' + esc(r.closeDate) + '">' + niceDate(r.closeDate) + '</td>' +
					'<td>' + esc(r.closeUserName) + '</td>' +
					'<td class="text-right lter" data-order="' + (parseFloat(r.closeAmount) || 0) + '">' + fmt(r.closeAmount) + '</td>' +
					'<td class="text-center" data-order="' + diff.raw + '">' + diff.html + '</td>';
			} else {
				closeCells =
					'<td><span class="label bg-success text-white text-u-c">En curso</span></td>' +
					'<td>—</td><td class="text-right lter">—</td><td class="text-center">—</td>';
			}

			body +=
				'<tr id="' + esc(r.drawerId) + '" data-id="' + esc(r.drawerId) + '" class="clickrow pointer">' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.registerName) + '</td>' +
				'<td data-order="' + esc(r.openDate) + '">' + niceDate(r.openDate) + '</td>' +
				'<td>' + esc(r.openUserName) + '</td>' +
				'<td class="text-right bg-light lt" data-order="' + (parseFloat(r.openAmount) || 0) + '">' + fmt(r.openAmount) + '</td>' +
				closeCells +
				'<td class="text-center"><a href="#" class="deleteItem hidden-print" data-id="' + esc(r.drawerId) + '"><i class="material-icons text-danger">close</i></a></td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><td colspan="10"></td></tr></tfoot>';
		return head + body + foot;
	}

	// ─────────────── Detalle (modal) ───────────────
	function row(label, value, cls) {
		return '<div class="wrapper-sm b-b ' + (cls || '') + '">' + label +
			'<span class="pull-right font-bold">' + value + '</span></div>';
	}

	function detailHtml(d) {
		var totalCash = parseFloat(d.return) || 0;
		var paymentRows = '';
		$.each(d.payments || [], function (i, p) {
			paymentRows += row(esc(p.label || p.type), money(p.price));
			if (p.type === 'cash') { totalCash += parseFloat(p.price) || 0; }
		});

		var ventas      = parseFloat(d.sold) + Math.abs(parseFloat(d.return) || 0);
		var totalEfvo   = (parseFloat(d.openAmount) + totalCash + parseFloat(d.income)) - parseFloat(d.expense);
		var totalDrawer = totalDrawerOf(d);

		var html =
			'<div class="col-xs-12 wrapper bg-white text-left">' +
			'<div class="text-center h4">' + esc(d.outletName) + ' <span class="text-sm text-muted">' + esc(d.registerName) + '</span></div>' +
			row('Caja inicial', money(d.openAmount), 'font-bold') +
			paymentRows +
			row('Extracciones', money(d.expense)) +
			row('Ingresos', money(d.income), 'b-dark') +
			row('Ventas', money(ventas), 'font-bold') +
			(parseFloat(d.return) ? row('Nota de Crédito', money(d.return), 'font-bold') : '') +
			row('Total de Efectivo', money(totalEfvo), 'font-bold') +
			row('TOTAL', money(totalDrawer), 'bg-light lt font-bold');

		// Formulario: cerrar (si está abierta) o corregir (si está cerrada).
		var openDateVal  = d.openDate  && moment(d.openDate).isValid()  ? moment(d.openDate).format('YYYY-MM-DD HH:mm:ss')  : '';
		var closeDateVal = d.closeDate && moment(d.closeDate).isValid() ? moment(d.closeDate).format('YYYY-MM-DD HH:mm:ss') : '';

		html += '<div class="m-t" id="drawerForm" data-id="' + esc(d.drawerId) + '">';
		if (d.isClosed) {
			html +=
				'<div class="text-center m-b font-bold text-u-c m-t">Corregir Cierre</div>' +
				'<label class="text-xs font-bold text-u-c">Fecha de apertura</label>' +
				'<input type="text" class="form-control datepicker m-b-sm no-border b-b dOpenDate" value="' + esc(openDateVal) + '" autocomplete="off" />' +
				'<label class="text-xs font-bold text-u-c">Fecha de cierre</label>' +
				'<input type="text" class="form-control datepicker m-b-sm no-border b-b dCloseDate" value="' + esc(closeDateVal) + '" autocomplete="off" />' +
				'<label class="text-xs font-bold text-u-c">Monto de apertura</label>' +
				'<input class="form-control no-border b-b maskCurrency m-b-sm dOpenAmount" value="' + esc(String(Math.round(parseFloat(d.openAmount) || 0))) + '">' +
				'<label class="text-xs font-bold text-u-c">Monto de cierre</label>' +
				'<input class="form-control no-border b-b maskCurrency m-b-sm dCloseAmount" value="' + esc(String(Math.round(parseFloat(d.closeAmount) || 0))) + '">' +
				'<a href="#" class="doCorrect btn btn-info rounded text-u-c font-bold btn-block">Guardar</a>';
		} else {
			html +=
				'<div class="text-center m-b font-bold text-u-c m-t">Cerrar Caja</div>' +
				'<label class="text-xs font-bold text-u-c">Fecha de cierre</label>' +
				'<input type="text" class="form-control datepicker m-b-sm no-border b-b dCloseDate" value="" autocomplete="off" />' +
				'<label class="text-xs font-bold text-u-c">Monto de cierre</label>' +
				'<input class="form-control no-border b-b maskCurrency m-b-sm dCloseAmount" value="">' +
				'<a href="#" class="doClose btn btn-info rounded text-u-c font-bold btn-block">Guardar</a>';
		}
		html += '</div>';

		// Diferencia (sólo si está cerrada).
		if (d.isClosed) {
			var diff = diffInfo(d.closeAmount, totalDrawer);
			html += '<div class="m-t">Cierre <span class="pull-right">' + money(d.closeAmount) + '</span></div>' +
				'<div>Diferencia <span class="pull-right">' + diff.html + '</span></div>';
		}

		html += '</div>';
		return html;
	}

	function openDetail(id) {
		spinner('body', 'show');
		ncmHelpers.load({
			url: BFF + '?id=' + encodeURIComponent(id), httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				spinner('body', 'hide');
				if (!res || !res.ok || !res.data || !res.data.detail) { message('No se pudo cargar el detalle', 'danger'); return; }
				var d = res.data.detail;
				$('#modalTiny .modal-content').html(detailHtml(d));
				$('#modalTiny').modal('show');
				$('.datepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
				if ($('.maskCurrency').length) { masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal); }
				wireDetailActions(id);
			},
			fail: function () { spinner('body', 'hide'); message('No se pudo cargar el detalle', 'danger'); }
		});
	}

	function postMutation(data, okMsg) {
		ncmHelpers.load({
			url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false, data: data,
			success: function (resp) {
				if (resp && resp.ok) {
					message(okMsg, 'success');
					$('#modalTiny').modal('hide');
					loadTable();
				} else {
					message('No se pudo procesar la acción', 'danger');
				}
			},
			fail: function () { message('No se pudo procesar la acción', 'danger'); }
		});
	}

	function wireDetailActions(id) {
		$('.doClose').off('click').on('click', function (e) {
			e.preventDefault();
			var date = $('.dCloseDate').val();
			if (!date) { message('Debe ingresar una fecha de cierre', 'danger'); return; }
			postMutation({ action: 'close', id: id, closeDate: date, closeAmount: parseAmount($('.dCloseAmount').val()) }, 'Caja cerrada');
		});
		$('.doCorrect').off('click').on('click', function (e) {
			e.preventDefault();
			postMutation({
				action: 'correct', id: id,
				openDate:    $('.dOpenDate').val(),
				closeDate:   $('.dCloseDate').val(),
				openAmount:  parseAmount($('.dOpenAmount').val()),
				closeAmount: parseAmount($('.dCloseAmount').val())
			}, 'Cierre actualizado');
		});
	}

	function loadTable() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(res.data.rows || []), "table": ".table1", "sort": 2,
					"footerSumCol": false,
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 1000, "nolimit": true,
					"tableName": 'tableDrawers', "fileTitle": 'control_de_cajas',
					"ncmTools": { left: '', right: '' }
				}, function () {
					bindRowEvents();
					onClickWrap('.exportTable', function (event, tis) { table2Xlsx(tis.data('table'), tis.data('name')); }, false, true);
				});
			}
		});
		window.xhrs.push(xhr);
	}

	function bindRowEvents() {
		onClickWrap('.deleteItem', function (event, tis) {
			event.stopPropagation();
			var id = tis.data('id');
			ncmDialogs.confirm('Realmente desea eliminar esta caja?', '', 'question', function (confirme) {
				if (confirme !== true) { return; }
				postMutation({ action: 'delete', id: id }, 'Caja eliminada');
			});
		}, false, true);

		onClickWrap('#tableDrawers tr.clickrow', function (event, tis) {
			openDetail(tis.attr('id'));
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
				loadTable();
			}
		});

		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadTable();
		});
	});

})();
