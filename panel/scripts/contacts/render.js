/**
 * Render del listado de Contactos (roles customer / user / supplier) a partir del
 * JSON crudo del backend (?action=generalTable&format=json → { contacts: [...] }).
 *
 * Misma estrategia que items/render.js: el front arma el HTML del thead+tbody+tfoot
 * y se lo pasa a ncmDataTables como iniData. El server manda DATOS, el front pinta.
 * El ORDEN de los <td> coincide 1:1 con el <thead> de cada rol en a_contacts.php
 * (customer 19 cols, user 10 cols, supplier 9 cols).
 */
(function (window) {
	'use strict';

	function esc(s) {
		if (s === null || s === undefined) return '';
		return String(s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	function td(content, attrs) {
		return '<td' + (attrs ? ' ' + attrs : '') + '>' + content + '</td>';
	}
	function colorStyleAttr(color) {
		return color ? 'style="border-left:5px solid #' + esc(color) + '"' : '';
	}
	function statusIcon(active) {
		return active
			? '<i class="material-icons text-success">check</i>'
			: '<i class="material-icons text-danger">close</i>';
	}

	/** <tr> de un contacto rol customer (19 cols). */
	function renderCustomerRow(c) {
		var cells =
			td('<span class="hidden hidden-print">' + esc(c.id) + '</span>', colorStyleAttr(c.color)) +
			td(esc(c.name), 'class="font-bold"') +
			td(esc(c.tin)) +
			td(esc(c.fullname), 'class="font-bold"') +
			td(esc(c.ci)) +
			td(esc(c.bday)) +
			td(esc(c.dateF), 'data-order="' + esc(c.date) + '"') +
			td(esc(c.updatedF), 'data-order="' + esc(c.updated) + '"') +
			td(esc(c.lastDateF), 'data-order="' + esc(c.lastDate || '') + '"') +
			td(esc(c.phone)) +
			td(esc(c.phone2)) +
			td(esc(c.email)) +
			td(esc(c.address)) +
			td(esc(c.location)) +
			td(esc(c.city)) +
			td(esc(c.note)) +
			td(esc(c.scoring), 'class="tdNumeric"') +
			td(esc(c.loyalty), 'class="tdNumeric"') +
			td(esc(c.distance), 'class="tdNumeric"');

		return '<tr data-id="' + esc(c.id) + '" class="clickrow ' + esc(c.id) + '">' + cells + '</tr>';
	}

	/** <tr> de un contacto rol user (10 cols). */
	function renderUserRow(c) {
		var roleLabel = '<span class="label ' + esc(c.label) + '">' + esc(c.roleName) + '</span>';
		var cells =
			td('<span class="hidden hidden-print">' + esc(c.id) + '</span>', colorStyleAttr(c.color)) +
			td(esc(c.name), 'class="font-bold"') +
			td(esc(c.tin)) +
			td(esc(c.dateF), 'data-order="' + esc(c.date) + '"') +
			td(esc(c.phone)) +
			td(esc(c.email)) +
			td(esc(c.address)) +
			td(roleLabel) +
			td(statusIcon(c.active), 'class="text-center"') +
			td(esc(c.outlet));

		return '<tr data-id="' + esc(c.id) + '" class="clickrow ' + esc(c.id) + '">' + cells + '</tr>';
	}

	/** <tr> de un contacto rol supplier (9 cols). */
	function renderSupplierRow(c) {
		var cells =
			td('<span class="hidden hidden-print">' + esc(c.id) + '</span>', colorStyleAttr(c.color)) +
			td(esc(c.name), 'class="font-bold"') +
			td(esc(c.tin)) +
			td(esc(c.fullname), 'class="font-bold"') +
			td(esc(c.dateF), 'data-order="' + esc(c.date) + '"') +
			td(esc(c.phone)) +
			td(esc(c.email)) +
			td(esc(c.address)) +
			td(esc(c.category));

		return '<tr data-id="' + esc(c.id) + '" class="clickrow ' + esc(c.id) + '">' + cells + '</tr>';
	}

	var ROW_BY_ROL = {
		customer: renderCustomerRow,
		user:     renderUserRow,
		supplier: renderSupplierRow,
	};

	// thead por rol: el orden coincide 1:1 con los <th> de a_contacts.php.
	function headCols(rol) {
		var tin = window.tin_name || 'RUC';
		if (rol === 'user') {
			return ['ID', 'Nombre y Apellido', 'Doc. de Identidad', 'Creado', 'Teléfono',
				'Email', 'Dirección', 'Rol', 'Estado', 'Sucursal'];
		}
		if (rol === 'supplier') {
			return ['ID', 'Nombre/Razon Social', tin, 'Encargado/a', 'Creado', 'Teléfono',
				'Email', 'Dirección', 'Categoria'];
		}
		return ['ID', 'Nombre/Razon Social', tin, 'Nombre y Apellido', 'Doc. de Identidad',
			'Fecha de Nacimiento', 'Creado', 'Actualizado', 'Última operación', 'Teléfono',
			'Teléfono 2', 'Email', 'Dirección', 'Localidad', 'Ciudad', 'Nota', 'Score',
			'Loyalty', 'Distancia (Km)'];
	}

	function renderHead(rol) {
		var cols = headCols(rol);
		var ths = '';
		for (var i = 0; i < cols.length; i++) ths += '<th>' + esc(cols[i]) + '</th>';
		return '<thead class="text-u-c"><tr>' + ths + '</tr></thead>';
	}

	function renderTbody(contacts, rol) {
		var rowFn = ROW_BY_ROL[rol] || renderCustomerRow;
		var rows = '';
		for (var i = 0; i < contacts.length; i++) rows += rowFn(contacts[i]);
		return '<tbody>' + rows + '</tbody>';
	}

	function renderFoot(rol) {
		var n = headCols(rol).length;
		return '<tfoot><tr><td colspan="' + n + '"></td></tr></tfoot>';
	}

	/** Tabla completa lista para iniData de ncmDataTables. rol default = customer. */
	function renderTable(contacts, rol) {
		rol = rol || 'customer';
		return renderHead(rol) + renderTbody(contacts || [], rol) + renderFoot(rol);
	}

	window.contactsRender = {
		row:      renderCustomerRow,
		userRow:  renderUserRow,
		supplierRow: renderSupplierRow,
		tbody:    renderTbody,
		table:    renderTable,
	};
})(window);
