//function to format bites bit.ly/19yoIPO
function bytesToSize(bytes) {
   var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
   if (bytes == 0) return '0 Bytes';
   var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
   return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
}

var deleteImage = function(id,callback){
	$.get('upload.php?action=delete&id='+companyId+'_'+id,function(res){
		callback && callback();
	});
};

function getAllSelectedValues(table,type){
	var ids = [];
	console.log('counting rows');
	table.rows('.selected').iterator( 'row', function ( context, index ) {
		var $el 	= $(this.row(index).node());
	    var id 		= $el.data('id');
	    var count 	= 0;
	    
	    if(type){
	    	count 	= $('#inventoryCountRow'+id).text();
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

$(document).ready(function(){

	window.baseUrl = window.baseUrl ? window.baseUrl : '/items';

	FastClick.attach(document.body);

	var url     = baseUrl + "?action=showTable" + iftn(archived,'');
    var rawUrl  = url;

	$.get(url,function(result){

		var tiposList = [
						{type : 'products',name : 'Productos', search : ' .producto'},
						{type : 'service',name : 'Servicios', search : '.servicio'},
						{type : 'combo',name : 'Combos', search : '.combo'},
						{type : 'production',name : 'Producción', search : '.producci'},
						{type : 'compounds',name : 'Compuestos', search : '.activo'},
						{type : 'groups',name : 'Grupos', search : '.grupo'},
						{type : 'giftcards',name : 'Gift Cards', search : '.gift card'},
						{type : 'discounts',name : 'Descuentos', search : '.descuento'}
					];

		var tiposDrop 	= 	'<span class="btn-group"><a href="#" class="btn btn-default dropdown-toggle" data-toggle="dropdown" id="typeActivator">Tipos <span class="caret"></span></a>' +
							'	<ul class="dropdown-menu animated fadeIn" id="typeActivatorMenu">' +
							'		<li> <span class="arrow top"></span> </li>';
									$.each(tiposList,function(i,val){
										tiposDrop += '<li><a href="#" data-type="'+val.type+'" data-name="' + val.search + '" data-index="' + i + '" class="typeActivator typeActivatorBtn' + i + ' text-default">' +
													'<i class="material-icons m-r-xs text-white">check</i>' + val.name + 
												'</a></li>';
									});
		tiposDrop 		+=	'</ul></span>';

        var options = {
                "container"   : ".tableContainer",
                "url"         : url,
                "rawUrl"      : rawUrl,
                "iniData"     : result.table,
                "table"       : ".table",
                "sort"        : 3,
                "footerSumCol" 	: [14,15,16,17],
                "currency"    : currency,
                "decimal"     : decimal,
                "thousand"    : thousandSeparator,
                "offset"      : offset,
                "limit"       : limit,
                "nolimit" 	  : true,
                "ncmTools"    : {
                          left  	: tiposDrop + '<a href="#" class="btn btn-default exportTable" data-table="tableItems" data-name="Inventario">Exportar Listado</a>' + result.categoriesSelect,
                          right   	: '<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o SKU" id="itemSearch" data-url="' + rawUrl + '&qry=">'
                          				
                          },
	            "colsFilter"  : {
									name 		: 'items4',
									menu 		:  [
													{"index":0,"name":'',"visible":true},
													{"index":1,"name":'Imagen',"visible":false},
													{"index":2,"name":'Artículo',"visible":true},
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
													{"index":17,"name":'Stock',"visible":false},
													{"index":18,"name":'Online',"visible":false}
													]
								}
        };

        manageTableLoad(options,function(oTable){
          loadTheTable(options,oTable);
        });
    });

	adm();

	var loadTheTable = function(tableOps,oTable){
		window.oTable 	= oTable;
		window.tableOps = tableOps;
		$('[data-toggle="tooltip"]').tooltip();

		$("table td.lazy").lazy();
		$('#bodyContent').scroll(function(){
			$("table td.lazy").lazy();
		});

		onClickWrap('.typeActivator',function(event,tis){
			var $tis 	= tis;
			var type 	= $tis.data('type');
			var find 	= '';
			var index 	= $tis.data('index');
			var name 	= $tis.data('name');
			var colIdx 	= 1;

			$.fn.dataTable.ext.search.pop();

			if($tis.hasClass('active')) {
				//inhabilito
				$('.typeActivatorBtn' + index + ' i').removeClass('text-info').addClass('text-white');
				$tis.removeClass('active');
			} else {
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
				)
			}
			oTable.draw();
		},false,true);

		submitForm2('#addItem,#editItem,#insertItem',function(element,id){	
			loadForm(baseUrl + '?action=editform&id=' + id,'#modalItem .modal-content',function(){
			});

			$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
				var $tRow = $('.editting');
				if($tRow.length > 0){
					oTable.row($tRow).remove();
					if(data){
						oTable.row.add($(data));
					}
				}
				oTable.draw();
			});
		},true);

		onClickWrap('.createItemBtn',function(event,tis){
			var extraUrl = '';
			if(tis.hasClass('discount')){
				extraUrl = '&discount=true';
			}else if(tis.hasClass('combo')){
				extraUrl = '&combo=true';
			}else if(tis.hasClass('giftcard')){
				extraUrl = '&giftcard=true';
			}

			var narrow 	 = tis.hasClass('modal-narrow');

			$.get(baseUrl + '?action=insertBtn'+extraUrl,function(response){
				response = response.split('|');
				if(response[0] == 'true'){

					id = response[2];
					loadForm('?action=editform&id=' + id,'#modalItem .modal-content',function(){
						if(narrow){
							$('#modalItem .modal-dialog').removeClass('modal-lg');
						}else{
							$('#modalItem .modal-dialog').addClass('modal-lg');
						}

						$('#modalItem').modal('show').one('shown.bs.modal',function(){
							$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
								var $tr = $(data);
								$('tr').removeClass('editting');
								$tr.addClass('editting');
								oTable.row.add($tr).draw();
								
							});
						});
					});
					
				}else if(response[0] == 'false'){
					message('Error al intentar procesar su petición','danger');
				}else if(response[0] == 'max'){
					$('#maxReached').modal('show');
				}else{
					alert(response[0]);
					return false;
				}
			});
		},false,true);

		onClickWrap('.ungroup',function(event,tis){
			confirmation('Realmente desea remover del grupo?', function (e) {
				if (e === true) {
					var id 	= tis.data('id');
					$.get(baseUrl + '?action=ungroup&id=' + id, function(response) {
						if(response == 'true'){
							message('Acción realizada exitosamente','success');

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
		},false,true);

		submitForm2('#editItemBulk',function(element,ids){
			$('#modalItem').modal('hide');
			var idss = ids.split(',');
			$.each(idss,function(k,id){
				$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
					var $tRow = $('tr[data-id="' + id + '"]');
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

		submitForm2('#inventoryForm',function(element,id){
			$('#modalLoad').modal('hide');
			$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
				var $tRow = $('tr[data-id="' + id + '"]');
				if($tRow.length > 0){
					oTable.row($tRow).remove();
					oTable.row.add($(data));
				}
				oTable.draw();
			});
		});

		onClickWrap('.multi',function(event,tis){
			var type 		= tis.attr('data-type');
			var selected 	= getAllSelectedValues(oTable);

			spinner('body', 'show');

			console.log(selected.length);
			
			if (selected.length < 1){
				alert('No ha seleccionado ningún artículo');
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
					alert('Debe seleccionar mas de un artículo de la lista');
					spinner('body', 'hide');
				}else if(type == 'archive'){
					var url = baseUrl + '?multi=true&action=archive&id=' + selected.join('|');
					
					$.get(url, function(response) {
						//console.log(response);
						if(validity(response)){
							message('Acción realizada exitosamente','success');
							$.each(selected,function(k,id){
								var $tRow = $('tr[data-id="' + id + '"]');
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
							message('Acción realizada exitosamente','success');
							$.each(selected,function(k,id){
								var $tRow = $('tr[data-id="' + id + '"]');
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
					confirmation('Se perderán todos los datos, inventario y reportes relacionados a estos artículos', function (e) {
						if (e) {
							var url = baseUrl + '?multi=true&action=delete&id='+selected.join('|');

							$.each(selected,function(k,id){
								var $tRow = $('tr[data-id="' + id + '"]');
								if($tRow.length > 0){
									oTable.row($tRow).remove();
								}
							});

							oTable.draw();
							
							$.get(url, function(response) {
								if(response == 'true'){
									message('Acción realizada exitosamente','success');
								}else{
									message('Error al intentar procesar su petición','danger');
								}

								spinner('body', 'hide');
							});
						}
					});
				}else if(type == 'group'){
					var $cbx 		= $('.table tr .selected').find('input:hidden');
					var editGroup 	= false;
					var allGroups 	= true;
					$cbx.each(function(i){
						if($(this).hasClass('group')){
							editGroup = $(this).val();
						}else{
							allGroups = false;
						}
					});

					if(editGroup && !allGroups){
						var url = baseUrl + '?multi=true&group='+editGroup+'&action=groupEdit&id='+selected.join('|');
						$.get(url, function(response) {
							if(response == 'true'){
								message('Acción realizada exitosamente','success');
								$.each(selected,function(k,id){
									if(id != editGroup){//elimino todos menos el grupo
										var $tRow = $('tr[data-id="' + id + '"]');
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
						prompter("Nombre del Grupo", function(name) {
							if (name) {
								var url = baseUrl + '?multi=true&name='+name+'&action=group&id='+selected.join('|');
								
								$.get(url, function(response) {//respuesta será ID del grupo creado
									if(response){
										$.get(tableOps.rawUrl + '&part=1&singleRow=' + response,function(data){
											oTable.row.add($(data)).draw();
										});

										$.each(selected,function(k,id){
											var $tRow = $('tr[data-id="' + id + '"]');
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
							message('Acción realizada exitosamente','success');
							$.each(selected,function(k,id){
								var $tRow = $('tr[data-id="' + id + '"]');
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
							message('Acción realizada exitosamente','success');
							$.each(selected,function(k,id){
								$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
									var $tRow = $('tr[data-id="' + id + '"]');
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

					var load = '?action=bulkEditForm';
					loadForm(load,'#modalItem .modal-content',function(){
						$('#modalItem').modal('show');
						$('[data-toggle="tooltip"]').tooltip();
						masksCurrency($('.maskInteger'),thousandSeparator,'no');
						masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
						$('input#bulkUpdateIds').val(selected.join('|'));
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
		},false,true);

		onClickWrap('.itemsAction',function(event,tis){
			var type 		= tis.data('type'); //obtengo el tipo de accion
			var index 		= parseInt(tis.data('position'));
			var id 			= tis.data('id');
			var load 		= tis.data('load');
			var element		= tis.data('element');
			var narrow 		= tis.hasClass('modal-narrow');
			
			if(tis.hasClass('disabled')){return false;}
				
			if(type == 'loadItem'){
				$('tr').removeClass('editting');
				tis.addClass('editting');

				if(narrow){
					$('#modalItem .modal-dialog').removeClass('modal-lg');
					var placeHolder = '<img src="/images/itemPlaceholderNarrow.png"/>';
				}else{
					$('#modalItem .modal-dialog').addClass('modal-lg');
					var placeHolder = '<img src="/images/itemPlaceholder.png"/>';
				}

				$('#modalItem .modal-content').html('<div class="col-xs-12 no-padder r-3x clear">' + placeHolder + '</div>',function(){
					$('#modalItem').modal('show');
					loadForm(load,'#modalItem .modal-content',function(){						
					});
				});
				
			}else if(type == 'deleteItem' || type == 'archiveItem'){
				var warn = (type == 'archiveItem') ? 'Seguro que desea Archivar?' : 'Seguro que desea eliminar?';
				var done = (type == 'archiveItem') ? 'archivado' : 'eliminado';
				confirmation(warn, function (e) {
					if (e) {
						$.get(load, function(response) {
							if(response == 'false'){
								message('Error al eliminar','danger');
								return;
							}

							oTable.row($('tr[data-id="' + id + '"]')).remove().draw();
							$('#modalItem').modal('hide');
							message('Artículo ' + done,'success');
						});
					}
				});
			}else if(type == 'empty'){

			}

		},false,true); //clickeable end/	

		var srcValCache = '';
	    $('#itemSearch').on('keyup',function(e){
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
	    			manageTableLoad(tableOps,function(oTable){
						loadTheTable(tableOps,oTable);
					});
		    	}else{
		    		message('Añada por lo menos 3 caracteres','warning');
		    	}
		    }
	    });

	    select2Simple($(".search,.searchSimple"));

	    onClickWrap('.filterByCategory',function(event,tis){
			var id = tis.data('id');
			spinner(tableOps.container, 'show');
			$('.filterByCategory').addClass('text-default');
			tis.removeClass('text-default');

			if(id == 'all'){
    			manageTableLoad(tableOps,function(oTable){
					loadTheTable(tableOps,oTable);
				});
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
		},false,true);
	};

	switchit();

	onClickWrap('.toggleInventory',function(event,tis){
		var classis = tis.data('inv');
		$(classis).toggleClass('hidden');
	});
	
	onClickWrap('#comboType',function(){
		spinner('body', 'show');
		$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
	});

	onClickWrap('.comissionTypeBtn',function(event,tis){
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

	onClickWrap('.priceTypeBtn',function(event,tis){
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

	$(document).on('change','#comboSelector',function(){
		spinner('body', 'show');
		$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
	});

	onClickWrap('#btnAddStock',function(event,tis){
		$('.addRemoveStockBlocks').addClass('hidden');
		$('#addStock').removeClass('hidden');		
	});

	onClickWrap('#btnRemoveStock',function(event,tis){
		$('.addRemoveStockBlocks').addClass('hidden');
		$('#removeStock').removeClass('hidden');	
	});

	onClickWrap('#btnAddStockSubmit',function(event,tis){
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

	onClickWrap('#btnRemoveStockSubmit',function(event,tis){
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

	function countPricesFromCompound(){
		var cogs 		= '';
		$('select.compoundSelect').each(function(){
			cogs += $(this).data('price');
		});
		return cogs;
	}

	onClickWrap('#productionBtn,#productionOrderBtn',function(event,tis){

		var units 		= $('#productionUnits').val();
		var itemName 	= tis.data('name');
		var outletName 	= tis.data('outletname');
		var id 			= tis.data('id');
		var max 		= tis.data('max');
		var isOrder 	= tis.data('order');
		var cogs 		= countPricesFromCompound();
		var expiration 	= $('#productionExpirationDate').val();
		

		console.log(isOrder);

		if(units < 1 || isNaN(units)){
			alert('Indique la cantidad que desea producir');
			return false;
		}else if(units>max){
			alert('Puede producir '+max+' unidades como máximo');
			return false;
		}else{
			var alrt = 'Se producirán '+units+' '+itemName+' en la sucursal '+outletName;
			if(isOrder){alrt = 'Desea ordenar '+units+' '+itemName+' en la sucursal '+outletName+'?';}
			confirmation(alrt, function (e) {
				if (e) {
					spinner('body', 'show');
					$.get('?action=produce&i='+id+'&c='+units+'&cogs='+cogs+'&ex='+expiration+'&ord='+isOrder,function(result){
						if(result == 'limit'){
							alert('Error: El producto puede tener un máximo de 30 compuestos');
						}else if(result == 'noinventory'){
							alert('Error: No hay suficientes compuestos para producir '+units+' unidades');
						}else if(result == 'true'){
							alert(units+' '+itemName+' producidos exitosamente');
						}else if(result == 'nooutlet'){
							alert('Debe seleccionar una sucursal donde se realizará la producción');
						}else if(result.length > 255){
							$(result).print();
							console.log(result);
						}else{
							alert(result);
						}
						spinner('body', 'hide');
					});
				}
			});
		}
	});

	//Filter rows
	onClickWrap('#filterRows',function(event,tis){
		var type = tis.attr('data-type');

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

	onClickWrap('.maskCurrency',function(event,tis){
		tis.select();
	});

	//$('.maskNum').mask('T000.000.000.000.000,00', { reverse: true, 'translation':{ T: { pattern: /[-]/, optional: true } } });

	onClickWrap('.table span.check',function(event,tis){
		thalog('click checkbox');
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

	onClickWrap('#checkAll',function(event,tis){
	    var $this 	= tis;
	    if(!$this.hasClass('selected')){
	    	$('.table tbody .check, .table tbody tr').removeClass('selected');
	    }else{
	    	$('.table tbody .check, .table tbody tr').addClass('selected');
	    }
	});	

	onClickWrap('.cancelItemView',function(event,tis){
		$('#modalItem').modal('hide');
	});

	onClickWrap('.inventoryBtn',function(event,tis){
		$('#modalItem').modal('hide');

		var url		 	= tis.attr('href');
		loadForm(url,'#modalLoad .modal-content',function(){
			$('#modalLoad').modal('show');
		});
		
		$('#modalLoad').one('hidden.bs.modal',function(){
			$('#modalItem').modal('show');
		});
	});

	onClickWrap('#bulkUpload',function(event,tis){
		var url		 	= tis.attr('href');
		loadForm(url,'#modalSmall .modal-content',function(){
			$('#modalSmall').modal('show');
		});
	});

	onClickWrap('.singleBarcode',function(event,tis){
		var id = tis.data('id');
		prompter("Ingrese la cantidad de códigos a imprimir", function(cant) {
			if (cant) {
				window.open('/barcode?ids='+id+'-'+cant);
			}
		});
	});

	$('#modalItem').on('shown.bs.modal',function(){
		select2Simple($(".search,.searchSimple"));
	}).on('hidden.bs.modal',function(){
	}).on('show.bs.modal',function(){
		if(jQuery().matchHeight){
			$('.matchCols').matchHeight();
		}
	});

	$(document).on('keyup', '#insertItemName',function(e){
		var name = $(this).val();
		var firstLetter = name.charAt(0);
		var secondLetter = name.charAt(1);
		var construct = '<span class="text-u-c">'+firstLetter+'</span>'+secondLetter;
		$('.itemName').html(name);
		$('#imgThumbLetters').html(construct);
	});

	$(document).on('focus','#tipo',function() {
	    prev_val = $(this).val();
	}).on('change','#tipo',function() {
	    $(this).blur(); // Firefox fix as suggested by AgDude
	    var optionSelected = $("option:selected", this);
    	var valueSelected = this.value;
    	var itemId = $(this).data('itemid');

	    if(valueSelected == 1 || valueSelected == 2){
    		$('.inventoryTools').removeClass('hidden');
    	}else{
    		if(prev_val == 1 || prev_val == 2){
			    var success = confirm('Se eliminará todo el inventario de este artículo. Desea continuar?');
			    if(success){
			        $('.inventoryTools').addClass('hidden');
			        //aqui llamo a un script para eliminar el inventario
			        $.get('?action=clearSingleInventory&id='+itemId);
			    }else{
			        $(this).val(prev_val);
			        return false; 
			    }
			}
		}
	});
 	
});