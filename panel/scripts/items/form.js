/**
 * itemFormV2 — POC del editform con templates Mustache + hidratación JSON.
 *
 * Compone el formulario en el front a partir de partials (shell + header + tabs)
 * hidratados con los datos de ?action=editform&format=json. NO toca el flujo
 * legacy (loadForm del editform HTML): se invoca aparte con
 *   itemFormV2.open('<itemId>')
 * para poder verificarlo en el browser sin romper la edición de producción.
 *
 * Reusa Mustache (global), formatNumber() y las globals window.decimal /
 * thousandSeparator / currency / companyId / baseUrl de a_items.php.
 */
(function (window) {
	'use strict';

	var TPL_BASE = '/items/templates/';
	var cache = {};

	function loadTpl(name) {
		if (cache[name]) return Promise.resolve(cache[name]);
		return fetch(TPL_BASE + name + '.html', { credentials: 'include' })
			.then(function (r) { return r.text(); })
			.then(function (t) { cache[name] = t; return t; });
	}

	function fmt(v) {
		return formatNumber(v || 0, '', window.decimal, window.thousandSeparator);
	}
	function fmtNoDec(v) {
		return formatNumber(v || 0, '', 'no', window.thousandSeparator);
	}

	function buildViewModel(d) {
		var it = d.item || {};
		var f  = d.form || {};
		var price = d.price || 0;
		var discount = parseFloat(it.itemdiscount) || 0;
		var finalPrice = discount > 0 ? price - (price * discount / 100) : price;

		// stock puede venir null o como objeto con stockOnHandCOGS
		var cogs = 0;
		if (d.stock && typeof d.stock === 'object') {
			cogs = parseFloat(d.stock.stockOnHandCOGS || d.stock.stockonhandcogs || 0) || 0;
		}
		var gross = finalPrice - cogs;
		var markup = (cogs > 0 && gross > 0) ? (gross / cogs) * 100 : 0;
		var margin = (finalPrice > 0 && gross > 0) ? (gross / finalPrice) * 100 : 0;

		function withSelected(arr, currentId) {
			return (arr || []).map(function (o) {
				return { id: o.id, name: o.name, selected: o.id === currentId };
			});
		}

		return {
			itemId:        it.itemid,
			itemName:      it.itemname || '',
			itemSKU:       it.itemsku || '',
			skuOrId:       it.itemsku || it.itemid,
			description:   it.itemdescription || it.description || '',
			currency:      window.currency || '',
			finalPriceFmt: fmt(finalPrice),
			canSale:       parseInt(it.itemcansale, 10) > 0,
			archived:      !(parseInt(it.itemstatus, 10) > 0),
			typeName:      f.typeName || '',
			img:           '/assets/250-250/0/' + window.companyId + '_' + it.itemid + '.jpg?' + (it.updated_at || ''),
			imgFlag:       d.image ? 1 : 0,
			cogsFmt:       fmt(cogs),
			markupFmt:     fmtNoDec(markup),
			marginFmt:     fmtNoDec(margin),
			grossFmt:      fmt(gross),
			taxes:         withSelected(d.options && d.options.taxes,      it.taxid),
			categories:    withSelected(d.options && d.options.categories, it.categoryid),
		};
	}

	var itemFormV2 = {
		// Carga datos + templates, compone y muestra el modal.
		open: function (id, modalSel) {
			modalSel = modalSel || '#modalLarge';
			var url = (window.baseUrl || '/items') + '?action=editform&format=json&id=' + encodeURIComponent(id);

			return fetch(url, { credentials: 'include' })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (!resp || !resp.ok) {
						throw new Error((resp && resp.error && resp.error.message) || 'Error cargando el item');
					}
					var d = resp.data;
					return Promise.all([
						loadTpl('shell'),
						loadTpl('header-default'),
						loadTpl('dataTab'),
					]).then(function (tpls) {
						var vm = buildViewModel(d);
						var html = Mustache.render(tpls[0], vm, {
							header:  tpls[1],
							dataTab: tpls[2],
						});
						$(modalSel + ' .modal-content').html(html);
						$(modalSel + ' .modal-dialog').addClass('modal-lg');
						$(modalSel).modal('show');
						return vm;
					});
				})
				.catch(function (e) {
					if (window.ncmDialogs) ncmDialogs.alert('itemFormV2: ' + e.message);
					else console.error('itemFormV2', e);
				});
		},
	};

	window.itemFormV2 = itemFormV2;
})(window);
