/**
 * Render del listado de Contactos (rol customer) a partir del JSON crudo del backend
 * (?action=generalTable&format=json → { contacts: [...] }).
 *
 * Misma estrategia que items/render.js: el front arma el HTML del thead+tbody+tfoot
 * y se lo pasa a ncmDataTables como iniData. El server manda DATOS, el front pinta.
 * El ORDEN de los <td> coincide 1:1 con el <thead> del rol customer (19 cols).
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

	/** Arma el <tr> de un contacto (rol customer). */
	function renderCustomerRow(c) {
		var colorStyle = c.color ? 'border-left:5px solid #' + esc(c.color) : '';

		var cells =
			td('<span class="hidden hidden-print">' + esc(c.id) + '</span>', colorStyle ? 'style="' + colorStyle + '"' : '') +
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

	function renderTbody(contacts) {
		var rows = '';
		for (var i = 0; i < contacts.length; i++) rows += renderCustomerRow(contacts[i]);
		return '<tbody>' + rows + '</tbody>';
	}

	function renderHead() {
		var tin = window.tin_name || 'RUC';
		var cols = ['ID', 'Nombre/Razon Social', tin, 'Nombre y Apellido', 'Doc. de Identidad',
			'Fecha de Nacimiento', 'Creado', 'Actualizado', 'Última operación', 'Teléfono',
			'Teléfono 2', 'Email', 'Dirección', 'Localidad', 'Ciudad', 'Nota', 'Score',
			'Loyalty', 'Distancia (Km)'];
		var ths = '';
		for (var i = 0; i < cols.length; i++) ths += '<th>' + esc(cols[i]) + '</th>';
		return '<thead class="text-u-c"><tr>' + ths + '</tr></thead>';
	}

	function renderFoot() {
		return '<tfoot><tr><td colspan="19"></td></tr></tfoot>';
	}

	/** Tabla completa lista para iniData de ncmDataTables. */
	function renderTable(contacts) {
		return renderHead() + renderTbody(contacts || []) + renderFoot();
	}

	window.contactsRender = {
		row:   renderCustomerRow,
		tbody: renderTbody,
		table: renderTable,
	};
})(window);
