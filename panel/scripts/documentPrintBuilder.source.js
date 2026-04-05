var ducumentPrintBuilder = {
  build: function(arr,sale,receiptConf,demo){
        var out = '';
    var mm  = 'mm' in receiptConf ? receiptConf.mm : ducumentPrintBuilder.findMm();

    if(typeof receiptConf === "undefined"){
      var receiptConf       = {};
      receiptConf.isTicket  = false;
      receiptConf.chars     = 32;
    }

    //variables para el receipt
    var receiptHeadIn         = [],
    receiptPropsHeadIn        = [],
    receiptSaleDetailsIn      = [],
    receiptFooterTextIn       = [],
    receiptPropsBottomIn      = [],
    logoIn                    = [],
    receiptTable              = '',
    receiptTableHead          = '',
    numsToWords               = ' ';

    $.each(arr, function(i, v) {

      var left      = ducumentPrintBuilder.pxTomm(v.left,mm);
      var top       = ducumentPrintBuilder.pxTomm(v.top,mm);
      var width     = ducumentPrintBuilder.pxTomm(v.width,mm);
      var height    = ducumentPrintBuilder.pxTomm(v.height,mm);
      var text      = (v.text)?v.text:'0';
      var type      = v.type;
      var url       = v.url;
      var align     = v.align;
      var size      = v.size;
      var family    = v.family;
      var bold      = v.bold;
      var textwrap  = v.textwrap;
      var fakeSale = {
        "currency": "Gs",
        "decimal": "no",
        "thouSeparator": "dot",
        "companyName": "Empresa",
        "companyBillingName": "Nombre empresa",
        "companyTIN": "1111111-1",
        "companyAddress": "Direccionb 1",
        "companyEmail": "roquetas@test.com",
        "companyPhone": "0991713132",
        "outlet": "Casa Central (Gastro)",
        "outletBillingName": "",
        "outletTin": "11111111-9",
        "outletAddress": "Direccion 2",
        "outletPhone": "021 111-678",
        "customerLoyalty": "0",
        "customerStoreCredit": "0",
        "total": "##.###",
        "rawtotal": '#####',
        "subtotal": "##.###",
        "discount": "#.###",
        "tax": "#.###",
        "taxArray": [
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            "#.###"
        ],
        "itemsSubtotals": [
            "0",
            "0",
            "0",
            "0",
            "0",
            "0",
            "0",
            "0",
            "0",
            "0",
            "##.###"
        ],
        "timestamp": 15358082714827952,
        "type": "0",
        "date": "2024-01-23 15:36:18",
        "dueDate": null,
        "invoiceAuthNo": "########",
        "authStart": "2022-08-01",
        "authExpiration": "2024-11-30 23:59:59",
        "invoiceNoMax": 999999,
        "invoiceNo": "000001",
        "invoicePrefix": "001-002-",
        "invoiceSufix": "",
        "ticketNo": "#######",
        "returnNo": "#######",
        "quoteNo": "#######",
        "orderNo": "#######",
        "scheduleNo": "#######",
        "typeDocument": "FACTURA",
        "payment": [],
        "note": "",
        "user": "Usuario",
        "register": "Caja Principal",
        "sale": [
            {
                "itemId": "####",
                "count": 2,
                "discount": 0,
                "name": "SW descrip",
                "note": "",
                "price": "##.###",
                "tags": "",
                "tax": 10,
                "taxAmount": "2.182",
                "total": "##.###",
                "totalDiscount": "0",
                "type": "product",
                "uId": "",
                "uniPrice": "##.###"
            },
            {
                "itemId": "###",
                "count": 1,
                "discount": 50,
                "name": "SW #####",
                "note": "",
                "price": "#.###",
                "tags": "",
                "tax": 10,
                "taxAmount": "682",
                "total": "#.###",
                "totalDiscount": "#.###",
                "type": "product",
                "uId": "",
                "uniPrice": "##.###"
            }
        ],
        "tags": "",
        "tableNo": false,
        "printer": "factura"
    };
      var tsale     = iftn(demo,sale,fakeSale);
      var items     = tsale.sale;
      var extraCss  = '';

      var company   = validity(v,false,true,'text')?v.text:'';

      if(type == 'item'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.name,'',val.name  + '<br>');
          }
        });
      }else if(type == 'item_receipt'){
        var receiptRow  = [];

        receiptTableHead = { type: 'table', lines: [ { item: 'DESCRIPCION', tax: ducumentPrintBuilder.config.taxName, qty: 'CANT ', code:'CODIGO', cost: 'PRECIO', total:'TOTAL' } ] };

        $.each(items,function(ind,val){
          if(val.type == 'discount'){
            return true;
          }

          var addLine   = (val.tags || val.note) ? true : false;

          var note      = ducumentPrintBuilder.unHTML(val.note,'decode');
          note          = val.note ? ' [' + note + ']' : '' ;

          var iva       = val.tax;
          var hasTaxObj = validity(val,false,true,'taxObj');
          var total     = val.total;

          /*if(hasTaxObj){

            var total   = 0;
            $.each(hasTaxObj,function(toI,toVal){
              if(iva == '10'){
                total = toVal.grav;
                return false;
              }
            });

          }*/
          
          receiptRow.push({ 
                            item  : val.name,
                            tax   : (val.tax ? (val.tax + '% ') : ''),
                            qty   : val.count + ' ',
                            code  : val.uId,
                            cost  : val.price,
                            total : total,
                            discount: { type: 'absolute', value: addLine, message: val.tags + note }
                          });
        });
        //var receiptTable = { type: 'table', lines: [{ item: 'DESCRIPCIÃ“N', tax: ducumentPrintBuilder.config.taxName, qty: 'CANT.', code:'CÃ“DIGO', cost: 'PRECIO', total:'TOTAL' }] },{ type: 'empty' };
        receiptTable = { type: 'table', lines: receiptRow };
      }else if(type == 'item_receipt_2'){
        var receiptRow  = [];

        receiptTableHead = { type: 'table', lines: [ { item: 'DESCRIPCION', tax: 'CANT ', code:'COD' } ] };

        $.each(items,function(ind,val){
          if(val.type == 'discount'){
            return true;
          }

          var addLine = (val.tags || val.note) ? true : false;
          var note    = ducumentPrintBuilder.unHTML(val.note,'decode');
          note        = ((val.note) ? ' [' + note + ']':'');
          receiptRow.push({ item: val.name, tax: val.count, code:val.uId, discount: { type: 'absolute', value: addLine, message: val.tags + note } });
        });
        //var receiptTable = { type: 'table', lines: [{ item: 'DESCRIPCIÃ“N', tax: ducumentPrintBuilder.config.taxName, qty: 'CANT.', code:'CÃ“DIGO', cost: 'PRECIO', total:'TOTAL' }] },{ type: 'empty' };
        receiptTable = { type: 'table', lines: receiptRow };
      }else if(type == 'item_receipt_3'){
        var receiptRow  = [];

        $.each(items,function(ind,val){
          if(val.type == 'discount'){
            return true;
          }

          var addLine = (val.tags || val.note)?true:false;

          var note    = ducumentPrintBuilder.unHTML(val.note,'decode');
          note        = ((val.note) ? ' [' + note + ']' : '');

          receiptRow.push({ item: val.name, total: val.total, code:val.uId, discount: { type: 'absolute', value: addLine, message: val.tags + note } });
        });
        //var receiptTable = { type: 'table', lines: [{ item: 'DESCRIPCIÃ“N', tax: ducumentPrintBuilder.config.taxName, qty: 'CANT.', code:'CÃ“DIGO', cost: 'PRECIO', total:'TOTAL' }] },{ type: 'empty' };
        receiptTable = { type: 'table', lines: receiptRow };
      }else if(type == 'item_receipt_4'){
        var receiptRow  = [];

        receiptTableHead = { type: 'table', lines: [ { item: 'DESCRIPCION', tax: '', qty: 'CANT ', code:'CODIGO', cost: 'PRECIO', total:'TOTAL' } ] };

        $.each(items,function(ind,val){
          if(val.type == 'discount'){
            return true;
          }

          var addLine   = (val.tags || val.note) ? true : false;

          var note      = ducumentPrintBuilder.unHTML(val.note,'decode');
          note          = val.note ? ' [' + note + ']' : '' ;

          var iva       = val.tax;
          var hasTaxObj = validity(val,false,true,'taxObj');
          var total     = val.total;
          
          receiptRow.push({ 
                            item  : val.name, 
                            tax   : '', 
                            qty   : val.count + ' ', 
                            code  : val.uId, 
                            cost  : val.price, 
                            total : total, 
                            discount: { type: 'absolute', value: addLine, message: val.tags + note } 
                          });
        });
        //var receiptTable = { type: 'table', lines: [{ item: 'DESCRIPCIÃ“N', tax: ducumentPrintBuilder.config.taxName, qty: 'CANT.', code:'CÃ“DIGO', cost: 'PRECIO', total:'TOTAL' }] },{ type: 'empty' };
        receiptTable = { type: 'table', lines: receiptRow };
      }else if(type == 'item_id'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.itemId,'',val.itemId + '<br>');
          }
        });
      }else if(type == 'item_uid'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.uId,'',val.uId + '<br>');
          }
        });
      }else if(type == 'item_discount'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.totalDiscount,'',val.totalDiscount + '<br>');
          }
        });
      }else if(type == 'item_tax'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.tax,'0') + '%' + '<br>';
          }
        });
      }else if(type == 'item_taxAmount'){
        var iva = text.replace(/^\D+|\D+$/g, "");
        text    = '';
        $.each(items,function(ind,val){
          if(val.type == 'discount'){
            return true;
          }

          if(iva == val.tax){
            text += iftn(val.price,'',val.price + '<br>');
          }else{
            if(iva == '0' && val.exent){
             text += val.exent + '<br>';  
            }else{
             text += '0' + '<br>'; 
            }
          }
                    
        });
      }else if(type == 'item_taxAmount_single'){
                  var iva = text.replace(/^\D+|\D+$/g, "");
        text    = '';
        $.each(items,function(ind,val){
                    if(val.type == 'discount'){
            return true;
          }

           var hasTaxObj = false //validity(val,false,true,'taxObj');
          if(hasTaxObj){
          
            $.each(hasTaxObj,function(toI,toVal){
              var hTOpercent  = validity(toVal,false,true,'percent');
              var hTOgrav     = validity(toVal,false,true,'grav');

              if(iva == hTOpercent){
                text += iftn(hTOgrav,'') + '<br>';
              }else{
                //text += '<br>';
              }
            });

          }else{

            if(iva == val.tax){
              text += iftn(val.total,'') + '<br>';
            }else{
              text += '0' + '<br>'; 
            }

          }
          
        });
      }else if(type == 'item_subtotal'){
        var iva       = text.replace(/^\D+|\D+$/g, "");
        var totalGrav = 0;
        text          = 0;

        $.each(tsale.itemsSubtotals,function(i,val){
          if(i == iva){
            text = val;
            return false;
          }
        });

        var ivaName = iva + '%';
        if(iva < 1){
          ivaName = 'Exentas';
        }

        receiptSaleDetailsIn.push({name : 'Sub. ' + ivaName, value : text, align : 'left'});
      }else if(type == 'tax_single'){
              
        var iva = text.replace(/^\D+|\D+$/g, "");
        text    = 0;
        
        $.each(tsale.taxArray,function(key,val){
                    if(iva == key){
            text = (val) ? val : 0;
            return false;
          }
        });

        iva     = iva ? iva : 0;
        
        receiptSaleDetailsIn.push({name : ducumentPrintBuilder.config.taxName + ' ' + iva + '%', value : text, align : 'left'});

      }else if(type == 'item_note'){
        text = '';
        $.each(items, function (ind, val) {
          var valnote = '';
          try {
            valnote = decodeURIComponent($('<div/>').html(iftn(val.note, '', val.note)).text()) + '<br>';
          } catch (e) {
            valnote = $('<div/>').html(iftn(val.note, '', val.note)).text() + '<br>';
          }
          text += iftn(val.note, '', valnote);
        });
      }else if(type == 'item_tags'){
        text = '';
        $.each(items,function(ind,val){
          text += iftn(val.tags,'',val.tags + '-');
        });
      }else if(type == 'item_units'){
        text = '';
        $.each(items,function(ind,val){
          if(val.type != 'discount'){
            text += iftn(val.count,'',val.count) + '<br>';
          }
        });
      }else if(type == 'item_price'){
        text = '';
        $.each(items,function(ind,val){

          if(val.type == 'discount'){
            return true;
          }

          text += iftn(val.price,0) + '<br>';
        });
      }else if(type == 'item_uni_price'){
        text = '';
        $.each(items,function(ind,val){

          if(val.type == 'discount'){
            return true;
          }

          text += iftn(val.uniPrice,0) + '<br>';
        });
      }else if(type == 'item_price_notax'){
        text = '';
        $.each(items,function(ind,val){
          var price = val.price;
          text += price + '<br>';
        });
      }else if(type == 'item_total'){
        text = '';
        $.each(items,function(ind,val){
          text += iftn(val.total,'',val.total) + '<br>';
        });
      }else if(type == 'tags'){
        text = validity(tsale,false,true,'tags')?tsale.tags:'';
        receiptFooterTextIn.push(text);
      }else if(type == 'sale_type'){
        text = '';
        if(tsale.type == 3){//contado
          text = 'Crédito';
        }else{
          text = 'Contado';
        }
        receiptPropsHeadIn.push('TIPO: '+text);
      }else if(type == 'sale_type_contado'){
        text = '';
        if(tsale.type < 1){//contado
          text = '✕';
        }
      }else if(type == 'sale_type_credit'){
        text = '';
        if(tsale.type == 3){//credito
          text = '✕';
        }
      }else if(type == 'document_type'){//---
        text = validity(tsale,false,true,'typeDocument')?tsale.typeDocument:'';
        receiptPropsHeadIn.push(text);
      }else if(type == 'document_number'){//---
        if(tsale.type == '3' || tsale.type == '0'){//factura contado o credito
          text = validity(tsale,false,true,'invoiceNo') ? tsale['invoiceNo'] : '0';
          //text = tsale['invoicePrefix']+text+tsale['invoiceSufix'];
          if(receiptConf.isTicket){
            text = tsale['invoicePrefix'] + text + tsale['invoiceSufix'];
          }
        }else if(tsale.type == '5'){//recibo pago de credito
          text = validity(tsale,false,true,'ticketNo')?tsale['ticketNo']:'0';
        }else if(tsale.type == '6'){//nota de credito
          text = validity(tsale,false,true,'returnNo')?tsale['returnNo']:'0';
        }else if(tsale.type == '9'){//cotizacion
          text = validity(tsale,false,true,'quoteNo')?tsale['quoteNo']:'0';
        }else if(tsale.type == '11' || tsale.type == '12'){//orden
          text = validity(tsale,false,true,'orderNo')?tsale['orderNo']:'0';
        }else if(tsale.type == '13'){//agendamiento
          text = validity(tsale,false,true,'scheduleNo')?tsale['scheduleNo']:'0';
        }

        receiptPropsHeadIn.push(text);
      }else if(type == 'document_prefix'){//---
        text = validity(tsale,false,true,'invoicePrefix')?tsale.invoicePrefix:'';
      }else if(type == 'associated_document'){//---
        text = validity(tsale,false,true,'associatedDocument')?tsale.associatedDocument:'';
      }else if(type == 'document_sufix'){//---
        text = validity(tsale,false,true,'invoiceSufix')?tsale.invoiceSufix:'';
      }else if(type == 'auth_number'){//---
        text = validity(tsale,false,true,'invoiceAuthNo')?tsale.invoiceAuthNo:'';
        receiptPropsHeadIn.push('Timbrado: '+text);
      }else if(type == 'auth_expiration'){//---
        text = validity(tsale,false,true,'authExpiration')?tsale.authExpiration:'';
        receiptPropsHeadIn.push('Vencimiento: ' + text);
      }else if(type == 'auth_start_date'){//---
        text = validity(tsale,false,true,'authStart') ? tsale.authStart : '';
        receiptPropsHeadIn.push('Inicio: ' + text);
      }else if(type == 'payment_methods'){//---
        text = '';
        $.each(tsale.payment,function(ind,val){
          text += val.name + ': ' + val.price + ' ';
          receiptFooterTextIn.push(val.name + ': ' + val.price);
        });
      }else if(type == 'transaction_id'){//---
        text = validity(tsale,false,true,'timestamp')?tsale.timestamp:0;
        receiptFooterTextIn.push(text.toString());
      }else if(type == 'transaction_id_barcode'){
        text = validity(tsale,false,true,'timestamp')?tsale.timestamp:0;//aqui convertir a barcode
        receiptFooterTextIn.push(text.toString());
      }else if(type == 'nums_to_words'){//---
        numsToWords = ducumentPrintBuilder.toTitleCase(numeroALetras(tsale.rawtotal));
        text = numsToWords;
      }else if(type == 'subtotal'){//---
        text = validity(tsale,false,true,'subtotal')?tsale.subtotal:0;
        receiptSaleDetailsIn.push({name:'SUBTOTAL',value:text,align:'left'});
      }else if(type == 'discount'){//---
        text = validity(tsale,false,true,'discount')?tsale.discount:0;
        receiptSaleDetailsIn.push({name:'DESCUENTO',value:text,align:'left'});
      }else if(type == 'tax_total'){//---
        text = validity(tsale,false,true,'tax')?tsale.tax:0;
        receiptSaleDetailsIn.push({name:ducumentPrintBuilder.config.taxName,value:text,align:'left'});
      }else if(type == 'total'){//---
        text = validity(tsale,false,true,'total')?tsale.total:0;
        receiptSaleDetailsIn.push({name:'TOTAL',value:text,align:'left'});
      }else if(type == 'date'){//---
        text = validity(tsale,false,true,'date')?tsale.date:'';
        receiptPropsHeadIn.push('Fecha: '+text);
      }else if(type == 'duedate'){//---
        text = validity(tsale,false,true,'dueDate')?tsale.dueDate:tsale.date;
        receiptPropsHeadIn.push('Vencimiento: '+text);
      }else if(type == 'note'){//---
        text = validity(tsale,false,true,'note')?tsale.note:'';
        //text = ducumentPrintBuilder.unHTML(text,'decode');
        text = text;
        receiptFooterTextIn.push(text);
      }else if(type == 'customer_name'){//---
        text = validity(tsale,false,true,'customerName') ? tsale.customerName : ducumentPrintBuilder.config.NoCustomerName;
        receiptPropsBottomIn.push('Cliente: '+text);
      }else if(type == 'customer_tin'){//---
        text = validity(tsale,false,true,'customerTIN') ? tsale.customerTIN : ducumentPrintBuilder.config.NoCustomerTIN;
        receiptPropsBottomIn.push(ducumentPrintBuilder.config.TINname+': '+text);
      }else if(type == 'customer_full_name'){//---
        text = validity(tsale,false,true,'customerFullName') ? tsale.customerFullName : '';
        receiptPropsBottomIn.push('Nombre: '+text);
      }else if(type == 'customer_ci'){//---
        text = validity(tsale,false,true,'customerCI') ? tsale.customerCI : '';
        receiptPropsBottomIn.push('Documento: ' + text);
      }else if(type == 'customer_email'){//---
        text = validity(tsale,false,true,'customerEmail') ? tsale.customerEmail : '';
        if(text){
          receiptPropsBottomIn.push('Email: '+text);
        }
      }else if(type == 'customer_phone'){//---
        text = validity(tsale,false,true,'customerPhone') ? tsale.customerPhone : '';
        if(text){
          receiptPropsBottomIn.push('Teléfono: '+text);
        }
      }else if(type == 'customer_phone_2'){//---
        text = validity(tsale,false,true,'customerPhone2') ? tsale.customerPhone2 : '';
        if(text){
          receiptPropsBottomIn.push('Teléfono 2: '+text);
        }
      }else if(type == 'customer_birthday'){//---
        text = validity(tsale,false,true,'customerBirthday') ? tsale.customerBirthday : '';
        receiptPropsBottomIn.push('Nacimiento: '+text);
      }else if(type == 'customer_note'){//---
        text = validity(tsale,false,true,'customerNote') ? tsale.customerNote : '';
        if(text){
          receiptPropsBottomIn.push('Nota: '+text);
        }
      }else if(type == 'customer_address'){//---
        text = validity(tsale,false,true,'customerAddress') ? tsale.customerAddress : '';
        receiptPropsBottomIn.push('Dirección: ' + text);
      }else if(type == 'customer_loyalty'){//---
        text = validity(tsale,false,true,'customerLoyalty') ? tsale.customerLoyalty : '';
        receiptPropsBottomIn.push('Loyalty: ' + text);
      }else if(type == 'customer_city'){//---
        text = validity(tsale,false,true,'customerCity') ? tsale.customerCity : '';
        receiptPropsBottomIn.push('Ciudad: ' + text);
      }else if(type == 'customer_location'){//---
        text = validity(tsale,false,true,'customerLocation') ? tsale.customerLocation : '';
        receiptPropsBottomIn.push('Localidad: ' + text);
      }else if(type == 'table_number'){//---
        text = validity(tsale,false,true,'tableNo') ? tsale.tableNo : '';
        if(text){
          receiptPropsBottomIn.push('Espacio: ' + text);
        }
      }else if(type == 'user_name'){//---
        text = validity(tsale,false,true,'user') ? tsale.user : '';
        receiptPropsHeadIn.push('Usuario: ' + text);
      }else if(type == 'register_name'){//---
        text = validity(tsale,false,true,'register') ? tsale.register : '';
        receiptPropsHeadIn.push('Caja: ' + text);
      }else if(type == 'printer_name'){//---
        text = validity(tsale,false,true,'printer') ? tsale.printer : '';
        receiptPropsHeadIn.push('Sector: ' + text);
      }else if(type == 'company_logo'){//---
        text = '<img src="'+url+'" width="100%" height="100%">';
        if(url){
          logoIn.push(url);
        }
      }else if(type == 'company_name'){//---
        text = tsale.companyName;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'company_email'){//---
        text = tsale.companyEmail;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'company_address'){//---
        text = tsale.companyAddress;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'company_billing_name'){//---
        text = tsale.companyBillingName;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'company_tin'){//---
        text = tsale.companyTIN;
        if(text){
          receiptHeadIn.push(text);
        }
       }else if(type == 'company_phone'){//---
        text = tsale.companyPhone;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'company_website'){//---
        text = tsale.companyWebsite;
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'outlet_name'){//---
        text = validity(tsale,false,true,'outlet') ? tsale.outlet : '';
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'outlet_billing_name'){//---
        text = validity(tsale,false,true,'outlet') ? tsale.outletBillingName : '';
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'outlet_tin'){//---
        text = validity(tsale,false,true,'outlet') ? tsale.outletTin : '';
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'outlet_address'){
        text = validity(tsale,false,true,'outletAddress') ? tsale.outletAddress : '';
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'outlet_phone'){
        text = validity(tsale,false,true,'outletPhone') ? tsale.outletPhone : '';
        if(text){
          receiptHeadIn.push(text);
        }
      }else if(type == 'fe_py'){//---
        text = '<img src="' + url + '" width="100%" height="100%">';
        if(url){
          receiptFooterTextIn.push(url);
        }
      }else{
        receiptFooterTextIn.push(text);
      }

      if(textwrap == 'wrap'){
        extraCss += 'text-overflow: clip!important; white-space: nowrap!important;overflow: hidden!important';
      }else{
        extraCss += 'text-overflow: none!important;white-space: wrap!important;overflow: none!important';
      }

      text = (!text || text == 'undefined') ? '' : text;

      out += '<div style="margin-top:' + top + 'mm;margin-left:' + left + 'mm;width:' + width + 'mm;height:' + height + 'mm;position:absolute;z-index:' + i + ';overflow:hidden;font-size:' + size + '!important;font-family:' + family + '!important;text-align:' + align + ';font-weight:' + bold + ';' + extraCss + '">' + text + '</div>';
    });
    
    //receipt
    var logo                = logoIn.length ? { type: 'logo', value: logoIn, width: '100px', height: '100px' } : {};

    var receiptHead         = receiptHeadIn.length ? { type: 'text', value: receiptHeadIn, align: 'center', padding:3 } : {};
   
    var receiptPropsHead    = receiptPropsHeadIn.length ? { type: 'text', value: receiptPropsHeadIn, align: 'left'} : {};
    
    var receiptSaleDetails  = receiptSaleDetailsIn.length ? { type: 'properties', lines: receiptSaleDetailsIn } : {};

    var receiptPropsBottom  = receiptPropsBottomIn.length ? { type: 'text', value: receiptPropsBottomIn, align: 'left', padding:2} : {};

    var receiptFooterText   = receiptFooterTextIn.length ? { type: 'text', value: receiptFooterTextIn, align:'center', padding:1 } : {};
    //

    receipt.config.width    = receiptConf.chars;
    receipt.config.EOL      = receiptConf.EOL;

    if(receiptConf.isTicket){
      var create = [
                    logo,
                    receiptHead,
                    { type: 'empty' },
                    receiptPropsHead,
                    { type: 'ruler' },
                    receiptTableHead,
                    { type: 'empty' },
                    receiptTable,
                    { type: 'ruler' },
                    { type: 'empty' },
                    receiptSaleDetails,
                    { type: 'text', value: numsToWords, align:'right', padding:1 },

                    { type: 'empty' },
                    receiptPropsBottom,
                    { type: 'empty' },
                    receiptFooterText,
                    { type: 'empty' },
                    { type: 'empty' },
                    { type: 'empty' }
                  ];

      var out = receipt.create(create);
    }else if(receiptConf.isCloseRegister){
      var create = [
                    receiptHead,
                    { type: 'empty' },
                    receiptPropsHead,
                    { type: 'ruler' },
                    receiptTable,
                    { type: 'ruler' },
                    { type: 'empty' },
                    receiptSaleDetails,
                    { type: 'empty' },
                    { type: 'empty' },
                    { type: 'empty' }
                  ];

      var out = receipt.create(create);
    }

    return out;
  },
  config    : {
    TINname         : 'RUC',
    NoCustomerName  : '',
    NoCustomerTIN   : '',
    taxName         : 'IVA'
  },
  isTicket  : function(size){
    if(size == 'receipt57' || size == 'receipt76' || size == 'receipt80'){
      return true;
    }else{
      return false;
    }
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
  unHTML  : function(html,modify){

    if(modify == 'decode'){
      html = ducumentPrintBuilder.isBase64(html);
    }

    var htmld = $('<div/>').html(html).text().replace(/<div\s*\/?>/mg,"\n");
    return $('<p>' + htmld + '</p>').text();
  },
  base64_decode : function(b64) {
    return decodeURIComponent(escape(atob(b64)));
  },
  base64_encode : function(data) {
    return btoa(unescape(encodeURIComponent(data)));
  },
  isBase64 : function(str) {

    if(!str){
      return false;
    }

    if (str === '' || str.trim() === ''){ return str; }
    try {
      var dec = ducumentPrintBuilder.base64_decode(str);
      if(ducumentPrintBuilder.base64_encode(dec) == str){
        return decodeURIComponent(dec);
      }else{
        return decodeURIComponent(str);
      }
    } catch (err) {
        return str;
    }
  },
  markupt2HTML : function(options){
    var text = options.text ? options.text : '';
    var type = options.type ? options.type : 'HtM';//or MtH
    var $el  = options.el;

    var HtMrules = [
      {find : '<br>', replace : '\n'},
      {find : '<br/>', replace : '\n'},
      {find : '<br />', replace : '\n'},
      {find : '<b>', replace : '*'},
      {find : '</b>', replace : '*'},
      {find : '<strong>', replace : '*'},
      {find : '</strong>', replace : '*'},
      {find : '<em>', replace : '_'},
      {find : '</em>', replace : '_'},
      {find : '<i>', replace : '_'},
      {find : '</i>', replace : '_'},
      {find : '</i>', replace : '_'},
      {find : '<li>', replace : '- '},
      {find : '</li>', replace : ''},
      {find : '<u>', replace : '~'},
      {find : '</u>', replace : '~'},
      {find : '&nbsp;&nbsp;•&nbsp;;', replace : '- '},
      {find : '<div>', replace : '\n'},
      {find : '</div>', replace : ''},
      {find : '<p>', replace : '\n'},
      {find : '</p>', replace : ''}
    ];

    var MtHrules = [
        {find : /(\*)(.*)\1/g, replace : '<strong>$2</strong>'},
        {find : /(_)(.*)\1/g, replace : '<em>$2</em>'},
        {find : /(~)(.*)\1/g, replace : '<u>$2</u>'},
        {find : /(- )(.*)/g, replace : '&nbsp;&nbsp;•&nbsp;; $2'},
        {find : /\n/g, replace : '<br>'},
        {find : /```(.*)```/g, replace : '<pre>$1</pre>'}
    ];

    if(type == 'HtM'){

      $.each(HtMrules,function(i,rule){
          text = text.split(rule.find).join(rule.replace);
      });

      text = ducumentPrintBuilder.stripHTML(text);

      if($el){
        $el.text(text);
        $el.val(text);    
      }else{
        return text;
      }

    }else{
      text = ducumentPrintBuilder.stripHTML(text);

      $.each(MtHrules,function(i,rule){
        text = text.replace(rule.find, rule.replace);
      });

      if($el){
          $el.html(text);
      }else{
        return text;
      }
    } 
  },
  stripHTML : function(text){
    var text2   = ducumentPrintBuilder.isBase64(text);
    var out   = $('<div/>').html(text2).text();
    return out;
  },
  processData: function(conf,data){

    var receiptConf = {};
    receiptConf.isTicket  = false;
    receiptConf.isHTML    = false;
    receiptConf.chars     = 0;
    receiptConf.space     = ' ';
    receiptConf.EOL       = '<br>';

    if(isTicket(conf.page_size)){
      receiptConf.isTicket  = conf.page_size;
      receiptConf.isHTML    = true;
      receiptConf.space     = ' ';

      if(conf.page_size == 'receipt57'){
        receiptConf.chars     = 35;
      }else if(conf.page_size == 'receipt76'){
        receiptConf.chars     = 42;
      }else{
        receiptConf.chars     = 50;
      }
    }

    var rows      = ducumentPrintBuilder.build(conf,data,receiptConf,true);

    if(!receiptConf.isTicket){
      var html    = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important;font-size:'+window.fontSize+'!important; color: black;}</style></head><body>' +rows+ '</body></html>';
    }else{
      if(receiptConf.isHTML){
        var html  = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}pre,*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important; font-size:'+window.fontSize+'!important; text-transform: uppercase!important;}</style></head><body><pre style="margin-left:'+window.receipt_left_margin+'mm;">' +rows+ '</pre></body></html>';
      }else{
        var html = rows;
      }
    }

    ducumentPrintBuilder.printAction(html);
    //console.log(html);
  },
  pxTomm: function(px,mymm){
    return Math.floor(px/mymm); //JQuery returns sizes in PX
  },
  mmTopx: function(mm,mymm){   
    return Math.ceil(mm*mymm); //JQuery returns sizes in PX
  },
  findMm: function(){
    $('body').append('<div id="find_mm" style="height:1mm!important;"></div>');
    var mm = $('#find_mm').height();//obtengo 1mm en px
    $('#find_mm').remove();
    return mm;
  },
  spaceW: 7.98,
  spaceH: 15,
  plainBuild: function(arr){
    ducumentPrintBuilder.sortOn(arr,"left");
    ducumentPrintBuilder.sortOn(arr,"top");
    var out = [];
    var prevTop = 0;
    var prevLeft = 0;
    $(arr).each(function(i,v) {
      var left    = Math.round(v.left/ducumentPrintBuilder.spaceW);
      var top     = Math.round(v.top/ducumentPrintBuilder.spaceH);
      var width   = Math.round(v.width/ducumentPrintBuilder.spaceW);
      var height  = Math.round(v.height/ducumentPrintBuilder.spaceH);
      var text    = iftn(v.text,'Test');
      
      var newtop = top - prevTop;
      if (top == prevTop) {
        var newleft = left - prevLeft;
      } else {
        var newleft = left;
      }

      out += ducumentPrintBuilder.plainRow(newleft, newtop, width, text, height);

      prevTop = top;
      prevLeft = left + width;      
    });
    return out;
  },
  plainRow: function(left, top, width, text, height) {
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
  },
  toTitleCase: function(str){
    return str.replace(/\w\S*/g, function(txt){return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();});
  }
};