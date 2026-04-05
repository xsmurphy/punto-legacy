
  var ncmCDS = {

    init    : () => {
      moment.locale('es');

      var h = $(window).height();
      $('.fullHeight').css({'height':h + 'px'});

      ncmCDS.pusher = new Pusher('24c4d438c59b81f27107', {
        cluster: 'sa1'
      });

      var channel = ncmCDS.pusher.subscribe(outletID + '-CDS');
      channel.unbind('order').bind('order', (result) => {
          ncmCDS.load();
      });

      ncmCDS.load();
    },

    process : (orders) => {

      var inProcess = [];
      var finished  = [];
      var obj       = {};

      if(orders){

        $.each(orders, (i, order) => {

          obj = {no : order.invoice_id, name : order.customer_name};

          if(order.order_status == 1){//si esta en proceso (verificar numero)
            inProcess.push(obj);
          }else if(order.order_status == 2){ //finalizado
            finished.push(obj);
          }

        });

        ncmCDS.render($('#listTpl'), {data : inProcess}, $('#process'));
        ncmCDS.render($('#listTpl'), {data : finished}, $('#finished'));

      }

    },

    load    : (callback) => {
      $.get('/cds.php?s=' + window.ese + '&action=list', (result) => {
        ncmCDS.process(result);
      });
    },

    render  : ($template, data, $wrap, returns) => {
      var template    = $template.html();
      var mustached   = Mustache.render(template, data);

      if(returns){
        return mustached;
      }else{
        $wrap.html(mustached); 
      }

    }

  };

  var ncmKDS = {
    cachedResult    : false,
    oldCachedResult : false,
    timeagoInterval : null,
    dataLoadInterval: null,
    lastChecked     : false,
    loading         : false,
    isUserActive    : true,
    xhr             : false,
    canLoad         : true,
    sliding         : false,
    slide           : 0,
    getOrdersIntval : 60000,
    updateUIIntval  : 30000,
    userIddleTime   : 20 * 60000,
    waitingOrders   : 0,
    scrollPos       : 0,
    cardsPerScreen  : iftn(simpleStorage.get('cardsPerScreen'),4),
    orders          : [],
    computerHour    : moment().format('YYYY-MM-DD HH'),
    activeOrders    : [],
    init            : () => {
      moment.locale('es');

      var h = $(window).height();
      $('.fullHeight').css({'height':h + 'px'});

      $('#kdsNamePlc').text(simpleStorage.get('kdsName'));

      ncmKDS.pusher = new Pusher('24c4d438c59b81f27107', {
        cluster: 'sa1'
      });

      var channel = ncmKDS.pusher.subscribe(outletID + '-KDS');
      channel.bind('order', (result) => {
          ncmKDS.dataLoaders();
      });

      ncmKDS.startDataLoad();
      ncmKDS.listeners();
    },
    listeners       : ()              => {

      ncmHelpers.onClickWrap('#settingsBtn',function(event,tis){
        ncmKDS.render($('#settingsTpl'),{},$('#modalSmall .modal-content'));
        $('#modalSmall').modal('show');
      });

      ncmHelpers.onClickWrap('#fullScreenBtn',function(event,tis){
        $(document).toggleFullScreen();
      });

      ncmHelpers.onClickWrap('#backToFirst',function(event,tis){
        ncmKDS.resetScreenPos();
      });

      ncmHelpers.onClickWrap('#resetConfig',function(event,tis){
        simpleStorage.flush();
        location.reload(true);
      });

      ncmHelpers.onClickWrap('.processOrderBtn',function(event,tis){
        var canPrint = simpleStorage.get('print');
        var id    = tis.data('id');
        var type  = tis.data('type');
        var oNo   = tis.data('order');
        var index = $('#' + id).data('index');
        index     = !index ? 0 : index;
        
        if(type == 'end'){

          ncmDialogs.confirm('¿Finalizar orden #' + oNo + '?','','question',function(res){
            if(res){
              delete ncmKDS.activeOrders[oNo];
              delete ncmKDS.cachedResult.orders[index];

              var $tisCard  = $('#' + id);
              var $tisItm   = $tisCard.parent('.item');
              var remainingCards = $tisItm.find('.card').length;
              
              $tisCard.addClass('fadeOutUp');

              setTimeout(function(){
                if(remainingCards < 2){
                  ncmKDS.resetScreenPos();
                }
                
                ncmKDS.setUIX(ncmKDS.cachedResult);
              },400);

              $.get('/kds.php?s=' + window.ese + '&action=update&i=' + id + '&t=' + type + '&d=' + currDate,success);
            }
          });
          
        }else{
          if(canPrint){
            $('#' + id).print();   
          }

          tis.data('type','end');
          tis.text('Finalizar');
          tis.removeClass('btn-info');
          tis.addClass('btn-success');

          ncmKDS.cachedResult.orders[index].order_status    = 3;

          tis.prop('disabled', true);
          tis.addClass('disabled');

          setTimeout(function(){
            ncmKDS.setUIX(ncmKDS.cachedResult);
          },3000);

          $.get('/kds.php?s=' + window.ese + '&action=update&i=' + id + '&t=' + type + '&d=' + currDate,success);

        }

        var success   = (data) => {};

        var currDate = moment().format('YYYY-MM-DD HH:mm:ss');
        
      });

      $(window).on('scroll',function() {
        ncmKDS.scrollPos = $(this).scrollTop();
      });

      $('#modalSmall').off('hidden.bs.modal,show.bs.modal,shown.bs.modal').on('show.bs.modal', function() {
        $('#modalSmall input#kdsName').val(simpleStorage.get('kdsName'));

        $('#modalSmall input#kdsName').off('keyup').on('keyup',function(){
          var name    = $(this).val();
          simpleStorage.set('kdsName',name);
          $('#kdsNamePlc').text(name);
        });

        var canPrint = simpleStorage.get('print');

        if(canPrint){
          $('#print').addClass('selected');
        }

        var playSound = simpleStorage.get('sound');
        if(playSound){
          $('#soundOn').addClass('selected');
        }

        switchit(function(tis,active){
          simpleStorage.set('print',active);
        },true,'#print');

        switchit(function(tis,active){
          simpleStorage.set('sound',active);

          if(active){
            ncmHelpers.playSound('newOrder');
            ncmDialogs.push('Notificaciones Activadas', 'Aquí recibirá las notificaciones de cada pedido',4000);
          }
        },true,'#soundOn');
             
        var orderOrder = simpleStorage.get('orderOrder');
        if(orderOrder){
          $('#orderOrder').addClass('selected');
        }

        switchit(function(tis,active){

          simpleStorage.set('orderOrder',active);

          ncmKDS.lastChecked = false;
          ncmKDS.xhr.abort();

          location.reload();

        },true,'#orderOrder');

        $('#modalSmall select#cardsPerScreen').val(ncmKDS.cardsPerScreen);

        $('#modalSmall select#cardsPerScreen').off('change').on('change',function(){
          var no    = $(this).val();
          simpleStorage.set('cardsPerScreen',no);
          ncmKDS.cardsPerScreen = no;
          ncmKDS.setUIX(ncmKDS.cachedResult);
        });

        var $catsEl = $('#modalSmall select#allowedCategories');

        $catsEl.select2({
          placeholder   : "Seleccione",
          theme         : "bootstrap",
          language      : 'es'
        }).off('select2:select select2:unselect').on('select2:select select2:unselect', function (e) {

          console.log('allowedCats',$(this).val());

          simpleStorage.set('allowedCategories',$(this).val());

          ncmKDS.resetScreenPos();
          setTimeout(function(){
            var copyOld         = ncmKDS.duplicateJson(ncmKDS.oldCachedResult);
            ncmKDS.cachedResult = copyOld;
            ncmKDS.setUIX(ncmKDS.cachedResult);  
          },700);

          
        });

        ncmKDS.buildCatsList();
      }).on('hidden.bs.modal',function(){
        ncmKDS.setUIX(ncmKDS.cachedResult); 
        setTimeout(function(){
          ncmKDS.resetScreenPos();
        },100);
      });

      $('#ordersList .carousel').on('slide.bs.carousel', function (e) {  
        var slideFrom   = $(this).find('.active').index();
        var slideTo     = $(e.relatedTarget).index();
        ncmKDS.slide    = slideTo;
        ncmKDS.sliding  = true;
      }).on('slid', function (e) {
        ncmKDS.sliding  = false;
      });

      $('#ordersList .carousel').off('touchstart').on('touchstart', function(event){
          const xClick = event.originalEvent.touches[0].pageX;
          $(this).one('touchmove', function(event){
              const xMove           = event.originalEvent.touches[0].pageX;
              const sensitivityInPx = 5;

              if( Math.floor(xClick - xMove) > sensitivityInPx ){
                  $(this).carousel('next');
              }
              else if( Math.floor(xClick - xMove) < -sensitivityInPx ){
                  $(this).carousel('prev');
              }
          });
          $(this).off('touchend').on('touchend', function(){
              $(this).off('touchmove');
          });
      });

    },
    buildList       : (data, options) => {

      var out           = [];
      var cols          = 0;
      var block         = '';
      var page          = 0;
      var pages         = '';
      var cnt           = 0;
      var allTags       = simpleStorage.get('tags');
      var allCats       = simpleStorage.get('allowedCategories');
      var avgOrderTime  = 30;
      var times         = [];
      var playingSound  = false;
      var date          = '';
      var duration      = '';
      ncmKDS.orders     = data.orders;

      $.each(ncmKDS.orders,function(k,o){

        //if(!ncmHelpers.validInObj(o,'order_details')){    
          ncmKDS.orders[k].index = k;
          $.each(o.order_details,function(key,value){
            date          = o.date;
            //voy sumando la duración de cada producto y meto en un array con date key, asi uso como tiempo limite de cada orden.
            duration      = (value.duration) ? parseInt( value.duration ) : 0;
            times[date]   = ((times[date]) ? times[date] : 0) + duration;

            if(ncmHelpers.validate(allCats)){
              if($.inArray(value.category_id,allCats) < 0){
                ncmKDS.orders[k].order_details[key] = false;
              }
            }
          });
        //}     

      });

      $.each(ncmKDS.orders,function(key,order){
        var tr          = '';
        var skipLine    = true;
        var date        = order.date;

        //cards per screen
        if(ncmKDS.cardsPerScreen == 4){
          order.cols_combo = 'col-lg-3 col-md-3';
        }else if(ncmKDS.cardsPerScreen == 6){
          order.cols_combo = 'col-lg-2 col-md-2';
        }else if(ncmKDS.cardsPerScreen == 12){
          order.cols_combo = 'col-lg-1 col-md-1';
        }

        //if(!ncmHelpers.validInObj(order,'order_details')){
        
        $.each(order.order_details,function(k,value){
          
          if(!value || !value.name){
            return;
          }else{
            skipLine = false;
          }

          value.fnote = ncmHelpers.markupt2HTML({
                                text  : value.note,
                                type  : 'MtH'
                              });//ncmHelpers.isBase64(value.note);

          var status    = (value.hasOwnProperty("status")) ? value.status : 2;
          var canceled  = '';
          
          if(status == 0){
            canceled = 'text-l-t text-muted';
          }
        });

        //}

        if(skipLine){
          return;
        }

        cols++;

        var orderDuration  = (times[date] > 0) ? times[date] : avgOrderTime;

        var dateX         = moment(date).utc().format("X");

        var tiempo        = explodes(' ',date,1);
        var hora          = explodes(':',tiempo,0);
        var min           = explodes(':',tiempo,1);
        order.time_at     = moment(date).format('HH:mm');
        var nextBtn       = 'Iniciar';
        var nextBtnType   = 'start';
        var nextBtnColor  = 'btn-info';
        var animation     = '';

        if(order.order_status == 3){
          nextBtn         = 'Finalizar';
          nextBtnType     = 'end';
          nextBtnColor    = 'btn-success';
        }

        //duration
        var now  = moment();//now
        var then = moment(date);
        var diff = moment.duration( now.diff(then) );

        var elapsed         = Math.round(diff.asMinutes());
        order.elapsedMins   = diff.minutes();
        order.elapsedHours  = diff.hours();

        if($.inArray(parseInt(order.number_id), ncmKDS.activeOrders) < 0){
          
          if(order.elapsedMins < 2){
            animation     = 'fadeInUp';

            ncmDialogs.push('KDS', 'Nueva orden # ' + order.number_id);

            if(simpleStorage.get('sound')){
              ncmHelpers.playSound('newOrder'); 
            }
          }

          ncmKDS.activeOrders.push( parseInt(order.number_id) );
        }

        //bar
        var halfMax       = orderDuration / 2;
        order.background  = 'bg text-white';
        order.barColor    = 'bg-warning';

        if(elapsed > orderDuration){
          order.background  = 'bg-danger lt text-white';
          order.barColor    = 'bg-danger';
        }else if(elapsed > halfMax){
          order.background  = 'bg-warning text-dark';
          order.barColor    = 'bg-danger';
        }

        if(elapsed < halfMax){
          order.barWidth = ncmKDS.getPercent(elapsed,halfMax);
        }else{
          order.barWidth = ncmKDS.getPercent(elapsed - halfMax,orderDuration);
        }       

        if(cols == 1){
          var active = '';
          if(cnt == 0){
            active = 'active';
          }
          block += '<div class="item ' + active + ' speed-4x" style="height: 95vh;">';
          pages += '<li data-target="#ordersList" data-slide-to="' + page + '" class="' + active + '"></li>';
        }

        var orderSource = order.order_name;
        var orderName   = 'Orden';

        if(orderSource == 'ecom'){
          orderName = 'Online';
        }else if($.isNumeric(orderSource)){
          orderName = 'Mesa ' + orderSource;
        }

        order.actionBtn     = nextBtn;
        order.actionBtnType = nextBtnType;
        order.actionBtnColor= nextBtnColor;
        order.source        = orderName;
        order.animation     = animation;

        order.order_fnote    = ncmHelpers.markupt2HTML({
                                text  : order.order_note,
                                type  : 'MtH'
                              });

        

        //if(!skipLine){
          block +=  ncmKDS.render($('#blockTpl'),order,false,true);
        //}

        if(cols == ncmKDS.cardsPerScreen){
          block += '</div>';
          cols = 0;
        } 

        cnt++;
      });

      return [block,pages];
    },
    duplicateJson   : (value)         => {
      return JSON.parse( JSON.stringify( value ) );
    },
    buildCatsList   : ()              => {
      var $catsEl = $('#modalSmall select#allowedCategories');
      var cats    = simpleStorage.get('categories');
      var allowed = simpleStorage.get('allowedCategories');

      $catsEl.html('');

      if(cats){

        $.each(cats,function(key,value){

          var selected = false;

          if($.inArray(value.ID,allowed) > -1){
            selected = 'selected';
          }

          $catsEl.append($('<option>', {
              value     : value.ID,
              text      : value.name,
              selected  : selected
          }));

        });
      }
    },
    setUIX          : (data)          => {
      //rebuild data

      if(!$.isEmptyObject(data.orders)){

        var newData = {orders : []};
        $.each(data.orders,function(i,val){

          if(ncmHelpers.validInObj(val,'UID')){
            newData.orders.push(val);
          }

        });

        data                        = newData;
        ncmKDS.cachedResult.orders  = newData.orders;
        
        var content = ncmKDS.buildList(data);

        if(ncmHelpers.validate(content[0])){
          $('.carousel-inner').html(content[0]);
          $('.carousel-indicators').html(content[1]);
        }else{
          $('.carousel-control, .carousel-indicators').addClass('hidden');
        }
        
        $(window).scrollTop(ncmKDS.scrollPos);

        if(ncmKDS.slide > 0){
          $('.carousel .item').eq(0).removeClass('active');
          $('.carousel .item').eq(ncmKDS.slide).addClass('active');
        }

        ncmKDS.countOrders();
        ncmKDS.listeners();
      }
    },
    dataLoaders     : ()              => {

      var success     = function(data){
        ncmKDS.lastChecked          = moment().format('YYYY-MM-DD HH:mm:ss');

        if(data.error == 'time'){
          //ncmKDS.canLoad = false;
          //ncmKDS.xhr.abort();
          //alert('Verifique la fecha y hora de su equipo.');
        }else if(data.error == 'timeout'){
          //solo en longpolling
        }else if(data.orders){
          ncmKDS.cachedResult     = data;
          ncmKDS.oldCachedResult  = ncmKDS.duplicateJson(data);
        }

        ncmKDS.setUIX(ncmKDS.cachedResult);
        ncmKDS.loading = false;
      };

      var orderOrder  = simpleStorage.get('orderOrder') ? 1 : 0;

      var loaderXhr   =  function(){
        if(ncmKDS.isUserActive && !ncmKDS.loading && ncmKDS.canLoad){
          ncmKDS.loading = true;
          ncmKDS.computerHour = moment().format('YYYY-MM-DD HH');

          ncmKDS.xhr = $.get('/kds.php?s=' + window.ese + '&action=lists&time=' + ncmKDS.lastChecked + '&compTime=' + ncmKDS.computerHour + '&reverse=' + orderOrder,success).fail(function (jqXHR) {
            ncmKDS.loading = false;
          });
        }else{
          //ncmKDS.loading = false;
        }
      };

      loaderXhr();

      ncmKDS.dataLoadInterval = setInterval(function(){
        loaderXhr();
      },ncmKDS.getOrdersIntval);
      
    },
    render          : ($template,data,$wrap,returns) => {
      var template    = $template.html();
      var mustached   = Mustache.render(template, data);
      if(returns){
        return mustached;
      }else{
        $wrap.html(mustached); 
      }
    },
    countOrders     : () => {
      ncmKDS.waitingOrders    = $('.card').length;

      $('#waitingOrders').text('x' + ncmKDS.waitingOrders);
    },
    getPercent      : (oldNumber, newNumber) => {
        return (oldNumber * 100) / newNumber;
    }
  };

  $(document).ready(function(){
    ncmCDS.init();
  });

  