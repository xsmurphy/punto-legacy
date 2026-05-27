/**
 * Front del reporte Facturas Recurrentes (a_report_recurring) — BFF de 3 niveles.
 *
 *   - config                      ← GET  /bff/bootstrap.php
 *   - { rows } (CRUDO)            ← GET  /bff/reports/recurring.php
 *   - pausar/activar/eliminar     ← POST /bff/reports/recurring.php (action=&id=)
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS mapea frecuencia→label y estado→
 * {label,color}, formatea fechas + total, arma los links (cliente / doc inicial) y los
 * botones de acción. esc() en datos. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/recurring.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	function niceDate(iso) {
		if (!iso) { return '—'; }
		var m = moment(iso);
		return m.isValid() ? m.format('DD/MM/YYYY') : '—';
	}

	// frecuencia (cruda) → etiqueta. Soporta valores legacy (daily/weekly/…) y nuevos (day/week/…).
	function frecLabel(f) {
		switch (String(f)) {
			case 'daily':     case 'day':       return 'Diaria';
			case 'weekly':    case 'week':      return 'Semanal';
			case 'monthly':   case 'month':     return 'Mensual';
			case 'quarterly':                   return 'Trimestral';
			case 'anual':     case 'year':      return 'Anual';
			default:                            return esc(f);
		}
	}

	// estado (crudo) → {label, color} (presentación, en el front).
	function statusInfo(s) {
		if (s === 0) { return { name: 'Finalizado', bg: 'bg-success' }; }
		if (s === 2) { return { name: 'Pausado',    bg: 'bg-warning' }; }
		return         { name: 'Activo',     bg: 'bg-light' };
	}

	// Botones de acción según el estado actual.
	function actions(r) {
		var id  = esc(r.recurringId);
		var rm  = '<a href="#" class="action" data-type="remove" data-id="' + id + '"><i class="material-icons text-danger">close</i></a>';
		if (r.status === 2) {
			return '<a href="#" class="m-r action" data-type="activate" data-id="' + id + '"><i class="material-icons text-info">play_circle_outline</i></a>' + rm;
		}
		if (r.status === 1) {
			return '<a href="#" class="m-r action" data-type="pause" data-id="' + id + '"><i class="material-icons text-info">pause</i></a>' + rm;
		}
		return rm;
	}

	function buildTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Cliente</th><th>Documento Inicial</th><th>Próxima</th><th>Finalización</th>' +
			'<th>Frecuencia</th><th>Estado</th><th>Total</th><th>Acciones</th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			var st     = statusInfo(parseInt(r.status, 10) || 0);
			var cName  = esc(r.clientName) + (r.clientSecondName ? ' <span class="text-muted">' + esc(r.clientSecondName) + '</span>' : '');
			var cLink  = r.clientId
				? '<a href="#" class="clickrow" data-load="/a_contacts?action=form&id=' + esc(r.clientId) + '&type=wl&ro=1">' + cName + '</a>'
				: cName;
			var doc    = r.txUid
				? '<a href="#" class="clickrow" data-load="/a_report_transactions?action=edit&uid=' + esc(r.txUid) + '&ro=true"><span class="text-info text-u-l">' + esc(r.invoiceNo) + '</span></a>'
				: esc(r.invoiceNo);

			body +=
				'<tr id="' + esc(r.recurringId) + '">' +
				'<td>' + cLink + '</td>' +
				'<td>' + doc + '</td>' +
				'<td data-order="' + esc(r.nextDate) + '">' + niceDate(r.nextDate) + '</td>' +
				'<td data-order="' + esc(r.endDate) + '">' + niceDate(r.endDate) + '</td>' +
				'<td><span class="label bg-info">' + frecLabel(r.frecuency) + '</span></td>' +
				'<td><span class="label ' + st.bg + '">' + st.name + '</span></td>' +
				'<td class="text-right bg-light lter" data-order="' + (parseFloat(r.total) || 0) + '" data-format="money">' + esc(RS.currency) + ' ' + fmt(r.total) + '</td>' +
				'<td class="text-center">' + actions(r) + '</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th>TOTALES:</th><th colspan="5"></th><th class="text-right"></th><th></th></tr></tfoot>';
		return head + body + foot;
	}

	function loadTable() {
		var xhr = ncmHelpers.load({
			url: BFF, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ncmDataTables({
					"container": ".tableContainer", "url": BFF, "rawUrl": BFF,
					"iniData": buildTable(res.data.rows || []), "table": ".table1", "sort": 2,
					"footerSumCol": [6],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "nolimit": true,
					"tableName": 'tableRecurring', "fileTitle": 'transacciones_recurrentes',
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
		onClickWrap('.clickrow', function (event, tis) {
			var load = tis.data('load');
			if (load) { loadForm(load, '#modalLarge .modal-content', function () { $('#modalLarge').modal('show'); }); }
		}, false, true);

		// Acciones (pausar/activar/eliminar): confirma → POST al BFF → recarga la tabla.
		onClickWrap('.action', function (event, tis) {
			var id   = tis.data('id');
			var type = tis.data('type');
			var msg  = type === 'pause' ? 'Realmente quiere pausar?'
				: type === 'activate' ? 'Realmente quiere activar?'
				: 'Realmente quiere eliminar?';

			ncmDialogs.confirm(msg, '', 'question', function (confirme) {
				if (confirme !== true) { return; }
				ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
					data: { action: type, id: id },
					success: function (resp) {
						if (resp && resp.ok) {
							message('Acción realizada', 'success');
							loadTable();
						} else {
							message('Error al procesar la acción', 'danger');
						}
					},
					fail: function () { message('Error al procesar la acción', 'danger'); }
				});
			});
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
	});

})();
