/**
 * Template builder (Plantillas de Impresión) — widget jQuery del módulo Ajustes (a_settings, incr. 3d-front).
 *
 * Widget portado VERBATIM del legacy a_settings.php (objetos templateBuilder + htmlToPlain). Per §17.2
 * el diseñador (canvas drag/drop con jQuery UI draggable/resizable/selectable + preview de impresión vía
 * ducumentPrintBuilder) se mantiene jQuery-owned. SOLO se recablearon sus 3 llamadas de datos al BFF:
 *   - loadTemplatesList → GET  /bff/settings.php?view=templates   (JSON {rows:[{id,name,json}]}; el <li> se arma client-side)
 *   - saveTemplate      → POST /bff/settings.php (action=saveTemplate&data=&i=)
 *   - removeTemplate    → POST /bff/settings.php (action=removeTemplate&id=)
 * El legacy estaba ROTO en PG (loadTemplatesList con companyId=1 vs UUID; removeTemplate con LIMIT 1).
 *
 * a_settings.js llama window.templateBuilder.init() cuando se abre la tab #printTemplates (shown.bs.tab).
 * Deps que el shell debe tener: jQuery UI (1.12.1) + touch-punch, jQuery.print, ducumentPrintBuilder (dpb).
 */
(function () {

	var BFF      = '/bff/settings.php';
	// tin_name se lee LAZY en el use-site (ducumentPrintBuilder.config.TINname): window.tinName lo
	// setea hydratePalette async tras ?view=templateFields, después de que carga este IIFE.

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	var ncmMaths = {
		parseFloatSafe: function (val) { return parseFloat(val); }
	};

  window.fontFamily = 'Arial';
  window.fontSize   = '8pt';
  window.fontCase   = 'none';
  window.receipt_left_margin = '7';
  window.isTicketConfig = false;
  window.selectedBox = false;

  var templateBuilder = {
    init: function(){
      templateBuilder.loadTemplatesList();
      spinner('body', 'hide');
    },
    buildJsonResult : function(tis, toSave){
        var arr     = templateBuilder.getPosition('.box');

        var dupli   = (tis)   ? tis.data('dupli') : false;
        var name    = $('input#templateName').val();
        name        = (dupli) ? name + ' [copia]' : name;
        var saveIt  = JSON.stringify({
                                      page_size           : window.paperSize,
                                      page_size_name      : $('#pageSizeTitle').html(),
                                      page_name           : name,
                                      page_font_family    : window.fontFamily,
                                      page_font_size      : window.fontSize,
                                      receipt_left_margin : window.receipt_left_margin,
                                      page_font_case      : window.fontCase,
                                      mm                  : ducumentPrintBuilder.findMm(),
                                      data                : arr
                                    });

        if(!toSave){
          $('textarea#jsonTemplateField').val($.trim( saveIt ));
        }

        return dupli;
    },
    loadTemplatesList: function(callback){
      spinner('#loadTemplatesList', 'show');
      // BFF: JSON {rows:[{id,name,json}]} → se arma el <li> client-side (el legacy servía el HTML).
      // El diseño (json) va en .loadTemplateData (texto escapado) que lee el handler .templateSelect.
      $.get(BFF + '?view=templates', function (res) {
        var rows = (res && res.ok && res.data && res.data.rows) || [];
        var html = '<li><a href="#" class="templateSelect" data-id="new"><span class="text-success"><i class="material-icons m-r-sm">add</i> Crear una Nueva</span></a></li>';
        rows.forEach(function (r) {
          html += '<li><a href="#" class="templateSelect" data-id="' + esc(r.id) + '" data-lock="false" data-name="' + esc(r.name) + '">' +
                  '<i class="material-icons m-r-sm text-muted">mode_edit</i>' + esc(r.name) +
                  '<div class="loadTemplateData hidden">' + esc(r.json) + '</div></a></li>';
        });
        $('#loadTemplatesList').html(html);
        $('#contentIt').html('');
        $('#templateName').val('');
        $('#templateId').val('');
        $('#deleteTemplate, #duplicateTemplate').data('id','').addClass('hidden');
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.setPage();
        spinner('#loadTemplatesList', 'hide');
        callback && callback();
      }, 'json');
    },
    resetBoxOps : () => {

      $('#boxOpsWrap').addClass('hidden');

      var activeClasses   = 'boxSelected bg-dark lter text-white';
      var inActiveClasses = 'bg-light text-dark';
      window.selectedBox  = false;

      $('.boxSelected').removeClass(activeClasses).addClass(inActiveClasses);

      $('.boxOpsTop').val('');
      $('.boxOpsLeft').val('');
      $('.boxOpsWidth').val('');
      $('.boxOpsHeight').val('');

      return {activeClasses : activeClasses, inActiveClasses : inActiveClasses};

    },
    manageFields: function(classs){

      $('#contentIt').selectable({
        start : () => {
          $(classs).resizable('disable');
        },
        stop  : () => {
          $(classs).resizable('enable');
        }
      });

      $(classs).resizable({
        handles     : window.isTicketConfig?'s':'se',
        helper      : "ui-resizable-helper",
        minHeight   : window.minHeight,
        autoHide    : true,
        grid        : [(mm),(mm)],
        start       : () => {
          templateBuilder.saveAlert(true);
        },
        stop        : () => {
          templateBuilder.saveAlert(true);
        }
      }).draggable({
        containment : '#contentIt',
        grid        : [(mm),(mm)],
        scroll      : true,
        delay       : 300,
        cursorAt    : { top: 5 },
        start       : function(event, ui){
          $('#guide-h,#guide-v,#guide2-h,#guide2-v,#guide3-h,#guide3-v').show();
          $(this).addClass('all-shadows').css('opacity','0.7').removeClass('bounceIn').find('.boxOps').hide();
          templateBuilder.saveAlert(true);
          $(".flagSave").val("active");
          if ($(this).hasClass("ui-selected")) {
            posTopArray   = [];
            posLeftArray  = [];
            $(".ui-selected").each(function (i) {
                posTopArray[i] = parseInt($(this).css('top'));
                posLeftArray[i] = parseInt($(this).css('left'));
            });
            begintop = $(this).offset().top;
            beginleft = $(this).offset().left;
          }
        },
        drag        : function(event, ui){
          var padd    = 21;
          var tTop    = $(this).position().top+padd;
          var tLeft   = $(this).position().left+padd;
          var oHeight = $(this).outerHeight();
          var oWidth  = $(this).outerWidth();

          $('#guide-h').css({'top'    : tTop});
          $('#guide-v').css({'left'   : tLeft});
          $('#guide2-h').css({'top'   : tTop+oHeight});
          $('#guide2-v').css({'left'  : tLeft+oWidth});
          $('#guide3-h').css({'top'   : (tTop+(oHeight/2))});
          $('#guide3-v').css({'left'  : (tLeft+(oWidth/2))});

          if ($(this).hasClass("ui-selected")) {
            var topdiff = $(this).offset().top - begintop;
            var leftdiff = $(this).offset().left - beginleft;
            $(".ui-selected").each(function (i) {
                $(this).css('top', posTopArray[i] + topdiff);
                $(this).css('left', posLeftArray[i] + leftdiff);
            });
          }

          templateBuilder.buildJsonResult();

        },
        stop        : function(){
          $('#guide-h,#guide-v,#guide2-h,#guide2-v,#guide3-h,#guide3-v').hide();
          $(this).removeClass('all-shadows').css('opacity','1').find('.boxOps').show();
          if(window.isTicketConfig){
            templateBuilder.setBoxContainerWidth();
          }

          $(".ui-selected").removeClass('lter');
          templateBuilder.buildJsonResult();
        }
      }).hover(function(){
        var newZindex = templateBuilder.getHighestZIndex()+1;
        $(this).css('z-index',newZindex).find('.boxOps').show();
        if(window.isTicketConfig){
          templateBuilder.setBoxContainerWidth();
        }
        if(!$(this).hasClass('ui-selected ui-selecting')){
          $(this).addClass('lter');
        }
      },function(){
        $(this).removeClass('lter').find('.boxOps').hide();
      });

      ncmHelpers.onClickWrap('#wrapit',function(event,tis){
        templateBuilder.resetBoxOps();
      });

      ncmHelpers.onClickWrap(classs,function(event,tis){

        var classes = templateBuilder.resetBoxOps();

        var activeClasses   = classes.activeClasses;
        var inActiveClasses = classes.inActiveClasses;

        var id = Math.random();

        $('#boxOpsWrap').removeClass('hidden');

        window.selectedBox = id;
        tis.removeClass(inActiveClasses).addClass(activeClasses);
        tis.attr('data-boxid', id);

        var top     = parseFloat( tis.css('top') );
        var left    = parseFloat( tis.css('left') );
        var width   = parseFloat( tis.css('width') );
        var height  = parseFloat( tis.css('height') );

        $('.boxOpsTop').val(top);
        $('.boxOpsLeft').val(left);
        $('.boxOpsWidth').val(width);
        $('.boxOpsHeight').val(height);

      });

      $('.boxOpsTop, .boxOpsLeft, .boxOpsWidth, .boxOpsHeight').off('keyup').on('keyup', function(){

        if(!window.selectedBox){
          return false;
        }

        var top     = parseFloat( $('.boxOpsTop').val() );
        var left    = parseFloat( $('.boxOpsLeft').val() );
        var width   = parseFloat( $('.boxOpsWidth').val() );
        var height  = parseFloat( $('.boxOpsHeight').val() );

        $('[data-boxid="' + window.selectedBox + '"]').css({
          'top'     : top + 'px',
          'left'    : left + 'px',
          'width'   : width + 'px',
          'height'  : height + 'px',
        });

        templateBuilder.saveAlert(true);

      });

      

    },
    boxTemplate: function(style,type,url,cont,align,size,family,bold,loading,textwrap){
      var rBCStyle = 'padding:0 20px 0 0;';
      if(type == 'company_logo'){
        url     = iftn(url,cont);
        cont    = '<img src="'+url+'" width="100%" height="100%">';
        style   = iftn(style,'width:20mm;height:20mm;');
      }else if(type == 'custom' && !loading){
        var custom = prompt("Ingrese un texto", "");
        if(custom) {
          cont = custom.replace("/\n","<br />");
        }else{
            return false;
        }
      }else if(type == 'hor_line'){
        cont    = '<div style="border-top:1px solid #c9d0d7; width:100%; min-width:40px;"></div>';
        rBCStyle = 'padding:1px 0;';
      }else if(type == 'ver_line'){
        cont    = '<div style="border-left:1px solid #c9d0d7; width:10px; height:100%; min-height: 40px;"></div>';
        rBCStyle = 'padding:1px 0;';
      }

      if(textwrap == 'wrap'){
        rBCStyle += 'text-overflow: clip!important; white-space: nowrap!important;overflow: hidden!important';
        var wrapIcon = 'wrap_text';
      }else{
        rBCStyle += 'text-overflow: none!important;white-space: wrap!important;overflow: none!important';
        var wrapIcon = 'short_text';
      }

      var out = '<div class="box bg-light lter text-dark r-2x b b-light animated bounceIn" style="'+style+'" data-type="'+type+'" data-url="'+url+'" data-textalign="'+align+'" data-fontsize="'+size+'" data-fontfamily="'+family+'" data-bold="'+bold+'" data-textwrap="'+textwrap+'">'+
              ' <div class="realBlockContent" style="'+rBCStyle+'">'+cont+'</div>'+
              ' <span class="boxOps col-xs-12 bg-dark lter wrapper-sm r-3x text-center animated fadeIn animatedx4">'+
              '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default m-b-xs boxRemove">'+
              '     <i class="material-icons text-danger">delete_outline</i>'+
              '   </a>';
              if(type != 'company_logo' || type != 'hor_line' || type != 'ver_line'){
                out +=  '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxFont m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">format_size</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxFamily m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">text_format</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxBold m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">format_bold</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxAlign m-b-xs">'+
                        '     <i class="material-icons">format_align_'+align+'</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxClone m-b-xs">'+
                        '     <i class="material-icons">content_copy</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxWrapText">'+
                        '     <i class="material-icons">'+wrapIcon+'</i>'+
                        '   </a>';
              }
      out +=  ' </span>'+
            '</div>';
      return out;
    },
    events: function(){
      onClickWrap('#viewTemplate',function(e,tis){
        templateBuilder.resetBoxOps();
        var receiptConf = {};
        receiptConf.isTicket  = false;
        receiptConf.isHTML    = false;
        receiptConf.chars     = 0;
        receiptConf.space     = ' ';
        receiptConf.EOL       = '<br>';
        
        if(window.paperSize == 'receipt57' || window.paperSize == 'receipt76' || window.paperSize == 'receipt80'){
          receiptConf.isTicket  = window.paperSize;
          receiptConf.isHTML    = true;
          receiptConf.space     = ' ';

          if(window.paperSize == 'receipt57'){
            receiptConf.chars     = 35;
          }else if(window.paperSize == 'receipt76'){
            receiptConf.chars     = 42;
          }else{
            receiptConf.chars     = 50;
          }
        }
        
        ducumentPrintBuilder.config.TINname         = (window.tinName || 'RUC');
        ducumentPrintBuilder.config.NoCustomerName  = 'Sin nombre';
        ducumentPrintBuilder.config.NoCustomerTIN   = 'xxxxxx';
        var rows = ducumentPrintBuilder.build(templateBuilder.getPosition('.box'),false,receiptConf,true);

        if(!receiptConf.isTicket){
          var html = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important;font-size:'+window.fontSize+'!important; color: black;}</style></head><body>' +rows+ '</body></html>';
        }else{
          if(receiptConf.isHTML){
            var loadFonts       =   '@font-face {' +
                                      ' font-family: "dotmatrix";' +
                                      ' src: local("Dot Matrix"), local("dotmatrix"), url("' + window.masterUrl + 'fonts/dotmatrix.ttf") format("truetype");' +
                                    '}' +
                                    '@font-face {' +
                                    '   font-family: "fakereceipt";' +
                                    '   src: local("FakeReceipt-Regular"),local("fakereceipt"), url(/assets/app/fonts/fakereceipt.woff) format("woff"), url(' + window.masterUrl + 'fonts/fakereceipt.ttf) format("truetype");' +
                                    '}';
            var html = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> ' + loadFonts + '@page{size:auto;margin:0;padding:0;border:0;}pre,*{padding: 0; margin: 0;border:0;font-family:' + window.fontFamily + '!important; font-size:'+window.fontSize+'!important; text-transform: uppercase!important;}</style></head><body><pre style="margin-left:'+window.receipt_left_margin+'mm;">' +rows+ '</pre></body></html>';
          }else{
            var html = rows;
          }
        }
        
        templateBuilder.printAction(html);

      });

      $('#ticketLeftMargin').keyup(function(){
        window.receipt_left_margin = parseFloat($(this).val());
      });

      $('#templateName').on('change',() => {
        templateBuilder.saveAlert(true);
      });

      $('#jsonTemplateField').on('change',() => {
        templateBuilder.saveAlert(true);
        templateBuilder.setPage();
      });
      
      onClickWrap('.saveTemplate',function(e,tis){
        templateBuilder.resetBoxOps();

        var flagSave  = $(".flagSave").val();
        if(flagSave == 'active'){
          var dupli   = templateBuilder.buildJsonResult(tis, true);

            var saveIt  = $('textarea#jsonTemplateField').val();
            var name    = $('input#templateName').val();

            var rawId   = (!dupli) ? $('#templateId').val() : '';

            spinner('body', 'show');
            // BFF: POST action=saveTemplate (data = diseño, i = id para update). Envelope JSON {ok}.
            var post    = $.post(BFF, { action: 'saveTemplate', data: saveIt, name: name, i: rawId }, null, 'json');

            post.done((res) => {
              if(res && res.ok){
                ncmDialogs.toast('Plantilla guardada','success');
                $('#contentIt').html('');
                templateBuilder.loadTemplatesList(function(){
                  templateBuilder.setPage(false,false);
                  templateBuilder.saveAlert(false);
                });
              }else{
                ncmDialogs.toast('No se pudo guardar','danger');
              }
              spinner('body', 'hide');
            }).fail(() => {
              ncmDialogs.toast('No se pudo guardar','danger');
              spinner('body', 'hide');
            });
        }else{
          ncmDialogs.toast('No se detectaron cambios para guardar','danger');
        }
       
      });

      window.paperSize = (!window.paperSize)?'a4page':window.paperSize;
      onClickWrap('.pFormat',function(e,tis){
        var size  = tis.data('size');
        var title = 'Formato: '+tis.find('b').text();
        $('.pFormat span').addClass('hidden');
        
        if(window.paperSize == size && (size != 'receipt80' || size != 'receipt76' || size != 'receipt57')){
          size = size+'-h';
          title += ' <span class="text-muted text-sm">(Horizontal)</span>';
        }else{
          tis.find('span').removeClass('hidden');
          title += ' <span class="text-muted text-sm">(Vertical)</span>';
        }
        templateBuilder.saveAlert(true);
        templateBuilder.setPage(size,title);
      });

      onClickWrap('.addField',function(e,tis){
        window.scrollTo(0,0);
        var cont    = tis.data('default');
        var type    = tis.data('type');
        var style   = 'padding:0; min-height:'+window.minHeight+'px;';
        var url     = '';

        var toAppend = templateBuilder.boxTemplate(style,type,url,cont,'left','inherit','inherit','normal',false,'cut');

        $('#contentIt').append(toAppend);
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxRemove',function(e,tis){
        tis.closest('.box').draggable().remove();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxFont',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('fontsize');
        var base    = 'inherit';
        var arr     = [
                        base,
                        '10pt',
                        '12pt',
                        '14pt',
                        '16pt',
                        '18pt',
                        '20pt',
                        '24pt',
                        '30pt',
                        '8pt'
                      ];

        $.each(arr,function(i,ar){
          var a = (i<1)?i:(i-1);
          if(data == arr[a]){
            ar = (i+1 == arr.length)?base:ar;
            el.css({'font-size':ar}).data('fontsize',ar);
          }
        });
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.pSize',function(e,tis){
        var size        = tis.data('size');
        window.fontSize = size;
        $('#contentIt').css('font-size',window.fontSize);
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.pUpper',function(e,tis){
        var size        = tis.data('size');
        window.fontCase = size;
        $('#contentIt').css('text-transform',window.fontCase);
        templateBuilder.saveAlert(true);
        if(size == 'uppercase'){
          tis.data('size','none');
        }else{
          tis.data('size','uppercase');
        }
      });

      onClickWrap('.pFamily',function(e,tis){
        var size          = tis.data('size');
        window.fontFamily = size;
        $('#contentIt').css('font-family',window.fontFamily);
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxAlign',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('textalign');

        if(data == 'left'){//si ya tiene el primer tamaño
          el.css({'text-align':'center'}).data('textalign','center');
          tis.find('i').text('format_align_center');
        }else if(data == 'center'){
          el.css({'text-align':'right'}).data('textalign','right');
          tis.find('i').text('format_align_right');
        }else{
          el.css({'text-align':'left'}).data('textalign','left');
          tis.find('i').text('format_align_left');
        }
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxBold',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('bold');

        if(data == 'normal'){//si ya tiene el primer tamaño
          el.css({'font-weight':'bold'}).data('bold','bold');
        }else{
          el.css({'font-weight':'normal'}).data('bold','normal');
        }
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxFamily',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('fontfamily');
        var base    = 'inherit';
        var arr     = [
                        base,
                        'Courier New',
                        'Arial',
                        'Times New Roman',
                        'Comic Sans MS',
                        'Trebuchet MS',
                        'Verdana'
                      ];

        $.each(arr,function(i,ar){
          var a = (i<1)?i:(i-1);
          if(data == arr[a]){
            ar = (i+1 == arr.length)?base:ar;
            el.css({'font-family':ar}).data('fontfamily',ar);
          }
        });

        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxClone',function(e,tis){
        var el      = tis.closest('.box');

        var toAppend = el.clone();
        $('#contentIt').append(toAppend);

        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxWrapText',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('textwrap');
        var ele     = el.find('.realBlockContent');

        if(data == 'cut'){//si ya tiene el primer tamaño
          var wrapObj     = {"text-overflow": "clip","white-space": "nowrap","overflow": "hidden"};
          var warpType    = 'wrap';
           var warpIcon   = 'wrap_text';
        }else{
          var wrapObj     = {"text-overflow": "none","white-space": "wrap","overflow": "none"};
          var warpType    = 'cut';
          var warpIcon    = 'short_text';
        }

        ele.attr('style','');
        tis.find('i').text(warpIcon);
        ele.css(wrapObj);
        ele.css('padding','0px 20px 0px 0px');
        el.data('textwrap',warpType);

        ele.show();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('#deleteTemplate',function(e,tis){
        templateBuilder.resetBoxOps();

        var id    = tis.data('id');

        confirmation('¿Realmente desea remover del grupo?', function (e) {
          if (e === true) {
            $.post(BFF, { action: 'removeTemplate', id: id }, function(res){
              if(res && res.ok){
                ncmDialogs.toast('Plantilla eliminada','success');
                templateBuilder.loadTemplatesList();
              }else{
                ncmDialogs.toast('No se pudo eliminar la plantilla','danger');
              }
            }, 'json');
            templateBuilder.saveAlert(false);
          }
        });

      });

      onClickWrap('.templateSelect',function(e,tis){

        templateBuilder.resetBoxOps();
       
        var id        = tis.data('id');
        spinner('body', 'show');
        if(id == 'new'){
          templateBuilder.loadTemplatesList(function(){
            templateBuilder.setPage(false,false);
            templateBuilder.saveAlert(true);

            spinner('body', 'hide');
            $('textarea#jsonTemplateField').val('');
          });
          $('#deleteTemplate, .saveTemplate').show();
          return false;
        }else if(id == '1'){
          $('#deleteTemplate, .saveTemplate').hide();
        }

        var data      =  tis.find('div.loadTemplateData').text();
        $('textarea#jsonTemplateField').val( $.trim( data ) );

        if($.type( data ) === 'string') { 
          data = $.parseJSON(data);
        }else{
          
        }
        
        var name      = tis.data('name');
        var size      = data.page_size;
        var sizeName  = data.page_size_name;
        var font      = data.page_font_family;
        var fontSize  = data.page_font_size;
        var fontCase  = data.page_font_case;
        var fLeftM    = data.receipt_left_margin;
        var out       = '';

        $('#templateName').val(name);
        $('#templateId').val(id);
        $('#deleteTemplate, #duplicateTemplate').data('id',id).removeClass('hidden');
        
        //$('#contentIt').html(out);
        $.each(data.data, function(i, v){
          out += templateBuilder.boxTemplate('top:'+v.top+'px;left:'+v.left+'px;width:'+v.width+'px;height:'+v.height+'px;min-height:'+window.minHeight+'px;position:absolute;z-index:'+i+';font-size:'+v.size+';font-family:'+v.family+';text-align:'+v.align+';font-weight:'+v.bold+';',v.type,v.url,v.text,v.align,v.size,v.family,v.bold,true,v.textwrap);
        });

        $('#contentIt').html(out);
        templateBuilder.setPage(size,sizeName,font,fontSize,fontCase,fLeftM);
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(false);
        spinner('body', 'hide');
        $(".flagSave").val("no-active");
      });
    },
    setPage: function(size,sizeName,font,fSize,fCase,fLeftM){
      var size      = (!size)?'a4page':size;
      var sizeName  = (!size)?'Formato: A4 (Vertical)':sizeName;
      var font      = (!font)?window.fontFamily:font;
      font          = (window.isTicketConfig)?"'fakereceipt', 'dotmatrix', monospace;":font;
      var fSize     = (!fSize)?window.fontSize:fSize;
      fSize         = (window.isTicketConfig)?'8pt':fSize;
      var fCase     = (!fCase)?window.fontCase:fCase;
      var fLeftM    = (!fLeftM)?window.receipt_left_margin:fLeftM;

      $('#contentIt').removeClass('a4page legalpage letterpage a4page-h legalpage-h letterpage-h receipt80 receipt76 receipt57').addClass(size).css({'font-family':font,'font-size':fSize});
      $('#pageSizeTitle').html(sizeName);
      $('#pageType').val(size);

      if(size == 'receipt80' || size == 'receipt76' || size == 'receipt57'){
        $('.receiptableN').addClass('hidden');
        $('.receiptableY').removeClass('hidden');
        templateBuilder.setBoxContainerWidth();
      }else{
        $('.receiptableY').addClass('hidden');
        $('.receiptableN').removeClass('hidden');
        window.isTicketConfig = false;
      }

      window.paperSize  = size;
      window.fontFamily = font;
      window.fontSize   = fSize;
      window.fontCase   = fCase;
      window.receipt_left_margin = fLeftM;
      $('.pUpper').data('size',fCase);
      $('#ticketLeftMargin').val(window.receipt_left_margin);
    },
    getPosition: function(classe) {
      var arr = [];
      $(classe).each(function() {
        var tis = $(this);
        arr.push({
          width   : tis.outerWidth(),
          height  : tis.outerHeight(),
          left    : tis.position().left,
          top     : tis.position().top,
          text    : tis.find('div.realBlockContent').html(),
          type    : tis.data('type'),
          url     : tis.data('url'),
          size    : tis.data('fontsize'),
          align   : tis.data('textalign'),
          family  : tis.data('fontfamily'),
          textwrap  : tis.data('textwrap'),
          bold    : tis.data('bold')
        });
      });

      arr.sort(function(a, b) {
        return a.top - b.top;
      });

      return arr;
    },
    setBoxContainerWidth: function(){
      var rowWidth = $('#contentIt').width();
      $('.box').css({left:0,width:rowWidth});
      $('.hiddenTicket').hide();
      window.isTicketConfig = true;
    },
    printAction: function(body, callback) {
      $("#printarea").remove();
      $('body').append('<div id="printarea" style="display:none;"></div>');
      var success = function() {
        $("#printarea").remove();
        callback && callback(true);
      };
      var failure = function() {
        $("#printarea").remove();
        callback && callback(false);
      };
      setTimeout(function() {
        $("#printarea").print({
          append: body,
          deferred: $.Deferred().done(success, failure)
        });
      }, 50);
    },
    getHighestZIndex: function(){
      var index_highest = 0;
      // more effective to have a class for the div you want to search
      $(".box").each(function() {
          // always use a radix when using parseInt
          var index_current = parseInt($(this).css("zIndex"), 10);
          if (index_current > index_highest) {
              index_highest = index_current;
          }
      });
      return index_highest;
    },
    saveAlert: function(noti){
       $(".flagSave").val("active");
      if(noti){
        window.onbeforeunload = function(){return true;};
      }else{
        window.onbeforeunload = null;
      }

      templateBuilder.buildJsonResult();
      ncmDialogs.toast('Actualizado','success');
    }
  };

  var htmlToPlain = {//este code usare para build el ticket template
    spaceW: 7.98,
    spaceH: 15,
    plainBuild: function(arr){
      htmlToPlain.sortOn(arr,"left");
      htmlToPlain.sortOn(arr,"top");
      var out = [];
      var prevTop = 0;
      var prevLeft = 0;
      $(arr).each(function(i,v) {
        var left    = Math.round(v.left/htmlToPlain.spaceW);
        var top     = Math.round(v.top/htmlToPlain.spaceH);
        var width   = Math.round(v.width/htmlToPlain.spaceW);
        var height  = Math.round(v.height/htmlToPlain.spaceH);
        var text    = v.text;
        
        var newtop = top - prevTop;
        if (top == prevTop) {
          var newleft = left - prevLeft;
        } else {
          var newleft = left;
        }

        out += htmlToPlain.buildIt(newleft, newtop, width, text, height);

        prevTop = top;
        prevLeft = left + width;      
      });
      return out;
    },
    plainbuildIt: function(left, top, width, text, height) {
      var lef   = '';
      var to    = '';
      var widt  = '';
      var txt   = text.split('');
      var txto  = '';
      var lbkd  = 1;
      for (var i = 0; i < left; i++) {
        lef += ' ';
      }
      for (var i = 0; i < top; i++) {
        to += '\n';
      }
      var lt = 0;
      for (var i = 0; i < txt.length; i++) {
        if(lt==width){
          if(lbkd<height){
            txto += '\n'+lef;
            lbkd++;
            lt = 0;
            i--;
          }else{
            break;
          }
        }else{
          txto += txt[i];
          lt++;
        }
      }
      return to + lef + txto;
    },
    sortOn: function(arr,key) {
      arr.sort(function(a, b) {
        if(a[key] < b[key]){
          return -1;
        }else if(a[key] > b[key]){
          return 1;
        }
        return 0;
      });
    }
  }


	window.templateBuilder = templateBuilder;
	window.htmlToPlain     = htmlToPlain;

})();
