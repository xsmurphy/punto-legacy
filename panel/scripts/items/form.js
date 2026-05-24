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
		// cache-bust en dev para no servir templates viejos del cache del browser
		return fetch(TPL_BASE + name + '.html?v=' + Date.now(), { credentials: 'include' })
			.then(function (r) { return r.text(); })
			.then(function (t) { cache[name] = t; return t; });
	}

	function fmt(v) {
		return formatNumber(v || 0, '', window.decimal, window.thousandSeparator);
	}
	function fmtNoDec(v) {
		return formatNumber(v || 0, '', 'no', window.thousandSeparator);
	}
	// El JSON trae booleanos PG como true/false (no 1/0). parseInt(true)=NaN,
	// así que hay que aceptar ambos.
	function truthy(v) {
		return v === true || v === 1 || v === '1' || parseInt(v, 10) > 0;
	}

	function buildViewModel(d) {
		var it = d.item || {};
		var f  = d.form || {};
		var price = d.price || 0;
		var discount = parseFloat(it.itemdiscount) || 0;
		var finalPrice = discount > 0 ? price - (price * discount / 100) : price;

		// stock puede venir null o como objeto con stockOnHandCOGS
		var stock = (d.stock && typeof d.stock === 'object') ? d.stock : {};
		function sf(k) { return parseFloat(stock[k] || stock[k.toLowerCase()] || 0) || 0; }
		var cogs = sf('stockOnHandCOGS');
		var purchaseCogs = sf('stockCOGS');
		var onHand = sf('stockOnHand');
		var gross = finalPrice - cogs;
		var markup = (cogs > 0 && gross > 0) ? (gross / cogs) * 100 : 0;
		var margin = (finalPrice > 0 && gross > 0) ? (gross / finalPrice) * 100 : 0;

		function withSelected(arr, currentId) {
			return (arr || []).map(function (o) {
				return { id: o.id, name: o.name, selected: o.id === currentId };
			});
		}

		return {
			// flags de tabs condicionales (qué tabs mostrar según tipo)
			inventoryTools:  !!f.inventoryTools,
			productionTools: !!f.productionTools,
			comboTools:      !!f.comboTools,

			// selectores por tipo (Mustache logic-less → pre-computar el selected)
			type:               f.type,
			isPrecombo:         f.type === 'precombo',
			isCombo:            f.type === 'combo',
			isComboAddons:      f.type === 'comboAddons',
			isProduct:          f.type === 'product',
			isProduction:       f.type === 'production',
			isDirectProduction: f.type === 'direct_production',

			// compuestos / receta
			compounds:     d.compounds || [],

			// upsell
			upsells:           d.upsells || [],
			upsellDescription: it.itemupselldescription || '',

			// group / giftcard (shells especiales)
			children:     d.children || [],
			giftExpCount: d.giftExpCount || '1',
			giftExpYear:  (d.giftExpTime || 'year') === 'year',
			giftExpMonth: d.giftExpTime === 'month',
			giftExpDay:   d.giftExpTime === 'day',

			isParent:      truthy(it.itemisparent),
			itemId:        it.itemid,
			itemName:      it.itemname || '',
			itemSKU:       it.itemsku || '',
			skuOrId:       it.itemsku || it.itemid,
			description:   it.itemdescription || it.description || '',
			currency:      window.currency || '',
			finalPriceFmt: fmt(finalPrice),
			priceNoDecFmt: fmtNoDec(price),
			canSale:       truthy(it.itemcansale),
			archived:      !truthy(it.itemstatus),
			saveLabel:     truthy(it.itemstatus) ? 'Guardar' : 'Re Activar',
			typeName:      f.typeName || '',
			img:           '/assets/250-250/0/' + window.companyId + '_' + it.itemid + '.jpg?' + (it.updated_at || ''),
			imgFlag:       d.image ? 1 : 0,
			cogsFmt:       fmt(cogs),
			markupFmt:     fmtNoDec(markup),
			marginFmt:     fmtNoDec(margin),
			grossFmt:      fmt(gross),
			taxes:         withSelected(d.options && d.options.taxes,      it.taxid),
			categories:    withSelected(d.options && d.options.categories, it.categoryid),

			// settingsTab
			brands:           withSelected(d.options && d.options.brands,   it.brandid),
			outlets:          withSelected(d.options && d.options.outlets,  it.outletid),
			discount:         it.itemdiscount ? parseFloat(it.itemdiscount) : 0,
			uom:              it.itemuom || '',
			waste:            it.itemwaste || '',
			sort:             (it.itemsort !== undefined && it.itemsort !== null) ? it.itemsort : '',
			commission:       it.itemcomissionpercent || '',
			commissionSymbol: truthy(it.itemcomissiontype) ? (window.currency || '$') : '%',
			pricePercent:     it.itempricepercent || '',
			priceTypeSymbol:  truthy(it.itempricetype) ? '%' : (window.currency || '$'),
			taxIncluded:      truthy(it.itemtaxincluded),
			ecom:             truthy(it.itemecom),
			featured:         truthy(it.itemfeatured),

			// inventoryTab
			stockCogsFmt:     fmt(purchaseCogs),
			stockAvgCogsFmt:  fmt(cogs),
			stockValueFmt:    fmt(cogs * onHand),
			deposits:         d.deposits || [],
			hasDeposits:      (d.deposits || []).length > 0,
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
					var vm = buildViewModel(d);

					// .first()+.empty() evita duplicar si el modal-content ya tenía
					// un render previo o el selector matchea más de uno.
					function inject(html) {
						$(modalSel + ' .modal-content').first().empty().html(html);
						$(modalSel + ' .modal-dialog').first().addClass('modal-lg');
						$(modalSel).modal('show');
						return vm;
					}

					// Tipos con layout propio (sin tabs) usan shell standalone.
					var SHELL_FOR = { discount: 'shell-discount', giftcard: 'shell-giftcard', group: 'shell-group' };
					var special = SHELL_FOR[d.form.type] || (vm.isParent ? 'shell-group' : null);

					if (special) {
						return loadTpl(special).then(function (tpl) {
							return inject(Mustache.render(tpl, vm));
						});
					}

					return Promise.all([
						loadTpl('shell'),
						loadTpl('header-default'),
						loadTpl('dataTab'),
						loadTpl('settingsTab'),
						loadTpl('inventoryTab'),
						loadTpl('productionTab'),
						loadTpl('kitTab'),
						loadTpl('upsellTab'),
					]).then(function (tpls) {
						return inject(Mustache.render(tpls[0], vm, {
							header:        tpls[1],
							dataTab:       tpls[2],
							settingsTab:   tpls[3],
							inventoryTab:  tpls[4],
							productionTab: tpls[5],
							kitTab:        tpls[6],
							upsellTab:     tpls[7],
						}));
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
