/**
 * Front del reporte Satisfacción (NPS) (a_report_satisfaction) — BFF de 3 niveles.
 *
 *   - config           ← GET  /bff/bootstrap.php
 *   - votos + NPS       ← GET  /bff/reports/satisfaction.php
 *   - borrar un voto    ← POST /bff/reports/satisfaction.php (action=delete&id=)
 *
 * Primer reporte con ESCRITURA por el BFF. El BFF manda datos crudos + conteos NPS
 * (REGLA RAÍZ 2); este JS formatea (timeago), arma barras + tabla y maneja el borrado.
 * esc() en los campos de datos. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/satisfaction.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// nivel → {clase, etiqueta} (presentación, en el front).
	function levelInfo(level) {
		if (level === 1) { return { bg: 'danger',  name: 'Malo' }; }
		if (level === 2) { return { bg: 'warning', name: 'Bueno' }; }
		return { bg: 'success', name: 'Excelente' };
	}

	function buildTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Nivel</th><th>Fecha</th><th>Cliente</th><th>Comentario</th><th>Sucursal</th><th></th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var lv = levelInfo(parseInt(r.level, 10) || 0);
			var ago = moment(r.date).isValid() ? moment(r.date).fromNow() : '';
			body +=
				'<tr class="clickrow pointer no-border b-l b-5x b-' + lv.bg + '" data-id="' + esc(r.transactionId) + '" data-load="/a_report_transactions?action=edit&id=' + esc(r.transactionId) + '&ro=true">' +
				'<td data-order="' + (parseInt(r.level, 10) || 0) + '"><span class="label bg-light">' + lv.name + '</span></td>' +
				'<td data-order="' + esc(r.date) + '"><span class="label bg-light" data-toggle="tooltip" data-placement="top" title="' + esc(r.date) + '"> ' + esc(ago) + ' </span></td>' +
				'<td> ' + esc(r.customerName) + ' </td>' +
				'<td> ' + esc(r.comment) + ' </td>' +
				'<td> ' + esc(r.outletName) + ' </td>' +
				'<td class="text-right"><a href="#" class="deleteItem" data-id="' + esc(r.satisfactionId) + '"><i class="text-danger material-icons">close</i></a></td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><td colspan="6"></td></tr></tfoot>';
		return head + body + foot;
	}

	function renderNps(nps) {
		$('#badP').text(nps.badPercent + '%');   $('#badC').text(nps.bad);
		$('#medP').text(nps.medPercent + '%');   $('#medC').text(nps.med);
		$('#goodP').text(nps.goodPercent + '%'); $('#goodC').text(nps.good);

		$('.satisfactionBarDetractors').attr('data-original-title', 'Detractores: ' + nps.bad + ' voto(s)').css('width', nps.badPercent + '%');
		$('.satisfactionBarPassives').attr('data-original-title', 'Pasivos: ' + nps.med + ' voto(s)').css('width', nps.medPercent + '%');
		$('.satisfactionBarPromoters').attr('data-original-title', 'Promotores: ' + nps.good + ' voto(s)').css('width', nps.goodPercent + '%');
	}

	function loadAll() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(res.data.rows || []), "table": ".table1", "sort": 1,
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 100,
					"tableName": 'tableTransactions', "fileTitle": 'Satisfaccion',
					"ncmTools": { left: '', right: '' }
				}, function () { bindRowEvents(); });

				renderNps(res.data.nps || { bad: 0, med: 0, good: 0, badPercent: 0, medPercent: 0, goodPercent: 0 });
				$('[data-toggle="tooltip"]').tooltip();
			}
		});
		window.xhrs.push(xhr);
	}

	function bindRowEvents() {
		// Borrado: confirma → POST al BFF (escritura front→BFF→API) → quita la fila.
		onClickWrap('.deleteItem', function (event, tis) {
			var id = tis.data('id');
			$('table tbody tr').removeClass('editting');
			tis.closest('tr').addClass('editting');

			ncmDialogs.confirm('Realmente desea eliminar?', '', 'question', function (r) {
				if (r !== true) { return; }
				ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
					data: { action: 'delete', id: id },
					success: function (resp) {
						if (resp && resp.ok) {
							$('.editting').remove();
							message('Dato eliminado', 'success');
						} else {
							message('Error al eliminar', 'danger');
						}
					},
					fail: function () { message('Error al eliminar', 'danger'); }
				});
			});
		}, false, true);

		onClickWrap('.clickrow', function (event, tis) {
			var load = tis.data('load');
			if (load) { loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); }); }
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
				loadAll();
			}
		});

		dateRangePickerForReports(
			moment(FROM, 'YYYY-MM-DD HH:mm:ss'),
			moment(TO, 'YYYY-MM-DD HH:mm:ss')
		);
		$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
			FROM = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
			TO   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
			loadAll();
		});
	});

})();
