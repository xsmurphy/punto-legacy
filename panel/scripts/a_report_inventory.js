/**
 * Front del reporte Inventario (a_report_inventory) — extraído del <script> inline
 * de a_report_inventory.php (split front/back, patrón del piloto a_report_summary).
 *
 * El back (a_report_inventory.php?action=generalTable / generalTableByDay /
 * widget=inventory) ya devuelve JSON; este front solo pinta la tabla + los totales.
 * Las vars que antes se inyectaban con tags PHP en el JS inline ahora llegan por
 * window.reportInventory (seteadas en la vista PHP). Las ramas que antes eran
 * condicionales PHP (bd / ii) ahora son cfg.byDay / cfg.itemId.
 */
(function () {

	var cfg      = window.reportInventory || {};
	var baseUrl  = cfg.baseUrl;
	var currency = cfg.currency;
	var offset   = cfg.offset;
	var limit    = cfg.limit;
	var itemId   = cfg.itemId || '';

	$(document).ready(function () {

		FastClick.attach(document.body);
		dateRangePickerForReports(cfg.startDate, cfg.endDate);

		var loadTheTable = function (tableOps, oTable) {
			onClickWrap('.doc', function (event, tis) {
				var load = tis.attr('href');
				loadForm(load, '#modalLarge .modal-content', function () {
					$('#modalLarge').modal('show');
				});
			}, false, true);
		};

		if (cfg.byDay) {

			var rawUrl = baseUrl + "?action=generalTableByDay";
			var url    = rawUrl + "&ii=" + itemId;

			$.get(url, function (result) {

				var options = {
					"container"    : ".tableContainer",
					"url"          : url,
					"rawUrl"       : rawUrl,
					"iniData"      : result.table,
					"table"        : ".table1",
					"sort"         : 0,
					"footerSumCol" : [5, 6],
					"currency"     : currency,
					"decimal"      : decimal,
					"thousand"     : thousandSeparator,
					"offset"       : offset,
					"limit"        : limit,
					"nolimit"      : true,
					"ncmTools"     : {
						left  : '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Inventario por día">Exportar Listado</a>',
						right : ''
					},
					"colsFilter"   : {
						name : 'inventoryHistoryByDay',
						menu : [
							{ "index": 0, "name": "Fecha",        "visible": true  },
							{ "index": 1, "name": "Artículo",     "visible": true  },
							{ "index": 2, "name": "Código / SKU", "visible": false },
							{ "index": 3, "name": 'Sucursal',     "visible": false },
							{ "index": 4, "name": 'Usuario',      "visible": false },
							{ "index": 5, "name": 'Existencia',   "visible": true  },
							{ "index": 6, "name": 'Costo Uni.',   "visible": true  }
						]
					}
				};

				manageTableLoad(options, function (oTable) {
					loadTheTable(options, oTable);
				});
			});

		} else {

			var rawUrl = baseUrl + "?action=generalTable";
			var url    = rawUrl + "&ii=" + itemId;

			$.get(url, function (result) {

				var byDayHref = itemId ? ('ii=' + itemId + '&bd=1') : 'bd=1';

				var options = {
					"container"    : ".tableContainer",
					"url"          : url,
					"rawUrl"       : rawUrl,
					"iniData"      : result.table,
					"table"        : ".table1",
					"sort"         : 0,
					"footerSumCol" : [7, 8, 9, 10],
					"currency"     : currency,
					"decimal"      : decimal,
					"thousand"     : thousandSeparator,
					"offset"       : offset,
					"limit"        : limit,
					"nolimit"      : true,
					"ncmTools"     : {
						left  : '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Inventario">Exportar Listado</a><a href="/@#report_inventory?' + byDayHref + '" class="btn btn-default hidden">Por Día</a>',
						right : ''
					},
					"colsFilter"   : {
						name : 'inventoryHistory2',
						menu : [
							{ "index": 0,  "name": "Fecha",        "visible": true  },
							{ "index": 1,  "name": "Artículo",     "visible": true  },
							{ "index": 2,  "name": "Código / SKU", "visible": false },
							{ "index": 3,  "name": 'Sucursal',     "visible": false },
							{ "index": 4,  "name": 'Depósito',     "visible": false },
							{ "index": 5,  "name": 'Usuario',      "visible": false },
							{ "index": 6,  "name": 'Fuente',       "visible": true  },
							{ "index": 7,  "name": 'Ingreso',      "visible": true  },
							{ "index": 8,  "name": 'Egreso',       "visible": true  },
							{ "index": 9,  "name": 'Existencia',   "visible": false },
							{ "index": 10, "name": 'Costo Uni.',   "visible": true  }
						]
					}
				};

				manageTableLoad(options, function (oTable) {
					loadTheTable(options, oTable);
				});
			});
		}

		if (!itemId) {
			$.get(baseUrl + '?widget=inventory', function (result) {
				$('#stockCOGS').text(result.cost);
				$('#stockSell').text(result.sell);
				$('#stockTotal').text(result.total);
			});
		}
	});

})();
