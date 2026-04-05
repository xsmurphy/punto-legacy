	$(document).ready(function(){
		FastClick.attach(document.body);
		
		var checkIfAdmin = function(){
	      var state = $('.role').val();
	      if(state == 1 || state == 2){
	        $(".pass").prop('disabled', false).val('');
	      }else{
	        $(".pass").prop('disabled', true).val('');
	      }
	    };

		$.get(loadUrl,function(result){

			if(_rol == 'user'){
				var sortBy = 3;
			
				var tableColumns = [
														{"index":0,"name":"ID","visible":false},
														{"index":1,"name":"Nombre y Apellido","visible":true},
														{"index":2,"name":'Doc. de Identidad',"visible":false},
														{"index":3,"name":"Creado","visible":true},
														{"index":4,"name":'Teléfono',"visible":true},
														{"index":5,"name":'Email',"visible":false},
														{"index":6,"name":'Dirección',"visible":false},
														{"index":7,"name":'Rol',"visible":true},
														{"index":8,"name":'Estado',"visible":false},
														{"index":9,"name":'Sucursal',"visible":true}
													];

			}

			if(_rol == 'customer'){
				var sortBy = 6;
				var tableColumns = [
										{"index":0,"name":"ID","visible":false},
										{"index":1,"name":"Nombre/Razón Social","visible":true},
										{"index":2,"name":tin_name,"visible":false},
										{"index":3,"name":"Nombre y Apellido","visible":false},
										{"index":4,"name":'Doc. de Identidad',"visible":false},
										{"index":5,"name":"Fecha de Nacimiento","visible":true},
										{"index":6,"name":"Creado","visible":false},
										{"index":7,"name":"Actualizado","visible":false},
										{"index":8,"name":"Última transacción","visible":true},
										{"index":9,"name":'Teléfono',"visible":true},
										{"index":10,"name":'Teléfono 2',"visible":false},
										{"index":11,"name":'Email',"visible":true},
										{"index":12,"name":'Dirección',"visible":false},
										{"index":13,"name":'Localidad',"visible":false},
										{"index":14,"name":'Ciudad',"visible":false},
										{"index":15,"name":'Nota',"visible":false},
										{"index":16,"name":'Score',"visible":false},
										{"index":17,"name":'Loyalty',"visible":false},
										{"index":18,"name":'Distancia',"visible":false}
									];
			}
			if(_rol == 'supplier'){
				var sortBy = 4;
				var tableColumns = [
										{"index":0,"name":"ID","visible":false},
										{"index":1,"name":"Nombre/Razón Social","visible":true},
										{"index":2,"name":tin_name,"visible":false},
										{"index":3,"name":"Encargado/a","visible":true},
										{"index":4,"name":"Creado","visible":true},
										{"index":5,"name":'Teléfono',"visible":false},
										{"index":6,"name":'Email',"visible":false},
										{"index":7,"name":'Dirección',"visible":false},
										{"index":8,"name":'Categoría',"visible":true}
									];
			}

			window.tableOps = {
		            "container"   	: ".tableContainer",
		            "url"       		: loadUrl,
		            "rawUrl" 				: loadUrl,
		            "iniData" 			: result.table,
		            "table"     		: "#tableContacts",
		            "sort"      		: sortBy,
								"search" 				: 'detailTableSearch',
								"offset" 				: parseInt(offset),
								"limit" 				: parseInt(limit),
								"nolimit" 			: true,
								"tableName" 		: 'tableContacts',
								"fileTitle" 		: 'Contactos',
								"ncmTools"			: {
																		left 		: 	'',
																		right 	: 	'<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o ' + tin_name + '" id="detailTableSearch" data-url="' + loadUrl + '&qry=">'
																  },
								"colsFilter"		: {
																		name 	: _rol + 'Listing',
																		menu 	: tableColumns
													  			},
							  "clickCB" 			: function(event,tis){
													  				checkIfAdmin();
																		var id = tis.data('id');
																		helpers.loadPageLoad = false;
																		window.location.hash = 'contacts&i=' + id;
																		$(window).trigger('hashvarchange');
							  									}
						};

			ncmDataTables(tableOps,function(oTable,_scope){
				loadTheTable(tableOps,oTable,_scope);
			});

		});

		var loadTheTable = function(tableOps,oTable,_scope){
			
			$('[data-toggle="tooltip"]').tooltip();

			onClickWrap('#alphabet span',function(event,tis){
				$('#alphabet').find('.active').removeClass('active');
				
				if(tis.hasClass('null')){
					_alphabetSearch = false;
				}else{
					_alphabetSearch = tis.text();
					tis.addClass('active');
				}
				otable.draw();
			},false,true);

			onClickWrap('.deleteItem',function(event,tis){
				var $tr 		= $('.editting');

				confirmation('Realmente desea eliminar?',function(conf){
					if(conf){
						var load = tis.data('load'); 
						
						oTable.row($tr).remove().draw();
						$('#modalLarge').modal('hide');

						$.get(load, function(response) {
							if(response == 'true'){
								message('Contacto eliminado','success');
							}else{
								message('Error al eliminar','danger');
							}
						});
					}
				});
			},false,true);

			onClickWrap('.viewAccount',function(event,tis){
				var $panel = $('#customerInfoPanel');
				var id = tis.data('id');
				spinner('#customerInfoPanel', 'show');
				$.get(baseUrl + "?action=getCustomerAccount&id="+id,function(data){
					$panel.html(data);
					spinner('#customerInfoPanel', 'hide');
				});
			},false,true);

			onClickWrap('.cancelItemView',function(event,tis){
				$('#modalLarge').modal('hide');
			},false,true);				

		    var timout 		= false;
		    var srcValCache = '';
		    $('#detailTableSearch').on('keyup',function(e){
		    	var $tis 	= $(this);
		    	var value 	= $tis.val();
		    	var tmout 	= 800;
		    	var code 	= e.keyCode || e.which;

				if(code == 13) { //Enter keycode
					//Do something
			    	if(value.length > 3){
			    		value = $.trim(value);
			    		if(!value || srcValCache == value){
			    			return false;
			    		}

		    			spinner(tableOps.container, 'show');
		    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
		    				oTable.rows().remove().draw();
		    				if(result){
		    					var line 	= explodes('[@]',result);
		    					$.each(line,function(i,data){
		    						if(data){
	                        			oTable.row.add($(data)).draw();
	                        		}
		    					});
		    				}

		    				_scope.events();

		    				$('.lodMoreBtnHolder').addClass('hidden');
		    				spinner(tableOps.container, 'hide');
			    		});
			    		
			    		srcValCache = value;

			    	}else if(value.length < 1 || !value){
			    		srcValCache = '';
		    			ncmDataTablesReset(oTable,tableOps);
			    	}
			    }
		    });

		    		};

		switchit(function(tis,isActive){
			if(tis.hasClass('cRFieldProgress')){
				var tid 		= tis.attr('id');
				var id 			= tid.split('_')[1];
				var $checkbox 	= tis.find('input');
				var isChecked 	= $checkbox.attr('checked') ? true : false;
				var str 		= 0;
				if(isChecked){
					str = 1;
				}

				var typeVal 	= $('#type' + id).val();
				if(typeVal == 2 && isChecked){
					$('#numericReportsSettingsBtn' + id).removeClass('hidden');
				}else{
					$('#numericReportsSettingsBtn' + id).addClass('hidden');
				}

				
				spinner('body', 'show');
				$.get(baseUrl + '?action=editRFieldProgress&val=' + str + '&id=' + id,function(result){
					spinner('body', 'hide');
				});
			}
		},true);

		var opts = {
		  	readAsDefault: 'ArrayBuffer',
				dragClass : 'dker',
				on: {
					beforestart: function(){
						spinner('body', 'show');
					},
			    load: function(e, file) {
			    	var result 		= new Uint8Array(e.target.result);
			      var xlsread 	= XLSX.read(result, {type: 'array'});
						var xlsjson 	= XLSX.utils.sheet_to_json(xlsread.Sheets.Sheet1);
			    	//console.log(xlsjson);

			    	$.ajax({
						url 			: '/a_contacts?action=file&debug=1',
						type 			: "POST",
						data 			: {"data":JSON.stringify(xlsjson)},
						success 		: function(result){
							if(result.success){
								message('Archivo subido, ' + result.inserted + ' creados y ' + result.updated + ' actualizados','success');

								setTimeout(function(){
									location.reload();
									spinner('body', 'hide');
								},2000);
							}
						}
					});
			  }
			}
		};

		$(".table-responsive").fileReaderJS(opts);

		onClickWrap('#createRecord',function(event,tis){
			$('#modalRecords').modal('show');
		},false,true);

		onClickWrap('#createProgress',function(event,tis){
			$('#modalLarge').modal('show');
		},false,true);

		$('#modalRecords').off('shown.bs.modal').on('shown.bs.modal', function () {
			spinner('body', 'show');
			$.get(baseUrl + '?action=recordList',function(result){
				//actualizo tabla de records
				if(result != 'false'){
					$('#recordsList').html(result);
				}else{
					$('#recordsList').html($('#noRecordsFoundMsg').html());
				}

				spinner('body', 'hide');

				$('.fichatable tbody').sortable({
					stop: function( event, ui ) {
						var $list = $(this).closest('tbody').find('tr');
            $list.each(function(i,val){
                var id = $(this).attr('id').replace('rField','');
                $.get(baseUrl + '?action=editRFieldSort&val=' + i + '&id=' + id);
            });
					}
				});

				$( "#recordsList" ).collapse().sortable({
			  	connectWith 	: "#dropBag",
			  	handle 				: ".panel-heading",
					stop 					: function( event, ui ) {
                        $('#recordsList .panel-heading').each(function(i,val){
                            var id = $(this).data('id');
                            $.get(baseUrl + '?action=editRecordSort&name=' + i + '&id=' + id);
                        });
          }
			  }); 

			  $( "#dropBag" ).sortable({
			    connectWith: "#recordsList"
			  }); 

			  /*$('#recordsList .panel-default').sortable({
					handle: ".panel-heading",
					stop: function( event, ui ) {
                        $('#recordsList .panel-heading').each(function(i,val){
                            var id = $(this).attr('id');
                            $.get(baseUrl + '?action=editRecordSort&name=' + i + '&id=' + id);
                        });
					}
				});*/

				$('select.typeRecordField').off('change').on('change',function(){
					var tis 	= $(this);
					var id 		= tis.data('id');
					var value 	= tis.val();

					var isChecked = $('#switch_' + id).find('input').val();
					if(value == 2 && isChecked){
						$('#numericReportsSettingsBtn' + id).removeClass('hidden');
					}else{
						$('#numericReportsSettingsBtn' + id).addClass('hidden');
					}
					
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRFieldType&val=' + value + '&id=' + id,function(result){
						if(result != 'false'){
							$('#type' + id).val(value);
							message('Guardado','success');
						}
						spinner('body', 'hide');
					});
				});
				
			});

		});
		
		onClickWrap('#addRecord',function(e,tis){
			prompter('Nombre de la Ficha',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=createRecord&name=' + str,function(result){
						if(result != 'false'){
							$(result).prependTo('#recordsList');
							$('.noDataMessage').remove();
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.editRecord',function(e,tis){
			var id 		= tis.data('id');
			var cname 	= $('#name' + id).text();
			prompter('Nuevo nombre de la Ficha',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRecord&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$('#name' + id).text(str);
						}
						spinner('body', 'hide');
					});
				}
			},cname);
		},false,true);

		onClickWrap('.deleteRecord',function(e,tis){
			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRecord&id=' + id,function(result){
						if(result == 'true'){
							$('#' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.addRecordField',function(e,tis){
			var id 			= tis.data('id');
			prompter('Nombre del Campo ej. (Peso)',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=createRField&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$(result).prependTo('#options' + id);
							$('.noDataMessage').remove();
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.editRecordField',function(e,tis){
			var id 		= tis.data('id');
			var cname 	= $('#name' + id).text();
			prompter('Nuevo nombre del Campo',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRField&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$('#name' + id).text(str);
						}
						spinner('body', 'hide');
					});
				}
			},cname);
		},false,true);

		onClickWrap('.deleteRecordField',function(e,tis){
			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRField&id=' + id,function(result){
						if(result == 'true'){
							$('#rField' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		});

		onClickWrap('.numericReportsSettings',function(e,tis){
			
			var content = ncmHelpers.mustacheIt($('#numberGraphConfig'),{},false,true);

			ncmDialogs.alert(content,false,'Configuración de reporte',function(){
				
			});

			return false;

			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRField&id=' + id,function(result){
						if(result == 'true'){
							$('#rField' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		});

		$(window).off('hashvarchange').on('hashvarchange', function() {

			var rawHash 	= window.location.hash.substring(1);
			var jHash 		= rawHash.split('&').reduce(function (result, item) {
			    var parts 	= item.split('=');
			    result[parts[0]] = parts[1];
			    return result;
			}, {});

			//helpers.loadPageLoad = true;

			if(jHash['i']){
				var tis 	= $('.' + jHash['i']);
				if(!tis.length){
					return false;
				}

				var load 	= baseUrl + '?action=form&id=' + jHash['i'];

				loadForm(load,'#modalLarge .modal-content',function(){
					$('#modalLarge').modal('show');
					$('.lockpass').mask('0000');
					masksCurrency($('.maskInteger'),thousandSeparator,'no');
					masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				});
			}
		});



		$('#modalLarge').off("hidden.bs.modal").on("hidden.bs.modal", function () {
			if(baseUrl == '/a_contacts'){
				helpers.loadPageLoad = false;
		        window.location.hash = 'contacts';
		        setTimeout(function(){
		        	helpers.loadPageLoad = true;
		        },100);
		    }else{
		    	$('#modalLarge').off("hidden.bs.modal");
		    }
	    });

		onClickWrap('.create',function(event,tis){
			var type = tis.data('type');
	        loadForm(baseUrl + '?action=form&type=' + type,'#modalLarge .modal-content',function(){
              $('#modalLarge').modal('show');
              $('.lockpass').mask('0000');
              masksCurrency($('.maskInteger'),thousandSeparator,'no');
              masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
            });
	    },false,true);

		onClickWrap('#reportDownloadGeneral',function(event,tis){
			var url = baseUrl + '?action=generalTable&download-report=true';
			window.open(url);
		},false,true);

		onClickWrap('#bulkUpload',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#mandatory',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#rolesSettings',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		},false,true);


		onClickWrap('#createRecordBulk',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#downloadContacts',function(event,tis){
			window.open(baseUrl + '?action=download');
		},false,true);

		$('#modalLarge').off('shown.bs.modal').on('shown.bs.modal', function() {
			onClickWrap('.editContact',function(event,tis){
				var type 	= tis.data('type');
				if(type == 'toggle'){
					var notsearch = $('.contactSearch').data('not');
					select2Ajax({element:'.contactSearch',url:baseUrl + '?action=searchCustomerInputJson&not=' + notsearch,type:'contact'});
				}
			},false,true);

			submitForm('#contactForm',function(element,id){
				$('#modalLarge').modal('hide');
				var $tr 		= $('.editting');
				$.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
					oTable.row($tr).remove();
					oTable.row.add($(data)).draw();
				});
			});
		});

		$('#modalSmall').off('shown.bs.modal').on('shown.bs.modal', function() {
			submitForm('#mandatoryForm',function(element,id){
				$('#modalSmall').modal('hide');

				if(id){
					message('Modificado','success');
				}else{
					message('No se pudo modificar','success');
				}
			});	
		});

		adm();		

		$(window).trigger('hashvarchange');	

	});
