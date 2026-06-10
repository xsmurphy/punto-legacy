/**
 * Front del reporte de Gift Cards (a_report_giftcards) — BFF de 3 niveles.
 *
 *   - config              ← GET  /bff/bootstrap.php
 *   - detail (rows + kpi) ← GET  /bff/reports/giftcards.php?view=detail[&singleRow]
 *   - delete / update     ← POST /bff/reports/giftcards.php (action=delete|update)
 *
 * El BFF devuelve filas CRUDAS + KPIs; este JS formatea TODO + arma la tabla, el modal de
 * edición y las acciones. Select2 de beneficiario sigue en /a_contacts (endpoint legacy de
 * búsqueda de contactos, sin BFF propio aún).
 */
(function () {

	var BFF       = '/bff/reports/giftcards.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var RS   = { currency: '', decimal: 'no', thousand: 'dot' };
	var ROWS = {};   // giftCardSoldId → fila cruda (para hidratar el modal sin re-fetch)

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
	// Inverso de fmt: del valor enmascarado (locale) a número plano para el POST.
	function parseAmount(str) {
		str = String(str == null ? '' : str);
		if (RS.thousand === 'dot') { str = str.replace(/\./g, '').replace(',', '.'); }
		else                       { str = str.replace(/,/g, ''); }
		str = str.replace(/[^0-9.\-]/g, '');
		var n = parseFloat(str) || 0;
		if (RS.decimal === 'no') { n = Math.trunc(n); }
		return n;
	}
	function niceDate(iso, withTime) {
		var m = moment(iso);
		if (!m.isValid()) { return '-'; }
		return m.format(withTime ? 'DD-MM-YYYY HH:mm' : 'DD-MM-YYYY');
	}
	function num(v) { return parseFloat(v) || 0; }
	function chunk4(s) { return String(s || '').replace(/(.{4})/g, '$1 ').trim(); }

	function buildTable(rows) {
		var head = '<thead class="text-u-c"><tr>' +
			'<th></th><th># Documento</th><th>Beneficiario</th><th>Vencimiento</th><th>Código</th>' +
			'<th>Código Único</th><th>Nota</th><th>Último uso</th><th>Envío</th><th>Sucursal</th><th>Saldo</th>' +
			'</tr></thead><tbody>';
		var body = '';
		$.each(rows, function (i, r) {
			// Sólo hex válido en el style (evita inyección CSS vía ';'/':'; esc() no cubre esos).
			var safeColor = /^[0-9a-fA-F]{3,8}$/.test(r.color || '') ? r.color : '';
			var color = safeColor ? ' style="color:#' + safeColor + '"' : '';
			body +=
				'<tr class="clickrow pointer" data-id="' + esc(r.giftCardSoldId) + '">' +
				'<td><i class="material-icons"' + color + '>card_giftcard</i></td>' +
				'<td>' + esc(r.doc) + '</td>' +
				'<td>' + esc(r.beneficiary || '-') + '</td>' +
				'<td data-order="' + esc(r.expires) + '">' + niceDate(r.expires) + '</td>' +
				'<td>' + esc(r.code) + '</td>' +
				'<td><span class="badge">' + esc(chunk4(r.ucode)) + '</span></td>' +
				'<td>' + esc(r.note) + '</td>' +
				'<td data-order="' + esc(r.lastUsed) + '">' + niceDate(r.lastUsed, true) + '</td>' +
				'<td data-order="' + esc(r.sendDate) + '">' + niceDate(r.sendDate) + '</td>' +
				'<td>' + esc(r.outletName) + '</td>' +
				'<td class="text-right bg-light lter" data-order="' + num(r.value) + '" data-format="money">' + fmt(r.value) + '</td>' +
				'</tr>';
		});
		var foot = '</tbody><tfoot><tr><th colspan="10">TOTALES:</th><th class="text-right"></th></tr></tfoot>';
		return head + body + foot;
	}

	function renderKpis(k) {
		if (!k) { return; }
		$('.expired').text(k.expired || 0);
		$('.soon').text(k.soon || 0);
		$('.nocredit').text(k.noCredit || 0);
		$('.available').text(k.available || 0);
		$('.availableValue').text(fmt(k.availableValue));
	}

	function editForm(r) {
		// Pre-selecciona beneficiario existente: select2Ajax lo respeta si hay <option selected>.
		var benefOpt = r.beneficiaryId
			? '<option value="' + esc(r.beneficiaryId) + '" selected>' + esc(r.beneficiary || r.beneficiaryId) + '</option>'
			: '';
		return '' +
			'<div class="modal-header">' +
			'  <button type="button" class="close cancelItemView" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
			'  <h4 class="modal-title">Gift Card</h4>' +
			'</div>' +
			'<div class="modal-body">' +
			'  <form id="editGC" class="form-horizontal">' +
			'    <input type="hidden" name="id" value="' + esc(r.giftCardSoldId) + '">' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Beneficiario</label>' +
			'      <div class="col-sm-9"><select name="beneficiaryId" class="form-control chosen-select"><option value="">—</option>' + benefOpt + '</select></div>' +
			'    </div>' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Código</label>' +
			'      <div class="col-sm-9"><input type="text" name="code" class="form-control maskInteger" value="' + esc(r.code || 0) + '" autocomplete="off"></div>' +
			'    </div>' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Saldo</label>' +
			'      <div class="col-sm-9"><input type="text" name="value" class="form-control maskCurrency" value="' + esc(String(Math.round(num(r.value)))) + '" autocomplete="off"></div>' +
			'    </div>' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Vencimiento</label>' +
			'      <div class="col-sm-9"><input type="text" name="expires" class="form-control datepicker" value="' + esc(r.expires || '') + '" autocomplete="off" readonly></div>' +
			'    </div>' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Fecha envío</label>' +
			'      <div class="col-sm-9"><input type="text" name="sendDate" class="form-control datepicker" value="' + esc(r.sendDate || '') + '" autocomplete="off" readonly></div>' +
			'    </div>' +
			'    <div class="form-group">' +
			'      <label class="col-sm-3 control-label">Nota</label>' +
			'      <div class="col-sm-9"><input type="text" name="note" class="form-control" value="' + esc(r.note || '') + '" autocomplete="off"></div>' +
			'    </div>' +
			'  </form>' +
			'</div>' +
			'<div class="modal-footer">' +
			'  <button type="button" class="btn btn-danger pull-left deleteGC" data-id="' + esc(r.giftCardSoldId) + '">Eliminar</button>' +
			'  <button type="button" class="btn btn-default cancelItemView">Cancelar</button>' +
			'  <button type="button" class="btn btn-primary saveGC">Guardar</button>' +
			'</div>';
	}

	function saveGC() {
		var f = $('#editGC');
		ncmHelpers.load({
			url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
			data: {
				action:        'update',
				id:            f.find('[name=id]').val(),
				code:          parseInt(f.find('[name=code]').val().replace(/[^0-9]/g, ''), 10) || 0,
				value:         parseAmount(f.find('[name=value]').val()),
				expires:       f.find('[name=expires]').val(),
				note:          f.find('[name=note]').val(),
				sendDate:      f.find('[name=sendDate]').val(),
				beneficiaryId: f.find('[name=beneficiaryId]').val() || ''
			},
			success: function (resp) {
				if (resp && resp.ok) {
					message('Guardado', 'success');
					$('#modalSmall').modal('hide');
					loadDetail();
				} else {
					message('Error al guardar', 'danger');
				}
			},
			fail: function () { message('Error al guardar', 'danger'); }
		});
	}

	function openEdit(id) {
		var r = ROWS[id];
		if (!r) { return; }
		$('#modalSmall .modal-content').html(editForm(r));
		$('#modalSmall').modal('show');

		if (typeof select2Ajax === 'function') {
			select2Ajax({ element: '.chosen-select', url: '/a_contacts?action=searchCustomerInputJson', type: 'contact' });
		}
		$('.datepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
		masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
		masksCurrency($('.maskInteger'), RS.thousand, 'no');

		onClickWrap('.cancelItemView', function () { $('#modalSmall').modal('hide'); });

		onClickWrap('.deleteGC', function (event, tis) {
			var delId = tis.data('id');
			ncmDialogs.confirm('¡No podrá deshacer esta acción!', '', 'warning', function (conf) {
				if (!conf) { return; }
				$('.modal').modal('hide');
				ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
					data: { action: 'delete', id: delId },
					success: function (resp) {
						if (resp && resp.ok) { message('Gift card eliminada.', 'success'); loadDetail(); }
						else { message('No se pudo eliminar.', 'danger'); }
					},
					fail: function () { message('No se pudo eliminar.', 'danger'); }
				});
			});
		});

		$('#editGC').off('submit').on('submit', function (e) { e.preventDefault(); saveGC(); return false; });
		onClickWrap('.saveGC', function () { saveGC(); });
	}

	function loadDetail() {
		var url = BFF + '?view=detail';
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
				ROWS = {};
				$.each(res.data.rows || [], function (i, r) { ROWS[r.giftCardSoldId] = r; });
				ncmDataTables({
					"container": "#tableContainer", "url": url, "iniData": buildTable(res.data.rows || []),
					"table": ".table1", "sort": 2, "footerSumCol": [10],
					"currency": RS.currency, "decimal": RS.decimal, "thousand": RS.thousand,
					"nolimit": true, "noMoreBtn": true, "tableName": 'tableGift', "fileTitle": 'Gift Cards',
					"ncmTools": { left: '<a href="#" class="btn btn-default exportTable" data-table="tableGift" data-name="Gift Cards">Exportar Listado</a>', right: '' },
					"colsFilter": { name: 'reportGiftcards', menu: [
						{ index: 0, name: 'Color', visible: true }, { index: 1, name: '# Documento', visible: true },
						{ index: 2, name: 'Beneficiario', visible: true }, { index: 3, name: 'Vencimiento', visible: false },
						{ index: 4, name: 'Código', visible: false }, { index: 5, name: 'Código Único', visible: true },
						{ index: 6, name: 'Nota', visible: false }, { index: 7, name: 'Último uso', visible: false },
						{ index: 8, name: 'Envío', visible: false }, { index: 9, name: 'Sucursal', visible: false },
						{ index: 10, name: 'Saldo', visible: true }
					] },
					"clickCB": function (event, tis) {
						openEdit(tis.attr('data-id'));
					}
				}, function () {
					onClickWrap('.exportTable', function (event, tis) { table2Xlsx(tis.data('table'), tis.data('name')); }, false, true);
				});
				renderKpis(res.data.kpi);
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
				loadDetail();
			}
		});
	});

})();
