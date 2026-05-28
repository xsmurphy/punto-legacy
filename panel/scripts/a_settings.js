/**
 * Front del módulo Ajustes (a_settings) — BFF + Alpine (incr. 2: tabs General + App).
 *
 *   - general (cfg)   ← GET  /bff/settings.php?view=general
 *   - options (selects)← GET  /bff/settings.php?view=options
 *   - taxonomies      ← GET  /bff/settings.php?view=taxonomies&type=<t>  (dropdowns adm, jQuery)
 *   - guardar          ← POST /bff/settings.php (action=update&type=setting) → company.config
 *
 * §17.2: Alpine es dueño de los campos/toggles del form (x-model); los 5 dropdowns adm
 * (impuestos/plan de cuentas/etiquetas/medios/bancos) los maneja jQuery — `adm()` (común) muta
 * el <select> (prepend <option>) y postea su CRUD al PHP legacy vía `/a_settings?tableExtra=…`.
 * Por eso esos selects NO usan x-for (evita que Alpine pise lo que mete jQuery). "Monedas" abre
 * una matriz en #modalTiny (jQuery-owned) cuyos datos vienen del BFF (?view=currencies, save
 * type=currencies). "Ordenar categorías" aún va al legacy `?action=`. El upload de logo va a
 * upload.php. (Lo legacy requiere la sesión del shell; los saves del BFF andan con el JWT.)
 *
 * Init determinista §17: clon DETACHED + Alpine.initTree (1×) + mountUI() para lo que toca el DOM.
 */
(function () {

	var BFF       = '/bff/settings.php';
	var LEGACY    = '/a_settings';
	var TAX_TYPES = ['tax', 'transactionCategory', 'tag', 'paymentMethod', 'bankName'];
	var SELECT_OF = { tax: '#taxAdd', transactionCategory: '#transactionCategory', tag: '#tagAdd', paymentMethod: '#paymentMAdd', bankName: '#bankCAdd' };

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function blankCfg() {
		return {
			name: '', address: '', email: '', billingName: '', ruc: '', billDetail: '', website: '',
			social: { facebook: '', instagram: '', youtube: '', twitter: '' },
			category: '', phone: '', city: '', country: '', language: 'es', timeZone: '',
			currency: '', thousandSeparator: 'dot', taxName: 'IVA', tin: '', itemsSaleLimit: '',
			decimal: false, sellsoldout: false, itemSerialized: false, drawerEmail: false, drawerBlind: false,
			settingRemoveTaxes: false, paymentId: false, creditLine: false, storeCredit: false,
			ignoreInternal: false, stockCountBlind: false, blockUsedDocNo: false, autoSendDocs: false,
			taxPy: false, weightBarcodes: false, deletedItemsHistory: false
		};
	}

	function settingsComponent() {
		return {
			cfg: blankCfg(),
			options: { countries: [], categories: {}, timezones: [], languages: [], thousandSeparator: [] },
			tax: { tax: [], transactionCategory: [], tag: [], paymentMethod: [], bankName: [] },
			logo: '',
			uploadUrl: '',
			saving: false,
			loaded: false,   // recién true cuando `general` cargó: guarda contra save() sobre cfg en blanco

			init: function () {},

			mountUI: function () {
				var self = this;
				window.baseUrl = LEGACY;   // adm() (common.js) postea a baseUrl + '?tableExtra=…'
				if (window.FastClick) { FastClick.attach(document.body); }

				// Opciones PRIMERO, luego general: x-model sobre un <select> cuyas <option> se
				// pueblan async pisa el valor del modelo ('' si la opción aún no existe). Cargar las
				// opciones antes garantiza que la <option> exista cuando x-model bindea cfg.category/country/timeZone.
				self._get('?view=options', function (d) {
					self.options = d;
					self._get('?view=general', function (g) {
						self.cfg = $.extend(true, blankCfg(), g);
						self.logo = g.logo || 'images/add.png';
						self.uploadUrl = g.uploadUrl || '';
						$('#image').attr('data-url', self.uploadUrl);   // logoUpload() lee data-url al cambiar
						self.loaded = true;
					});
				});

				// Upload de logo: logoUpload() (común) wirea el change de #image → POST multipart a
				// data-url (upload.php?id={companyId}) y actualiza .itemImg con la imagen guardada.
				// Es un handler DELEGADO en document sin .off() propio → limpiar antes para no apilar
				// duplicados si el shell SPA re-monta esta vista sin recargar (POSTs duplicados).
				$(document).off('change', '#image');
				if (typeof logoUpload === 'function') { logoUpload(); }

				// Dropdowns adm (jQuery-owned): poblar + wirear el CRUD legacy.
				TAX_TYPES.forEach(function (t) {
					self._get('?view=taxonomies&type=' + t, function (d) {
						self.tax[t] = (d && d.rows) || [];
						self.fillAdmSelect(t);
					});
				});
				if (typeof adm === 'function') { adm(); }   // wirea .addItemPart/.editItemPart/.deleteItemPart

				// Monedas: matriz en #modalTiny (jQuery-owned §17.2), datos via BFF.
				ncmHelpers.onClickWrap('a.setCurrenciesBtn', function () { self.openCurrencies(); });
				// Botones que aún van al legacy (sesión del shell): ordenar categorías, logo.
				ncmHelpers.onClickWrap('a.sortCategoriesBtn', function () { window.location.href = LEGACY + '?action=sortCategories'; });
				ncmHelpers.onClickWrap('#uploadImgBtn', function () { $('#image').trigger('click'); });

				$('[data-toggle="tooltip"]').tooltip();

				// Plantillas de impresión (incr. 3d): hidratar la paleta (campos que dependen de la
				// empresa) con templateFields, y arrancar el widget jQuery (a_settings_templates.js)
				// la 1ª vez que se abre la tab. El builder es jQuery-owned (§17.2); ver ese archivo.
				self._get('?view=templateFields', function (tf) { self.hydratePalette(tf); });

				ncmHelpers.onClickWrap('#printTemplatesBtn', function () {});   // no-op: el tab lo maneja bootstrap
				$('#printTemplatesBtn').off('shown.bs.tab.tpl').on('shown.bs.tab.tpl', function () {
					if (self._tplStarted) { return; }
					window.mm = $('#my_mm').height();          // px por mm (el ruler #my_mm vive en la tab)
					window.minHeight = window.mm * 5;
					if (typeof spinner === 'function') { spinner('body', 'show'); }
					if (window.templateBuilder) { window.templateBuilder.init(); }
					self._tplStarted = true;
				});
			},

			/* ───────────── plantillas: hidratar paleta (campos dinámicos por empresa) ───────────── */
			hydratePalette: function (tf) {
				tf = tf || {};
				window.tinName = tf.tinName || 'RUC';
				var tools = document.getElementById('templateBuilderTools');
				if (!tools) { return; }

				var taxName = tf.taxName || 'IVA';
				var tinName = tf.tinName || 'RUC';
				var now = new Date();
				var pad = function (n) { return (n < 10 ? '0' : '') + n; };
				var today = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());

				// Filas por-impuesto (reemplazan los loops PHP del legacy).
				var single = '', item = '';
				(tf.taxes || []).forEach(function (t) {
					var nm = (t.name === '' || t.name == null) ? '0' : String(t.name);
					single += '<a class="addField list-group-item" data-type="tax_single" data-default="Total ' + esc(taxName) + ' ' + esc(nm) + '%" href="#">Total ' + esc(taxName) + ' ' + esc(nm) + '%</a>';
					item   += '<a class="addField list-group-item receiptableN" data-type="item_taxAmount_single" data-default="{{TAX_SINGLE_' + esc(nm) + '%}}" href="#">Artículo ' + esc(taxName) + ' ' + esc(nm) + '%</a>';
					item   += '<a class="addField list-group-item" data-type="item_subtotal" data-default="{{TAX_SUBTOTAL_' + esc(nm) + '%}}" href="#">Suma ' + esc(taxName) + ' ' + esc(nm) + '%</a>';
				});

				var html = tools.innerHTML
					.split('__TAX__').join(esc(taxName))
					.split('__TIN__').join(esc(tinName))
					.split('__TODAY__').join(esc(today))
					.replace('<span class="taxSingleRows"></span>', single)
					.replace('<span class="taxItemRows"></span>', item);
				tools.innerHTML = html;

				// Defaults de los campos de empresa (data-cf marca cuál) — se setean tras reconstruir el HTML.
				var co = tf.company || {};
				$('#templateBuilderTools .addField[data-cf]').each(function () {
					var k = this.getAttribute('data-cf');
					this.setAttribute('data-default', (co[k] != null) ? co[k] : '');
				});

				// El rebuild de innerHTML borró los tooltips de la paleta → re-inicializarlos.
				$('#templateBuilderTools [data-toggle="tooltip"]').tooltip();
			},

			_get: function (qs, cb) {
				var xhr = ncmHelpers.load({
					url: BFF + qs, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) { if (res && res.ok) { cb(res.data); } }
				});
				window.xhrs.push(xhr);
			},

			fillAdmSelect: function (type) {
				var sel = SELECT_OF[type];
				if (!sel) { return; }
				var html = '';
				(this.tax[type] || []).forEach(function (o) {
					html += '<option value="' + esc(o.id) + '">' + esc(o.name || (type === 'tax' ? 'Exento' : '')) + '</option>';
				});
				$(sel).html(html);
			},

			/* ───────────── presentación (header) ───────────── */
			categoryLabel: function () {
				var groups = this.options.categories || {};
				for (var g in groups) {
					for (var label in groups[g]) {
						if (String(groups[g][label]) === String(this.cfg.category)) { return label; }
					}
				}
				return '';
			},
			countryName: function () {
				var self = this;
				var c = (this.options.countries || []).find(function (x) { return x.code === self.cfg.country; });
				return c ? c.name : 'Ningún País';
			},

			/* ───────────── monedas (matriz, modal jQuery §17.2) ───────────── */
			openCurrencies: function () {
				var self = this;
				var sym  = self.cfg.currency || '';
				var flagsCDN = 'https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.4.3/flags/1x1/';

				self._get('?view=currencies', function (d) {
					var rows = (d && d.rows) || [];
					var html = '<div class="col-xs-12 wrapper panel m-n" id="setCurrenciesList">' +
						'<div class="col-xs-12 text-center text-u-c font-bold m-b">Monedas</div>' +
						'<table class="table bg-white m-n"><tbody>';
					rows.forEach(function (val) {
						html += '<tr><td class="font-bold"><div class="m-t-xs">' +
							'<img src="' + flagsCDN + esc(String(val.ccode).toLowerCase()) + '.svg" class="m-r-sm" width="20">' + esc(val.code) +
							'</div></td><td>' +
							'<input class="form-control text-right" data-code="' + esc(val.code) + '" value="' + esc(val.value) + '">';
						if (Number(val.value) > 0) {
							html += '<div class="text-xs text-right currencyExp' + esc(val.code) + '">1 ' + esc(val.code) + ' = ' + esc(val.value) + ' ' + esc(sym) + '</div>';
						}
						html += '</td></tr>';
					});
					html += '</tbody></table></div>';

					$('#modalTiny').modal('show');
					$('#modalTiny .modal-content').html(html);

					$('#setCurrenciesList input').off('change').on('change', function () {
						var allCur = [];
						$('#setCurrenciesList input').each(function () {
							var tis = $(this), value = tis.val(), code = tis.data('code');
							if (Number(value) > 0) { allCur.push({ code: code, value: value }); }
						});
						var xhr = ncmHelpers.load({
							url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false,
							data: { action: 'update', type: 'currencies', currencies: JSON.stringify(allCur) },
							success: function (res) {
								if (res && res.ok) { ncmDialogs.toast('Guardado', 'success'); }
								else if (typeof message === 'function') { message('No se pudo guardar', 'danger'); }
							}
						});
						window.xhrs.push(xhr);
					});

					$('#setCurrenciesList input').off('keyup').on('keyup', function () {
						var tis = $(this), value = tis.val(), code = tis.data('code');
						$('.currencyExp' + code).text('1 ' + code + ' = ' + value + ' ' + sym);
					});
				});
			},

			/* ───────────── guardar ───────────── */
			save: function () {
				var self = this;
				if (self.saving) { return; }
				// No guardar sobre un cfg en blanco (si el fetch de general falló/no cargó → POST de
				// strings vacíos pisaría config real). Sólo se guarda con los ajustes ya cargados.
				if (!self.loaded) {
					if (typeof message === 'function') { message('Aún cargando los ajustes, intentá de nuevo', 'warning'); }
					return;
				}
				self.saving = true;
				var c = self.cfg;
				var data = {
					action: 'update', type: 'setting',
					address: c.address, website: c.website, email: c.email, ruc: c.ruc, phone: c.phone,
					city: c.city, country: c.country, language: c.language, timeZone: c.timeZone,
					currency: c.currency, taxName: c.taxName, billingName: c.billingName, tin: c.tin,
					billDetail: c.billDetail, category: c.category, thousandSeparator: c.thousandSeparator,
					itemsSaleLimit: c.itemsSaleLimit,
					facebook: c.social.facebook, instagram: c.social.instagram, youtube: c.social.youtube, twitter: c.social.twitter,
					decimal: c.decimal ? 1 : '', sellsoldout: c.sellsoldout ? 1 : '', itemSerialized: c.itemSerialized ? 1 : '',
					drawerEmail: c.drawerEmail ? 1 : '', drawerBlind: c.drawerBlind ? 1 : '', settingRemoveTaxes: c.settingRemoveTaxes ? 1 : '',
					paymentId: c.paymentId ? 1 : '', creditLine: c.creditLine ? 1 : '', storeCredit: c.storeCredit ? 1 : '',
					ignoreInternal: c.ignoreInternal ? 1 : '', stockCountBlind: c.stockCountBlind ? 1 : '', blockUsedDocNo: c.blockUsedDocNo ? 1 : '',
					autoSendDocs: c.autoSendDocs ? 1 : '', taxPy: c.taxPy ? 1 : '', weightBarcodes: c.weightBarcodes ? 1 : '',
					deletedItemsHistory: c.deletedItemsHistory ? 1 : ''
				};
				var xhr = ncmHelpers.load({
					url: BFF, httpType: 'POST', type: 'json', hideLoader: true, warnTimeout: false, data: data,
					success: function (res) {
						self.saving = false;
						if (res && res.ok) {
							if (typeof message === 'function') { message('Ajustes guardados', 'success'); }
						} else if (typeof message === 'function') {
							message('No se pudo guardar', 'danger');
						}
					},
					error: function () { self.saving = false; }
				});
				window.xhrs.push(xhr);
			}
		};
	}

	window.settingsModule = settingsComponent;

	$(function () {
		var root = document.getElementById('settingsRoot');
		if (!root || !window.Alpine) { return; }
		var fresh = root.cloneNode(true);
		fresh.setAttribute('x-data', 'settingsModule()');
		Alpine.initTree(fresh);
		root.parentNode.replaceChild(fresh, root);
		Alpine.$data(fresh).mountUI();
	});

})();
