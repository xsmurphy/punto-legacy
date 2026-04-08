    var ncmPurchase = {
      inxs            : 0,
      expenseMode     : false,
      noPurchaseMode  : false,
      noOrderMode     : false,
      noTransferMode  : false,
      currentMode     : 0,
      tabIndex        : 0,
      checkVisibles   : function(value){
        if(value == 0){//compra
          $('.mainActionBtn').attr('id','totalPurchase');
          $('.visiblePurchase').show();
          ncmPurchase.noPurchaseMode = false;
        }else if(value == 1){//orden de compra
          $('.mainActionBtn').attr('id','totalOrder');
          $('.visibleOrder').show();
          ncmPurchase.noOrderMode     = true;
        }else if(value == 2){//pedido de reposición
          $('.mainActionBtn').attr('id','totalReposition');
          ncmPurchase.noTransferMode  = false;
        }else if(value == 3){//Nota de Crédito
          $('.mainActionBtn').attr('id','totalPurchase');
          $('.visiblePurchase').show();
          ncmPurchase.noPurchaseMode = false;
        }
      },
      listeners       : function(){

        $(document).on('focus', '.select2-selection.select2-selection--single', function (e) {
          $(this).closest(".select2-container").siblings('select:enabled').select2('open');
        });

        // steal focus during close - only capture once and stop propogation
        $('select.searchAjax, select.searchAjaxItem, select.search, select.searchSimple').on('select2:closing', function (e) {
          $(e.target).data("select2").$selection.one('focus focusin', function (e) {
            e.stopPropagation();
          });
        });

        select2Simple('#typeOfOrder','body',function(tis){
          var value = tis.val();
          ncmPurchase.currentMode = value;

          $('.visiblePurchase').hide();
          $('.visibleOrder').hide();
          ncmPurchase.noPurchaseMode  = true;
          ncmPurchase.noOrderMode     = true;
          ncmPurchase.noTransferMode  = true;

          ncmPurchase.checkVisibles(value);
          $('.matchCols').matchHeight();
        });

        select2Simple('.search');

        $(document).on('keyup change','.price, .units, .pack, .tax, #discount',function(e){
          var code    = e.keyCode || e.which;
          var tis     = $(this);
          var prevVal = tis.val();
          var index   = tis.data('id');
          ncmPurchase.calculatePurchase();
        }).on('keydown','.price',function(e){
          var code    = e.keyCode || e.which;
          var tis     = $(this);
          var index   = tis.data('id');
          if(code === 9 && tis.hasClass('price') && $('#line' + index).hasClass('isLast')) { //Enter keycode
            e.preventDefault(); 
            var prevVal = tis.val();
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs);
             tis.val(prevVal);

             ncmPurchase.checkVisibles(ncmPurchase.currentMode);
             
            //ncmPurchase.calculatePurchase();  
            return false;
          }
        });
      },
      load : function(){

        $('.datepicker').datetimepicker({
          format            : 'YYYY-MM-DD',
          showClear         : true,
          ignoreReadonly    : true
        });

        var options = {
          placeholder       : "Seleccione...",
          allowClear        : true
        };

        masksCurrency($('.units'),thousandSeparator,'no');
        masksCurrency($('.maskFloat'),thousandSeparator,'yes');
        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
        //masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
        ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
        masksCurrency($('.maskInteger'),thousandSeparator,'no');

        $('.tabindex').each(function(){
          var tis = $(this);
          tis.attr('tabindex',ncmPurchase.tabIndex);
          ncmPurchase.tabIndex++;
        });

        ncmPurchase.events();
        ncmPurchase.itemSelect2();
        ncmPurchase.calculatePurchase();

        //ncmPurchase.addNewLine(0);
        ncmHelpers.mustacheIt($('#noDataTpl'),[],$('#itemsList'));

        $('.matchCols').matchHeight();
        setTimeout(function(){
          select2Simple('.search');
          ncmPurchase.itemSelect2('0');
        },180);

        ncmPurchase.listeners();
        ncmPurchase.unOrder();
      },
      events : function(){
        onClickWrap('#totalOrder',function(){

          confirmation('¿Desea generar la orden?', function (e) {
            if (e) {
              $('.btn-status').attr('disabled',true);

              setTimeout(function(){
                $('.btn-status').attr('disabled',false);
              },6000);

              $('#addPurchase').attr('action',baseUrl + '?action=insert&typestate=order').submit();
              window.onbeforeunload = null;
            }
          });
          
        });

        onClickWrap('#totalPurchase',function(){

          confirmation('¿Desea generar la compra?', function (e) {
            if (e) {
              spinner('body', 'show');

              $('.btn-status').attr('disabled',true);

              setTimeout(function(){
                $('.btn-status').attr('disabled',false);
              },6000);

              $('#addPurchase').attr('action', baseUrl + '?action=insert&typestate=purchase').submit();
              window.onbeforeunload = null;
            }
          });

        });

        onClickWrap('.openRow',function(event,tis){
          var id = tis.data('id');
          $('.secondRow' + id + ', .rowIcon' + id).toggleClass('hidden');
        });

        onClickWrap('#moreOps',function(event,tis){
          $('#moreOpsPanel').toggleClass('hidden');
          if($('#moreOpsPanel').hasClass('hidden')){
            tis.text('+ Más opciones');
          }else{
            tis.text('- Ocultar opciones');
            $('.datepickerTime').datetimepicker({
              format            : 'YYYY-MM-DD HH:mm:ss',
              showClear         : true,
              ignoreReadonly    : true
            });
          }

          switchit(function(tis, active){
            $('#creditoText,#contadoText').removeClass('text-success');
            if(active){
              $('#creditoText').addClass('text-success');
              $('#dueDateSelect').show();
            }else{
              $('#contadoText').addClass('text-success');
              $('#dueDateSelect').hide();
            }
          },true);

          $('.matchCols').matchHeight();
        });

        submitForm('#addPurchase',function(element,id){
          $('#addPurchase')[0].reset();
          $('#itemsList').html('');
          $('#totalPurchase').val('Registrar');
          $('.btn-status').attr('disabled');
          spinner('body', 'hide');
          message('Generado','success');
          ncmPurchase.tabIndex = 0;
          ncmHelpers.loadPageRefresh(false,'purchase');
        });

        var dataSelect  = $('#productsSelect').html();
        var taxSelect   = $('#taxSelect').html();

        onClickWrap("#add",function(event,tis) {
          var max = 1;
          var i   = 0;
          while (i < max) {
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs);
            i++;
          }
          ncmPurchase.checkVisibles(ncmPurchase.currentMode);
        });

        onClickWrap(".remove",function(event,tis) {
          ncmPurchase.tabIndex = ncmPurchase.tabIndex - 7;
          
          $('#itemsList').children().last().remove();

          ncmPurchase.expenseMode = false;

          var amount = 0;
          $(".totalItem").each(function(i,v){
            amount += Number($(this).attr('data-raw'));
          });

          $("#totalOrder,#totalPurchase,#totalReposition").val('Registrar ' + formatNumber(amount,currency,decimal,thousandSeparator));
          $('.btn-status').attr('disabled',false);

          if($.trim($("#itemsList").html())==''){
            ncmHelpers.mustacheIt($('#noDataTpl'),[],$('#itemsList'));
            window.onbeforeunload = null;
          }
        });

        onClickWrap(".addExpense",function(event,tis) {
          var index = tis.data('index');
          $('.productExspenceLine' + index).html('<input class="form-control no-bg no-border b-b expense' + index + ' tabindex" value="" name="item[' + index + '][title]" placeholder="Añada una descripción del gasto">');

          ncmPurchase.expenseMode = true;

          $('.tabindex').each(function(){
            var tis = $(this);
            tis.attr('tabindex',ncmPurchase.tabIndex);
            ncmPurchase.tabIndex++;
          });

          setTimeout(function(){
            $('.expense' + index).focus();
          },100);
        });

        //CREAR PROVEEDOR
        select2Ajax({element:'.searchAjax',url:'/a_contacts?action=searchCustomerInputJson&t=2',type:'contact'});

        onClickWrap('.createSupplier',function(event,tis){
          loadForm('/a_contacts?action=form&type=zg','#modalLarge .modal-content',function(){
            $('#modalLarge').modal('show');
            $('.lockpass').mask('0000');
            masksCurrency($('.maskInteger'),thousandSeparator,'no');
            //masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
            ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
          });
        });
        ///

        //create item
        onClickWrap('.createItem',function(event,tis){
          $.get('/a_items?action=insertBtn',function(response){
            response = response.split('|');
            if(response[0] == 'true'){
              id = response[2];
              loadForm('/a_items?action=editform&outcall=true&id=' + id,'#modalLarge .modal-content',function(){
                $('#modalLarge .modal-dialog').addClass('modal-lg');
                ncmPurchase.expenseMode  = false;
                $('#modalLarge').modal('show');
                $('.matchCols').matchHeight();
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
        });

        $('#modalLarge').off('shown.bs.modal').on('shown.bs.modal',function(){
          select2Simple('.search,.searchSimple'); 
          submitForm('#contactForm,#editItem',function(element,id){
            $('#modalLarge').modal('hide');
            $('#modalLarge').modal('hide');
          });
        });
        

        //
        onClickWrap(".cancelItemView",function(event,tis) {
          $('.modal').modal('hide');
        });
      },
      itemSelect2 : function(index){
        select2Ajax({
          element :'.searchAjaxItem',
          url     :'/a_items?action=searchItemStockableInputJson',
          type    :'item',
          onLoad  : function(el,container){
            //var closetabIn = container.closest('.searchAjaxItem').attr('tabindex');
            var closetabIn = $('units' + index).attr('tabindex');
            container.find('.select2-selection').attr('tabindex',closetabIn + 1);
          },
          onChange  : function($el,data){
              var id            = data.id;
              var taxId         = data.tax;
              var cost          = data.cost;
              console.log(data)
              $('input#price' + index).val(cost);
              $('section.scrollable').removeAttr('data-select2-id');
              $('select#tax' + index + ' option').each(function(){
                var tis = $(this);
                currVal = tis.text();
                currVal = currVal.substring(0, currVal.length - 1);
                console.log(`Hola que tal esta opcion seleccione ${currVal} - ${taxId}`)
                if(currVal == taxId){
                    tis.attr("selected","selected");
                    console.log(`Hola que tal esta opcion seleccione ${currVal} - ${taxId}`)
                }
              });

              ncmPurchase.calculatePurchase();

              setTimeout(function(){
                $('input#price' + index).focus();  
              },100);
          }
        });
      },
      addNewLine : function(i,qty,itemId,itemName,title,total){
        var theTitle  = (ncmPurchase.expenseMode) ? ' ' : false;
        var itotal    = 0;
        var iunits    = 0;

        if(title){
          theTitle    = title;
        }

        if($('#itemsList #noContentMsg').length){
          $('#itemsList #noContentMsg').remove();
        }

        $('.isLast').removeClass('isLast');

        if(total){
          iunits  = unMaskCurrency(qty,thousandSeparator,'yes');
          itotal  = (total / iunits);
        }

        var data =  {
                      index     : i,
                      title     : theTitle,
                      qty       : iftn(qty,'1,000'),
                      price     : itotal,
                      itemId    : itemId,
                      itemName  : itemName,
                      noPurchase: ncmPurchase.noPurchaseMode,
                      noOrder   : ncmPurchase.noOrderMode,
                      noTransfer: ncmPurchase.noTransferMode
                    };

        var newtr = ncmHelpers.mustacheIt($('#lineTpl'),data,false,true);
      
        $('#itemsList').append(newtr);

        console.log('added',newtr);

        select2Simple('.search');
        ncmPurchase.itemSelect2(i);

        $('.datepicker').datetimepicker({
          format            : 'YYYY-MM-DD',
          showClear         : true,
          ignoreReadonly    : true
        });

        $('[data-toggle="tooltip"]').tooltip();
        $('.matchCols').matchHeight();

        setTimeout(function(){
          $('input#units' + i).focus();  
        },100);

        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
        ncmMaskInput({el:$('.maskCurrency'),thousand:thousandSeparator,decimal:decimal});
        masksCurrency($('.maskInteger'),thousandSeparator,'no');

        $('.tabindex').each(function(){
          var tis = $(this);
          tis.attr('tabindex',ncmPurchase.tabIndex);
          ncmPurchase.tabIndex++;
        });

        window.onbeforeunload = function() {
            return true;
        };
      },
      calculatePurchase : function(){
        var total = 0;
        $('#itemsList .liner').each(function(k,val){
          var id        = $(this).data('index');
          var pricey    = $('#price' + id).val();
          var unitsy    = $('#units' + id).val();
          var packsy    = $('#pack' + id).val();
          var taxy      = parseFloat($('#tax' + id + ' option:selected').text());
          
          var price     = unMaskCurrency(pricey,thousandSeparator,decimal);
          var units     = unMaskCurrency(unitsy,thousandSeparator,'yes');
          var pack      = unMaskCurrency(packsy,thousandSeparator,'no');
          var thePrice  = price * units;

          var taxval    = getTaxOfPrice(taxy,thePrice);

          if(decimal == 'no'){
            taxval = Math.round(taxval);
          }else{
            taxval = taxval.toFixed(2);
          }
          

          if(pack > 0){
            //thePrice = thePrice / pack;
            $('#packedPrice' + id).val(price / pack);
            $('#packedUnits' + id).val(pack * units);
          }
          
          var fTotal  = formatsNumber({number:thePrice,currency:currency});
          var fTotalX = 'P.U. ' + pricey;

          $("#taxvalue" + id).val(taxval);

          $("#total" + id).text(fTotal).data('raw',thePrice);
          $("#totalX" + id).text(fTotalX).data('raw',thePrice);

          total = total + thePrice;
          
        });

        var discounty = $('#discount').val();
        var discount  = unMaskCurrency(discounty,thousandSeparator,decimal);
        var total     = total - discount;

        if(total < 0.001){
          total = 0;
        }

        $("#totalPurchase").val('Registrar ' + formatsNumber({number:total,currency:currency}));
        $('.btn-status').attr('disabled',false);
      },
      unOrder : function(){

        if(ncmHelpers.validity(window.unOrderAction.extraction)){

          ncmPurchase.expenseMode = true;
          ncmPurchase.inxs++;
          ncmPurchase.addNewLine(ncmPurchase.inxs, "1,000", false, window.unOrderAction.description, window.unOrderAction.description, window.unOrderAction.amount);
          ncmPurchase.calculatePurchase();

        }

        if(ncmHelpers.validity(window.unOrderAction.unOrder)){

          $.each(window.unOrderAction.lines,(i,val) => {
            ncmPurchase.inxs++;
            ncmPurchase.addNewLine(ncmPurchase.inxs, val.qty, val.itemId, val.itemName, val.title, val.price);
            console.log(val);
          });
          ncmPurchase.calculatePurchase();

        }

      }
    };

    console.log('calling unorder');
    ncmPurchase.load();

    ncmiGuiderConfig.tourTitle  = 'guide.purchase';
    ncmiGuiderConfig.loc        = '/@#purchase';
    ncmiGuiderConfig.intro = {
                                cover:'//wordpress/wp-content/uploads/2020/07/macbook-dashboard-plant.png',
                                title:'¿Dudas de cómo usar la sección compras y gastos?',
                                content:'Hagamos una guía rápida!',
                                overlayColor:'#3b464d'
                              };

    ncmiGuiderConfig.steps = [{
                                title     :'Configuración',       
                                content   :'En esta sección podrá añadir los datos principales de su compra o gasto como el proveedor, número de documento, descuento, comentarios, etc.', 
                                target    : (isMobile.phone) ? '#contentAppear > div.col-xs-12.no-padder.m-b' : '#addPurchase > div.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn',
                                disable   : true
                              },{
                                title     : 'Proveedor',       
                                content   : 'Aquí puede crear y buscar un proveedor para añadirlo a la compra o gasto.',  
                                target    : '#addPurchase > div.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn > div > div.col-xs-12.no-padder.visiblePurchase.visibleOrder > div',
                                disable   : true
                              },{
                                title     : 'Más Opciones',       
                                content   : 'Aquí podrá mostrar/ocultar más datos para añadir a su compra como por ej. fecha de vencimiento, forma de pago, contado/crédito, etc.',  
                                target    :'#moreOps',
                                disable   : true
                              },{
                                title     :'Tipo',       
                                content   :'No solo puedes registrar una compra o gasto, también puede generar una orden de compra o un pedido de reposición de stock',
                                target    :'.col-md-3.col-sm-12.col-xs-12.matchCols.no-padder.bg-info.gradBgBlue.tutLeftColumn div > div:nth-child(7)',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Hagamos una prueba',       
                                content   : 'Presiona en <b>Agregar</b> para comenzar a cargar productos o gastos.',
                                target    : '#add span',
                                event     : 'click',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title           : 'Cantidad adquirida',       
                                content         : 'Añade la cantidad que corresponde, las unidades van a la izquierda de la coma. Presiona 0 tres veces para pasarlo a la izquierda y luego en Sig.',
                                target          : '#units1',
                                waitElementTime : 200,
                                delayBefore     : 250,
                                before          : ncmiGuiderConfig.scrollToIt
                              },{
                                title     :'Añade un Producto',
                                content   :'En este campo puedes buscar y crear un producto inventariable.',
                                target    :'.col-md-5.col-sm-5.col-xs-12.wrapper-xs.productExspenceLine1',
                                timer     : '6000',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : '¿Es un Gasto?',       
                                content   : 'Mejor registremos un gasto, presiona aquí para añadir un gasto en lugar de un producto.',
                                target    : '#line1 div:nth-child(1) > div:nth-child(3) > div > span:nth-child(4) > a > i',
                                event     : 'click',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Escribe la descripción del gasto',       
                                content   : 'Por ej. Alquiler del local',
                                target    : '#line1 > div:nth-child(1) > div.col-md-5.col-sm-5.col-xs-12.wrapper-xs.productExspenceLine1',
                                timer     : '20000',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Costo',
                                content   : 'Aquí va el costo unitario del producto o gasto',
                                target    : '#price1',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title     : 'Cálculo del Total',
                                content   : 'Aquí se calculará automáticamente el total del producto.',
                                target    : '#line1 > div:nth-child(1) > div.col-md-2.col-sm-2.col-xs-6.wrapper-xs.text-right.visiblePurchase.visibleOrder',
                                disable   : true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title:'¿Más productos o gastos?',       
                                content:'Presiona en Agregar o Eliminar para añadir o quitar líneas',
                                timer           : '8000',
                                target:'#addPurchase > div.col-md-9.col-sm-12.col-xs-12.bg-white.no-padder.m-n.table-responsive.panel.matchCols > div.col-xs-12.wrapper.text-center.hidden-print',
                                disable:true,
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              },{
                                title:'¿Todo listo?',       
                                content:'Ahora si presiona en <b>Registrar</b> para procesar y finalizar la operación, <a href="/panel-de-control/compras-y-gastos" target="_blank" class="text-white">visita el tutorial online</a> para más información.',
                                target    :'#totalPurchase',
                                delayBefore :250,
                                before    : ncmiGuiderConfig.scrollToIt
                              }];

    var guideMade = simpleStorage.get('iguide_purchase');

    if(!guideMade){
      simpleStorage.set('iguide_purchase',true);
      ncmiGuiderConfig.start();
    }

    ncmHelpers.onClickWrap('.iguiderStart',function(event,tis){
      ncmiGuiderConfig.start();
    });

    if(autoStartGuide){
      ncmiGuiderConfig.start();
    }

