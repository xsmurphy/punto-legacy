/**
 * Render del listado de Items en el front a partir del JSON crudo del backend
 * (?action=showTable&format=json).
 *
 * Estrategia: el front arma el HTML del <tbody> (una fila por item) y se lo
 * pasa a ncmDataTables como iniData — igual que cuando el server mandaba HTML,
 * pero ahora el server manda DATOS y el front los pinta. Así no tocamos el
 * god-helper ncmDataTables (compartido por todos los listados).
 *
 * El ORDEN de los <td> coincide 1:1 con el <thead> de a_items.php (20 cols).
 * Reusa formatNumber() de common.js + globals window.decimal /
 * window.thousandSeparator / window.companyId.
 */
(function (window) {
	'use strict';

	function fmtMoney(v) {
		return formatNumber(v || 0, '', window.decimal, window.thousandSeparator);
	}
	function fmtQty(v) {
		return formatNumber(v || 0, '', 'si', window.thousandSeparator, false, 3);
	}
	function esc(s) {
		if (s === null || s === undefined) return '';
		return String(s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	function td(content, attrs) {
		return '<td' + (attrs ? ' ' + attrs : '') + '>' + content + '</td>';
	}

	/** Arma el <tr> de un item. */
	function renderItemRow(it) {
		var narrow = it.narrow ? 'modal-narrow' : '';
		var group  = it.isParent ? 'group' : '';

		// col 0 — imagen + id oculto (clickrow lee el id del <tr>)
		var imgAttr = it.image
			? 'class="lazy" data-src="/assets/60-60/0/' + esc(window.companyId) + '_' + esc(it.itemId) + '.jpg"'
			: '';
		var c0 = td('<span class="hidden hidden-print">' + esc(it.itemId) + '</span>', imgAttr);

		// col 1 — nombre
		var c1 = td(esc(it.name), 'class="font-bold"');
		// col 2 — tipo
		var c2 = td(esc(it.textIcon));
		// col 3 — fecha
		var c3 = td(it.date ? esc(String(it.date).substring(0, 10)) : '-', 'data-sort="' + esc(it.date) + '"');
		// col 4 — uom
		var c4 = td(it.uom ? '<span class="badge">' + esc(it.uom) + '</span>' : '-');
		// col 5 — sku
		var c5 = td(it.sku ? esc(it.sku) : '-');
		// col 6 — marca
		var c6 = td(esc(it.brand));
		// col 7 — categoría
		var c7 = td(esc(it.category));
		// col 8 — sucursal
		var c8 = td(esc(it.outlet));
		// col 9 — sesiones
		var c9 = td(it.sessions ? esc(it.sessions) : '-', 'class="text-right bg-light lter" data-sort="' + esc(it.sessions) + '"');
		// col 10 — duración
		var c10 = td(it.duration ? esc(it.duration) : '-', 'class="text-right bg-light lter" data-sort="' + esc(it.duration) + '"');
		// col 11 — merma
		var c11 = td(it.waste > 0 ? esc(it.waste) + '%' : '-', 'class="text-right bg-light lter" data-sort="' + esc(it.waste) + '"');
		// col 12 — comisión
		var commTxt = '-';
		if (it.commission !== null && it.commission !== '' && it.commission !== undefined) {
			commTxt = it.commissionType ? fmtMoney(it.commission) : (formatNumber(it.commission, '', 'no', window.thousandSeparator) + '%');
		}
		var c12 = td(commTxt, 'class="text-right bg-light lter" data-sort="' + esc(it.commission) + '"');
		// col 13 — descuento
		var discTxt = it.discount > 0 ? '~' + formatNumber(it.discount, '', 'no', window.thousandSeparator) + '%' : '-';
		var c13 = td(discTxt, 'class="text-right bg-light lter" data-sort="' + esc(it.discount) + '"');
		// col 14 — costo
		var c14 = td(it.cogs > 0 ? fmtMoney(it.cogs) : '-',
			'class="text-right bg-light lter" data-sort="' + (it.cogs || 0) + '" data-format="money"');
		// col 15 — precio (strike si descuento)
		var priceTxt = fmtMoney(it.price);
		if (it.discount > 0) {
			var fin = it.price - (it.price * (it.discount / 100));
			priceTxt = '<strike class="text-xs text-danger block">' + fmtMoney(it.price) + '</strike>' + fmtMoney(fin);
		}
		var c15 = td(priceTxt, 'class="text-right bg-light lter" data-sort="' + (it.priceStored || 0) + '" data-format="money"');
		// col 16 — valor
		var c16 = td(it.value > 0 ? fmtMoney(it.value) : '0',
			'class="text-right bg-light lter" data-sort="' + (it.value || 0) + '" data-format="money"');
		// col 17 — IVA
		var c17 = td(esc(it.tax) + '%', 'class="text-right bg-light lter" data-sort="' + esc(it.tax) + '"');
		// col 18 — stock
		var stockTxt = '-';
		if (it.trackInventory) {
			var tip = it.capacity ? ' data-toggle="tooltip" data-placement="left" title="Capacidad de producción ' + esc(it.capacity) + ' unidades"' : '';
			stockTxt = '<span class="' + (it.stockClass || 'bg-light lter') + ' font-bold label"' + tip + '>' + fmtQty(it.stock) + '</span>';
		}
		var c18 = td(stockTxt, 'class="text-right" data-sort="' + (it.stock || 0) + '"');
		// col 19 — online
		var c19 = td(it.online ? '<i class="material-icons text-success">check</i>' : '',
			'class="text-center" data-sort="' + (it.online ? 1 : 0) + '"');

		return '<tr id="' + esc(it.itemId) + '" class="clickrow ' + narrow + ' ' + group + '">' +
			c0 + c1 + c2 + c3 + c4 + c5 + c6 + c7 + c8 + c9 + c10 +
			c11 + c12 + c13 + c14 + c15 + c16 + c17 + c18 + c19 + '</tr>';
	}

	/** Arma el <tbody> completo a partir del array de items. */
	function renderItemsTbody(items) {
		var rows = '';
		for (var i = 0; i < items.length; i++) rows += renderItemRow(items[i]);
		return '<tbody>' + rows + '</tbody>';
	}

	/** <thead> con los 20 títulos (TAX_NAME viene de window.taxName). */
	function renderHead() {
		var cols = ['', 'Nombre', 'Tipo', 'Creado', 'Ud. Medida', 'Código', 'marca',
			'categoría', 'Sucursal', 'Sesiones', 'Duración', 'Merma', 'Comisión',
			'Descuento', 'Costo', 'Precio', 'Valor', (window.taxName || 'IVA'), 'stock', 'Online'];
		var ths = '<th style="max-width:60px;width:60px;"></th>';
		for (var i = 1; i < cols.length; i++) ths += '<th>' + esc(cols[i]) + '</th>';
		return '<thead class="text-u-c"><tr>' + ths + '</tr></thead>';
	}

	/** <tfoot> con la fila de TOTALES (9 cols mergeadas + 10 sumables + online). */
	function renderFoot() {
		var ths = '<th colspan="9">TOTALES:</th>';
		for (var i = 0; i < 10; i++) ths += '<th class="text-right"></th>';
		ths += '<th></th>';
		return '<tfoot><tr class="text-right strong">' + ths + '</tr></tfoot>';
	}

	/** Tabla completa thead+tbody+tfoot, lista para iniData de ncmDataTables. */
	function renderItemsTable(items) {
		return renderHead() + renderItemsTbody(items) + renderFoot();
	}

	window.itemsRender = {
		row:   renderItemRow,
		tbody: renderItemsTbody,
		table: renderItemsTable,
	};
})(window);
