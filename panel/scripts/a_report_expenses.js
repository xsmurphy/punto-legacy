/**
 * Front del reporte Movimientos de Caja (a_report_expenses) — BFF de 3 niveles.
 *
 *   - config                          ← GET  /bff/bootstrap.php
 *   - { rows, users } (CRUDO)         ← GET  /bff/reports/expenses.php?from=&to=
 *   - editar / eliminar               ← POST /bff/reports/expenses.php (action=&id=…)
 *
 * El BFF manda datos crudos (REGLA RAÍZ 2); este JS mapea type→{Ingreso|Extracción}, formatea
 * fecha + monto, arma la tabla, el modal de edición (con select de usuarios) y las acciones.
 * esc() en datos. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */
(function () {

	var BFF       = '/bff/reports/expenses.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS    = { currency: '', decimal: 'no', thousand: 'dot' };
	var USERS = [];   // [{id, name}] para el dropdown del modal
	var ROWS  = {};   // expensesId → fila cruda (para hidratar el modal sin re-fetch)

	var FROM = moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00');
	var TO   = moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	// Inverso de fmt: del valor enmascarado (locale) a número plano para el POST. El parseo de
	// locale es presentacional → vive en el front (REGLA RAÍZ 2); la API recibe un número crudo.
	function parseAmount(str) {
		str = String(str == null ? '' : str);
		if (RS.thousand === 'dot') { str = str.replace(/\./g, '').replace(',', '.'); }
		else                       { str = str.replace(/,/g, ''); }
		str = str.replace(/[^0-9.\-]/g, '');
		var n = parseFloat(str) || 0;
		if (RS.decimal === 'no') { n = Math.trunc(n); }
		return n;
	}
	function niceDate(iso) {
		if (!iso) { return '—'; }
		var m = moment(iso);
		return m.isValid() ? m.format('DD-MM-YYYY HH:mm') : '—';
	}
	function typeLabel(t) { return parseInt(t, 10) ? 'Ingreso' : 'Extracción'; }

	function buildTable(rows) {
		var head =
			'<thead class="text-u-c"><tr>' +
			'<th>Fecha</th><th>Sucursal</th><th>Caja</th><th>Usuario</th><th>Nota</th>' +
			'<th>Tipo</th><th class="text-center">Total</th><th class="text-center"></th>' +
			'</tr></thead><tbody>';

		var body = '';
		$.each(rows, function (i, r) {
			body +=
				'<tr id="' + esc(r.expensesId) + '" data-id="' + esc(r.expensesId) + '" class="clickrow pointer">' +
				'<td data-order="' + esc(r.date) + '">' + niceDate(r.date) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td>' + esc(r.registerName) + '</td>' +
				'<td>' + esc(r.userName) + '</td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td><span class="badge bg-info">' + typeLabel(r.type) + '</span></td>' +
				'<td class="text-right bg-light lter" data-order="' + (parseFloat(r.amount) || 0) + '" data-format="money">' + esc(RS.currency) + ' ' + fmt(r.amount) + '</td>' +
				'<td class="text-center">' +
				'<a href="#" class="deleteItem hidden-print" data-id="' + esc(r.expensesId) + '"><i class="material-icons text-danger">close</i></a>' +
				'</td>' +
				'</tr>';
		});

		var foot = '</tbody><tfoot><tr><th>TOTALES:</th><th colspan="5"></th><th class="text-right"></th><th></th></tr></tfoot>';
		return head + body + foot;
	}

	// Modal de edición (fecha / usuario / total / nota), hidratado desde la fila en memoria.
	function editForm(r) {
		var userOpts = '<option value="">—</option>';
		$.each(USERS, function (i, u) {
			userOpts += '<option value="' + esc(u.id) + '"' + (u.id === r.userId ? ' selected' : '') + '>' + esc(u.name) + '</option>';
		});
		// La fecha viene ISO (con zona); el input la edita en 'YYYY-MM-DD HH:mm:ss'.
		var dateVal = r.date && moment(r.date).isValid() ? moment(r.date).format('YYYY-MM-DD HH:mm:ss') : '';

		return '' +
			'<div class="col-xs-12 no-padder bg-white r-24x clear">' +
			'  <form id="updateForm" name="updateForm">' +
			'    <div class="col-xs-12 m-t m-b-lg">' +
			'      <label class="font-bold text-u-c text-xs">Fecha</label>' +
			'      <input type="text" class="form-control no-border rounded bg-light datetimepicker pointer text-center" name="date" value="' + esc(dateVal) + '" autocomplete="off" readonly />' +
			'      <label class="font-bold text-u-c text-xs m-t">Usuario</label>' +
			'      <select name="user" class="form-control no-border b-b select2">' + userOpts + '</select>' +
			'      <label class="text-u-c font-bold text-xs m-t">Total</label>' +
			// Valor inicial = unidades enteras crudas: masksCurrency despoja separadores y trata el
			// resto como entero (un "150.000,00" se convertiría en 15.000.000). Pasar "150000" → "150.000".
			'      <input type="text" class="maskCurrency no-border b-b form-control font-bold input-lg" name="total" value="' + esc(String(Math.round(r.amount))) + '" id="payAmountField">' +
			'      <label class="font-bold text-u-c text-xs m-t">Nota</label>' +
			'      <textarea class="form-control no-bg no-border b-b" name="note" autocomplete="off" placeholder="Nota">' + esc(r.note) + '</textarea>' +
			'    </div>' +
			'    <div class="col-xs-12 wrapper bg-light lter text-center">' +
			'      <button type="submit" class="btn btn-info btn-lg btn-rounded text-u-c font-bold">Guardar</button>' +
			'      <input type="hidden" name="id" value="' + esc(r.expensesId) + '">' +
			'    </div>' +
			'  </form>' +
			'</div>';
	}

	function openEdit(id) {
		var r = ROWS[id];
		if (!r) { return; }
		$('#modalTiny .modal-content').html(editForm(r));
		$('#modalTiny').modal('show');

		if (typeof select2Simple === 'function') { select2Simple('.select2'); }
		if ($('.maskCurrency').length) { masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal); }
		$('.datetimepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
		$('#payAmountField').focus();

		$('#updateForm').off('submit').on('submit', function (e) {
			e.preventDefault();
			var f = $(this);
			ncmHelpers.load({
				url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
				data: {
					action: 'update',
					id:    f.find('[name=id]').val(),
					date:  f.find('[name=date]').val(),
					total: parseAmount(f.find('[name=total]').val()),
					note:  f.find('[name=note]').val(),
					user:  f.find('[name=user]').val()
				},
				success: function (resp) {
					if (resp && resp.ok) {
						message('Guardado', 'success');
						$('#modalTiny').modal('hide');
						loadTable();
					} else {
						message('Error al guardar', 'danger');
					}
				},
				fail: function () { message('Error al guardar', 'danger'); }
			});
			return false;
		});
	}

	function loadTable() {
		var url = BFF + '?from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO);
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				var d = res.data || {};
				USERS = d.users || [];
				ROWS  = {};
				$.each(d.rows || [], function (i, r) { ROWS[r.expensesId] = r; });

				ncmDataTables({
					"container": ".tableContainer", "url": url, "rawUrl": url,
					"iniData": buildTable(d.rows || []), "table": ".table1", "sort": 0,
					"footerSumCol": [6],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"offset": 0, "limit": 500, "nolimit": true,
					"tableName": 'tableExtractions', "fileTitle": 'extracciones_de_caja',
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
		// Eliminar: confirma → POST al BFF → recarga la tabla (mismo patrón que recurring).
		onClickWrap('.deleteItem', function (event, tis) {
			event.stopPropagation();
			var id = tis.data('id');
			ncmDialogs.confirm('Realmente desea eliminar?', '', 'question', function (confirme) {
				if (confirme !== true) { return; }
				ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
					data: { action: 'delete', id: id },
					success: function (resp) {
						if (resp && resp.ok) {
							message('Eliminado', 'success');
							loadTable();
						} else {
							message('Error al eliminar', 'danger');
						}
					},
					fail: function () { message('Error al eliminar', 'danger'); }
				});
			});
		}, false, true);

		// Click en la fila → modal de edición.
		onClickWrap('#tableExtractions tr.clickrow', function (event, tis) {
			openEdit(tis.attr('id'));
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
