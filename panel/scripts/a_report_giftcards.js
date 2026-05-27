/**
 * Front del reporte de Gift Cards (a_report_giftcards) — BFF de 3 niveles.
 *
 *   - config              ← GET /bff/bootstrap.php
 *   - detail (rows + kpi)  ← GET /bff/reports/giftcards.php?view=detail[&singleRow]
 *
 * El BFF devuelve filas CRUDAS + KPIs; este JS formatea TODO + arma la tabla y las tarjetas.
 * El form de edición y los writes NO se migraron: se sirven por el PHP legacy vía
 *   /a_report_giftcards?action=giftcard|update|delete
 * cargados en el modal global del shell (#modalSmall).
 */
(function () {

	var BFF       = '/bff/reports/giftcards.php';
	var BOOTSTRAP = '/bff/bootstrap.php';
	var LEGACY    = '/a_report_giftcards';

	var RS = { currency: '', decimal: 'no', thousand: 'dot' };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
	function fmt(n) { return formatNumber(n || 0, '', RS.decimal, RS.thousand); }
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
				'<tr class="clickrow pointer" data-load="' + LEGACY + '?action=giftcard&id=' + esc(r.giftCardSoldId) + '">' +
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

	var editWired = false;
	function wireEditModal() {
		if (editWired) { return; }
		editWired = true;
		$('#modalSmall').off('shown.bs.modal').on('shown.bs.modal', function () {
			if (typeof select2Ajax === 'function') {
				select2Ajax({ element: '.chosen-select', url: '/a_contacts?action=searchCustomerInputJson', type: 'contact' });
			}
			$('.datepicker').datetimepicker({ format: 'YYYY-MM-DD HH:mm:ss', showClear: true, ignoreReadonly: true });
			masksCurrency($('.maskCurrency'), RS.thousand, RS.decimal);
			masksCurrency($('.maskInteger'), RS.thousand, 'no');
			submitForm('#editSale', function (tis, result) {
				if (result) { $('#modalSmall').modal('hide'); loadDetail(); }
			});
			onClickWrap('.cancelItemView', function () { $('#modalSmall').modal('hide'); });
			onClickWrap('.delete', function (event, tis) {
				var id = tis.data('id');
				ncmDialogs.confirm('¡No podrá deshacer esta acción!', '', 'warning', function (conf) {
					if (conf) {
						$('.modal').modal('hide');
						$.get(LEGACY + '?action=delete&id=' + id, function (data) {
							if (data === 'true' || data === true) { message('Eliminado', 'success'); loadDetail(); }
							else { message('No se pudo eliminar', 'danger'); }
						});
					}
				});
			});
		});
	}

	function loadDetail() {
		var url = BFF + '?view=detail';
		var xhr = ncmHelpers.load({
			url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
			success: function (res) {
				if (!res || !res.ok) { return; }
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
						var load = tis.attr('data-load');
						loadForm(load, '#modalSmall .modal-content', function () { $('#modalSmall').modal('show'); });
					}
				}, function () {
					wireEditModal();
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
