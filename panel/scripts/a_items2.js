
	if(!isArchived){
		itemsOps += 	'<li><a href="#" id="bulkEditBtn" data-type="bulkEdit" class="multi text-default"><span class="material-icons m-r-sm">edit</span>Edición masiva</a></li>' +
									'<li><a href="#" id="" data-type="group" class="multi group text-default"><span class="material-icons m-r-sm">done_all</span>Agrupar</a></li>' +
									'<li><a href="#" id="" data-type="barcode" class="multi text-default"><span class="material-icons m-r-sm">qr_code_2</span>Códigos de Barra</a></li>';
	}

	itemsOps += 		'<li>';
	
	if(isArchived){
		itemsOps +=		'	<a href="#" data-type="unarchive" class="multi text-default"><span class="material-icons m-r-sm">unarchive</span>Re Activar</a>' +
					'<a href="#" data-type="delete" class="multi text-default"><span class="material-icons text-danger">delete_forever</span>Eliminar</a>';
	}else{
		itemsOps +=		'	<a href="#" data-type="archive" class="multi text-default" data-toggle="tooltip" data-placement="top" title="Desactivar los artículos seleccionados para que no se muestren en la caja registradora">' +
									'		<span class="material-icons text-dangers m-r-sm">archive</span>Archivar' +
									'	</a>';
	}
	
	itemsOps +=			'</li>';



	$(document).ready(function(){
		window.baseUrl = window.baseUrl ? window.baseUrl : '/items';
		FastClick.attach(document.body);
		$('.matchCols').matchHeight();

		function getAllSelectedValues(table,type){
			var ids = [];
			table.rows('.selected').iterator( 'row', function ( context, index ) {
				var $el 	= $(this.row(index).node());
			    var id 		= $el.attr('id');
			    var count 	= 0;
			    
			    if(type){
			    	count 	= $('#inventoryCountRow' + id).text();
					if(count == "+"){
						ids.push(id);
					}else{
						count 	= (count < 1) ? 1 : count;
					
						for(a = 0; a < count; a++){
							ids.push(id);
						}
					}
			    }else{
			    	ids.push(id);
			    }
			});

			return ids;
		};

		var url     = baseUrl + "?action=showTable" + iftn(archived,'');
	    var rawUrl  = url;

		var xhr = $.get(url,function(result){

			var tiposList = [
							{type : 'products',name : 'Productos', search : 'producto'},
							{type : 'service',name : 'Servicios', search : 'servicio'},
							{type : 'combo',name : 'Combos', search : 'combo'},
							{type : 'production',name : 'Producción', search : 'producci'},
							{type : 'compounds',name : 'Compuestos', search : 'activo/comp'},
							{type : 'groups',name : 'Grupos', search : 'grupo'},
							{type : 'giftcards',name : 'Gift Cards', search : 'gift card'},
							{type : 'discounts',name : 'Descuentos', search : 'descuento'}
						];

			var tiposDrop 	= 	'<span class="btn-group">' +
								'	<a href="#" class="btn dropdown-toggle b b-light r-3x m-r-xs" data-toggle="dropdown" id="typeActivator" title="Tipos">' +
								'		<span class="material-icons">filter_list</span>' +
								'	</a>' +
								'	<ul class="dropdown-menu animated fadeIn speed-4x" id="typeActivatorMenu">' +
								'		<li><a href="#" data-type="all" class="typeActivator text-default">' +
								'			<i class="material-icons m-r-xs text-white">check</i>Todos</a></li>';
										$.each(tiposList,function(i,val){
											tiposDrop += '<li><a href="#" data-type="'+val.type+'" data-name="' + val.search + '" data-index="' + i + '" class="typeActivator typeActivatorBtn' + i + ' text-default">' +
														'<i class="material-icons m-r-xs text-white">check</i>' + val.name + 
													'</a></li>';
										});
			tiposDrop 		+=	'	</ul></span>';

	        var options = {
	                "container"   : ".tableContainer",
	                "url"         : url,
	                "rawUrl"      : rawUrl,
	                "iniData"     : result.table,
	                "table"       : ".table",
	                "sort"        : 3,
	                "footerSumCol" 	: [14,15,16,18],
	                "currency"    : currency,
	                "decimal"     : decimal,
	                "thousand"    : thousandSeparator,
	                "offset"      : window.offset,
	                "limit"       : window.limit,
	                "nolimit" 	  : true,
	                "tableName" 	: 'tableItems',
									"fileTitle" 	: 'Inventario',
	                "ncmTools"    : {
					                          left  		: tiposDrop + result.categoriesSelect,
					                          right   	: '<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o SKU" id="itemSearch" data-url="' + rawUrl + '&qry=">',
					                          ops 			: { menuTop : itemsOps, menuBottom : '' }
				                          },
		            "colsFilter"  : {
										name 		: 'items9',
										menu 		:  [
														{"index":0,"name":'Imagen', "visible" : false},
														{"index":1,"name":'Artículo',"visible":true},
														{"index":2,"name":'Tipo',"visible":false},
														{"index":3,"name":'Fecha',"visible":false},
														{"index":4,"name":'Ud. Medida',"visible":false},
														{"index":5,"name":'Código/SKU',"visible":false},
														{"index":6,"name":'Marca',"visible":false},
														{"index":7,"name":'Categoría',"visible":true},
														{"index":8,"name":'Sucursal',"visible":false},

														{"index":9,"name":'Sesiones',"visible":false},
														{"index":10,"name":'Duración',"visible":false},
														{"index":11,"name":'Merma',"visible":false},

														{"index":12,"name":'Comisión',"visible":false},
														{"index":13,"name":'Descuento',"visible":false},
														{"index":14,"name":'Costo',"visible":false},
														{"index":15,"name":'Precio',"visible":true},
														{"index":16,"name":'Valor',"visible":false},
														{"index":17,"name":taxName,"visible":false},
														{"index":18,"name":'Stock',"visible":false},
														{"index":19,"name":'Online',"visible":false}
														]
									},
					"clickCB" 		: 	function(event,tis){
											var id 			= tis.attr('id');
											helpers.loadPageLoad = false;
											window.location.hash = window.baseUrlH + '&i=' + id;
											$(window).trigger('hashvarchange');
										}
	        };

	        ncmDataTables(options,function(oTable,_scope){
	          loadTheTable(options,oTable);
	        });
	    });

	    window.xhrs.push(xhr);

		adm();

		var loadTheTable = function(tableOps,oTable,_scope){
			window.oTable 	= oTable;
			window.tableOps = tableOps;
			$('[data-toggle="tooltip"]').tooltip();

			$("table td.lazy").lazy();
			$('#bodyContent').scroll(function(){
				$("table td.lazy").lazy();
			});

			ncmHelpers.onClickWrap('.typeActivator',function(event,tis){
				var $tis 	= tis;
				var type 	= $tis.data('type');
				var find 	= '';
				var index 	= $tis.data('index');
				var name 	= $tis.data('name');
				var colIdx 	= 2;

				$.fn.dataTable.ext.search.pop();

				$('a.typeActivator i').removeClass('text-info').addClass('text-white');
				$('a.typeActivator').removeClass('active');

				if(type == 'all'){
					window.oTable.draw();
					return false;
				}

				if(!$tis.hasClass('active')){
					//habilito
					$('.typeActivatorBtn' + index + ' i').removeClass('text-white').addClass('text-info');
					$tis.addClass('active');

					$.fn.dataTable.ext.search.push(
						function(settings, data, dataIndex) {
							var field = data[colIdx].toLowerCase();

							if(field.indexOf(name) >= 0){
								return data[colIdx];
							}
						}
					);
				}else{
					$('.typeActivatorBtn' + index + ' i').removeClass('text-info').addClass('text-white');
					$tis.removeClass('active');
				}

				window.oTable.draw();
			});

			ncmHelpers.onClickWrap('#editItem .clickrow',function(event,tis){
				var id 				 = tis.attr('id');
				helpers.loadPageLoad = false;
				window.location.hash = window.baseUrlH + '&i=' + id;
				$(window).trigger('hashvarchange');
			});

			ncmHelpers.onClickWrap('.createItemBtn',function(event,tis){
				var extraUrl = '';
				if(tis.hasClass('discount')){
					extraUrl = '&discount=true';
				}else if(tis.hasClass('combo')){
					extraUrl = '&combo=true';
				}else if(tis.hasClass('giftcard')){
					extraUrl = '&giftcard=true';
				}

				var narrow 	 = tis.hasClass('modal-narrow');

				$.get(baseUrl + '?action=insertBtn' + extraUrl,function(response){
					if(validity(response,'string')){
						response = response.split('|');
					}else{
						ncmDialogs.alert('No posee permisos');
						return false;
					}

					if(response[0] == 'true'){

						id = response[2];
						loadForm(baseUrl + '?action=editform&id=' + id,'#modalLarge .modal-content',function(){
							if(narrow){
								$('#modalLarge .modal-dialog').removeClass('modal-lg');
							}else{
								$('#modalLarge .modal-dialog').addClass('modal-lg');
							}

							editItemActions();

							$('#modalLarge').modal('show').one('shown.bs.modal',function(){
								$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
									var $tr = $(data);
									oTable.row.add($tr).draw();
								});
							}).one('show.bs.modal',function(){
								
							});
						});
						
					}else if(response[0] == 'false'){
						message('Error al intentar procesar su petición','danger');
					}else if(response[0] == 'max'){
						ncmDialogs.confirm('Ha alcanzado el límite de artículos','Contáctenos y le asistiremos para incrementar','warning');
					}else{
						ncmDialogs.alert(response[0]);
						return false;
					}
				});
			});

			ncmHelpers.onClickWrap('.ungroup',function(event,tis){
				confirmation('¿Realmente desea remover del grupo?', function (e) {
					if (e === true) {
						var id 	= tis.data('id');
						$.get(baseUrl + '?action=ungroup&id=' + id, function(response) {
							if(response == 'true'){
								message('Removido','success');

								$('.row' + id).remove();
								$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
									oTable.row.add($(data));
								});

								oTable.draw();
								
							}else{
								message('Error al intentar procesar su petición','danger');
							}
						});
					}
				});
			});

			ncmHelpers.onClickWrap('.multi',function(event,tis){
				var type 		= tis.data('type');
				var selected 	= getAllSelectedValues(window.oTable);
				console.log(selected);

				spinner('body', 'show');
				
				if (selected.length < 1){
					ncmDialogs.alert('No ha seleccionado ningún artículo','warning','Puede seleccionar presionando Shift + click');
					spinner('body', 'hide');
					return false;
				}else if(selected.length == 1){
					if(type == 'barcode'){						
						prompter("Ingrese la cantidad de códigos a imprimir", function(cant) {
							if (cant) {
								spinner('body', 'hide');
								window.open('/barcode?ids=' + selected + '-' + cant);
							}
						});
					}else if(type == 'delete' || type == 'edit' || type == 'group' || type == 'bulkEdit'){
						ncmDialogs.alert('Debe seleccionar mas de un artículo de la lista');
						spinner('body', 'hide');
					}else if(type == 'archive'){
						var url = baseUrl + '?multi=true&action=archive&id=' + selected.join('|');
						
						$.get(url, function(response) {
							//console.log(response);
							if(validity(response)){
								message('Archivado','success');
								$.each(selected,function(k,id){
									var $tRow = $('tr#' + id);
									if($tRow.length > 0){
										oTable.row($tRow).remove();
									}
								});
								oTable.draw();
							}else{
								message('Error al intentar procesar su petición','danger');
							}
							spinner('body', 'hide');
						});
					}else if(type == 'unarchive'){
						
						var url = baseUrl + '?multi=true&action=unarchive&id=' + selected.join('|');
						
						$.get(url, function(response) {
							//console.log(response);
							if(validity(response)){
								message('Re Activado','success');
								$.each(selected,function(k,id){
									var $tRow = $('tr#' + id);
									if($tRow.length > 0){
										oTable.row($tRow).remove();
									}
								});
								oTable.draw();
							}else{
								message('Error al intentar procesar su petición','danger');
							}
							spinner('body', 'hide');
						});		
					}else if(type == 'inventory'){
						thalog('inventory');
						var multiSelect = getAllSelectedValues();
						window.open('inventory-view.php?ids='+multiSelect.join('|'));
						spinner('body', 'hide');
					}
				}else{
					if(type == 'barcode'){
						var multiSelect = getAllSelectedValues(oTable,true);
						window.open('/barcode?ids=' + multiSelect.join('|'));
						spinner('body', 'hide');
					}else if(type == 'delete'){
						spinner('body', 'hide');
						ncmDialogs.confirm('¿Desea eliminar?','Se perderán todos los datos, inventario y reportes relacionados a estos artículos','warning', function (e) {
							if (e) {
								var url = baseUrl + '?multi=true&action=delete&id=' + selected.join('|');

								$.each(selected,function(k,id){
									var $tRow = $('tr#' + id);
									if($tRow.length > 0){
										oTable.row($tRow).remove();
									}
								});

								oTable.draw();
								
								$.get(url, function(response) {
									if(response == 'true'){
										message('Eliminado','success');
									}else{
										message('Error al intentar procesar su petición','danger');
									}

									spinner('body', 'hide');
								});
							}
						});
					}else if(type == 'group'){
						var $cbx 		= $('.table tr.selected');
						var editGroup 	= false;
						var allGroups 	= true;
						$cbx.each(function(i){
							if($(this).hasClass('group')){
								editGroup = $(this).attr('id');
							}else{
								allGroups = false;
							}
						});

						if(editGroup && !allGroups){
							var url = baseUrl + '?multi=true&group=' + editGroup + '&action=groupEdit&id='+selected.join('|');
							$.get(url, function(response) {
								if(response == 'true'){
									message('Realizado','success');
									$.each(selected,function(k,id){
										if(id != editGroup){//elimino todos menos el grupo
											var $tRow = $('tr#' + id);
											if($tRow.length > 0){
												oTable.row($tRow).remove().draw();
											}
											spinner('body', 'hide');
										}
									});
								}else{
									message('Error al intentar procesar su petición','danger');
								}
							});
						}else{
							spinner('body', 'hide');
							prompter("Nombre del Grupo", function(name) {
								if (name) {
									var url = baseUrl + '?multi=true&name='+name+'&action=group&id='+selected.join('|');
									
									$.get(url, function(response) {//respuesta será ID del grupo creado
										if(response){
											$.get(tableOps.rawUrl + '&part=1&singleRow=' + response,function(data){
												oTable.row.add($(data)).draw();
											});

											$.each(selected,function(k,id){
												var $tRow = $('tr#' + id);
												if($tRow.length > 0){
													oTable.row($tRow).remove();
												}
											});

											oTable.draw();
											spinner('body', 'hide');
										}else{
											message('Error al intentar procesar su petición','danger');
										}
									});
								}
							});
						}		
					}else if(type == 'archive'){
						
						var url = baseUrl + '?multi=true&action=archive&id='+selected.join('|');
						
						$.get(url, function(response) {
							//console.log(response);
							if(validity(response)){
								message('Archivados','success');
								$.each(selected,function(k,id){
									var $tRow = $('tr#' + id);
									if($tRow.length > 0){
										oTable.row($tRow).remove();
									}
								});
							}else{
								message('Error al intentar procesar su petición','danger');
							}
							oTable.draw();
							spinner('body', 'hide');
						});
					}else if(type == 'unarchive'){
						
						var url = baseUrl + '?multi=true&action=unarchive&id='+selected.join('|');
						
						$.get(url, function(response) {
							//console.log(response);
							if(response == 'true'){
								message('Re Activados','success');
								$.each(selected,function(k,id){
									$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
										var $tRow = $('tr#' + id);
										oTable.row($tRow).remove();
									});
								});
							}else{
								message('Error al intentar procesar su petición','danger');
							}
							oTable.draw();
							spinner('body', 'hide');
						});
					}else if(type == 'bulkEdit'){
						var rowIndex = [];

						var load = baseUrl + '?action=bulkEditForm';
						loadForm(load,'#modalLarge .modal-content',function(){
							$('#modalLarge').modal('show');
							$('[data-toggle="tooltip"]').tooltip();
							masksCurrency($('.maskInteger'),thousandSeparator,'no');
							masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
							$('input#bulkUpdateIds').val(selected.join('|'));
							select2Simple($(".search,.searchSimple"));
							spinner('body', 'hide');
						});
					}else if(type == 'inventory'){
						var multiSelect = getAllSelectedValues(oTable,true);
						window.open('inventory-view.php?ids='+multiSelect.join('|'));
						spinner('body', 'hide');
					}else if(type == 'export'){
						thalog('export');
						var multiSelect = getAllSelectedValues(oTable,true);
						window.open('?multi=true&action=exportCSV&ids='+selected.join('|'));
						spinner('body', 'hide');
					}
				}
			});

			$(window).off('hashvarchange').on('hashvarchange', function() {
				var rawHash 	= window.location.hash.substring(1);
				var jHash 		= rawHash.split('&').reduce(function (result, item) {
				    var parts 	= item.split('=');
				    result[parts[0]] = parts[1];
				    return result;
				}, {});

				if(jHash['i']){
					var tis 		= $('#' + jHash['i']);
					var load 		= baseUrl + '?action=editform&id=' + jHash['i'];
					var narrow 		= tis.hasClass('modal-narrow');
					var modal		= '#modalLarge';

					if(!tis.length){
						return false;
					}

					if(narrow){
						var modal 		= '#modalSmall';
						var placeHolder = '<img src="/images/itemPlaceholderNarrow.png"/>';
					}else{
						var placeHolder = '<img src="/images/itemPlaceholder.png"/>';
					}

					if($('.modal').is(':visible')){
						$('.modal').modal('hide');
					}
					
					$(modal).find('.modal-content').html('<div class="col-xs-12 no-padder">' + placeHolder + '</div>',function(){
						setTimeout(function(){
							$(modal).modal('show');
						},300);
						
						loadForm(load, modal + ' .modal-content', function(){
							helpers.loadPageLoad = true;
							editItemActions();				
						});
					});
				}
			});

			$(window).trigger('hashvarchange');

			

			ncmHelpers.onClickWrap('.itemsAction',function(event,tis){
				var type 		= tis.data('type'); //obtengo el tipo de accion
				var index 		= parseInt(tis.data('position'));
				var id 			= tis.data('id');
				var load 		= tis.data('load');
				var element		= tis.data('element');
				var narrow 		= tis.hasClass('modal-narrow');
				
				if(tis.hasClass('disabled')){return false;}

				if(type == 'deleteItem' || type == 'archiveItem'){
					var warn = (type == 'archiveItem') ? '¿Seguro que desea Archivar?' : '¿Seguro que desea eliminar?';
					var done = (type == 'archiveItem') ? 'archivado' : 'eliminado';
					confirmation(warn, function (e) {
						if (e) {
							$.get(load, function(response) {
								if(response == 'false'){
									message('Error al eliminar','danger');
									return;
								}

								oTable.row($('tr#' + id)).remove().draw();
								$('#modalLarge').modal('hide');
								message('Artículo ' + done,'success');
								$('.modal').modal('hide');
							});
						}
					});
				}else if(type == 'empty'){

				}
			});

			var srcValCache = '';
		    $('#itemSearch').off('keyup').on('keyup',function(e){
		    	var $tis 	= $(this);
		    	var value 	= $tis.val();
		    	var tmout 	= 800;
		    	var code 	= e.keyCode || e.which;

		    	if(code == 13) { //Enter keycode
			    	if(value.length > 2){
			    		if(!$.trim(value) || srcValCache == value){
			    			return false;
			    		}

		    			spinner(tableOps.container, 'show');
		    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
		    				oTable.rows().remove();
		    				if(result){
		    					var line 	= explodes('[@]',result);
		    					$.each(line,function(i,data){
		    						if(data){
		                    			oTable.row.add($(data));
		                    		}
		    					});
		    				}

		    				oTable.draw();

		    				$('.lodMoreBtnHolder').addClass('hidden');
		    				spinner(tableOps.container, 'hide');
			    		});
			    		

			    		srcValCache = value;

			    	}else if(value.length < 1 || !value){
			    		srcValCache = '';

			    		ncmDataTablesReset(oTable,tableOps);
			    	}else{
			    		message('Añada por lo menos 3 caracteres','warning');
			    	}
			    }
		    });

		    select2Simple($(".search,.searchSimple"));

		    ncmHelpers.onClickWrap('.filterByCategory',function(event,tis){
				var id = tis.data('id');
				spinner(tableOps.container, 'show');
				$('.filterByCategory').addClass('text-default');
				tis.removeClass('text-default');

				if(id == 'all'){					
					ncmDataTablesReset(oTable,tableOps);
				}else{
					var url = tableOps.rawUrl + '&srccat=' + id + '&part=1&nolimit=1';
					$.get(url,function(result){
						oTable.rows().remove();
						if(result){
							var line 	= explodes('[@]',result);
							$.each(line,function(i,data){
								if(data){
		                			oTable.row.add($(data));
		                		}
							});
						}

						oTable.draw();

						$('.lodMoreBtnHolder').addClass('hidden');
						spinner(tableOps.container, 'hide');
		    		});
				}
			});

			ncmHelpers.onClickWrap('.table span.check',function(event,tis){
			    var $this 	= tis
			    var $input 	= $this.find('input');
			    var val 	= $input.val();
			    var $tr 	= $this.closest('tr');
			    
			   	if($this.hasClass('selected')){
			        $this.removeClass('selected');
			    }else{
			        $this.addClass('selected');
			    }

			    if($tr.hasClass('selected')){
			        $tr.removeClass('selected');
			    }else{
			        $tr.addClass('selected');
			    }    
			});

			submitForm2('#addItem,#editItem,#insertItem',function(element,id){
				var modalId = '#modalLarge';
				if($('#modalSmall').is(':visible')){
					modalId = '#modalSmall';
				}

				loadForm(baseUrl + '?action=editform&id=' + id, modalId + ' .modal-content',function(){
					//$('.modal').modal('hide');
					$('.matchCols').matchHeight();
					$('#modalLarge, #modalSmall').trigger('ncmModalUpdate');
				});

				$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
					var $tRow = $('tr#' + id);
					if($tRow.length > 0){
						oTable.row($tRow).remove();
						if(data){
							oTable.row.add($(data));
						}
					}
					oTable.draw();
				});
			},true);

			submitForm2('#editItemBulk',function(element,ids){
				$('#modalLarge').modal('hide');
				var idss = ids.split(',');
				$.each(idss,function(k,id){
					$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
						var $tRow = $('tr#' + id);
						if($tRow.length > 0){
							oTable.row($tRow).remove();
							if(data){
								oTable.row.add($(data));
							}
						}
						oTable.draw();
					});
				});
			},true);

			submitForm2('#csvForm',function(element,ids){
				$('#modalSmall').modal('hide');
				
			},true);

			submitForm2('#inventoryForm',function(element,id){
				$('#modalLoad').modal('hide');
				$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
					var $tRow = $('tr#' + id);
					if($tRow.length > 0){
						oTable.row($tRow).remove();
						oTable.row.add($(data));
					}
					oTable.draw();
				});
			});

		};

		var editItemActions = function(){

			/*$('[data-toggle="tab"]').on('shown.bs.tab', function (e) {
			  $('.matchCols').matchHeight();
			}).on('click',function(){
				$(this).tab('show');
				if($(this).closest('li').hasClass('active')){
					$(this).tab('hide');
				}else{
					$(this).tab('show');
				}
			});*/

			masksCurrency($('.maskInteger'),thousandSeparator,'no');
			masksCurrency($('.maskFloat'),thousandSeparator,'yes');
			masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
			masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

			ncmHelpers.onClickWrap('a.tabs',function(e,tis){
				var tab 		= tis.closest('li');
				var target 		= tis.attr('href');
				var allBodies 	= $('.tab-pane'); 
				var allTabs 	= tis.closest('.nav-tabs').find('li'); 
				
				allTabs.removeClass('active');
				allBodies.removeClass('active').hide();

				masksCurrency($('.maskInteger'),thousandSeparator,'no');
				masksCurrency($('.maskFloat'),thousandSeparator,'yes');
				masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

				tab.addClass('active');
				$(target).addClass('active').show();
				$('.matchCols').matchHeight();

				ncmUI.setDarkMode.autoSelected();
			});


			$('#insertItemName').off('keyup').on('keyup',function(e){
				var name 			= $(this).val();
				var firstLetter 	= name.charAt(0);
				var secondLetter 	= name.charAt(1);
				var construct 		= '<span class="text-u-c">' + firstLetter + '</span>' + secondLetter;
				$('.itemName').html(name);
				$('#imgThumbLetters').html(construct);
			});

			ncmHelpers.onClickWrap('.comissionTypeBtn',function(event,tis){
				var symbol = tis.data('symbol');
				$('.comissionTypeBtn').removeClass('active');
				tis.addClass('active');
				$('.comissionType').val(symbol);
				$('#comissionType b').text(symbol);

				if(symbol == '%'){
					$('#itemComission').removeClass('maskCurrency').addClass('maskInteger');
				}else{
					$('#itemComission').removeClass('maskInteger').addClass('maskCurrency');
				}

				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				masksCurrency($('.maskInteger'),thousandSeparator,'no');
			});

			ncmHelpers.onClickWrap('.priceTypeBtn',function(event,tis){
				var symbol = tis.data('symbol');
				$('.priceTypeBtn').removeClass('active');
				tis.addClass('active');
				$('.priceType').val(symbol);
				$('#priceType b').text(symbol);

				if(symbol == '%'){
					$('#itemPricePercent').removeClass('disabled').attr('disabled',false).focus();
				}else{
					$('#itemPricePercent').addClass('disabled').attr('disabled','disabled').val(0);
				}

				masksCurrency($('.maskPercentInt'),thousandSeparator,'no');
			});

			ncmHelpers.onClickWrap('#btnAddStock',function(event,tis){
				$('.addRemoveStockBlocks').addClass('hidden');
				$('#addStock').removeClass('hidden');		
			});

			ncmHelpers.onClickWrap('#btnRemoveStock',function(event,tis){
				$('.addRemoveStockBlocks').addClass('hidden');
				$('#removeStock').removeClass('hidden');	
			});

			ncmHelpers.onClickWrap('#btnAddStockSubmit',function(event,tis){
				var count = $('#addStockCount').val();
				var price = $('#addCogsCount').val();
				var url   = tis.attr('href');
				url 	  = url + '&count=' + count + '&price=' + price;

				$.get(url,function(result){
					if(result == 'true'){
						$('.addRemoveStockBlocks').addClass('hidden');
						$('#successStock').removeClass('hidden');
						setTimeout(function(){
							$('#successStock').addClass('hidden');
							$('#editItem').submit();
						}, 2000);
					}
				});
			});

			ncmHelpers.onClickWrap('#btnRemoveStockSubmit',function(event,tis){
				var count = $('#removeStockCount').val();
				var url   = tis.attr('href');
				url 	  = url + '&count=' + count;

				$.get(url,function(result){
					if(result == 'true'){
						$('.addRemoveStockBlocks').addClass('hidden');
						$('#successStock').removeClass('hidden');
						setTimeout(function(){
							$('#successStock').addClass('hidden');
							$('#editItem').submit();
						}, 2000);
					}
				});
			});

			ncmHelpers.onClickWrap('.cancelItemView',function(event,tis){
				$('.modal').modal('hide');
			});

			switchit(false,true);

			ncmHelpers.onClickWrap('.toggleInventory',function(event,tis){
				var classis = tis.data('inv');
				$(classis).toggleClass('hidden');
			});
			
			ncmHelpers.onClickWrap('#comboType',function(){
				spinner('body', 'show');
				$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
			});

			ncmHelpers.onClickWrap('.maskCurrency',function(event,tis){
				tis.select();
			});

			ncmHelpers.onClickWrap('#productionBtn,#productionOrderBtn',function(event,tis){

				var units 		= $('#productionUnits').val();
				var itemName 	= tis.data('name');
				var outletName 	= tis.data('outletname');
				var id 			= tis.data('id');
				var max 		= tis.data('max');
				var isOrder 	= tis.data('order');
				var cogs 		= countPricesFromCompound();
				var expiration 	= $('#productionExpirationDate').val();

				if(units < 1 || isNaN(units)){
					ncmDialogs.alert('Indique la cantidad que desea producir');
					return false;
				}else if(units>max){
					ncmDialogs.alert('Puede producir ' + max + ' unidades como máximo');
					return false;
				}else{
					var alrt = 'Se producirán ' + units + ' ' + itemName + ' en la sucursal ' + outletName;
					if(isOrder){alrt = '¿Desea ordenar ' + units + ' ' + itemName + ' en la sucursal ' + outletName + '?';}
					confirmation(alrt, function (e) {
						if (e) {
							spinner('body', 'show');
							$.get(baseUrl + '?action=produce&i=' + id + '&c=' + units + '&cogs=' + cogs + '&ex=' + expiration + '&ord=' + isOrder,function(result){
								if(result == 'limit'){
									ncmDialogs.alert('Error: El producto puede tener un máximo de 30 compuestos');
								}else if(result == 'noinventory'){
									ncmDialogs.alert('Error: No hay suficientes compuestos para producir ' + units + ' unidades');
								}else if(result == 'true'){
									ncmDialogs.alert(units + ' ' + itemName + ' producidos exitosamente');
								}else if(result == 'nooutlet'){
									ncmDialogs.alert('Debe seleccionar una sucursal donde se realizará la producción');
								}else if(result.length > 255){
									$(result).print();
									console.log(result);
								}else{
									ncmDialogs.alert(result);
								}
								spinner('body', 'hide');
							});
						}
					});
				}
			});

			function countPricesFromCompound(){
				var cogs 		= '';
				$('select.compoundSelect').each(function(){
					cogs += $(this).data('price');
				});
				return cogs;
			}

			select2Simple($(".search,.searchSimple"));
			$('.matchCols').matchHeight();
		};


		//IMAGE UPLOAD DESKTOP AND MOBILE
		

		/*switchit(function(tis,active){
			var itemId = tis.attr('data-itemId');
			if(itemId){
				if(active){
					$.get('?action=createinventory&i='+itemId+'&s=1',function(data){
						if(data == 'true'){
							$('.inventoryBtn').show();
						}
					});
				}else{
					$.get('?action=createinventory&i='+itemId+'&s=0',function(data){
						if(data == 'true'){
							$('.inventoryBtn').hide();
						}
					});
				}
			}
		});*/


		//Filter rows
		ncmHelpers.onClickWrap('#filterRows',function(event,tis){
			var type = tis.data('type');

			if(type == 'filter'){
				tis.attr('data-type','reset');
				var filt = tis.attr('data-filter');

				$('.tableContainer tbody tr').hide();

				$('*[data-to-filter="'+filt+'"]').show();

				
				tis.text('Ver todos');

			   /* $.fn.dataTable.ext.search.push(
			      function(settings, data, dataIndex) {
			          return $(table.row(dataIndex).node()).attr('data-to-filter') == filt;
			        }
			    );*/
			    //table.draw();
			    
			}else{
				tis.attr('data-type','filter');
				$('.tableContainer tbody tr').show();
				tis.text('Ver disponíbles en esta sucursal');
				
				//$.fn.dataTable.ext.search.pop();
			}
		});

		masksCurrency($('.maskPercent'),thousandSeparator,'yes',false,'3');
		masksCurrency($('.maskPercentInt'),thousandSeparator,'no');
		masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

		//$('.maskNum').mask('T000.000.000.000.000,00', { reverse: true, 'translation':{ T: { pattern: /[-]/, optional: true } } });

		ncmHelpers.onClickWrap('#checkAll',function(event,tis){
		    if(tis.hasClass('selected')){
		    	$('.table tbody .check, .table tbody tr').removeClass('selected');
		    }else{
		    	$('.table tbody .check, .table tbody tr').addClass('selected');
		    }

		});

		ncmHelpers.onClickWrap('.inventoryBtn',function(event,tis){
			$('#modalLarge').modal('hide');

			var url		 	= tis.attr('href');
			loadForm(url,'#modalLoad .modal-content',function(){
				$('#modalLoad').modal('show');
			});
			
			$('#modalLoad').one('hidden.bs.modal',function(){
				$('#modalLarge').modal('show');
			});
		});

		ncmHelpers.onClickWrap('#bulkUpload',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		});

		ncmHelpers.onClickWrap('.singleBarcode',function(event,tis){
			var id = tis.data('id');
			prompter("Ingrese la cantidad de códigos a imprimir", function(cant) {
				if (cant) {
					window.open('/barcode?ids='+id+'-'+cant);
				}
			});
		});

		

		$('#modalLarge, #modalSmall').off('shown.bs.modal show.bs.modal hidden.bs.modal shown.bs.tab ncmModalUpdate').on('shown.bs.modal show.bs.modal shown.bs.tab ncmModalUpdate',function(){

			ncmUI.setDarkMode.autoSelected();

			var rawHash 	= window.location.hash.substring(1);
			var jHash 		= rawHash.split('&').reduce(function (result, item) {
			    var parts 	= item.split('=');
			    result[parts[0]] = parts[1];
			    return result;
			}, {});

			if(jHash['i']){
				var opts = {
							  "listEl" : '#ncmDBItemFilesTab',
							  "token"  : ncmDBActive,
							  'folder' : '/item/' + jHash['i']
							};

				if(ncmDBActive){
					ncmDropbox(opts);
				}
			}

			$('#comboSelector').off('change').on('change',function(){
				spinner('body', 'show');
				$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
			});

			$('#productionType').off('change').on('change',function(){
				spinner('body', 'show');
				$('#editItem').submit();
			});

			ncmHelpers.onClickWrap('.print',function(event,tis){
				var id = tis.data('type');
				$(id).print();
			});

			ncmHelpers.onClickWrap('a.itemImageBtn',function(event,tis){
				$('#itemImageInput').trigger('click');
			});

			$(document).off('change','#itemImageInput').on('change','#itemImageInput',function(e){
				var file 			= e.target.files[0];//this.files[0];
				var reader 			= new FileReader();
			    var name 			= file.name;
			    var size 			= file.size;
			    var type 			= file.type;
			    var width 			= file.width;
			    var height 			= file.height;
			    var go 				= false;
			    var $this 			= $(this);

			    if(size > 900000 || !type || (type != 'image/jpeg' && type != 'image/png' && type != 'image/gif')){
			    	alert('La imagen debe ser JPG, PNG o GIF y debe de pesar menos de 900KB');
			    	return false;
			    }else{
					$('.itemImgFlag').val(1);
					reader.onloadend 	= function () {
						$('.itemImageBtn img.itemImg').attr('src',reader.result);
					};

					reader.onerror = function () {
						alert('No se pudo leer la imagen');
					}

					if (file) {
						reader.readAsDataURL(file);
					} else {
						alert('No se pudo seleccionar la imagen');
					}
				}
			});

			ncmHelpers.onClickWrap('#deleteImgBtn',function(event,tis){
				var id = tis.data('id');
				$.get('upload.php?action=delete&id=' + companyId + '_' + id,function(res){
					$('.itemImg').attr('src','images/transparent.png');
					$('.item-overlay').addClass('bg-light dk active').removeClass('opacity');
					$('#itemImgFlag').val('false');
				});
			});

			var $sSimpleEl = $('.search,.searchSimple');
			select2Simple($sSimpleEl,$('#modalLarge'));
			select2Ajax({
	          element : '.searchAjax',
	          url     : baseUrl + '?action=searchItemInputJson',
	          type    : 'item',
	          onLoad  : function(el,container){},
	          onChange  : function($el,data){}
	        });

			$('[data-toggle="tooltip"]').tooltip();
			$("[data-toggle=popover]").popover().on('show.bs.popover', function(){
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
			});
			masksCurrency($('.maskInteger'),thousandSeparator,'no');
			masksCurrency($('.maskFloat'),thousandSeparator,'yes');
			masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
			masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

			masksCurrency($('.maskPercent'),thousandSeparator,'yes',false,'3');	

			addRemoveTextBox('#addCompound','#rmCompound','#compoundHolder',(window.compBoxesList) ? window.compBoxesList : '',function(){
				masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
				masksCurrency($('.maskFloat'),thousandSeparator,'yes');
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

				select2Simple($(".search,.searchSimple"),$('#modalLarge'));
				select2Ajax({
		          element :'.searchAjax',
		          url     : baseUrl + '?action=searchItemInputJson',
		          type    :'item',
		          onLoad  : function(el,container){
		          },
		          onChange  : function($el,data){
			 		var uom 		= data;
					var $uomPlace 	= $el.closest('div.TextBoxDiv').children('div.col-sm-2').find('span.badge');
					if($uomPlace.length > 0 && validity(uom.uom)){
						$uomPlace.text(uom.uom);
					}
		          }
		        });
		        $('.matchCols').matchHeight();
			});

			ncmHelpers.onClickWrap('a.setCurrenciesBtn',function(event,tis){
				var id = tis.data('id');
		      	ncmHelpers.setCurrency(baseUrl,id);
		    });

			var BHID 		= '#dateHoursTab';
			var currBH 		=  $.trim($('#dateHoursTab .businessHoursConfig').text());
			if(currBH){
				currBH = JSON.parse(currBH);
		        var bHours = $('#dateHoursTab div.businessHours').businessHours({
		            checkedColorClass   : 'bg-info lt',
		            uncheckedColorClass : 'bg-danger lt',
		            operationTime       : currBH,
		            weekdays            : ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
		            postInit            :function(){
		                
		            },
		            dayTmpl              :'<div class="dayContainer col-md-3 col-xs-4 m-b wrapper-sm" style="min-height:134px;">' +
		                                  ' <div class="weekday font-bold text-u-c"></div>' +
		                                  ' <div data-original-title="" class="colorBox m-b-xs r-3x pointer"><input type="checkbox" class="invisible operationState"></div>' +
		                                  ' <div class="operationDayTimeContainer">' +
		                                  '   <div class="operationTime input-group m-b-xs">' + 
		                                  
		                                  '     <input type="time" name="startTime" class="mini-time form-control operationTimeFrom">' +
		                                  '   </div>' +
		                                  '   <div class="operationTime input-group">' +
		                                  
		                                  '     <input type="time" name="endTime" class="mini-time form-control operationTimeTill">' +
		                                  '   </div>' +
		                                  ' </div>' + 
		                                  '</div>'
		        });

		        
		        ncmHelpers.onClickWrap('#dateHoursTab div.businessHours .colorBox',function(event,tis){
		        	$('#dateHoursTab input.businessHours').val( JSON.stringify(bHours.serialize()) );
		        });

		        $('#dateHoursTab input.operationTimeFrom, #dateHoursTab input.operationTimeTill').off('change').on('change',function(){
		        	$('#dateHoursTab input.businessHours').val( JSON.stringify(bHours.serialize()) );
		        });
		        
		    }

		}).on('hidden.bs.modal',function(){
			if(window.location.hash.indexOf("#" + window.baseUrlH) > -1){
				helpers.loadPageLoad = false;
		        window.location.hash = window.baseUrlH;
		        setTimeout(function(){
		        	helpers.loadPageLoad = true;
		        },100);
		    }
		}).on('show.bs.modal',function(){

		});

		$('#tipo').off('focus change').on('focus',function() {
		    prev_val = $(this).val();
		}).on('change',function() {
		    $(this).blur(); // Firefox fix as suggested by AgDude
		    var optionSelected = $("option:selected", this);
	    	var valueSelected = this.value;
	    	var itemId = $(this).data('itemid');

		    if(valueSelected == 1 || valueSelected == 2){
	    		$('.inventoryTools').removeClass('hidden');
	    	}else{
	    		if(prev_val == 1 || prev_val == 2){
				    var success = confirm('Se eliminará todo el inventario de este artículo. ¿Desea continuar?');
				    if(success){
				        $('.inventoryTools').addClass('hidden');
				        //aqui llamo a un script para eliminar el inventario
				        $.get(baseUrl + '?action=clearSingleInventory&id='+itemId);
				    }else{
				        $(this).val(prev_val);
				        return false; 
				    }
				}
			}
		});
	 	
	});
