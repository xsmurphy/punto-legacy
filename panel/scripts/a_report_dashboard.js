/**
 * Front del Dashboard del panel (a_dashboard) — BFF de 3 niveles, templating con Alpine.
 *
 * Diseño VISUALMENTE idéntico al legacy; cambia la plomería de datos y el templating:
 *   - templating: Alpine (reemplaza Mustache) — el HTML bindea x-text/x-html/x-show/x-for
 *     contra el estado de este componente. Charts Chart.js quedan imperativos (canvas por id).
 *   - config (currency/decimal/thousand) ← GET /bff/bootstrap.php
 *   - cada widget                        ← GET /bff/reports/dashboard.php?widget=…
 *   - gráfico de ingresos + sparkline    ← GET /bff/reports/summary.php?view=chart
 *
 * El front NUNCA pega a /API/v1 (siempre al BFF). El service manda datos CRUDOS; este front
 * formatea TODO lo presentacional (currency, %, flechas de comparación). Ver REGLA RAÍZ 2.
 *
 * Init determinista: el fragmento NO trae x-data en el markup (evita la carrera con el
 * MutationObserver de Alpine antes de que cargue este <script>). Acá, en $(ready), se hace
 * root.setAttribute('x-data','dashboard()') + Alpine.initTree(root) — alineado con la carga
 * lazy de fragmentos del shell (document ya está ready → el callback corre al instante).
 *
 * Widgets gateados por módulo (satisfaction/tables/schedule): el service devuelve [] cuando el
 * módulo está apagado → el loader deja showX en false. El tour iguider queda fuera (10-roadmap).
 */
(function () {

	var BFF       = '/bff/reports/dashboard.php';
	var SUMMARY   = '/bff/reports/summary.php';
	var BOOTSTRAP = '/bff/bootstrap.php';

	var SUCCESS = '<span class="text-success m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
	var FAIL    = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_down</i></span>';
	var EVEN    = '<span class="font-bold m-r-xs m-l-xs"><i class="material-icons">trending_flat</i></span>';

	function dashboardComponent() {
		return {
			cfg : { currency: '', decimal: 'no', thousand: 'dot', companyName: '' },
			from: moment().subtract(7, 'days').format('YYYY-MM-DD 00:00:00'),
			to  : moment().endOf('day').format('YYYY-MM-DD HH:mm:ss'),

			income : { total: 0, expenses: 0, revenue: 0, margin: 0, count: 0, customerAverage: 0 },
			arrows : { total: '', expenses: '', revenue: '', margin: '', count: '', customerAverage: '', customersNew: '', customersOld: '' },
			payment: { contado: 0, credito: 0, cobrado: 0, porcobrar: 0, contadoCount: 0, creditoCount: 0, cobradoCount: 0, porcobrarCount: 0 },
			showPayment: false,
			showChart  : false,
			customers  : { total: 0, new: 0, old: 0 },
			rates      : { retention: 0, growth: 0, churn: 0 },
			topItems   : [],
			topItemsLoaded: false,
			schedule   : { scheduledCount: 0, shiftHours: 0, freeHours: 0, occupancy: 0, workingHours: 0, blockedHours: 0 },
			showSchedule: false,
			tables     : { tablesCount: 0, totalTables: 0, occupacy: 0, freeTables: 0 },
			showTables : false,
			orders     : { ordersCount: 0, onlineCount: 0 },
			showOrders : false,
			satisfaction: { detractors: { percent: 0, count: 0 }, passives: { percent: 0, count: 0 }, promoters: { percent: 0, count: 0 } },
			showSatisfaction: false,
			info       : { giftCardsCount: 0, openDrawersCount: 0, outletsCount: 0, plan: '', usersCount: 0, itemsCount: 0, transactionsCount: 0 },
			hasHours   : true,
			hoursLoaded: false,
			hasCategories  : true,
			categoriesLoaded: false,
			_charts: {},

			/* ───────────── init (Alpine lo llama al iniciar el componente; el nodo está DETACHED,
			 * ver $(ready) — por eso acá NO hay setup que toque el DOM del documento) ───────────── */
			init: function () {
				var self = this;

				Chart.defaults.global.responsive          = true;
				Chart.defaults.global.maintainAspectRatio  = false;
				Chart.defaults.global.legend.display       = false;

				ncmHelpers.load({
					url: BOOTSTRAP, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) {
						if (res && res.ok && res.data) {
							self.cfg.currency    = res.data.currency || '';
							self.cfg.decimal     = res.data.decimal  || 'no';
							self.cfg.thousand    = res.data.thousand || 'dot';
							self.cfg.companyName = res.data.companyName || '';
							window.currency          = self.cfg.currency;
							window.decimal           = self.cfg.decimal;
							window.thousandSeparator = self.cfg.thousand;
						}
						self.loadAll();
					}
				});
			},

			// Setup que requiere el nodo en el documento (date-picker, tooltips). Se llama desde
			// $(ready) DESPUÉS de insertar el componente ya inicializado.
			mountUI: function () {
				var self = this;
				dateRangePickerForReports(
					moment(self.from, 'YYYY-MM-DD HH:mm:ss'),
					moment(self.to,   'YYYY-MM-DD HH:mm:ss'),
					'left', false, true
				);
				$('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function (ev, picker) {
					self.from = picker.startDate.format('YYYY-MM-DD HH:mm:ss');
					self.to   = picker.endDate.format('YYYY-MM-DD HH:mm:ss');
					self.loadAll();
				});
				$('[data-toggle="tooltip"]').tooltip();
				if (window.FastClick) { FastClick.attach(document.body); }
			},

			/* ───────────── formateo + helpers ───────────── */
			fmt: function (n) { return formatNumber(n || 0, '', this.cfg.decimal, this.cfg.thousand); },
			fmtInt: function (v) { return formatNumber(parseFloat(v) || 0, '', 'no', this.cfg.thousand); },
			esc: function (s) {
				return String(s == null ? '' : s)
					.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
			},
			goHash: function (h) { window.location.hash = h; },

			// Filas de Top 5 Artículos (x-html del tbody; reactivo a topItems/topItemsLoaded).
			topItemsRows: function () {
				if (!this.topItemsLoaded) { return ''; }
				if (!this.topItems.length) {
					return '<tr><td colspan="3" class="text-center font-bold text-muted">' +
						'<div class="text-center font-bold text-muted">' +
						'<img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-md"></div></td></tr>';
				}
				var self = this;
				return this.topItems.map(function (it) {
					return '<tr><td>' + self.esc(it.name) + '</td>' +
						'<td class="text-right">' + self.fmtInt(it.count) + '</td>' +
						'<td class="font-bold text-right">' + self.fmt(it.total) + '</td></tr>';
				}).join('');
			},

			// Ventas/ingresos: subir es bueno (verde). Egresos: invertido.
			arrowUp: function (now, prev) { return now > prev ? SUCCESS : (now < prev ? FAIL : EVEN); },
			arrowDown: function (now, prev) { return now < prev ? SUCCESS : (now > prev ? FAIL : EVEN); },

			gauge: function (value, color) {
				value = Number(value) || 0;
				return 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' +
					value + ', ' + (value - 100) + '], backgroundColor: [ "' + color + '", "%23e8eff0" ] } ] }, ' +
					'options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';
			},

			/* ───────────── carga de datos (BFF) ───────────── */
			_get: function (name, extra, cb) {
				var self = this;
				var url = BFF + '?widget=' + name +
					'&from=' + encodeURIComponent(self.from) + '&to=' + encodeURIComponent(self.to) + (extra || '');
				var xhr = ncmHelpers.load({
					url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) { if (res && res.ok) { cb(res.data); } }
				});
				window.xhrs.push(xhr);
			},

			loadAll: function () {
				// Reset de los gates antes de recargar (date re-fetch): los widgets gateados sólo se
				// REVELAN al traer datos; sin esto, un período nuevo sin datos dejaría visible el dato viejo.
				this.showPayment = this.showChart = this.showSchedule = this.showTables = this.showOrders = this.showSatisfaction = false;
				this.loadIncome();
				this.loadPayment();
				this.loadChart();
				this.loadSchedule();
				this.loadTables();
				this.loadOrders();
				this.loadInfo();
				this.loadSatisfaction();
				this.loadTopItems();
				this.loadRates();
				this.loadCustomers();
				this.loadTopCategories();
				this.loadTopHours();
			},

			loadIncome: function () {
				var self = this;
				self._get('incomeOutcomeStats', '', function (d) {
					self.income = { total: d.total, expenses: d.expenses, revenue: d.revenue, margin: d.margin, count: d.count, customerAverage: d.customerAverage };
					self._get('incomeOutcomeStats', '&prev=true', function (p) {
						self.arrows.total           = self.arrowUp(d.total, p.total);
						self.arrows.expenses        = self.arrowDown(d.expenses, p.expenses);
						self.arrows.revenue         = self.arrowUp(d.revenue, p.revenue);
						self.arrows.margin          = self.arrowUp(d.margin, p.margin);
						self.arrows.count           = self.arrowUp(d.count, p.count);
						self.arrows.customerAverage = self.arrowUp(d.customerAverage, p.customerAverage);
					});
				});
			},

			loadPayment: function () {
				var self = this;
				self._get('paymentStatus', '', function (d) {
					self.payment = d;
					if (d.creditoCount > 0 || d.cobradoCount > 0 || d.porcobrarCount > 0) {
						self.showPayment = true;
						self.$nextTick(function () { self.drawPayment(d); });
					}
				});
			},

			loadInfo: function () {
				var self = this;
				self._get('info', '', function (d) { if (d && d.itemsCount != null) { self.info = d; } });
			},

			loadCustomers: function () {
				var self = this;
				self._get('customers', '', function (d) {
					if (!d || d.total == null) { return; }
					self.customers = { total: d.total, new: d.new, old: d.old };
					self._get('customers', '&prev=true', function (p) {
						self.arrows.customersNew = self.arrowUp(d.new, p.new);
						self.arrows.customersOld = self.arrowUp(d.old, p.old);
					});
				});
			},

			loadRates: function () {
				var self = this;
				self._get('customersRates', '', function (d) {
					if (!d) { return; }
					self.rates = {
						retention: Number(d.retention_rate) || 0,
						growth   : Number(d.customer_growth_rate) || 0,
						churn    : Number(d.churn_rate) || 0
					};
				});
			},

			loadSatisfaction: function () {
				var self = this;
				self._get('satisfaction', '', function (d) {
					if (!d || d.detractors == null) { return; }
					self.satisfaction = d;
					self.showSatisfaction = true;
					self.$nextTick(function () { $('[data-toggle="tooltip"]').tooltip(); });
				});
			},

			loadSchedule: function () {
				var self = this;
				self._get('schedule', '', function (d) {
					if (!ncmHelpers.validity(d) || d.scheduledCount == null) { return; }
					self.schedule = d;
					self.showSchedule = true;
				});
			},

			loadTables: function () {
				var self = this;
				self._get('tables', '', function (d) {
					if (!ncmHelpers.validity(d) || d.tablesCount == null) { return; }
					self.tables = d;
					self.showTables = true;
				});
			},

			loadOrders: function () {
				var self = this;
				self._get('orders', '', function (d) {
					if (!d || d.ordersCount == null) { return; }
					self.orders = d;
					self.showOrders = true;
				});
			},

			loadTopItems: function () {
				var self = this;
				self._get('topItems', '', function (d) {
					self.topItems = (d || []).map(function (it) { return { name: it.name, count: it.count, total: it.total }; });
					self.topItemsLoaded = true;
				});
			},

			/* ───────────── charts (imperativos) ───────────── */

			drawPayment: function (d) {
				var self = this;
				var darkBg = (window.ncmUI && ncmUI.setDarkMode && ncmUI.setDarkMode.isSet) ? '#3b464d' : '#d7e5e8';

				var ctxA = $('#chart-contado')[0].getContext('2d');
				var gradA = ctxA.createLinearGradient(500, 0, 100, 0);
				gradA.addColorStop(0, '#01D7A1'); gradA.addColorStop(1, '#54cfc7');
				self._destroy('contado');
				self._charts.contado = new Chart(ctxA, {
					type: 'doughnut',
					data: { labels: ['Contado', 'Crédito'], datasets: [{ data: [d.contado, d.credito], backgroundColor: [darkBg, gradA] }] },
					animation: true, options: { cutoutPercentage: 85, tooltips: chartTooltipStyle.tooltips }
				});

				var ctxB = $('#chart-porcobrar')[0].getContext('2d');
				var gradB = ctxB.createLinearGradient(500, 0, 100, 0);
				gradB.addColorStop(0, '#01D7A1'); gradB.addColorStop(1, '#54cfc7');
				self._destroy('porcobrar');
				self._charts.porcobrar = new Chart(ctxB, {
					type: 'doughnut',
					data: { labels: ['Por Cobrar', 'Cobrado'], datasets: [{ data: [d.porcobrar, d.cobrado], backgroundColor: [gradB, darkBg] }] },
					animation: true, options: { cutoutPercentage: 85, tooltips: chartTooltipStyle.tooltips }
				});
			},

			loadTopCategories: function () {
				var self = this;
				self._get('topCategories', '', function (d) {
					self.categoriesLoaded = true;
					if (!ncmHelpers.validity(d)) { self.hasCategories = false; return; }
					self.hasCategories = true;
					self.$nextTick(function () { self.drawCategories(d); });
				});
			},

			drawCategories: function (d) {
				var self = this;
				var ctx  = $('#topCategoriesChart')[0].getContext('2d');
				var grad = ctx.createLinearGradient(500, 0, 100, 0);
				grad.addColorStop(0, '#01D7A1'); grad.addColorStop(1, '#54cfc7');

				var tips = ncmHelpers.cloneObj(chartTooltipStyle);
				tips.tooltips.callbacks.title = function () { return false; };
				tips.tooltips.callbacks.label = function (item, data) {
					var dataItem = data.datasets[item.datasetIndex].data[item.index];
					return dataItem.g + ': ' + dataItem.v;
				};

				self._destroy('categories');
				self._charts.categories = new Chart(ctx, {
					type: 'treemap',
					data: { datasets: [{
						tree: d, data: d.amount, backgroundColor: grad, spacing: 3, borderWidth: 0,
						borderColor: 'rgba(180,180,180, 0.15)', key: 'total', groups: ['title'],
						fontColor: '#fff', fontFamily: 'Source Sans Pro'
					}] },
					options: { maintainAspectRatio: false, title: { display: false }, legend: { display: false }, tooltips: tips.tooltips }
				});
			},

			loadTopHours: function () {
				var self = this;
				self._get('topHours', '', function (d) {
					self.hoursLoaded = true;
					if (!d || !ncmHelpers.validity(d.hour)) { self.hasHours = false; return; }
					self.hasHours = true;
					self.$nextTick(function () { self.drawHours(d); });
				});
			},

			drawHours: function (d) {
				var self = this;
				var ctx  = $('#topHoursChart')[0].getContext('2d');
				var grad = ctx.createLinearGradient(300, 0, 100, 0);
				grad.addColorStop(0, '#01D7A1'); grad.addColorStop(1, '#54cfc7');
				self._destroy('hours');
				self._charts.hours = new Chart(ctx, {
					type: 'polarArea',
					data: { labels: d.hour, datasets: [{ data: d.total, backgroundColor: [grad, grad, '#2f3940', '#2f3940', '#405161', '#405161', '#778490', '#778490', '#d7e5e8', '#d7e5e8', '#e8eff0', '#e8eff0'] }] },
					animation: true, options: chartTooltipStyle
				});
			},

			loadChart: function () {
				var self = this;
				var url = SUMMARY + '?view=chart&from=' + encodeURIComponent(self.from) + '&to=' + encodeURIComponent(self.to);
				var xhr = ncmHelpers.load({
					url: url, httpType: 'GET', hideLoader: true, type: 'json', warnTimeout: false,
					success: function (res) {
						if (!res || !res.ok || !res.data || !res.data.chart) { return; }
						var ch = res.data.chart;
						if (ch.gross && ch.gross.length) {
							self.showChart = true;
							self.$nextTick(function () { self.drawChart(ch); self.drawSparkline(ch.gross); });
						}
					}
				});
				window.xhrs.push(xhr);
			},

			// Sparkline de fondo en la card de Ingresos (quickchart.io), igual al legacy.
			drawSparkline: function (gross) {
				var total = gross.reduce(function (a, b) { return parseInt(a) + parseInt(b); }, 0);
				if (!total) { return; }
				var pct = gross.map(function (v) { return parseInt((v * 100) / total); });
				var url = encodeURI('https://quickchart.io/chart?cht=lc&chd=t:' + pct.toString() + '&chco=ffffff4d&chf=a,s,000000&chls=4.0&chs=400x80');
				$('#totalIncome').removeClass('gradBgBlue').addClass('text-white')
					.attr('style', 'background:url(' + url + ') no-repeat center center / cover, linear-gradient(314deg, #54CFC7,#6BC0D1);');
			},

			drawChart: function (ch) {
				var self = this;
				var labels = ch.buckets.map(function (b) { return ch.isDay ? (b + 'h') : moment(b).format('DD MMM'); });

				Chart.defaults.global.legend.display = true;
				var ctx  = $('#summaryChart')[0].getContext('2d');
				var grad = ctx.createLinearGradient(1600, 0, 0, 0);
				grad.addColorStop(0, '#01D7A1'); grad.addColorStop(0.5, '#54cfc7'); grad.addColorStop(1, '#54cfc7');

				var data = {
					labels: labels,
					datasets: [
						{
							label: 'Margen', data: ch.margin, type: 'line', borderColor: '#FF9469',
							pointColor: '#FF9469', pointHoverRadius: 8, pointHoverBorderColor: '#fff',
							pointHoverBackgroundColor: '#FF9469', pointBorderColor: '#FF9469', pointBackgroundColor: '#FF9469',
							pointRadius: 3, pointHoverBorderWidth: 3, pointBorderWidth: 1, pointHitRadius: 20, borderWidth: 3, fill: false
						},
						{ type: 'bar', label: 'Ingresos', backgroundColor: grad, data: ch.gross },
						{ type: 'bar', label: 'Egresos', backgroundColor: chartSecondColor, data: ch.grossE }
					]
				};

				self._destroy('summary');
				self._charts.summary = new Chart(ctx, { type: 'bar', data: data, animation: true, options: chartBarStackedGraphOptions });
				Chart.defaults.global.legend.display = false;
			},

			_destroy: function (key) {
				if (this._charts[key]) { try { this._charts[key].destroy(); } catch (e) {} this._charts[key] = null; }
			}
		};
	}

	// Componente global (lo resuelve x-data="dashboard()").
	window.dashboard = dashboardComponent;

	// Init determinista (ver cabecera). El fragmento HTML tiene `x-ignore` en el root para
	// prevenir que Alpine intente evaluar `x-text="cfg.companyName"` antes de que tengamos el
	// componente wireado. El init real: clonar a un nodo FRESCO (los expandos _x_ no se clonan),
	// quitarle x-ignore, ponerle x-data e inicializarlo con Alpine.initTree mientras está
	// DETACHED (el observer no lo ve). Al reinsertarlo ya está marcado → el observer lo saltea.
	// Init exactamente 1× por instancia. mountUI() corre el setup que requiere DOM.
	function setupDashboard(root) {
		if (!root || !window.Alpine || root._puntoInited) return false;
		if (typeof window.dashboard !== 'function') {
			// El componente debió cargarse vía script tag — defensa para race rara.
			console.warn('[dashboard] window.dashboard no está listo, skipping init');
			return false;
		}
		root._puntoInited = true;
		var fresh = root.cloneNode(true);
		fresh.removeAttribute('x-ignore');
		fresh.setAttribute('x-data', 'dashboard()');
		try {
			Alpine.initTree(fresh);
			root.parentNode.replaceChild(fresh, root);
			var data = Alpine.$data(fresh);
			if (data && typeof data.mountUI === 'function') data.mountUI();
		} catch (err) {
			console.error('[dashboard] init failed', err);
		}
		return true;
	}

	// Doble entry: (a) intentar en $(ready) por si el shell ya inyectó el fragmento (entrada directa
	// a /@#dashboard con prefetch, dev local con server-side render); (b) MutationObserver sobre
	// #bodyContent para detectar cuando el shell inyecta el dashboard vía hashchange (caso default
	// en prod). El observer queda vivo por la vida de la página — al user volver al dashboard, el
	// shell reemplaza #bodyContent y un nuevo #dashboardRoot dispara setup() otra vez.
	$(function () {
		if (!window.Alpine) return;
		if (setupDashboard(document.getElementById('dashboardRoot'))) return;

		var bodyContent = document.getElementById('bodyContent') || document.body;
		var observer = new MutationObserver(function () {
			var root = document.getElementById('dashboardRoot');
			if (root && !root._puntoInited) setupDashboard(root);
		});
		observer.observe(bodyContent, { childList: true, subtree: true });
	});

})();
