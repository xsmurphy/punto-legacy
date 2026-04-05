
window.isUserActive = true;

var colorPrimary 	= '#17c3e5';
var colorDanger 	= '#f05050';
var colorWarning 	= '#fad733';
var colorInfo 		= '#4cb6cb';
var colorSuccess 	= '#1ab667';
var colorDefaultBg	= '#e8eff0';
var colorDark		= '#5a6a7a';
var colorGray		= '#f2f5f5';
var colorWhite		= '#ffffff';
//charts
var chartGridColors 	= 'rgba(217,228,230,1)';
var chartSecondColor	= '#778490';
var chartAxisFotnColor	= '#939aa0';
var chartFotnFamily		= "'Source Sans Pro', 'Helvetica Neue', 'Helvetica', 'Arial'";
var chartTooltipBg 		= 'rgba(77,93,110,1)';
var chartTooltipBgH     = '#4d5d6e';
var chartTooltipFontColor = '#eaeef1';

var chartTooltipStyle = {
								tooltips: {
				            		backgroundColor : chartTooltipBg,
				            		displayColors  	: false,
				            		xPadding 		: 15,
				            		yPadding 		: 10,
				            		cornerRadius 	: 10,
						            callbacks 		: {
						            	title: function(item, data) {
								          return data['labels'][item[0]['index']];
								        },
								        label: function(item, data) {
								          var value = data['datasets'][0]['data'][item['index']];
								          var out 	= value;
								          if($.isNumeric(value)){
								          	out 	= formatNumber(value,'',decimal,thousandSeparator);
								          }
								          return out;
								        }
								        /*afterLabel: function(tooltipItem, data) {
								          var dataset = data['datasets'][0];
								          var percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][0]['total']) * 100)
								          return '(' + percent + '%)';
								        }*/
						            }
						        }
							};

var chartTooltipStyleFn = function(){
	return {
				tooltips: {
            		backgroundColor : chartTooltipBg,
            		displayColors  	: false,
            		xPadding 		: 15,
            		yPadding 		: 10,
            		cornerRadius 	: 10,
		            callbacks 		: {
		            	title: function(tooltipItem, data) {
				          return data['labels'][tooltipItem[0]['index']];
				        },
				        label: function(tooltipItem, data) {
				          var value = data['datasets'][0]['data'][tooltipItem['index']];
				          var out 	= value;
				          if($.isNumeric(value)){
				          	out = formatNumber(value,'',decimal,thousandSeparator);
				          }
				          return out;
				        }
				        /*afterLabel: function(tooltipItem, data) {
				          var dataset = data['datasets'][0];
				          var percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][0]['total']) * 100)
				          return '(' + percent + '%)';
				        }*/
		            }
		        }
			}
}

var chartLineGraphOptions = {
								legend : {
									labels : {

									}
								},
								scales: {
								  xAxes: [{
								      	display 		: false,
								      	ticks 			: {
								            beginAtZero : true,
								            fontColor 	: chartAxisFotnColor,
								            fontFamily 	: chartFotnFamily
								        },
								        gridLines 		: {
								          color 		: chartGridColors,
								          lineWidth 	: 1
								        },
								        zeroLineColor 	: chartGridColors,
								        offset 			: true
								  }],
								  yAxes: [{
								        ticks: {
								            beginAtZero 	: true,
								            fontColor 		: chartAxisFotnColor,
								            fontFamily 		: chartFotnFamily,
								            callback: function(value, index, values) {
			                                    return formatNumber(value,'',decimal,thousandSeparator,false,false,true);
			                                }
								        },
								        gridLines: {
								          color: chartGridColors,
								          lineWidth: 1
								        },
								        zeroLineColor 	: chartGridColors
								    }]
								},
								pan: {
								    enabled: true,
								    mode: 'xy'
								},
								zoom: {
								    enabled: true,
								    mode: 'xy',
								},
								tooltips: {
				            		backgroundColor: chartTooltipBg,
				            		displayColors  	: false,
				            		xPadding 		: 15,
				            		yPadding 		: 10,
				            		cornerRadius 	: 10,
						            callbacks: {
						                labelColor: function(tooltipItem, chart) {
						                    return {
						                        backgroundColor: chartTooltipBg
						                    }
						                },
						                labelTextColor:function(tooltipItem, chart){
						                    return chartTooltipFontColor;
						                },
						                label: function(tooltipItem, chart){
			                                var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
			                                return datasetLabel + ': ' + formatNumber(tooltipItem.yLabel,'',decimal,thousandSeparator);
			                            }
						            }
						        }
							};

var chartBarStackedGraphOptions = {
									cornerRadius: 5,
							      	title: {
										display: false
									},
									legend : {
										labels : {
											
										}
									},
									responsive: true,
									scales: {
										xAxes: [{
											display: false,
											stacked: false,
											ticks: {
									            beginAtZero 	: true,
									            fontColor 		: chartAxisFotnColor,
									            fontFamily 		: chartFotnFamily
									        },
									        gridLines: {
									          color: chartGridColors,
									          lineWidth: 1
									        },
									        zeroLineColor 	: chartGridColors,
									        offset 			: true
										}],
										yAxes: [{
											stacked: false,
											ticks: {
									            beginAtZero 	: true,
									            fontColor 		: chartAxisFotnColor,
									            fontFamily 		: chartFotnFamily,
									            callback: function(value, index, values) {
				                                    return formatNumber(value,'',decimal,thousandSeparator,false,false,true);
				                                }
									        },
									        gridLines: {
									          color: chartGridColors,
									          lineWidth: 1
									        },
									        zeroLineColor 	: chartGridColors
										}]
									},
									tooltips: {
					            		backgroundColor: chartTooltipBg,
					            		displayColors  	: false,
					            		xPadding 		: 15,
					            		yPadding 		: 10,
					            		cornerRadius 	: 10,
							            callbacks: {
							                labelColor: function(tooltipItem, chart) {
							                    return {
							                        backgroundColor: chartTooltipBg
							                    }
							                },
							                labelTextColor:function(tooltipItem, chart){
							                    return chartTooltipFontColor;
							                },
							                label: function(tooltipItem, chart){
				                                var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
				                                return datasetLabel + ': ' + formatNumber(tooltipItem.yLabel,'',decimal,thousandSeparator);
				                            }
							            }
							        }
							      };



function colorFromValueForCharts(value, border) {
	var alpha = (1 + Math.log(value)) / 5;
	var color = "#62bcce";
	if (border) {
		alpha += 0.01;
	}
	return Chart.helpers.color(color).alpha(alpha).rgbString();
}

function switchit(callback,reset,el) {
	thalog('switchit fn');
	var $el = el ? el + ', ' + el + ' .swinner' : '.switch-select, .swinner';
	onClickWrap($el,function(event,tis){
		var $input = tis.find('input');

		if(tis.hasClass('disabled')){
			return false;
		}

		if(tis.hasClass('selected')){
			tis.removeClass('selected');
			$input.attr('checked',false);
			var active = false;
		}else{
			tis.addClass('selected');
			$input.attr('checked','checked');
			var active = true;
		}
		callback && callback(tis,active);
	},true,reset);
}

var ncmHelpers = {
	fetchingUrl : '',
	cloneObj 	: function(obj) {
	    return JSON.parse( JSON.stringify(obj) );
	},
	playSound : function(type){
		var snd = 'https://assets.encom.app/sounds/payment_success.m4a';

		if(type == 'error'){
		 	snd = 'https://assets.encom.app/sounds/payment_failure.m4a';
		}else if(type == 'newOrder'){
			snd = 'https://assets.encom.app/sounds/new_order_sound.mp3';
		}

		if(type == 'reset'){
		  $('#sound').html('');         
		}else{
			Notification.requestPermission().then(function(permission) {
				if (permission === 'granted') {	
					var audio = new Audio(snd);
					audio.play();
				}
			});
			//$('#sound').append('<audio class="audios" id="yes-audio" controls preload="true" autoplay> <source src="' + snd + '" type="audio/mpeg"> </audio>');
			
		}
	},
	load : function(options){
		var url 		= ('url' in options) ? options.url : '',
			success 	= ('success' in options) ? options.success : '',
			fail 		= ('fail' in options) ? options.fail : '', 
			hideloader 	= ('hideLoader' in options) ? options.hideLoader : false,
			data 		= ('data' in options) ? iftn(options.data,{}) : {},
			httpType 	= ('httpType' in options) ? options.httpType : 'POST',
			dataType 	= ('type' in options) ? options.type : 'text',
			$container 	= ('container' in options) ? options.container : '',
			contentType = ('contentType' in options) ? options.contentType : 'application/x-www-form-urlencoded',
			headers 	= ('headers' in options) ? options.headers : {},
			timeout 	= ('timeout' in options) ? options.timeout : 60000,
			warnTimeout = ('warnTimeout' in options) ? options.warnTimeout : true;

		if(!hideloader){
			ncmHelpers.loadIndicator({container:$container,status:'show'});
		}

	    var loadtimeoutID = window.setTimeout(function(){
			 ncmDialogs.toast('Todavía Procesando...','info');
		}, timeout / 2);

		return $.ajax({
					    url 		: url,
					    data 		: data,
					    type 		: httpType,
					    dataType 	: dataType,
					    headers 	: headers,
					    contentType : contentType,
					    timeout 	: timeout,
					    success 	: function(data){
					    	window.clearTimeout(loadtimeoutID);
					    	ncmHelpers.loadIndicator();
					        success && success(data);
					    },
					    error 		: function(xmlhttprequest, textstatus, message) {
					    	window.clearTimeout(loadtimeoutID);
					    	ncmHelpers.loadIndicator();
					    	if(textstatus==="timeout" && warnTimeout) {
					            ncmDialogs.confirm('Demora más de lo normal','Puede que haya mucha información que descargar, presione en Aceptar para volver a intentar','warning',function(a){
									if(a){
							  			location.reload();
							  		}else{
							  			fail && fail('false');
							  		}
							  	});
					        } else {
					        	fail && fail('false');
					        }					        
					    }
					});
	},
	loadIndicator : function(options){
		var container 	= validity(options,false,true,'container') ? options.container : 'body',
		$container 		= $(container),
		status 			= validity(options,false,true,'status'),
		ref 			= container.split('.').join("").split('#').join("");

		if(status == 'show'){
			$container.parent().css('position','relative');
			var svg = '<img class="spinnerLoad-' + ref + '" style="position: fixed; left:50%; top:40%; z-index:9999999; margin-left: -30px;" src="/images/svg-loaders/puff.svg" width="60">';
			$container.append(svg);
		}else{
			$('.spinnerLoad-' + ref).remove();
		}
	},
	btnIndicator : function(options){
		var $btn 		= validity(options,false,true,'btn') ? options.btn : false,
			eText 		= validity(options,false,true,'enabledText') ? options.enabledText : 'Enviar',
		 	dText 		= validity(options,false,true,'disabledText') ? options.disabledText : 'Procesando',
		 	status 		= validity(options,false,true,'status') ? options.status : 'load';

		if(status == 'disable'){
			$btn.attr('disabled','disabled').addClass('disabled').html(dText);
		}else{
			$btn.removeAttr('disabled').removeClass('disabled').html(eText);
		}	
	},
	loginInputUserManager : function(options){

		var $input 	= options.input,
		$areacode 	= options.areacode, 
		$inputWrap 	= options.inputWrap,
		geoURL 		= 'https://extreme-ip-lookup.com/json/',
		flagsCDN 	= 'https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.4.3/flags/1x1/',
		justLoad 	= options.load;

		if(justLoad){
			$.get(geoURL,function(ip){
		    	var k = (ip && ip.countryCode && countries[ip.countryCode]) ? ip.countryCode : 'PY';

			    var val 		= countries[k];
		        var selected 	= '<img src="' + flagsCDN + k.toLowerCase() + '.svg" width="20"> <span class="font-bold text-md m-l-sm selectedPhoneCode" data-country="' + k + '">+' + val.phone + '</span>';
		        $('.countriesBtn').html(selected);
		     }).fail(function(){
		     	var k = 'PY';
		     	var val = countries[k];
		     	var selected = '<img src="' + flagsCDN + k.toLowerCase() + '.svg" width="20"> <span class="font-bold text-md m-l-sm selectedPhoneCode" data-country="' + k + '">+' + val.phone + '</span>';
		     	$('.countriesBtn').html(selected);
		     });

		    $.each(countries,function(k,val){
				var row 	= 	'<li><a href="#" class="signInCountry" data-country="' +  k.toLowerCase() + '" data-code="' + val.phone + '"><img src="' + flagsCDN + k.toLowerCase() + '.svg" width="20">' +
								'<span class="font-bold text-md m-l-sm">+' +  val.phone + '</span></a></li>';
			  	var before 	= $('.signInCountriesList').html();
				$('.signInCountriesList').html(before + row);
			});

			onClickWrap('.signInCountry',function(event,tis){
		    	var k 			= tis.data('country');
		    	var phone 		= tis.data('code');

		    	$('.signInCountry').removeClass('bg-light');
		    	tis.addClass('bg-light');

		        var selected 	= 	'<img src="https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.4.3/flags/1x1/' + k.toLowerCase() + '.svg" width="20">' +
		        					'<span class="font-bold text-md m-l-sm selectedPhoneCode" data-country="' + k + '">+' + phone + '</span>';
		        $('.countriesBtn').html(selected);	
		    });
		}else{
			var val = $input.val();
			if($.isNumeric(val) || !validity(val)){
				$areacode.show();
				$inputWrap.removeClass('col-xs-12').addClass('col-xs-9');
			}else if(validity(val,'string')){
				$areacode.hide();
				$inputWrap.removeClass('col-xs-9').addClass('col-xs-12');
			}
		}
	},
	trackPage : function(page,title){
		ga('set', 'page', page);
		ga('set', 'title', title);
		ga('send', 'pageview');
	},
	unHashUrl : function(){
		var noHashURL = window.location.href.replace(/#.*$/, '');
		window.history.replaceState('', document.title, noHashURL);
	},
	reHashUrl : function(page){
		page = page ? page : ncmHelpers.loadPageCurrent;
		var noHashURL = window.location.href.replace(/#.*$/, '#' + page);
		window.history.replaceState('', document.title, noHashURL);
	},
	loadedPageCache : [],
	loadPageLoad 	: true,
	loadPageCurrent : '',
	loadPageRefresh : function(nocache,goto){
		ncmHelpers.hashNoCache 	= nocache;
		var current 			= goto ? goto : window.location.hash.substring(1);
		ncmHelpers.loadPageLoad = false;
		window.location.hash 	= '#!';
		setTimeout(function(){
			ncmHelpers.loadPageLoad = true;
			window.location.hash 	= '#' + current;
		},100);
		
	},
	isPageHashed 	: function(){
		var hash = window.location.hash.split('#')[1];
		if(!hash || hash == 'undefined'){
			return false;
		}else{
			return hash;
		}
	},
	preCachePages : function(popPages){
		if(popPages){
			$.each(popPages,function(i,page){
				var currHash = window.location.hash.substring(1);
				if(page != currHash && !ncmHelpers.loadedPageCache[page]){
					$.get('/a_' + page,function(content){
						ncmHelpers.loadedPageCache[page] = content;
					});
				}
			});
		}
	},
	hashNoCache 	: false,
	loadPageOnHashChange : function(options){
		$(window).off('hashchange hashcheck').on('hashchange hashcheck', function(){

			if(!ncmHelpers.loadPageLoad){
				return false;
			}

			if(window.onbeforeunload){
				ncmDialogs.confirm('Los cambios no se gardarán','¿Desea Continuar?','warning',function(e){
					if(e){
						ncmHelpers.loadOnHashFn(options);
						window.onbeforeunload = null;
					}else{
						ncmHelpers.reHashUrl();
					}
				});
			}else{
				ncmHelpers.loadOnHashFn(options);
			}
			
		});
	},
	setPageTitle : function(){
		var title 		= $.trim($('#pageTitle').text());
		title 			= title.replace('help_outline','').replace('live_help','');

		document.title 	= iftn(title,'Panel de Control - ENCOM');
		return title;
	},
	loadOnHashFn : function(options){
		var rawHash 	= window.location.hash.substring(1);
		var hashs 		= rawHash.split('&');
		var hash 		= hashs[0];
		hash 			= rawHash;

		/*var hVar 		= rawHash.split('&').reduce(function (result, item) {
		    var parts 	= item.split('=');
		    result[parts[0]] = parts[1];
		    return result;
		}, {});*/

		if(!hash){
			window.location.hash = '#dashboard';
		}

		if($('.modal').is(':visible')){
			$('.modal').off('shown.bs.modal,hidden.bs.modal,show.bs.modal').modal('hide').one('hidden.bs.modal',function(){
				setTimeout(function(){
					window.location.hash = hash;
				},200);
			});
			return false;
		}

		$('.modal').off('show.bs.modal shown.bs.modal').on('show.bs.modal shown.bs.modal',function(){
			ncmUI.setDarkMode.autoSelected();
		});

		var container 	= '#bodyContent';
		var cache 		= [];

		if( hash ){
			
			//scrollToTopNcm('remove');
			//scrollToBottomNcm('remove');

			options.onBefore && options.onBefore();

			if(isMobile.phone){
				window.snapper.close();
			}

			$.each(window.xhrs,function(i,val){
				if(validity(val)){
					val.abort();
				}
			});

			window.xhrs = [];

			if(ncmHelpers.loadedPageCache[hash] && !ncmHelpers.hashNoCache){
				//console.log(hash,'loaded from cache');
				$(container).html(ncmHelpers.loadedPageCache[hash]);

				ncmHelpers.setPageTitle();
				ncmHelpers.loadPageCurrent = hash;
				ncmHelpers.trackPage(ncmHelpers.loadPageCurrent,document.title);
				ncmUI.setDarkMode.autoSelected();
			}else{
				//console.log(hash,'loaded from url');
				var xhr = ncmHelpers.load({
							url 		: '/a_' + hash,
							container 	: container,
							success 	: function(data){
								if(data){
									ncmHelpers.loadedPageCache[hash] = data;
									$(container).html(data);

									ncmHelpers.setPageTitle();

									ncmHelpers.loadPageCurrent = hash;

									ncmHelpers.trackPage(ncmHelpers.loadPageCurrent,document.title);

									/*window.history.pushState({
									    id : hash
									}, title, 'https://panel.encom.app/@#' + hash);	*/

									options.onAfter && options.onAfter();

									ncmUI.setDarkMode.autoSelected();

								}
								ncmHelpers.hashNoCache 	= false;
							},
							fail 		: function(){
								$.get('/a_404.php',function(data){
									ncmHelpers.loadedPageCache['404'] = data;
									$(container).html(data);
									document.title = 'Página no encontrada';
								});
								ncmHelpers.hashNoCache 	= false;
							}
						});

				window.xhrs.push(xhr);
			}
		}
	},
	mustacheIt : function($template,data,$wrap,returns){
		var template 	= $template.html();
		var mustached 	= Mustache.render(template, data);

		if(returns){
			return mustached
		}else{
			$wrap.html(mustached);
		}
	},
	delayKeyUp : function(fn, ms) {
		let timer = 0
		return function(...args) {
	    	clearTimeout(timer)
	    	timer = setTimeout(fn.bind(this, ...args), ms || 0)
		}
	},
	validate : function(value,force,isObj,val){
		try{
			if(isObj){
				if(value.hasOwnProperty(val)){
					return ncmHelpers.validate(value[val]);
				}
			}

			if(jQuery.type(value) === "undefined"){
				return false;
			}else{
				if(!value || value === null){
				  return false;
				}

				if(force){
					if(force == 'email'){
						return validateEmail(value);
					}else if(jQuery.type(value) === force){
						return ncmHelpers.validate(value);
					}else{
						return false;
					}
				}else{
				  if(jQuery.type(value) === "number"){
				    if(value < 0.00001){
				      return false;
				    }
				  }else if(jQuery.type(value) === "string"){
				    if(value.length < 1){
				      return false;
				    }
				  }else if(jQuery.type(value) === "array"){
					if(value.length < 1 || !ncmHelpers.validate(value[0])){
				      return false;
				    }
				  }else if(jQuery.type(value) === "object"){
				    if(Object.keys(value).length < 1){
				      return false;
				    }
				  }
				}

				return value;

			}

		}catch(t){
	  		return false;
	  	}
	},
	validInObj : function(obj,val){
		return ncmHelpers.validate(obj,false,true,val);
	},
	validity : function(value,force,isObj,val){
		return ncmHelpers.validate(value,force,isObj,val);
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
		  var dec = ncmHelpers.base64_decode(str);
		  if(ncmHelpers.base64_encode(dec) == str){
		    return decodeURIComponent(dec);
		  }else{
		    return decodeURIComponent(str);
		  }
		} catch (err) {
		    return str;
		}
	},
	defaultEvents : function(){
		onClickWrap('.print',function(event,tis){
			var el = tis.data('element');
			$(el).print();
		},false,true);
	},
	stripHTML : function(text){
		text = ncmHelpers.isBase64(text);
		text = $('<div/>').html(text).text();
		return text;
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
			{find : '&nbsp;&nbsp;•&nbsp;', replace : '- '},
			{find : '<div>', replace : '\n'},
			{find : '</div>', replace : ''},
			{find : '<p>', replace : '\n'},
			{find : '</p>', replace : ''}
		];

		var MtHrules = [
		    {find : /(\*)(.*)\1/g, replace : '<strong>$2</strong>'},
		    {find : /(_)(.*)\1/g, replace : '<em>$2</em>'},
		    {find : /(~)(.*)\1/g, replace : '<u>$2</u>'},
		    {find : /(- )(.*)/g, replace : '&nbsp;&nbsp;•&nbsp; $2'},
		    {find : /\n/g, replace : '<br>'},
		    {find : /```(.*)```/g, replace : '<pre>$1</pre>'}
		];

		function linkify(inputText) {
		    var replacedText, replacePattern1, replacePattern2, replacePattern3;

		    replacePattern1 = /(\b(https?|ftp):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/gim;
		    replacedText 	= inputText.replace(replacePattern1, '<a href="$1" target="_blank" style="color:#000;">$1</a>');

		    replacePattern2 = /(^|[^\/])(www\.[\S]+(\b|$))/gim;
		    replacedText 	= replacedText.replace(replacePattern2, '$1<a href="http://$2" target="_blank" style="color:#000;">$2</a>');

		    return replacedText;
		}

		if(type == 'HtM'){
			$.each(HtMrules,function(i,rule){
			  	text = text.split(rule.find).join(rule.replace);
			});

			text = ncmHelpers.stripHTML(text);

			if($el){
			    $el.text(text);
				$el.val(text);    
			}else{
				return text;
			}
		}else{
			text = ncmHelpers.stripHTML(text);

			$.each(MtHrules,function(i,rule){
				text = text.replace(rule.find, rule.replace);
			});

			text = linkify(text);

			if($el){
			    $el.html(text);
			}else{
				return text;
			}
		}
	},
	onClickWrap : function(element,callback,propagate,opts){
		if(jQuery.type('click') === 'string'){
			$element = $(element);
		}else{
			$element = element;
		}

		var event 		= 'click';

		$(document).off(event,element).on(event,element,function(event){
			event.preventDefault();

			var $tis 	= $(this);

			if (ncmHelpers.validity(propagate) || $tis.data('propagate')) {
				if(!$(event.target).is(element)){
					return;
				}
			}else{
				event.stopPropagation();
			}

			var animate = $tis.data('animation');

			if(animate){
				$tis.removeClass(animate + ' fadeInDown ').addClass('animated ' + animate).afterAnimation(function(){
					$tis.removeClass('animated ' + animate);
				});
			}

			if(event.shiftKey){
				if(ncmHelpers.validate(opts,false,true,'shiftKeyCallBack')){
					opts.shiftKeyCallBack && opts.shiftKeyCallBack(event,$(this));
				}
			}else{
				callback && callback(event,$tis);
			}
		});	
	},
	copyTextToClipBoard : function($el) {
		$element = $el[0];
	    var range;
	    
	    if(document.selection){ // IE
	        range = document.body.createTextRange();
	        range.moveToElementText($element);
	        range.select();
	        document.execCommand("copy");
	    }else if(window.getSelection){
	        range = document.createRange();
	        range.selectNode($element);
	        window.getSelection().removeAllRanges();
	        window.getSelection().addRange(range);
	        document.execCommand("copy");
	    }

	    setTimeout(function(){
	    	window.getSelection().removeAllRanges();
	    },300);
	},
	getDistanceInKM : function(lat1, lon1, lat2, lon2) {
		var rad = function(x) {
			return x * Math.PI / 180;
		}

		lat1 = parseFloat(lat1);
		lon1 = parseFloat(lon1);
		lat2 = parseFloat(lat2);
		lon2 = parseFloat(lon2);

		var R 		= 6378.137; //Radio de la tierra en km
		var dLat 	= rad(lat2 - lat1);
		var dLong 	= rad(lon2 - lon1);
		var a 		= Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLong / 2) * Math.sin(dLong / 2);
		var c 		= 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
		var d 		= R * c;
		return d.toFixed(2); //Retorna tres decimales
	},
	getUserLocation : function(callback,onDenied) {
		var msgtitle 	= '¿Activar geolocalización?';
		var msgdesc 	= 'Necesitamos obtener su ubicación para mostrarle la distancia en el mapa';
		var nomsgtitle 	= 'No pudimos obtener su ubicación';
		if(navigator.permissions){
			navigator.permissions.query({name:'geolocation'}).then(function(result) {
				if (result.state == 'granted') {
					navigator.geolocation.getCurrentPosition(callback,onDenied);
				} else if (result === 'default') {
					ncmDialogs.toast(nomsgtitle,'warning');
					onDenied && onDenied();
				} else if (result.state == 'prompt') {
					ncmDialogs.confirm(msgtitle,msgdesc,'question',function(a){
						if(a && navigator.geolocation){
				  			navigator.geolocation.getCurrentPosition(callback,onDenied);
				  		}else{
				  			ncmDialogs.toast(nomsgtitle,'warning');
				  		}
				  	});
				} else if (result.state == 'denied') {
					ncmDialogs.toast(nomsgtitle,'warning');
					onDenied && onDenied();
				}

				result.onchange = function() {
					
				}
			});
		}else{
			ncmDialogs.confirm(msgtitle,msgdesc,'question',function(a){
				if(a && navigator.geolocation){
		  			navigator.geolocation.getCurrentPosition(callback,onDenied);
		  		}else{
		  			ncmDialogs.toast(nomsgtitle,'warning');
		  		}
		  	});
		}
	},
	fullScreenTextSearch : function(element,searchBox){
		jQuery.expr[":"].Contains = jQuery.expr.createPseudo(function(arg) {
		    return function( elem ) {
		        return jQuery(elem).text().toUpperCase().indexOf(arg.toUpperCase()) >= 0;
		    };
		});

		$(searchBox).on('keyup',function(){
			var tis 	= $(this);
			var value 	= tis.val();

			if(value.length > 2){
				$(element).hide();
				$('.fullSearchHide').hide();

				var $found = $(element + ':Contains("' + value + '")');
				if($found.length){

					$found.each(function(){
						$(this).show();

						var family = $(this).data('family');
						$('[data-family="' + family + '"]').each(function(){
							$(this).show();
						});
					});
				}
			}else{
				$(element).show();
				$('.fullSearchHide').show();
			}
		});
	},
	coorsParser: function(url) {
	    if (!url) {
	      return false;
	    }

	    var lat = false;
	    var lng = false;

	    //check if are coors instead url
	    var chkCoors    = url.split(',');
	    var chLat       = parseFloat(chkCoors[0]);
	    var chLng       = parseFloat(chkCoors[1]);
	    if (!isNaN(chLat) && !isNaN(chLng)) {
	      return {
	        'lat': chLat,
	        'lng': chLng
	      };
	    }

	    var separators  = '/@';
	    var separators2 = '!3d';

	    if (url.indexOf(separators) > -1) {//es tomado del mapa, las coords estan al final de la url

	        if (url.indexOf(separators2) > -1) {
	            var part    = url.split('!3d');
	            part        = part[1];
	            var coors   = part.split('!4d');
	        }else{
	            var part    = url.split(separators);
	            part        = part[1];
	            var coors   = part.split(',');
	        }
	        
	        lat         = parseFloat(coors[0]);
	        lng         = parseFloat(coors[1]);

	        if(!lat || !lng){
	            var part    = url.split(separators);
	            part        = part[1];
	            var coors   = part.split(',');
	            lat         = parseFloat(coors[0]);
	            lng         = parseFloat(coors[1]);
	        }

	    } else {
	        try{
	            var parsed  = new URL(url);
	            var q       = parsed.searchParams.get('q');
	            var coors   = q.split(',');
	            lat         = parseFloat(coors[0]);
	            lng         = parseFloat(coors[1]);
	        } catch(error){
	            
	        }
	    }

	    if (isNaN(lat) || isNaN(lng)) {
	    	return false;
	    } else {
			return {
				'lat': lat,
				'lng': lng
			};
	    }
	},
	setCurrency : function(setUrl,id){
		$('.modal').modal('hide');

		var ids = '';
		if(id){
			ids = '&id=' + id;
		}
		$.get(setUrl + '?action=setCurrencies' + ids,function(data){
	        var table     = '<div class="col-xs-12 wrapper panel m-n" id="setCurrenciesList">' +
	                        ' <div class="col-xs-12 text-center text-u-c font-bold m-b">Monedas</div>' + 
	                        ' <table class="table bg-white m-n">' +
	                        '   <tbody>';
	        var flagsCDN  = 'https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.4.3/flags/1x1/';

	        $.each(data,function(i,val){
	          table +=  '<tr>' +
	                    '   <td class="font-bold">' +
	                    '     <div class="m-t-xs">' +
	                    '       <img src="' + flagsCDN + val.ccode.toLowerCase() + '.svg" class="m-r-sm" width="20">' + val.code + 
	                    '     </div>' +
	                    '   </td>' +
	                    '   <td>' +
	                    '     <input class="form-control text-right" data-code="' + val.code + '" value="' + val.value + '">';

	          if(val.value > 0){
	            table +='     <div class="text-xs text-right currencyExp' + val.code + '">1 ' + currency + ' = ' + val.value + ' ' + val.code + '</div>';
	          }
	            table +='   </td>' +
	                    '</tr>';
	        });

	        table       += '    </tbody>' +
	                       '  </table>' +
	                       '</div>';

	        setTimeout(function(){
	        	$('#modalTiny').modal('show');
	        	$('#modalTiny .modal-content').html(table);

	        	$('#setCurrenciesList input').off('change').on('change',function(){
					var allCur = [];

					$('#setCurrenciesList input').each(function(){
						var tis     = $(this);
						var value   = tis.val();
						var code    = tis.data('code');
						if(value > 0){
						  allCur.push({'code' : code, 'value' : value});
						}
					});

					var send      = btoa( JSON.stringify(allCur) );
					$.get(setUrl + '?action=setCurrencies&update=' + send + ids,function(){
						ncmDialogs.toast('Guardado','success');
					});
		        });

		        $('#setCurrenciesList input').off('keyup').on('keyup',function(){
		          var tis     = $(this);
		          var value   = tis.val();
		          var code    = tis.data('code');

		          $('.currencyExp' + code).text('1 ' + window.currency + ' = ' + value + ' ' + code);
		        });

	        },500);	        

	        
	    });
	}
};

var helpers = ncmHelpers;

var ncmTypeahead = {
	menuOpen 	: false,
	listId 		: '',
	listIdel 	: '',
	selectedCls : 'bg-light dk',
	init : function(options){
		ncmTypeahead.listId 	= 'ncmTypeaheadList' + mt_rand(2,25);
		ncmTypeahead.listIdel 	= '.' + ncmTypeahead.listId;
		var list = '<div class="' + ncmTypeahead.listId + '" style="max-width:200px;display:none;"><ul class="list-group"></ul></div>';
		$(options.el).after(list);
	 	$(options.el).keydown(function(e) {
		    if (e.keyCode == 13) { // enter
		    	helpers.load({
		    		'url' 			: options.url,
		    		'hideloader' 	: true,
		    		//'type' 			: 'json',
		    		'success' 		: function(data){
		    			var data 	= $.parseJSON(data);
		    			var lis 	= '';

		    			$.each(data,function(i,val){
		    				lis += '<li class="list-group-item">' + val.name + ' / ' + val.ssku + '</li>';
		    			});

		    			if(lis){
		    				$(ncmTypeahead.listIdel).find('ul').html(lis);
		    			}

		    			if ($(ncmTypeahead.listIdel).is(":visible")) {
				        	ncmTypeahead.selectOption();
				        } else {
				        	$(ncmTypeahead.listIdel).show();
				        }
		    		},
		    		'fail' 			: function(){

		    		}
		    	});
		        
		        ncmTypeahead.menuOpen = !ncmTypeahead.menuOpen;
		    }
		    if (e.keyCode == 38) { // up
		        var selected = $("." + selectedCls);
		        $(ncmTypeahead.listIdel + " li").removeClass(selectedCls);
		        if(selected.prev().length == 0) {
		            selected.siblings().last().addClass(selectedCls);
		        }else{
		            selected.prev().addClass(selectedCls);
		        }
		    }
		    if (e.keyCode == 40) { // down
		        var selected = $("." + selectedCls);
		        $(ncmTypeahead.listIdel + " li").removeClass(selectedCls);
		        if (selected.next().length == 0) {
		            selected.siblings().first().addClass(selectedCls);
		        } else {
		            selected.next().addClass(selectedCls);
		        }
		    }
		});

		$(ncmTypeahead.listIdel + ' li').mouseover(function() {
			$(ncmTypeahead.listIdel + ' li').removeClass(selectedCls);
			$(this).addClass(selectedCls);
		}).click(function() {
			ncmTypeahead.selectOption(options.el);
		});
	},
	buildList:function(type,data){
		if(type == 'items'){
		
		}else if(type == 'customers'){
			
		}
	},
	selectOption : function(el) {
		$(el).val($(".selected").text());
		$(".ncmTypeaheadList").hide();
	}
};


function trim(str, charlist) {
  //  discuss at: http://phpjs.org/functions/trim/
  // original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // improved by: mdsjack (http://www.mdsjack.bo.it)
  // improved by: Alexander Ermolaev (http://snippets.dzone.com/user/AlexanderErmolaev)
  // improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // improved by: Steven Levithan (http://blog.stevenlevithan.com)
  // improved by: Jack
  //    input by: Erkekjetter
  //    input by: DxGx
  // bugfixed by: Onno Marsman
  //   example 1: trim('    Kevin van Zonneveld    ');
  //   returns 1: 'Kevin van Zonneveld'
  //   example 2: trim('Hello World', 'Hdle');
  //   returns 2: 'o Wor'
  //   example 3: trim(16, 1);
  //   returns 3: 6

  var whitespace, l = 0,
    i = 0;
  str += '';

  if (!charlist) {
    // default list
    whitespace =
      "\n\r\t\f\x0b\xa0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200a\u200b\u2028\u2029\u3000";
  } else {
    // preg_quote custom list
    charlist += '';
    whitespace = charlist.replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, '$1');
  }

  l = str.length;
  for (i = 0; i < l; i++) {
    if (whitespace.indexOf(str.charAt(i)) === -1) {
      str = str.substring(i);
      break;
    }
  }

  l = str.length;
  for (i = l - 1; i >= 0; i--) {
    if (whitespace.indexOf(str.charAt(i)) === -1) {
      str = str.substring(0, i + 1);
      break;
    }
  }

  return whitespace.indexOf(str.charAt(0)) === -1 ? str : '';
}

var getTaxOfPrice = function(tax,price){	
	if(tax && price && tax > 0){
		var taxVal 		= price / (1 + (tax / 100));
		var total 		= price - taxVal;

		if(total && total > 0){
			return total;
		}else{
			return 0;
		}
	}else{
		return 0;
	}
};

function validateEmail(email) {
    var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(email);
}

function parse_url (str, component) {
	  // http://kevin.vanzonneveld.net
	  // +      original by: Steven Levithan (http://blog.stevenlevithan.com)
	  // + reimplemented by: Brett Zamir (http://brett-zamir.me)
	  // + input by: Lorenzo Pisani
	  // + input by: Tony
	  // + improved by: Brett Zamir (http://brett-zamir.me)
	  // %          note: Based on http://stevenlevithan.com/demo/parseuri/js/assets/parseuri.js
	  // %          note: blog post at http://blog.stevenlevithan.com/archives/parseuri
	  // %          note: demo at http://stevenlevithan.com/demo/parseuri/js/assets/parseuri.js
	  // %          note: Does not replace invalid characters with '_' as in PHP, nor does it return false with
	  // %          note: a seriously malformed URL.
	  // %          note: Besides function name, is essentially the same as parseUri as well as our allowing
	  // %          note: an extra slash after the scheme/protocol (to allow file:/// as in PHP)
	  // *     example 1: parse_url('http://username:password@hostname/path?arg=value#anchor');
	  // *     returns 1: {scheme: 'http', host: 'hostname', user: 'username', pass: 'password', path: '/path', query: 'arg=value', fragment: 'anchor'}
	  var query, key = ['source', 'scheme', 'authority', 'userInfo', 'user', 'pass', 'host', 'port',
	            'relative', 'path', 'directory', 'file', 'query', 'fragment'],
	    ini = (this.php_js && this.php_js.ini) || {},
	    mode = (ini['phpjs.parse_url.mode'] &&
	      ini['phpjs.parse_url.mode'].local_value) || 'php',
	    parser = {
	      php: /^(?:([^:\/?#]+):)?(?:\/\/()(?:(?:()(?:([^:@]*):?([^:@]*))?@)?([^:\/?#]*)(?::(\d*))?))?()(?:(()(?:(?:[^?#\/]*\/)*)()(?:[^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
	      strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*):?([^:@]*))?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
	      loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/\/?)?((?:(([^:@]*):?([^:@]*))?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/ // Added one optional slash to post-scheme to catch file:/// (should restrict this)
	    };

	  var m = parser[mode].exec(str),
	    uri = {},
	    i = 14;
	  while (i--) {
	    if (m[i]) {
	      uri[key[i]] = m[i];
	    }
	  }

	  if (component) {
	    return uri[component.replace('PHP_URL_', '').toLowerCase()];
	  }
	  if (mode !== 'php') {
	    var name = (ini['phpjs.parse_url.queryKey'] &&
	        ini['phpjs.parse_url.queryKey'].local_value) || 'queryKey';
	    parser = /(?:^|&)([^&=]*)=?([^&]*)/g;
	    uri[name] = {};
	    query = uri[key[12]] || '';
	    query.replace(parser, function ($0, $1, $2) {
	      if ($1) {uri[name][$1] = $2;}
	    });
	  }
	  delete uri.source;
	  return uri;
	}

function equalHeight(boxes){
	boxes.height('auto');
	var maxHeight = Math.max.apply( Math, boxes.map(function(){ return $(this).height(); }).get());
	boxes.height(maxHeight);
}

// generate a random number within a range (PHP's mt_rand JavaScript implementation)
function mt_rand (min, max){
	// http://kevin.vanzonneveld.net
	// +   original by: Onno Marsman
	// +   improved by: Brett Zamir (http://brett-zamir.me)
	// +   input by: Kongo
	// *     example 1: mt_rand(1, 1);
	// *     returns 1: 1
	var argc = arguments.length;
	if (argc === 0) {
		min = 0;
		max = 2147483647;
	}
	else if (argc === 1) {
		throw new Error('Warning: mt_rand() expects exactly 2 parameters, 1 given');
	}
	else {
		min = parseInt(min, 10);
		max = parseInt(max, 10);
	}
	return Math.floor(Math.random() * (max - min + 1)) + min;
}
	
$(document).ready(function(){
	// loading state for buttons
	$.ajaxSetup({timeout:50000}); //timeout para ajax requests

	if(jQuery().tooltip) {
    	$('[data-toggle="tooltip"]').tooltip({
		  content: function () {
		      return $(this).prop('title');
		  }
		});
	}

	//$('#datepicker').datepicker({ dateFormat: 'mm/dd/yy' });

	$('body').on('hidden.bs.modal', '.modal', function () {
	    $(this).removeData('bs.modal');
	  });

	onClickWrap('.toggleMenu',function(event,tis){
		//$('#menu').show();
		$('#menu').toggleClass('hidden-xs');
	});

	onClickWrap('[data-toggle*="btn-loading"]',function(event,tis){
		tis.button('loading');
	    setTimeout(function () {
	    	tis.button('reset')
	    }, 3000);
	});

	onClickWrap('.clicker',function(event,tis){
		var type 	= tis.data('type');
		var target 	= tis.data('target');
		if(type == 'toggle'){
			$(target).toggle();
		}
	});

	onClickWrap('.tgInsideBtn',function(event,tis){
	    $(tis).find('.seen').toggle();
		$(tis).find('.see').toggle();
	});

	onClickWrap('.clearField',function(event,tis){
	    var input = $(tis).attr('data-field');
		$(input).val('').focus();
	});

});

var onClickWrap = function(element,callback,propagate,offIt,opts){

	ncmHelpers.onClickWrap(element,callback,propagate,opts);

	/*if(offIt){
		$(document).off('click', element);
	}

	$(document).on('click',element,function(event){
		event.preventDefault();

		if(!propagate){
			event.stopPropagation();
		}

		if(event.shiftKey){
			opts.shiftKeyCallBack && opts.shiftKeyCallBack(event,$(this));
		}else{
			callback && callback(event,$(this));
		}		
	});*/
};

var oneClickWrap = function(element,callback,propagate){ //este metodo solo listen por una vez

	if(jQuery.type(element) === 'string'){
		$element = $(element);
	}else{
		$element = element;
	}

	$element.one('click',function(event){
		thalog('fn oneClickWrap '+element);
		event.preventDefault();
		if(!validityChecker(propagate)){
			event.stopPropagation();
		}
		callback && callback(event,$(this));
	});
};

var onlyClickWrap = function(element,callback,propagate){

	if(jQuery.type('click') === 'string'){
		$element = $(element);
	}else{
		$element = element;
	}

	$element.off('click').on('click',function(event){
		thalog('fn onClickWrap '+element);
		event.preventDefault();
		if(!validityChecker(propagate)){
			event.stopPropagation();
		}
		callback && callback(event,$(this));
	});
};

window.alert = function(msg){
	var preAlert = window.alert;
	if (typeof Swal !== 'undefined') {
		ncmDialogs.alert(msg);
	}else{
		preAlert(msg);
	}
};

function confirmation(text,callback) {
	ncmDialogs.confirm(text,'','question',callback);
}

function prompter(text,callback,defaul) {
	ncmDialogs.prompt(text,defaul,'text',callback);
}

var ncmDialogs = {
	alert : function(msg,type,message){
		var icon 	= 'warning';
		var title 	= msg;
		var message = message ? message : '';

		if(type == 'error' || type == 'danger'){
			icon 	= 'error';
			title 	= 'Error';
			message = msg;
		}else{

		}

		Swal.fire({
		  title: title,
		  text: message,
		  type: icon,
		  confirmButtonText: 'Aceptar'
		});
	},
	confirm : function(title,msg,type,callback,btns){
		btns = btns ? btns : {};
		type = (type == 'danger') ? 'error' : type;
		Swal.fire({
		  title 			: title,
		  text 				: msg,
		  type 				: type,
		  showCancelButton 	: true,
		  confirmButtonText	: ('accept' in btns) ? btns.accept : 'Aceptar',
		  cancelButtonText 	: ('cancel' in btns) ? btns.cancel : 'Cancelar'
		}).then((result) => {
		  if(result.value) {
		    callback && callback(result.value);
		  }else if(result.dismiss === Swal.DismissReason.cancel) {
		    callback && callback(false);
		  }else{
		  	callback && callback(false);
		  }
		},(dismiss) => {
			callback && callback(false);
		});
	},
	prompt : function(title,val,type,callback){
		Swal.fire({
		  title 			: title,
		  input 			: (!type ? 'text' : type),
		  inputValue 		: val ? val : '',
		  showCancelButton 	: true,
		  confirmButtonText	: 'Aceptar',
		  cancelButtonText 	: 'Cancelar',
		  inputPlaceholder 	: title,
		  onOpen 			: function(){
		  	$('.swal2-input').focus();
		  	onClickWrap('.swal2-input', function(event,tis){
		  		tis.focus();
		  	},false,true);
		  },
		  inputValidator 	: (value) => {
		    if (!value) {
		      return 'No puede dejar el campo en blanco'
		    }
		  }
		}).then((result) => {
		  if(result.value) {
		    callback && callback(result.value);
		  }else if(result.dismiss === Swal.DismissReason.cancel) {
		    callback && callback(false);
		  }
		})
	},
	toast : function(message,type,duration){
		type = (type == 'danger') ? 'error' : type;
		var tost = Swal.mixin({
		  toast: true,
		  position: 'top-end',
		  showConfirmButton: false,
		  timer: iftn(duration,3000),
		  timerProgressBar: true,
		  onOpen: (toast) => {
		    toast.addEventListener('mouseenter', Swal.stopTimer)
		    toast.addEventListener('mouseleave', Swal.resumeTimer)
		  }
		});

		tost.fire({
		  type: type,
		  title: message
		});
	},
	push : function(title,msg,sticky){
		var ops = {
				    body 		: msg,
				    icon 		: 'https://app.encom.app/images/iconincomesm.png',
				    timeout 	: 4000,
				    onClick 	: function () {
				        window.focus();
				        this.close();
				    }
				};

		if(sticky){
			ops.timeout = 30000;
		}

		if (typeof Push !== 'undefined') {
			Push.create(title, ops);
		}
	}
};

function message(message,type,duration){
	ncmDialogs.toast(message,type,duration);
	return;
	var logo 	= 'https://app.encom.app/images/iconincomesmwhite.png';
	var danger 	= '/images/toast_danger.png';
	var success = '/images/toast_success.png';
	var textual = '<div class="wrapper-xs wrap-l-sm wrap-r-sm bg-dark rounded text-white text-sm animated fadeInLeft speed-3x" id="toastnlogomsg" style="position:absolute;left:80px;top:30px;">' + message + '</div>';

	if(type == 'warning'){
		type = danger;
	}else if(type == 'success'){
		type = success;
	}else if(type == 'danger'){
		type = danger;
	}else{
		type = success;
	}

	if($('#toastnlogomsg').is(':visible')){
		$('#toastnlogo').attr('src',type).addClass('animated bounceIn');
		$('#toastnlogomsg').text(message);
	}else{
		$('#toastnlogo').attr('src',type).addClass('animated bounceIn');
		$('body').append(textual);

		setTimeout(function() {
			$('#toastnlogo').attr('src',logo).removeClass('animated bounceIn');
			$('#toastnlogomsg').remove();
			spinner('body', 'hide');
		}, iftn(duration,3000));
	}
}

function time () {
  return Math.floor(new Date().getTime() / 1000);
}

function dateRangePickerForReports(start,end,side,time,simple,test){
	$el = $('#customDateR');
	if(isMobile.phone){
		simple = true;
		time   = true;
	}

	if(!start || !end){
		var start 	= moment().subtract(7, 'days');
	    var end 	= moment().endOf('day');
	}

	if(!side){
		side 		= 'right';
	}

	if(!time){
		var timepicker 		= false;
		var timepicker24 	= false;
		var format 			= "YYYY-MM-DD H:mm:ss";
	}else{
		var timepicker 		= true;
		var timepicker24 	= true;
		var format 			= "YYYY-MM-DD H:mm:ss";
	}

	if(!simple){
		var ranges = {
           'Hoy' 			: [moment().startOf('day'), moment().endOf('day')],
           'Ayer' 			: [moment().subtract(1, 'days').startOf('day'), moment().subtract(1, 'days').endOf('day')],
           '7 Días' 		: [moment().subtract(6, 'days').startOf('day'), moment().endOf('day')],
           '30 Días' 		: [moment().subtract(29, 'days').startOf('day'), moment().endOf('day')],
           'Este Mes' 		: [moment().startOf('month'), moment().endOf('month')],
           'Mes Pasado' 	: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
	}else{
		var ranges = {
           'Hoy' 			: [moment().startOf('day'), moment().endOf('day')],
           '7 Días' 		: [moment().subtract(7, 'days'), moment().endOf('day')],
           '30 Días' 		: [moment().subtract(29, 'days').startOf('day'), moment().endOf('day')],
           'Este Mes' 		: [moment().startOf('month'), moment().endOf('month')],
           'Mes Pasado' 	: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
           //'Este Año' 		: [moment().startOf('year'), moment().endOf('year')]
        };

        $el.attr('readonly','readonly').css('background-color','#fff');
	}

	var datePicketMaxRange = $el.data('max');
	if(datePicketMaxRange){
		datePicketMaxRange = {days:datePicketMaxRange};
	}else{
		datePicketMaxRange = {days:365};
	}

    $el.daterangepicker({
    	timePicker 			: timepicker,
    	timePicker24Hour 	: timepicker24,
    	dateLimit 			: datePicketMaxRange,
        startDate 			: start,
        endDate 			: end,
        opens 				: side,
        alwaysShowCalendars : (isMobile.phone || simple) ? false : true,
        showCustomRangeLabel: (simple) ? false : true,
        autoApply 			: false,
        parentEl 			: $('#content section.scrollable'),
        buttonClasses 		: 'btn btn-rounded btn-sm font-bold text-u-c',
        ranges: ranges,
        locale: {
	        "format" 			: format,
	        "separator" 		: " - ",
	        "applyLabel" 		: "Aplicar",
	        "cancelLabel" 		: "Cancelar",
	        "fromLabel" 		: "Desde",
	        "toLabel" 			: "Hasta",
	        "customRangeLabel" 	: "Personalizado",
	        "daysOfWeek": [
	            "Dom",
	            "Lun",
	            "Mar",
	            "Mie",
	            "Jue",
	            "Vie",
	            "Sab"
	        ],
	        "monthNames": [
	            "Enero",
	            "Febrero",
	            "Marzo",
	            "Abril",
	            "Mayo",
	            "Junio",
	            "Julio",
	            "Agosto",
	            "Septiembre",
	            "Octubre",
	            "Noviembre",
	            "Diciembre"
	        ],
	        "firstDay": 1
	    }

    });

    $('#customDateR').attr('style','');

    $('#customDateR').off('apply.daterangepicker show.daterangepicker showCalendar.daterangepicker').on('apply.daterangepicker', function(ev, picker) {
    	var date 			= $(this).val();
    	window.realFrom 	= explodes(' - ',date,0);
    	window.realTo 		= explodes(' - ',date,1);

    	/*simpleStorage.set('fromTime',from);
    	simpleStorage.set('toTime',to);*/
    	var isHash 	= ncmHelpers.isPageHashed();
    	if(isHash){
    		$.post('/a_' + isHash,{range : date, hashed : true},function(result){
    			ncmHelpers.loadPageRefresh(true);
    		});
    	}else{
    		$('#manualDate').submit();
    	}


	   	
	}).on('show.daterangepicker showCalendar.daterangepicker',function(){
		console.log('picker shown');
		$('.daterangepicker tbody td').each(function(){
			$(this).addClass('needsclick');
		});
	});
}

function getFormattedDate() {
    var date = new Date();
    var str = date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate() + " " +  date.getHours() + ":" + date.getMinutes() + ":" + date.getSeconds();
    return str;
}

function htmlEntities(str) {
    return String(str).replace(/&/g, '&').replace(/</g, '&lt;').replace(/>/g, '>').replace(/"/g, '"');
}

function nFormatter(num, digits) {
  var si = [
    { value: 1, symbol: "" },
    { value: 1E3, symbol: "k" },
    { value: 1E6, symbol: "M" },
    { value: 1E9, symbol: "G" },
    { value: 1E12, symbol: "T" },
    { value: 1E15, symbol: "P" },
    { value: 1E18, symbol: "E" }
  ];
  var rx = /\.0+$|(\.[0-9]*[1-9])0+$/;
  var i;
  for (i = si.length - 1; i > 0; i--) {
    if (num >= si[i].value) {
      break;
    }
  }
  return (num / si[i].value).toFixed(digits).replace(rx, "$1") + si[i].symbol;
}

function formatNumber(number,currency,decimal,thousandSeparator,stringed,customDecimal,short){

	var comma 	= ',',
		dot 	= '.';
	
	var dN 	= 0,
		dS 	= (stringed)?'':dot,
		tS 	= comma;

	if(decimal != 'no'){
		dN = 2;
	}

	if(customDecimal){
		dN = customDecimal;
	}

	if(thousandSeparator != 'comma'){
		dS 	= (stringed)?'':comma;
		tS 	= dot;
	}

	if(short){
		var num = nFormatter(number, '2');
	}else{
		var num = $.number(number, dN, dS, tS);
	}
	
	//if(currency == null){currency = 'Gs.';}
	return currency + num;
}

function convertToRealNumber(number,forceDecimals,decimalsCount,decimal){
	if(!number){number = 0;}

	if(!decimal){
		decimal = 'no';
	}

	if(decimal == 'no' && !forceDecimals){
		if(thousandSeparator == 'dot'){
			explode 	= number.split(','); //esto es para eliminar los decimales
			number 		= explode[0];
			number 		= number.replace(".", "");
		}else{
			explode 	= number.split('.'); //esto es para eliminar los decimales
			number 		= explode[0];
			number 		= number.replace(",", "");
		}
		return number;
	}else{
		if(thousandSeparator == 'dot'){
			number 		= number.replace(".", "");
			number 		= number.replace(",", ".");
		}else{
			number 		= number.replace(",", "");
		}

		number = number_format(number,decimalsCount,'.','');

		return number;
	}
}

var explodes = function(del,str,retrn){

	if(validity(str)){
		var explode = str.split(del);
		if(retrn > -1){
			return explode[retrn];
		}else{
			return explode;
		}
	}else{
		return false;
	}
};

var postIt = function(url,vars,success,fail){
	$.ajax({
	    url       : url,
	    type      : 'POST',
	    dataType  : 'json',
	    data      : vars,
	    success   : function(data){ 
	      success && success(data);
	    },
	    error     : function(data) {
	      fail && fail('false');
	    }
	});
};

var masksCurrency = function($input,ts,dc,operator,customDecimal,callback){
	
	$input.addClass('text-right').attr('autocomplete','off');//pongo todos los numéricos a la derecha
	$input.each(function(index, el){
		
		var val 	= $(el).val();
		//limpio los valores del campo para dejar en integer
		val 		= val.split(',').join('');
		val 		= val.split('.').join('');
		var fNops = {
					number 			:val,//str/num
					decimal 		:dc,//yes/no
					typing 			:true,
					customDecimal 	:iftn(customDecimal,2)
				};
		$(el).val(formatsNumber(fNops));
	});

	$input.off('focus change').on('focus',function(){
		$input 			= $(this);
		operator 		= iftn(operator,'0');
		var taped 		= false;
		var inp 		= $input.val();

		//primero formateo on load el campo, por si tiene data ya adentro
		if(validityChecker(inp)){
			var fNops = {
						number 			:inp,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
			var formatted = formatsNumber(fNops);
		}

		//
		$input.val('').off('focusout').on('focusout',function(){
			if(!validityChecker($input.val())){
				$input.val(inp);
			}

			callback && callback($(this));
		});
		//

		$input.off('keypress keydown').on('keypress',function(e){    
			
	        var inp 					= $(this).val();
			var val						= (inp==0)?'':inp;
			var keyCode 				= e.which;
			var key 					= String.fromCharCode(keyCode);
			var numberPadFnHasPercent 	= '';

			if(!taped){
				val = '';
			}

			//limpio los valores del campo para dejar en integer
			val = val.split(',').join('');
			val = val.split('.').join('');

			if (keyCode == 37 && operator) {// si aprieto percent
				numberPadFnHasPercent = '%';
			}else if((keyCode>47 && keyCode<58)){	
				val	= val+key;
				e.preventDefault();
			}

			taped = true;

			if(val == ''){val = '0'; numberPadFnHasPercent = '';}

			var fNops = {
						number 			:val,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
			var formatted = formatsNumber(fNops)+numberPadFnHasPercent;
			$(this).val(formatted);

			$input.trigger('formatted');

			return false;
	    }).on('keydown',function(e){
	    	var inp 	= $(this).val();
			var val		= (inp==0)?'':inp;
			var keyCode = e.which;

			if(keyCode == 8){
				val = val.split(',').join('');
				val = val.split('.').join('');

				val = val.slice(0, -1);

				var fNops = {
						number 			:val,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
				var formatted = formatsNumber(fNops);

				$(this).val(formatted);

				$input.trigger('formatted');

		  		e.preventDefault();
		  		return false;
		  	}
		});

    }).on('change',function(){
    	callback && callback($(this));
    });
}

var ncmMaskInput = function(options){
	var $input 			= options.el;
	var customDecimal 	= options.decimals;
	var dc 				= options.decimal;
	var ts 				= options.thousand;
	var operator 		= options.operator;
	var callback 		= options.callback;
	
	$input.addClass('text-right').attr('autocomplete','off');//pongo todos los numéricos a la derecha
	$input.each(function(index, el){
		
		var val 	= $(el).val();
		//limpio los valores del campo para dejar en integer
		val 		= val.split(',').join('');
		val 		= val.split('.').join('');
		var fNops = {
					number 			:val,//str/num
					decimal 		:dc,//yes/no
					typing 			:true,
					customDecimal 	:iftn(customDecimal,2)
				};
		$(el).val(formatsNumber(fNops));
	});

	$input.off('focus change').on('focus',function(){
		$input 			= $(this);
		operator 		= iftn(operator,'0');
		var taped 		= false;
		var inp 		= $input.val();

		//primero formateo on load el campo, por si tiene data ya adentro
		if(validityChecker(inp)){
			var fNops = {
						number 			:inp,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
			var formatted = formatsNumber(fNops);
		}

		//
		$input.val('').off('focusout').on('focusout',function(){
			if(!validityChecker($input.val())){
				$input.val(inp);
			}

			callback && callback($(this));
		});
		//

		$input.off('keypress keydown').on('keypress',function(e){    
			
	        var inp 					= $(this).val();
			var val						= (inp==0)?'':inp;
			var keyCode 				= e.which;
			var key 					= String.fromCharCode(keyCode);
			var sufix 					= '';
			var prefix 					= '';

			if(!taped){
				val = '';
			}

			//limpio los valores del campo para dejar en integer
			val = val.split(',').join('');
			val = val.split('.').join('');

			if (keyCode == 37 && operator) {// si aprieto percent
				sufix = '%';
			}else if((keyCode>47 && keyCode<58)){	
				val	= val + key;
				e.preventDefault();
			}else if(keyCode == 45 && options.allowNegative){
				prefix = '-';
			}

			taped = true;

			if(val == ''){val = '0'; prefix = '';}

			var fNops = {
						number 			:val,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
			var formatted = prefix + formatsNumber(fNops) + sufix;
			$(this).val(formatted);

			$input.trigger('formatted');

			return false;
	    }).on('keydown',function(e){
	    	var inp 	= $(this).val();
			var val		= (inp==0)?'':inp;
			var keyCode = e.which;

			if(keyCode == 8){
				val = val.split(',').join('');
				val = val.split('.').join('');

				val = val.slice(0, -1);

				var fNops = {
						number 			:val,//str/num
						decimal 		:dc,//yes/no
						typing 			:true,
						customDecimal 	:iftn(customDecimal,2)
					};
				var formatted = formatsNumber(fNops);

				$(this).val(formatted);

				$input.trigger('formatted');

		  		e.preventDefault();
		  		return false;
		  	}
		});

    }).on('change',function(){
    	callback && callback($(this));
    });
};

var formatsNumber = function(options){
	thalog('formatsNumber fn');

	/*
	var fNops = {
				number:,//str/num
				decimal:,//yes/no
				thousandSeparator:,//comma/dot
				currency:,//bool
				customDecimal:,//bool
				max:,//num
				typing:,//bool
				raw://bool
				}
	*/

	options.thousandSeparator 	= iftn(options.thousandSeparator,window.thousandSeparator);
	options.decimal 			= iftn(options.decimal,window.decimal);

	var comma 	= ',',
		dot 	= '.',
		number 	= options.number,
		dN 		= 0,
		dS 		= dot,
		tS 		= comma,
		currency = iftn(options.currency,'',window.currency);

	if(options.decimal == 'yes'){
		dN = iftn(options.customDecimal,2);
	}

	if(options.max > 0){
		number = (number > options.max) ? options.max : number;
	}

	if(options.decimal == 'yes' && options.typing){
		number = typeInDecimalMaker(number,dN);
	}

	if(options.thousandSeparator != 'comma'){
		dS 	= comma;
		tS 	= dot;
	}

	if(options.raw){
		return typeInDecimalMaker(options.number,dN,true);
	}else{
		var num = $.number(number, dN, dS, tS);
		return currency + num;
	}
}

function typeInDecimalMaker(number,dN,rawIt){
	
	var strnumber 	= number.toString();
	strnumber 		= strnumber.split('.').join('');
	strnumber 		= strnumber.split(',').join('');
	dN 				= iftn(dN,2);

	/*if(strnumber.length > dN){
    	var part 	= number.split('');
      	var n 		= '';
    	for(var u = 0;u<part.length;u++){
	      	if(u == part.length-dN){
	        	n += '.';
	        }
	      	n += part[u];
	    }
    	number = n;
    }else if(strnumber.length == 2){
    	number = '0.'+strnumber;
    }else if(strnumber.length == 1){
		number = '0.0'+strnumber;
    }else{
    	return 0;
    }*/
 	
 	var n 		= '';
	for(var u = 0;u<(dN-(strnumber.length-1));u++){
      	n += '0';
    }
	number = n+strnumber;
	var part = number.split('');
	var n = '';
	for(var i=0;i<part.length;i++){
		if(i == (part.length-dN)){
	  	n += '.';
	  }
	  n += part[i];
	}
	number = n;

	return $.number(number, dN, '.', '');
	
}

function roundPrice(price){
	//return Math.floor(price);
	return price;
}

function number_format(number, decimals, decPoint, thousandsSep) {
  number = (number + '').replace(/[^0-9+\-Ee.]/g, '')
  var n = !isFinite(+number) ? 0 : +number
  var prec = !isFinite(+decimals) ? 0 : Math.abs(decimals)
  var sep = (typeof thousandsSep === 'undefined') ? ',' : thousandsSep
  var dec = (typeof decPoint === 'undefined') ? '.' : decPoint
  var s = ''
  var toFixedFix = function (n, prec) {
    var k = Math.pow(10, prec)
    return '' + (Math.round(n * k) / k)
      .toFixed(prec)
  }
  // @todo: for IE parseFloat(0.55).toFixed(0) = 0;
  s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.')
  if (s[0].length > 3) {
    s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep)
  }
  if ((s[1] || '').length < prec) {
    s[1] = s[1] || ''
    s[1] += new Array(prec - s[1].length + 1).join('0')
  }
  return s.join(dec)
}

function isNumber(n) {
  return !isNaN(parseFloat(n)) && isFinite(n);
}

function isInt(n){
    return Number(n) === n && n % 1 === 0;
}

function isFloat(n){
    return Number(n) === n && n % 1 !== 0;
}

var checkIfallDecimals = function(value){
	value = value.toString();
	var split = value.split(".");
	if(split[1]){
		if(split[1].length < 2){
			return value+'0';
		}else{
			return value;
		}
	}else{
		return value;
	}
};

var maskCurrency = function($selector,separator,decimals,currency,extdec){
	if(extdec === true){
		extdec = '00';
	}else{
		extdec = '';
	}
	currency 	= (!currency)?'':currency;
	decPlus 	= '';
	var options = { 
					reverse: true,
					placeholder:currency,
				   'translation':{
				   		T: {
				   			pattern: /[-]/, 
				   			optional: true
				   			
				   		    }
				   		}
				  };

	if(decimals == 'no'){
		if(separator == 'dot'){
			$selector.mask('T000.000.000.000.000', options);
		}else{
			$selector.mask('T000,000,000,000,000', options);
		}
	}else{
		
		if(separator == 'dot'){
			$selector.mask('T000.000.000.000.000,'+extdec, options);
		}else{
			$selector.mask('T000,000,000,000,000.'+extdec, options);
		}
	}
};

var unMaskCurrency = function(value,separator,decimals){
	var out = 0;
	if(decimals == 'no'){
		if(separator == 'dot'){
			out = value.split(",");
			out = out[0].replace(/\./g, "");
		}else{
			out = value.split(".");
			out = out[0].replace(/\,/g, "");
		}
	}else{
		if(separator == 'dot'){
			out = value.replace(".", "");
			out = out.replace(",", ".");
		}else{
			out = value.replace(",", "");
		}
	}
	return out;
};

function getFullDateAndTime(dateonly,houronly,fromInput){
	var date = new Date();
	
	var y = date.getFullYear();
	var m = date.getMonth()+1;
	var d = date.getDate();
	
	var h = date.getHours();
	var i = (date.getMinutes()<10?'0':'')+date.getMinutes();
	var s = (date.getSeconds()<10?'0':'')+date.getSeconds();
	
	if(m < 10){m = '0'+m;}
	
	if(dateonly == true){
		var fullDate = y+'-'+m+'-'+d;
	}else if(houronly == true){
		var fullDate = h+':'+i+':'+s;
	}else if(fromInput){
		var val		= $(fromInput).val();
		var fullDate = (val == '')?y+'-'+m+'-'+d+' '+h+':'+i+':'+s:val;
	}else{
		var fullDate = y+'-'+m+'-'+d+' '+h+':'+i+':'+s;
	}
	thalog('Date: '+fullDate);
	return fullDate;
}

var timeAgo = function(date){
    var date_past = new Date(date).getTime();
    var date_now  = new Date().getTime();
    // get total seconds between the times
    var delta = Math.abs(date_past - date_now) / 1000;

    // calculate (and subtract) whole days
    var days = Math.floor(delta / 86400);
    delta -= days * 86400;

    // calculate (and subtract) whole hours
    var hours = Math.floor(delta / 3600) % 24;
    delta -= hours * 3600;

    // calculate (and subtract) whole minutes
    var minutes = Math.floor(delta / 60) % 60;
    delta -= minutes * 60;

    // what's left is seconds
    var seconds = delta % 60;  // in theory the modulus is not required

    days    = Math.round(days);
    hours   = Math.round(hours);
    minutes = Math.round(minutes);
    seconds = Math.round(seconds);

    if(days > 0){
      return days + 'd ' + hours + 'h';
    }else if(hours > 0){
      return hours + 'h ' + minutes + 'm';
    }else if(minutes > 0){
      return minutes + 'm ' + seconds + 's';
    }else{
      return seconds + 's';
    }
  };

/*window.onerror = function(msg, url, linenumber) {
    thalog('Error message: '+msg+' \n URL: '+url+' \n Line Number: '+linenumber);
    return true;
}*/

//$('body').append('<div id="thalog"></div>');
function thalog(text){
	if(window.thalog){
		//console.log(text);
	}
}

var get_selected_values = function(type){
	thalog('get_selected_values fn');
	var values = [];
	var id,
	count,
	$cbx = $('.table tr .selected').find('input:hidden');

	if(type == 'multiBC'){
		$cbx.each(function(i){
			id 		= $(this).val();
			count 	= $('#inventoryCountRow'+id).text();

			if(count == "+"){
				values.push(id);
			}else{
				count 	= (count<1)?1:count;
			
				for(a=0;a<count;a++){
					values.push(id);
				}
			}
		});
		return values;
	}else{
		$cbx.each(function(i){
			//if($(this).is(":checked")){
				values[i] = $(this).val();
			//}
		});
		return values;
	}
}

function bytesToSize(bytes) {
   var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
   if (bytes == 0) return '0 Byte';
   var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
   return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
};

var submitForm = function(formId,callback,files,onBefore){

	$(formId).off('submit').on('submit',function(e) {
		onBefore && onBefore();
		var formData = iftn(files,$(this).serialize(),new FormData(this));
		$.ajax({ // create an AJAX call...
			data: formData, // get the form data
			type: $(this).attr('method'), // GET or POST
			url: $(this).attr('action'), // the file to call
			success: function(response) { // on success..e
				if(response.error){
					spinner('body', 'hide');
            		message('No se pudo procesar','warning');
            		return false;
            	}

				if(response.indexOf('|') > -1){
					response 		= response.split('|');
					var firstResp 	= response[0];
				}else{
					var firstResp 	= response;
				}
				
				if(firstResp == 'true'){
					message('Realizado','success');
				}else if(firstResp == 'false'){
					message('Error al procesar','danger');
				}else if(firstResp == 'max'){
					$('#maxReached').modal();
					return false;
				}else{
					if(firstResp.length > 256){
						//location.reload();
					}else{
						if(firstResp){
							ncmDialogs.alert(firstResp);
						}else{
							ncmDialogs.alert('ERROR: No hubo respuesta');
						}
						return false;
					}
				}
				callback && callback($(this),response[2]);
			}
		});
		return false; // cancel original event to prevent form submitting
	});
};

var submitForm2 = function(formId,callback,files,onBefore){
	$(document).off('submit',formId);
	$(document).on('submit',formId,function(e) {
		onBefore && onBefore();
		e.preventDefault();
		$(formId).ajaxSubmit({
            success: function(response){

            	if(response.error){
            		spinner('body', 'hide');
            		message('No se pudo procesar','warning');
            		return false;
            	}

                if(response.indexOf('|') > -1){
					response 		= response.split('|');
					var firstResp 	= response[0];
				}else{
					var firstResp 	= response;
				}
				
				if(firstResp == 'true'){
					message('Realizado','success');
				}else if(firstResp == 'false'){
					message('Error al procesar','danger');
				}else if(firstResp == 'max'){
					$('#maxReached').modal();
					return false;
				}else{
					if(firstResp.length > 256){
						location.reload();
					}else{
						thalog(firstResp);
						alert(firstResp);
						return false;
					}
				}
				callback && callback($(this),response[2]);
            }
        });

		return false; // cancel original event to prevent form submitting
	});
};

function loadInModal(url,modal){
	var options 	= {
						remote: url
						};
	$(modal).find('.modal-body').html(' ');
	$(modal).removeData('bs.modal').modal(options);
	
	//$(modal).modal(options);
}

function bootboxPrompt(title,value,callback){
	var promtOptions = {
		title: title,
		buttons:{
				confirm: {
					label: "Ok"
				}
			},
		
		inputType:'textarea',
		value: value,
		callback: function(result){
			if(result){
				callback && callback(result);
			}
		}
	}
	
	var p = bootbox.prompt(promtOptions);
    return p;
}

function clockStartTime(time,date) {
    var today=new Date();
	
    var h=today.getHours();
    var m=today.getMinutes();
    var s=today.getSeconds();
    m = clockCheckTime(m);
    h = clockCheckTime(h);
    $(time).html(h+":"+m);
	$(date).html(today.toDateString());
    var t = setTimeout(function(){clockStartTime(time)},5000);
}

function clockCheckTime(i) {
    if (i<10) {i = "0" + i};  // add zero in front of numbers < 10
    return i;
}



var htmlOriginal = $.fn.html;

// redefine the `.html()` function to accept a callback
$.fn.html = function(html,callback){
  // run the old `.html()` function with the first parameter
  var ret = htmlOriginal.apply(this, arguments);
  // run the callback (if it is defined)
  if(typeof callback == "function"){
    callback();
  }
  // make sure chaining is not broken
  return ret;
}

var codeSearch = function(code,callback){
    thalog('codeSearch fn');
	if(code){
		var searchIndex = arraySearch(productsObj,'itemId',code);
		//thalog('search result: '+searchId);
		if(searchIndex != 'none'){
			var p = productsObj[parseInt(searchIndex)];
			//thalog(p.name);
			var tax = inventoryObj[arraySearch(inventoryObj,'itemId',p.itemId)].tax;
			insertItemToSale(p.itemId,p.sku,p.name,p.price,tax);
			callback && callback()
		}else{
			var searchSKU = arraySearch(productsObj,'sku',code);
			if(searchSKU != 'none'){
				var p = productsObj[parseInt(searchSKU)];
				var tax = inventoryObj[arraySearch(inventoryObj,'itemId',p.itemId)].tax;
				insertItemToSale(p.itemId,p.sku,p.name,p.price,tax);
				callback && callback()
			}else{
				bootbox.alert('<center class="wrapper"><i class="h1 icon-close text-danger"></i> <span class="block h3 m-t-sm">El producto ingresado no existe o fue modificado</span></center>');
			}
		}
	}
};


var removeFromArray = function(array,field,id,callback){
    thalog('removeFromArray fn');
	
	var index = arraySearch(array,field,id);

	if(index != 'none'){
		removeObjectEntry(array,index)
		callback && callback()
	}
};

var removeObjectEntry = function(array,index){
	thalog('removeObjectEntry fn');
	index = parseInt(index);
	if (index > -1) {
	    array.splice(index, 1);
	}

};

var generateUid = function () {
    var d = new Date();
	var n = d.getTime();
	return n;
};

var arraySearch = function(array,field,value,multi,get){
    thalog('arraySearch fn');
	var length = array.length;
	var a = [];
	var type;
	var result;

	if(jQuery.type(value) === 'number'){
		type = 'number';
	}else if(jQuery.type(value) === 'string'){
		type = 'string';
	}

	for (var i = 0; i < length; i++) {

		result = array[i][field];

		if(type == 'number'){
			result 	= parseInt(result);
		}else if(type === 'string'){
			result 	= result.toString();
		}

		if (result === value) {
			index = i;
			thalog(value+' found');
			if(multi){
				a.push(index);
			}else{
				return index;
				break;
			}
		}else{
            thalog(value + ' NOT found in ' + field);
        }
	}

	if(a.length < 1){
		return 'none';
	}else{
		return a;
	}
};

var objSearch = function(obj,searchF,searchV,getF,multi){
	var a = [];
	$.each(obj,function(i,v){
      if(v[searchF] == searchV){
      	if(multi){
      		a.push(v[getF]);
      	}else{
      		return a = v[getF];
      	}
      }
  });
  
  return a;
}

var getSingleValue = function(table,id,field,callback){
	var id 	= parseInt(id);
	var req = db.get(table, id);
	req.done(function(record) {
		if(field){
				callback && callback(record.field);
		}else{
				callback && callback(record);
		}
	});
	req.fail(function(e) {
		throw e;
		return false;
	});
};

function checkHashUrl(){
	return ncmHelpers.isPageHashed();
}

/*var validityChecker = function(value,isObj,val){
	if(isObj){
		if(value.hasOwnProperty(val)){
			return validityChecker(value[val]);
		}
	}

	if(!value || value == 'undefined' || value == null || value == false){
		return false;
	}else{
		return true;
	}
};*/

var validityChecker = function(value,force,isObj,val){
	return ncmHelpers.validate(value,force,isObj,val);
	/*try{
		if(isObj){
			if(value.hasOwnProperty(val)){
				return validityChecker(value[val]);
			}
		}

		if(jQuery.type(value) === "undefined"){
			return false;
		}else{
			if(!value || value === null){
			  return false;
			}

			if(force){
				if(force == 'email'){
					return validateEmail(value);
				}else if(jQuery.type(value) === force){
					return validityChecker(value);
				}else{
					return false;
				}
			}else{
			  if(jQuery.type(value) === "number"){
			    if(value < 0.00001){
			      return false;
			    }
			  }else if(jQuery.type(value) === "string"){
			    if(value.length < 1){
			      return false;
			    }
			  }else if(jQuery.type(value) === "array"){
				if(value.length < 1 || !validityChecker(value[0])){
			      return false;
			    }
			  }else if(jQuery.type(value) === "object"){
			    if(Object.keys(value).length < 1){
			      return false;
			    }
			  }
			}

			return value;

		}

	}catch(t){
  		return false;
  	}*/
};

var validity = function(value,force,isObj,val){
	return ncmHelpers.validate(value,force,isObj,val);
	//return validityChecker(value,force,isObj,val);
};

var sort_by = function(field, reverse, primer){

   var key = primer ? 
       function(x) {return primer(x[field])} : 
       function(x) {return x[field]};

   reverse = [-1, 1][+!!reverse];

   return function (a, b) {
       return a = key(a), b = key(b), reverse * ((a > b) - (b > a));
     } 
};

var getFirstLetters = function(text){
	var out = '<span class="text-u-c">'+text.charAt(0)+'</span>'+text.charAt(1);
	return out;
};

(function($) {
    $.fn.clickToggle = function(func1, func2) {
        var funcs = [func1, func2];
        this.data('toggleclicked', 0);
        this.click(function() {
            var data = $(this).data();
            var tc = data.toggleclicked;
            $.proxy(funcs[tc], this)();
            data.toggleclicked = (tc + 1) % 2;
        });
        return this;
    };
}(jQuery));

var download = function(url,callback){
    thalog('download fnc');
    
	var randNum = Math.floor((Math.random()*10000)+1);			
	
	var get = $.when($.get(url+extraDat));
	
	get.done(function(data){
		callback && callback(data);
	});
    
	get.fail(function(){
		//var message = '<center><h2><i class="fa fa-warning text-danger"></i> Error.</h2><h4> No pudimos conectarnos al servidor.</h4> Asegurese de poseer una conexión a Internet continua y haga click el boton de reconexión. <br><br> <a href="index.html" class="btn btn-primary"><i class="fa fa-refresh"></i></a></center>';
		//$('#modalLoading .modal-body').html(message);
	});
};

var loadPageInContainer = function(page,container,internal,callback){
	thalog('loadPageInContainer fn');
	$(container).html('');
	spinner(container, 'show');
	if(!internal){
		$.get(page, function(data) {
		  $(container).html(data);
		  spinner(container, 'hide');
		  callback && callback();
		});
	}else{
		$(container).html(page);
		spinner(container, 'hide');
		callback && callback();
	}
};

var spinner = function(container, status){
	//thalog('spinner '+container+' '+status+' fn');

	var exContainer = container.split('.').join("").split('#').join("");
	$container 		= $(container);
	if(status == 'show'){
		//if($container.is(':empty')){
			$container.parent().css('position','relative');
			var svg = '<img class="spinnerLoad-' + exContainer + '" style="position: fixed; left:50%; top:40%; z-index:9999999; margin-left: -30px;" src="/images/svg-loaders/puff.svg" width="60">';
			$container.append(svg);
		//}
	}else{
		$('.spinnerLoad-' + exContainer).remove();
	}
};

var iftn = function(condition,replace,secondcondition){
	var replace       = validity(replace) ? replace : '';
	var final         = validity(secondcondition) ? secondcondition : condition;
	return validity(condition) ? final : replace;
};

function adm(callback){	
	onClickWrap('.editItemPart, .addItemPart, .deleteItemPart, .toggleItemPart', function(event,tis){
		var secid		= tis.data('select');
		var table 		= tis.data('table');
		var valType 	= tis.data('valtype');
		var outlet 		= iftn(tis.data('outlet'),'false');
		var $selected 	= ( $('#' + secid + " option:selected") ) ? $('#' + secid + " option:selected") : $("#" + secid + " option:first");
		var txt 		= $.trim($selected.text()).replace('× ', '');
		var id 			= $('#' + secid).val();
		var casee 		= tis.attr('class').split(/[ ,]+/);
		casee 			= casee[0];
		var toggle 		= $selected.data('toggle');
		toggle 			= (toggle === "") ? '2' : toggle; 
		

		if(casee == 'editItemPart'){
			prompter('Editar ' + encodeURI(txt), function(str) {
										if (str) {
											if(valType == 'num'){
												var str = (+str.replace(',', '.'));
												if(isNaN(str)){
													alert('Solo puede ingresar números');
													return false;
												}
											}

											$.get(baseUrl + '?actionExtra=edit&tableExtra=' + table + '&valExtra=' + str + '&idExtra=' + id + '&toggleExtra=' + toggle, function(data) {
												thalog('#' + secid + ' option[value="' + id + '"].remove');
												thalog('.' + table + ' prepend: ' + data);
												$('#' + secid + ' option[value="' + id + '"]').remove();
												$('.' + table).prepend(data);
												callback && callback();
											});
										}
									}, txt);
		}else if(casee == 'addItemPart'){
			prompter("Insertar", function (str) {
				if (str){
					if(valType == 'num'){
						var str = (+str.replace(',', '.'));
						if(isNaN(str)){
							alert('Solo puede ingresar números');
							return false;
						}
					}
					$.get(baseUrl + '?actionExtra=add&tableExtra=' + table + '&valExtra=' + str + '&admOutlet=' + outlet, function(data) {
						//console.log('prepend: '+data);
					  $('.' + table).prepend(data);
					  callback && callback();
					});
				}
			});
		}else if(casee == 'deleteItemPart'){
			confirmation('Realmente desea eliminar "' + encodeURI(txt) + '"?', function (e) {
				if (e === true) {
					$.get(baseUrl + '?actionExtra=delete&tableExtra='+table+'&idExtra='+id, function(data) {
						//console.log('#'+secid+' option[value="' + data + '"].remove');
						$('#'+secid+' option[value="' + data + '"]').remove();
						callback && callback();
					});
				}
			});
		}else if(casee == 'toggleItemPart'){
			toggle 	= (toggle == '2')?'1':'2';
			$.get(baseUrl + '?actionExtra=toggle&tableExtra='+table+'&valExtra='+txt+'&idExtra='+id+'&toggleExtra='+toggle, function(data) {
				$('#'+secid+' option[value="' + id + '"]').remove();
				$('.'+table).prepend(data);
				callback && callback();
			});
		}
	},false,true);
}



var clickeable = function(){
	thalog('clickeable fn');
	onClickWrap('.clickeable',function(event,tis){
		var type 		= tis.attr('data-type'); //obtengo el tipo de accion
		var index 		= parseInt(tis.attr('data-position'));
		var id 			= parseInt(tis.attr('data-id'));
		var load 		= tis.attr('data-load');
		var element		= tis.attr('data-element');
		
		if(tis.hasClass('disabled')){return false;}
			
		if(type == 'loadItem'){ //note
			loadItemsForm(load);
		}else if(type == 'deleteItem'){ //discount
			
				
		}else if(type == 'empty'){

		}

	}); //clickeable end/	
};

var strMoneyToNumber = function(currency){
	currency = (!currency)?'':currency;
	//return Number(currency.replace(/[^0-9\.]+/g,""));
	return Number(currency.replace(/[^0-9\.-]+/g,""));
};

function abbrNum(number, decPlaces) {
    // 2 decimal places => 100, 3 => 1000, etc
    decPlaces = Math.pow(10,decPlaces);

    // Enumerate number abbreviations
    var abbrev = [ "K", "M", "B", "T" ];

    // Go through the array backwards, so we do the largest first
    for (var i=abbrev.length-1; i>=0; i--) {

        // Convert array index to "1000", "1000000", etc
        var size = Math.pow(10,(i+1)*3);

        // If the number is bigger or equal do the abbreviation
        if(size <= number) {
             // Here, we multiply by decPlaces, round, and then divide by decPlaces.
             // This gives us nice rounding to a particular decimal place.
             number = Math.round(number*decPlaces/size)/decPlaces;

             // Handle special case where we round up to the next abbreviation
             if((number == 1000) && (i < abbrev.length - 1)) {
                 number = 1;
                 i++;
             }

             // Add the letter for the abbreviation
             number += abbrev[i];

             // We are done... stop
             break;
        }
    }

    return number;
}

var manageTable = function(info,callback){ 
	thalog('manageTable fn');

	var container 	= info.container;
	var url 		= info.url;
	var iniData		= info.iniData;
	var table 		= info.table;
	var sort 		= info.sort;
	var footerSum	= info.footerSum;
	var fSumCol		= iftn(info.footerSumCol,[]);
	var currency	= info.currency;
	var dc			= info.decimal;
	var ts			= info.thousand;
	var hideCol 	= info.hiddenColumns;
	var child		= info.allowChild;
	var childHide	= (info.allowChildHide)?info.allowChildHide:false;
	var childBg		= (info.allowChildBg)?info.allowChildBg:'';
	var pagination	= true;//(info.showPagination)?info.showPagination:false;
	var rowsLength	= (info.rowsLength)?info.rowsLength:100;
	var extraHtml	= info.extraHtml;
	var extra 		= '';
	var p 			= (pagination)?'p':'';
	var data 		= '';

	spinner(container, 'show');

	function feedData(url,iniData,callback){
		if(!validityChecker(iniData)){
			$.get(url, function(data){
				callback && callback(data);
			});
		}else{
			callback && callback(iniData);
		}
	}

	feedData(url, iniData, function( data ) {

		if(extraHtml == 'yes'){
			dats 	= data.split("[@]");
			extra 	= dats[0];
			data 	= dats[1];

			if($('#creditBlocks').length){
				extra = '';
			}
		}else if(extraHtml == 'maybe'){
			dats 	= data.split("[@]");
			extra 	= '';
			data 	= dats[1];
		}

		if(data.length > 0){
			var options = {
	 						"dom"					: "<'row'<'col-md-9  col-sm-6 text-left hidden-xs'B><'col-md-3 col-sm-6 hidden-print'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'"+p+">",
	 						"paging"				: pagination,
	 						"lengthMenu"			: [ [100, 400, 800, -1], [100, 400, 800, "Todo"] ],
	 						"pageLength"			: rowsLength,
	 						"destroy"				: true,
	 						"stateSave"				: true,
	 						"oLanguage"				: { "sSearch": "" },
					        "order"					: [[ sort, "desc" ]],
					        "bSortClasses"			: false,
					        "buttons"				: 
					        [
					        	/*{ extend: 'copy', text: 'Copiar', className: 'btn btn-default', exportOptions: {stripHtml: false}  },*/
					        	/*{ extend: 'csv', text: 'A CSV', className: 'btn btn-default'  },*/
					        	{ extend: 'excel', text: 'Exportar listado', className: 'btn btn-default', exportOptions: {
					        		format: {
						                body: function ( data, row, column, node ) {
						                    // si es numerico, hago un unformat number o algo asi

						                    var data = $("<div/>").html(data).text();

						                    if($.isNumeric(data)){
						                    	return convertToRealNumber(data);
						                    }else{
						                    	return data;
						                    }
						                }
						            }
					        	} },
					        	/*{ extend: 'pdf', text: 'A PDF', className: 'btn btn-default'  },*/
					        	/*{ extend: 'print', text: 'Imprimir', className: 'btn btn-default', exportOptions: {stripHtml: false}  }*/
					        ],
	 						"language"				: 
	 									[{
										    "decimal"	: dc,
										    "thousands"	: ts,
										    "zeroRecords": '<div class="wrapper-sm text-muted text-center font-thin"> <div class="b h1 rounded wrapper-lg" style="width:103px; margin:0 auto;"><i class="icon-magnifier"></i></div> <span class="block m-t-md h3"> No pudimos encontrar lo que busca</span><span class="block text-sm">Intenta buscar utilizando otra de combinación de palabras o presionando el botón <b>Cargar Más</b></span></div>'
									  	}],

							"drawCallback"			: function () {
								$('.dataTables_paginate .paginate_button').addClass('btn btn-default');
					            $('.dataTables_paginate span').children('.paginate_button').each(function () {
					            	var current = $(this);
					            	current.addClass('btn');
									if(current.hasClass('current')){
										current.addClass('btn-info');
									}else{
										current.addClass('btn-default');
									}
								});					            
					        },
							
					        "footerCallback"		: function ( row, data, start, end, display ) {
					        	if(fSumCol.length > 0){
						            var api = this.api();
							        api.columns(fSumCol, {page:'current'}).every(function () {
							        	var $selector 	= $(this.nodes());
										var suma 		= 0,type,value=0,out;
										$selector.each(function(){
											value 	= $(this).data('order');
											type 	= $(this).data('format');
											if(validityChecker(value)){
												suma 	+= parseFloat(value);
											}
										});
										out = suma;
										var percent = (type=='percent')?'%':'';
										if(type == 'money'){
											out = formatNumber(suma,currency,dc,ts);
										}else if(isFloat(suma)){
											out = formatNumber(suma,'','yes')+percent;
										}else if(isInt(suma)){
											out = formatNumber(suma,'','no')+percent;
										}else{
											out = 0;
										}
										$(this.footer()).html(out);
							        });
						        }
					        }
						        
						  };

			if ($.fn.DataTable.isDataTable(table)){
				oTable.destroy();
			}

			$(table).html(data);

			if(extraHtml){
				$(container).prepend(extra);
			}

			window.oTable = $(table).DataTable(options);

			$.each(hideCol,function(i,val){
				console.log('hidding '+i);
				oTable.column(val).visible(false);
			});

			if(child){
				oTable.rows().every(function () {
					var arr 	= this.data();
					var arra 	= Object.keys(arr);
			    	var a1 		= arr[arra.length-1];

			    	if(a1 && a1 != ""){
			    		this.child(a1).show();
					    this.nodes().to$().addClass('shown');	
					    
			    		var id 		= this.nodes().to$().data('id');
			    		var hidden 	= (childHide)?'hidden ':'';
			    		this.nodes().to$().closest('tr').next('tr').addClass(hidden + 'childRow' + id + ' ' + childBg);
				    	
				    }
			    });
			}

			$(table).width('100%');

			$('div.dataTables_filter input').addClass('form-control no-border bg-light lter rounded pull-right').attr('placeholder','Filtrar listado...');
			$('div.dataTables_filter').addClass('col-xs-12');
			$('div.dataTables_filter label').addClass('block');

			spinner(container, 'hide');

			callback && callback(oTable);

		}else{
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
			$(table).html(noContent);
			spinner(container, 'hide');
			callback && callback();
		}

	});
};

var manageTablePage = function(info,callback){ 
	thalog('manageTablePage fn');

	var container 	= info.container;
	var url 		= info.url;
	var iniData		= info.iniData;
	var table 		= info.table;
	var sort 		= info.sort;
	var footerSum	= info.footerSum;
	var fSumCol		= iftn(info.footerSumCol,[]);
	var currency	= info.currency;
	var dc			= info.decimal;
	var ts			= info.thousand;
	var hideCol 	= info.hiddenColumns;
	var child		= info.allowChild;
	var childHide	= (info.allowChildHide)?info.allowChildHide:false;
	var childBg		= (info.allowChildBg)?info.allowChildBg:'';
	var pagination	= (info.showPagination == 'no') ? false : true;
	var rowsLength	= 100;//(info.rowsLength)?info.rowsLength:false;
	var extraHtml	= info.extraHtml;
	var search		= info.search;//id del input
	var serverPagin	= info.serverPaging;//server side paging
	var extra 		= '';
	var p 			= (!serverPagin)?'p':''; 

	spinner(container, 'show');

	$(table).html('<tr><td><img src="/images/bg-report-list.png" width="100%" class="m-t-md"></td></tr>');

	function feedData(url,iniData,callback){
		if(!validity(iniData)){
			$.get(url, function(data){
				callback && callback(data);
			});
		}else{
			callback && callback(iniData);
		}
	}

	function toFeed(data){

		if(data.length < 1){
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
			$(table).html(noContent);
			spinner(container, 'hide');
			callback && callback();

			return false;
		}

		if(extraHtml == 'yes'){
			dats 	= data.split("[@]");
			extra 	= dats[0];
			data 	= dats[1];
			pages 	= dats[2];

			if($('#creditBlocks').length){
				extra = '';
			}
		}else if(extraHtml == 'maybe'){
			dats 	= data.split("[@]");
			extra 	= '';
			data 	= dats[1];
			pages 	= dats[2];
		}

		if(search){
			var dom = "<'row'<'col-sm-6 text-left hidden-xs hidden-print'B><'col-sm-6' <'col-sm-6 " + search + "Holder no-padder'><'col-sm-6 hidden-xs no-padder hidden-print'f> <'col-sm-6 visible-xs m-t-sm no-padder'f>>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'"+p+">";
		}else{
			var dom = "<'row'<'col-md-9  col-sm-6 text-left hidden-xs hidden-print'B><'col-md-3 col-sm-6 hidden-print'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'"+p+">";
		}

		if(data.length > 0){
			var options = {
	 						"dom"					: dom,
	 						"paging"				: pagination,
	 						"lengthMenu"			: [ [100, 400, 800, -1], [100, 400, 800, "Todo"] ],
	 						"pageLength"			: rowsLength,
	 						"destroy"				: true,
	 						"stateSave"				: true,
	 						"oLanguage"				: { "sSearch": "" },
					        "order"					: [[ sort, "desc" ]],
					        "bSortClasses"			: false,
					        "buttons"				: 
					        [
					        	/*{ extend: 'copy', text: 'Copiar', className: 'btn btn-default', exportOptions: {stripHtml: false}  },*/
					        	/*{ extend: 'csv', text: 'A CSV', className: 'btn btn-default'  },*/
					        	{ extend: 'excel', text: 'Exportar listado', className: 'btn btn-default', exportOptions: {
					        		format: {
						                body: function ( data, row, column, node ) {
						                    // si es numerico, hago un unformat number o algo asi

						                    var data = $("<div/>").html(data).text();

						                    if($.isNumeric(data)){
						                    	return convertToRealNumber(data);
						                    }else{
						                    	return data;
						                    }
						                }
						            }
					        	} },
					        	/*{ extend: 'pdf', text: 'A PDF', className: 'btn btn-default'  },*/
					        	/*{ extend: 'print', text: 'Imprimir', className: 'btn btn-default', exportOptions: {stripHtml: false}  }*/
					        ],
	 						"language"				: 
	 									[{
										    "decimal"	: dc,
										    "thousands"	: ts,
										    "zeroRecords": '<div class="wrapper-sm text-muted text-center font-thin"> <div class="b h1 rounded wrapper-lg" style="width:103px; margin:0 auto;"><i class="icon-magnifier"></i></div> <span class="block m-t-md h3"> No pudimos encontrar lo que busca</span><span class="block text-sm">Intenta buscar utilizando otra de combinación de palabras</span></div>',
										    "paginate": {
										        "first":      "Principio",
										        "last":       "Último",
										        "next":       "Siguiente",
										        "previous":   "Anterior"
										    }
									  	}],

							"drawCallback"			: function () {
								$('.dataTables_paginate .paginate_button').addClass('btn btn-default');
					            $('.dataTables_paginate span').children('.paginate_button').each(function () {
					            	var current = $(this);
					            	current.addClass('btn');
									if(current.hasClass('current')){
										current.addClass('btn-info');
									}else{
										current.addClass('btn-default');
									}
								});					            
					        },
							
					        "footerCallback"		: function ( row, data, start, end, display ) {
					        	if(fSumCol.length > 0){
						            var api = this.api();
							        api.columns(fSumCol, {page:'current'}).every(function () {
							        	var $selector 	= $(this.nodes());
										var suma 		= 0,type,value=0,out;
										$selector.each(function(){
											value 	= $(this).data('order');
											type 	= $(this).data('format');
											if(value != ''){
												if(value < 0){
													suma 	-= parseFloat(Math.abs(value));
												}else{
													suma 	+= parseFloat(value);
												}
											}
										});
										out = suma;
										var percent = (type=='percent')?'%':'';
										if(type == 'money'){
											out = formatNumber(suma,currency,dc,ts);
										}else if(isFloat(suma)){
											out = formatNumber(suma,'','yes')+percent;
										}else if(isInt(suma)){
											out = formatNumber(suma,'','no')+percent;
										}else{
											out = 0;
										}
										$(this.footer()).html(out);
							        });
						        }
					        }
						        
						  };

			if ($.fn.DataTable.isDataTable(table)){
				window.oTable.destroy();
			}

			$(table).html(data);

			if(extraHtml){
				$('.removable').remove();
				$(container).prepend(extra);
				$(container).append(pages);
			}

			window.oTable = $(table).DataTable(options);

			$.each(hideCol,function(i){
				oTable.column(i).visible(false);
			});

			if(child){
				oTable.rows().every(function () {
					var arr 	= this.data();
					var arra 	= Object.keys(arr);
			    	var a1 		= arr[arra.length-1];

			    	if(a1 && a1 != ""){
			    		this.child(a1).show();
					    this.nodes().to$().addClass('shown');	
					    
			    		var id = this.nodes().to$().data('id');
			    		var hidden = (childHide)?'hidden ':'';
			    		this.nodes().to$().closest('tr').next('tr').addClass(hidden+'childRow'+id+' '+childBg);
				    }
			    });
			}

			$(table).width('100%');

			$('div.dataTables_filter input').addClass('form-control rounded pull-right').attr('placeholder','Filtrar listado');
			$('div.dataTables_filter').addClass('col-xs-12');
			$('div.dataTables_filter label').addClass('block');
			
			if(search){
				$('.' + search + 'Holder').append('<div class="input-group pull-right m-b-sm"> <input type="text" class="form-control rounded" id="'+search+'" placeholder="Buscar"> <span class="input-group-btn"> <button data-url="'+url+'" data-container="'+container+'" class="btn btn-default btn-rounded searchDB" data-target="#'+search+'" type="button"><i class="material-icons">search</i></button> </span> </div>');
			}

			spinner(container, 'hide');

			ncmUI.setDarkMode.autoSelected();

			callback && callback(oTable);

		}
	}

	feedData(url, iniData, function(data){toFeed(data)});

	onClickWrap('.servPagination',function(event,tis){
		var url 		= tis.data('url');
		var container 	= tis.data('container');
		spinner(container, 'show');
		$(container).css({'opacity':'0.5'});
		feedData(url, false, function(data){toFeed(data); $(container).css({'opacity':'inherit'});spinner(container, 'hide');$('html,body').scrollTop($(container).offset().top);});
	},false,true);

	onClickWrap('.searchDB',function(event,tis){
		var target 		= tis.data('target');
		var value 		= $(target).val();
		var url 		= tis.data('url');
		var container 	= tis.data('container');

		console.log('searching',target,value);
		spinner(container, 'show');
		$(container).css({'opacity':'0.5'});
		var current = tis.data('current');
		feedData(url+'&sea='+value, false, function(data){toFeed(data); $(container).css({'opacity':'inherit'});spinner(container, 'hide');$('html,body').scrollTop($(container).offset().top);});
	},false,true);


};

var manageTablePageJson = function(info,callback){ 
	thalog('manageTablePageJson fn');

	var container 	= info.container;
	var url 		= info.url;
	var iniData		= info.iniData;
	var table 		= info.table;
	var sort 		= info.sort;
	var footerSum	= info.footerSum;
	var fSumCol		= iftn(info.footerSumCol,[]);
	var currency	= info.currency;
	var dc			= info.decimal;
	var ts			= info.thousand;
	var hideCol 	= info.hiddenColumns;
	var child		= info.allowChild;
	var childHide	= (info.allowChildHide)?info.allowChildHide:false;
	var childBg		= (info.allowChildBg)?info.allowChildBg:'';
	var pagination	= true;//(info.showPagination)?info.showPagination:false;
	var rowsLength	= 100;//(info.rowsLength)?info.rowsLength:false;
	var extraHtml	= info.extraHtml;
	var search		= info.search;//id del input
	var serverPagin	= info.serverPaging;//server side paging
	var extra 		= '';
	var p 			= (!serverPagin)?'p':''; 

	spinner(container, 'show');

	$(table).html('<tr><td><img src="/images/bg-report-list.png" width="100%" class="m-t-md"></td></tr>');

	function feedData(url,iniData,callback){
		if(!validity(iniData)){
			$.get(url, function(data){
				callback && callback(data);
			});
		}else{
			callback && callback(iniData);
		}
	}

	function toFeed(data){

		if(data.length < 1){
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
			$(table).html(noContent);
			spinner(container, 'hide');
			callback && callback();

			return false;
		}

		if(extraHtml == 'yes'){
			dats 	= data.split("[@]");
			extra 	= dats[0];
			data 	= dats[1];
			pages 	= dats[2];

			if($('#creditBlocks').length){
				extra = '';
			}
		}else if(extraHtml == 'maybe'){
			dats 	= data.split("[@]");
			extra 	= '';
			data 	= dats[1];
			pages 	= dats[2];
		}

		if(search){
			var dom = "<'row'<'col-sm-6 text-left hidden-xs'B><'col-sm-6' <'col-sm-6 "+search+"Holder no-padder'><'col-sm-6 hidden-xs no-padder'f> <'col-sm-6 visible-xs m-t-sm no-padder'f>>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'"+p+">";
		}else{
			var dom = "<'row'<'col-md-9  col-sm-6 text-left hidden-xs'B><'col-md-3  col-sm-6'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'"+p+">";
		}

		if(data.length > 0){
			var options = {
	 						"dom"					: dom,
	 						"paging"				: pagination,
	 						"lengthMenu"			: [ [100, 400, 800, -1], [100, 400, 800, "Todo"] ],
	 						"pageLength"			: rowsLength,
	 						"destroy"				: true,
	 						"stateSave"				: true,
	 						"oLanguage"				: { "sSearch": "" },
					        "order"					: [[ sort, "desc" ]],
					        "bSortClasses"			: false,
					        "buttons"				: 
					        [
					        	/*{ extend: 'copy', text: 'Copiar', className: 'btn btn-default', exportOptions: {stripHtml: false}  },*/
					        	/*{ extend: 'csv', text: 'A CSV', className: 'btn btn-default'  },*/
					        	{ extend: 'excel', text: 'Exportar listado', className: 'btn btn-default', exportOptions: {
					        		format: {
						                body: function ( data, row, column, node ) {
						                    // si es numerico, hago un unformat number o algo asi

						                    var data = $("<div/>").html(data).text();

						                    if($.isNumeric(data)){
						                    	return convertToRealNumber(data);
						                    }else{
						                    	return data;
						                    }
						                }
						            }
					        	} },
					        	/*{ extend: 'pdf', text: 'A PDF', className: 'btn btn-default'  },*/
					        	/*{ extend: 'print', text: 'Imprimir', className: 'btn btn-default', exportOptions: {stripHtml: false}  }*/
					        ],
	 						"language"				: 
	 									[{
										    "decimal"	: dc,
										    "thousands"	: ts,
										    "zeroRecords": '<div class="wrapper-sm text-muted text-center font-thin"> <div class="b h1 rounded wrapper-lg" style="width:103px; margin:0 auto;"><i class="icon-magnifier"></i></div> <span class="block m-t-md h3"> No pudimos encontrar lo que busca</span><span class="block text-sm">Intenta buscar utilizando otra de combinación de palabras</span></div>',
										    "paginate": {
										        "first":      "Principio",
										        "last":       "Último",
										        "next":       "Siguiente",
										        "previous":   "Anterior"
										    }
									  	}],

							"drawCallback"			: function () {
								$('.dataTables_paginate .paginate_button').addClass('btn btn-default');
					            $('.dataTables_paginate span').children('.paginate_button').each(function () {
					            	var current = $(this);
					            	current.addClass('btn');
									if(current.hasClass('current')){
										current.addClass('btn-info');
									}else{
										current.addClass('btn-default');
									}
								});					            
					        },
							
					        "footerCallback"		: function ( row, data, start, end, display ) {
					        	if(fSumCol.length > 0){
						            var api = this.api();
							        api.columns(fSumCol, {page:'current'}).every(function () {
							        	var $selector 	= $(this.nodes());
										var suma 		= 0,type,value=0,out;
										$selector.each(function(){
											value 	= $(this).data('order');
											type 	= $(this).data('format');
											if(validityChecker(value)){
												suma 	+= parseFloat(value);
											}
										});
										out = suma;
										var percent = (type=='percent')?'%':'';
										if(type == 'money'){
											out = formatNumber(suma,currency,dc,ts);
										}else if(isFloat(suma)){
											out = formatNumber(suma,'','yes')+percent;
										}else if(isInt(suma)){
											out = formatNumber(suma,'','no')+percent;
										}else{
											out = 0;
										}
										$(this.footer()).html(out);
							        });
						        }
					        }
						        
						  };

			if ($.fn.DataTable.isDataTable(table)){
				window.oTable.destroy();
			}

			$(table).html(data);

			if(extraHtml){
				$('.removable').remove();
				$(container).prepend(extra);
				$(container).append(pages);
			}

			window.oTable = $(table).DataTable(options);

			$.each(hideCol,function(i){
				oTable.column(i).visible(false);
			});

			if(child){
				oTable.rows().every(function () {
					var arr 	= this.data();
					var arra 	= Object.keys(arr);
			    	var a1 		= arr[arra.length-1];

			    	if(a1 && a1 != ""){
			    		this.child(a1).show();
					    this.nodes().to$().addClass('shown');	
					    
			    		var id = this.nodes().to$().data('id');
			    		var hidden = (childHide)?'hidden ':'';
			    		this.nodes().to$().closest('tr').next('tr').addClass(hidden+'childRow'+id+' '+childBg+' ');
				    }
			    });
			}

			$(table).width('100%');

			$('div.dataTables_filter input').addClass('form-control rounded pull-right').attr('placeholder','Filtrar listado');
			$('div.dataTables_filter').addClass('col-xs-12');
			$('div.dataTables_filter label').addClass('block');
			
			if(search){
				$('.'+search+'Holder').append('<div class="input-group pull-right m-b-sm"> <input type="text" class="form-control rounded" id="'+search+'" placeholder="Buscar"> <span class="input-group-btn"> <button data-url="'+url+'" data-container="'+container+'" class="btn btn-default btn-rounded searchDB" data-target="#'+search+'" type="button"><i class="material-icons">search</i></button> </span> </div>');
			}

			spinner(container, 'hide');

			ncmUI.setDarkMode.autoSelected();

			callback && callback(oTable);

		}
	}

	feedData(url, iniData, function(data){toFeed(data)});

	onClickWrap('.servPagination',function(event,tis){
		var url 		= tis.data('url');
		var container 	= tis.data('container');
		spinner(container, 'show');
		$(container).css({'opacity':'0.5'});
		feedData(url, false, function(data){toFeed(data); $(container).css({'opacity':'inherit'});spinner(container, 'hide');$('html,body').scrollTop($(container).offset().top);});
	},false,true);

	onClickWrap('.searchDB',function(event,tis){
		var target 		= tis.data('target');
		var value 		= $(target).val();
		var url 		= tis.data('url');
		var container 	= tis.data('container');

		console.log('searching',target,value);
		spinner(container, 'show');
		$(container).css({'opacity':'0.5'});
		var current = tis.data('current');
		feedData(url+'&sea='+value, false, function(data){toFeed(data); $(container).css({'opacity':'inherit'});spinner(container, 'hide');$('html,body').scrollTop($(container).offset().top);});
	},false,true);
};

var manageTableLoad = function(info,callback){ 
	thalog('manageTableLoad fn');

	var container 	= info.container;
	var url 		= info.url;
	var iniData		= info.iniData;
	var table 		= info.table;
	var sort 		= info.sort;
	var fSumCol		= iftn(info.footerSumCol,[]);
	var currency	= info.currency;
	var dc			= info.decimal;
	var ts			= info.thousand;
	var hideCol 	= info.hiddenColumns;
	var rowsLength	= iftn(info.rowsLength,500);
	var to			= info.offset;
	var hideMoreBtn = (info.noMoreBtn) ? info.noMoreBtn : false;
	var limit		= info.limit;
	var nolimit		= info.nolimit;
	var resultCount = iftn(info.resultCount,'∞');
	var loadMoreBtn = table.replace('.','').replace('#','') + 'Btn';
	var hideFilter 	= info.hideFilter;
	var colsFilter 	= iftn(info.colsFilter,'');
	var ncmTools 	= iftn(info.ncmTools,'');
	var tableId 	= table.replace('.', '').replace('#','');
	var child		= info.allowChild;
	var childHide	= (info.allowChildHide)?info.allowChildHide:false;
	var childBg		= (info.allowChildBg)?info.allowChildBg:'';

	spinner(container, 'show');

	$(table).html('<tr><td><img src="/images/bg-report-list.png" width="100%" class="m-t-md"></td></tr>');

	function feedData(url,iniData,callback){
		if(!validity(iniData)){
			$.get(url, function(data){
				callback && callback(data);
			});
		}else{
			callback && callback(iniData);
		}
	}

	function toFeed(data){

		if(data.length < 1){
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
			$(table).html(noContent);
			spinner(container, 'hide');
			callback && callback();

			return false;
		}

		var dom = "<'row'<'col-md-9 col-sm-6 text-left ncmTableTools" + tableId + " hidden-print'B><'col-md-3 col-sm-6 no-padder hidden-print'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'>";		

		if(data.length > 0){
			var options = {
	 						"dom"					: dom,
	 						"searchDelay" 			: 300,
	 						"deferRender" 			: true,
	 						"orderClasses" 			: false,
	 						"paging"				: false,
	 						"pageLength"			: limit,
	 						"destroy"				: true,
	 						"stateSave"				: true,
					        "order"					: [[ sort, "desc" ]],
					        "bSortClasses"			: false,
					        /*"scrollY" 			: true,
					        "scrollX" 				: true,
					        "scrollCollapse" 		: true,
					        "fixedColumns" 			: true,*/
					        "language"				: 	{
					        								"sSearch": "",
														    "decimal"		: dc,
														    "thousands"		: ts,
														    "zeroRecords"	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> No pudimos encontrar lo que busca</div><div>Intente utilizando otra combinación de palabras</div></div>',
														    "emptyTable" 	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3">No hay información disponible</div></div>'
													  	},
					        "buttons"				: 
					        [
					        	{ extend: 'excel', text: 'Exportar listado', className: 'btn btn-default', exportOptions: {
					        		format: {
						                body: function ( data, row, column, node ) {
						                    // si es numerico, hago un unformat number o algo asi

						                    var data = $("<div/>").html(data).text();

						                    if($.isNumeric(data)){
						                    	return convertToRealNumber(data);
						                    }else{
						                    	return data;
						                    }
						                }
						            }
					        	} },
					        ],
					        "footerCallback"		: function ( row, data, start, end, display ) {
					        	if(fSumCol.length > 0){
						            var api = this.api();
							        api.columns(fSumCol, {page:'current'}).every(function () {
							        	var $selector 	= $(this.nodes());
										var suma 		= 0,type,value=0,out;
										$selector.each(function(){
											value 	= $(this).data('order');
											type 	= $(this).data('format');
											if(value != ''){
												if(value < 0){
													suma 	-= parseFloat(Math.abs(value));
												}else{
													suma 	+= parseFloat(value);
												}
											}
										});
										out = suma;
										var percent = (type=='percent')?'%':'';
										if(type == 'money'){
											out = formatNumber(suma,currency,dc,ts);
										}else if(isFloat(suma)){
											out = formatNumber(suma,'','yes') + percent;
										}else if(isInt(suma)){
											out = formatNumber(suma,'','no') + percent;
										}else{
											out = 0;
										}
										$(this.footer()).html(out);
							        });
						        }
					        }
						        
						  };

			if ($.fn.DataTable.isDataTable(table)){
				window.oTable.destroy();
			}

			$(table).html(data);

			$.fn.dataTable.ext.errMode = 'none';

			window.oTable = $(table).DataTable(options);

			$.each(hideCol,function(i){
				oTable.column(i).visible(false);
			});

			if(child){
				oTable.rows().every(function () {
					var arr 	= this.data();
					var arra 	= Object.keys(arr);
			    	var a1 		= arr[arra.length-1];

			    	if(a1 && a1 != ""){
			    		this.child(a1).show();
					    this.nodes().to$().addClass('shown');	
			    		var id 		= this.nodes().to$().data('id');
			    		var hidden 	= (childHide)?'hidden ':'';
			    		this.nodes().to$().closest('tr').next('tr').addClass(hidden + 'childRow' + id + ' ' + childBg);
				    	
				    }
			    });
			}

			$(table).width('100%');

			$('div.dataTables_filter input').addClass('form-control rounded pull-right').attr('placeholder','Filtrar listado');
			$('div.dataTables_filter').addClass('col-xs-12');
			$('div.dataTables_filter label').addClass('block');
			if(hideFilter){
				$('div.dataTables_filter input').addClass('hidden');
			}

			//Filters and tools
			if(validity(colsFilter) || validity(ncmTools)){
				var colsBtn = '';
				if(colsFilter){
					colsBtn = tableColumnsFilterBldr(colsFilter,oTable);
				}

				var fltr = 	'<div class="col-md-9 no-padder m-b-xs">' + 
							'	<span class="btn-group">' +
									colsBtn + 
									ncmTools.left + 
							'	</span>' +
							'</div>';
				fltr 	+= '<div class="col-md-3 no-padder m-b-xs">' + ncmTools.right + '</div>';

				$('.ncmTableTools' + tableId).html(fltr);
			}
			//

			//load more btn
			var rowsInTable = $(table + ' tr').length - 2;

			$('.lodMoreBtnHolder').remove();

			//if(rowsInTable < limit){
				//no muestro el boton de cargar mas si hay menos resultados que el limite
			//}else{
				var loadMore 	= 	'<div class="col-xs-12 text-center hidden-print lodMoreBtnHolder">' +
									'	<div class="text-center text-sm">Mostrando <span id="' + loadMoreBtn + 'cnt">' + rowsInTable + '</span> líneas</div>' +
								    '	<a href="#" class="btn btn-lg btn-rounded btn-dark text-u-c font-bold hidden" id="' + loadMoreBtn + '">Cargar Más</a>';
				if(nolimit){
					loadMore   += 	'	<a href="#" class="text-u-c text-xs hidden block m-t" id="' + loadMoreBtn + 'all">Cargar masivamente</a>';
				}
				
				loadMore 	   +=	'</div>';
			//}

			if(!window.standAlone){
				scrollToTopNcm();
				scrollToBottomNcm();
			}

			if(!hideMoreBtn){
				$(container).append(loadMore);
			}

			to 			= to + limit;

			onClickWrap('#' + loadMoreBtn,function(event,tis){

				var load 		= url + "&part=true&offset=" + to;
				spinner(container, 'show');
				$('#' + loadMoreBtn).text('Cargando...').addClass('disabled');
				$.get(load,function(mdata){
					spinner(container, 'hide');
					if(!mdata){
						$('#' + loadMoreBtn).addClass('disabled').text('No hay más resultados');
						message('Ya no hay resultados','warning');
						return false;
					}

					$('#' + loadMoreBtn).text('Cargar Más').removeClass('disabled');

					var rows 	= explodes('[@]',mdata);

					to 			= to + limit;

					$.each(rows,function(i,row){
						if(row){
							oTable.row.add($(row));
						}
					});

					oTable.draw();

					var rowsInTable = $(table + ' tr').length - 1;
					$('#' + loadMoreBtn + 'cnt').text(rowsInTable);

					$('[data-toggle="tooltip"]').tooltip();

					$('a.scrollToTop, a.scrollToBottom').hide();
					$('a.scrollToBottom').show();
				});

			},false,true);

			onClickWrap('#' + loadMoreBtn + 'all',function(event,tis){

				if(!nolimit){
					return false;
				}

				var tisLimit 	= 1000;

				var load 		= url + "&part=true&offset=" + to + '&limit=' + tisLimit;
				
				spinner(container, 'show');
				$('#' + loadMoreBtn).text('Cargando...').addClass('disabled');

				$.get(load,function(mdata){
					spinner(container, 'hide');
					if(!mdata){
						$('#' + loadMoreBtn).addClass('disabled').text('No hay más resultados');
						$('#' + loadMoreBtn + 'all').addClass('hidden');
						message('Ya no hay resultados','warning');
						return false;
					}

					$('#' + loadMoreBtn).text('Cargar Más').removeClass('disabled');

					var rows 	= explodes('[@]',mdata);

					to 			= to + tisLimit;
					

					/*oTable.row.add($(rows.join())).draw();
					var rowsInTable = $(table + ' tr').length - 1;
					$('#' + loadMoreBtn + 'cnt').text(rowsInTable);*/

					$.each(rows,function(i,row){
						if(row){
							oTable.row.add($(row));
						}
					});

					oTable.draw();
					var rowsInTable = $(table + ' tr').length - 1;
					$('#' + loadMoreBtn + 'cnt').text(rowsInTable);
					$('[data-toggle="tooltip"]').tooltip();

					$('a.scrollToTop, a.scrollToBottom').hide();
					$('a.scrollToBottom').show();
				});

			},false,true);

			onClickWrap('.exportTable',function(event,tis){
				var theTable 	= tis.data('table');
				var name 		= tis.data('name');

				table2Xlsx(theTable,name);
			},false,true);

			onClickWrap('a.toggle-col',function(event,tis){
				var saved 	= simpleStorage.get(colsFilter.name);
				var index 	= tis.data('column');
			    var column 	= oTable.column(index);

			    if(!column.visible()){
			    	column.visible(true);
			    	tis.find('i').removeClass('text-white').addClass('text-info');
			    	saved[index].visible = true;
			    	simpleStorage.set(name, saved);
			    }else{
			    	column.visible(false);
			    	tis.find('i').removeClass('text-info').addClass('text-white');
			    	saved[index].visible = false;
			    	simpleStorage.set(name, saved);
			    }
			},false,true);

			onClickWrap('a.resetCols',function(event,tis){
				simpleStorage.deleteKey(colsFilter.name);
				//simpleStorage.flush();
				location.reload();
			},false,true);

			$('#' + loadMoreBtn).removeClass('hidden');
			$('#' + loadMoreBtn + 'all').removeClass('hidden');
			
			$('[data-toggle="tooltip"]').tooltip();

			spinner(container, 'hide');

			ncmUI.setDarkMode.autoSelected();

			callback && callback(oTable);
		}
	}

	feedData(url, iniData, function(data){
		toFeed(data);
	});
};

var ncmUI = {
	setDarkMode : {
		auto : function(){
			
            if(window.matchMedia('prefers-color-scheme: dark').matches){
				ncmUI.setDarkMode.setDark();
            }else{
				
            }

            var myDate = new Date(); 
			if (myDate.getHours() > 18 && myDate.getHours() < 5 ){
				ncmUI.setDarkMode.setDark();
			}
		},
		autoSelected : function(){
			var darkModeStored = (typeof simpleStorage !== "undefined") ? simpleStorage.get('darkMode') : false;
			if(darkModeStored){
				ncmUI.setDarkMode.setDark();
			}
		},
		isSet : false,
		setDark : function(){
			if(ncmUI.setDarkMode.isSet){
			//	return false;
			}

			$('.panel,h1,.h1').addClass('text-white');
			$('h2,.h2,h3,.h3').addClass('text-light');
			$('.b-b').addClass('b-dark');

			$('.text-white').removeClass('text-black').addClass('text-white');
			$('.text-dark').removeClass('text-dark').addClass('text-white');
			$('.text-black').removeClass('text-black').addClass('text-white');


			$('.panel').removeClass('panel').addClass('bg-black');
			$('.bg-white').removeClass('bg-white').addClass('bg-black');
			$('.bg-light').removeClass('bg-light').addClass('bg-dark');
			$('.lter').removeClass('lter').addClass('dker');
			$('.lt').removeClass('lt').addClass('dk');
			$('.dk').removeClass('dk').addClass('lt');
			$('.dker').removeClass('dker').addClass('lter');
			$('.btn-default').removeClass('btn-default').addClass('btn-dark');
			$('.badge').addClass('text-dark');

			$('.dropdown-menu').removeClass('bg-white');			

			$('.table tbody .label').removeClass('bg-light').addClass('bg-dark');

			$('body,html').removeClass('bg-light bg-black lt dker lter').addClass('bg-dark dk darkMode');

			chartLineGraphOptions.scales.yAxes[0].gridLines.color 	= "rgba(53,70,80,1)";
			chartLineGraphOptions.scales.xAxes[0].gridLines.color 	= "rgba(53,70,80,1)";
			chartLineGraphOptions.scales.xAxes[0].ticks.fontColor 	= '#9badb9';
			chartLineGraphOptions.scales.yAxes[0].ticks.fontColor 	= '#9badb9';
			chartLineGraphOptions.legend.labels.fontColor 			= '#9badb9';

			chartBarStackedGraphOptions.scales.yAxes[0].gridLines.color 	= "rgba(53,70,80,1)";
			chartBarStackedGraphOptions.scales.xAxes[0].gridLines.color 	= "rgba(53,70,80,1)";
			chartBarStackedGraphOptions.scales.xAxes[0].ticks.fontColor 	= '#9badb9';
			chartBarStackedGraphOptions.scales.yAxes[0].ticks.fontColor 	= '#9badb9';
			chartBarStackedGraphOptions.legend.labels.fontColor 			= '#9badb9';


			$('#bodyContent,#nav').removeClass('bg-dark bg-light lt lter bg dk dker').addClass('bg-black lt');

			$('#nav #vbox').removeClass('bg-dark bg-light lt lter bg dk dker').addClass('bg-black dk');		

			$('iframe').each(function(i){
				if($(this).length){
					var tis = $(this);
					var src = tis.attr('src');
					if(src && ( src.search("light") || src.search("dark") ) ){
						var res = src.split('light').join('dark');
						tis.attr('src',res);
					}
				}
			});

			ncmUI.setDarkMode.isSet = true;
		}
	},
	verticalAlign : function($el,elH,wH){
		var wH 		= iftn(wH,$(window).height());
		var elH 	= iftn(elH,$el.outerHeight());
		var rest 	= wH - elH;
		var hRest 	= rest / 2;

		$el.css({'margin-top' : hRest + 'px'});
	}
};

var ncmDTHideRows = 	function($selected,success,fail,forceHide){
	var hideClasses = 'text-l-t text-muted hidden-print noxls b-danger';

	if($selected.length > 0){
		$selected.each(function(i,v){
			var tiss = $(this);
			if(forceHide){
				tiss.addClass(hideClasses);
			}else{
				tiss.toggleClass(hideClasses);
			}
		});

		success && success();
	}else{
		fail && fail();
	}
};

var ncmDataTables = function(options,callback){
	/**/
	var ncmTablesHTML = {
							opts 	: {},
							oTable 	: false,
							loadBulkLimit : 1000,
							callback : false,
							init : function(info,callback){
								ncmTablesHTML.opts 		= info;
								ncmTablesHTML.opts.el 	= $(info.table);
								ncmTablesHTML.callback 	= callback;

								ncmTablesHTML.DTOpts = {
									"dom"					: "<'row'<'col-md-9 col-sm-6 text-left ncmTableTools" + ncmTablesHTML.tableId() + " hidden-print'B><'col-md-3 col-sm-6 no-padder hidden-print'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'>",
									"deferRender" 			: true,
									"orderClasses" 			: false,
									"paging"				: false,
									"pageLength"			: ncmTablesHTML.opts.limit,
									"destroy"				: true,
									"stateSave"				: true,
									"ordering"				: true,
							        "order"					: ncmTablesHTML.opts.sort ? [[ ncmTablesHTML.opts.sort, "desc" ]] : false,
							        "bSortClasses"			: false,
							        "columnDefs" 			: [
							        							{ targets : 'hidden', searchable: false, visible: false },
							        							{ targets : 'no-search', searchable: false, orderable: true },
							        							{ targets : 'no-order', searchable: true, orderable: false },
       															{ targets : 'ignored', searchable: false, orderable: false }
       														],
							        "language"				: 	{
							        								"sSearch" 		: "",
																    "decimal"		: ncmTablesHTML.opts.decimal,
																    "thousands"		: ncmTablesHTML.opts.thousand,
																    "zeroRecords"	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> No pudimos encontrar lo que busca</div><div>Intente utilizando otra combinación de palabras</div></div>',
																    "emptyTable" 	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3">No hay información disponible</div></div>'
															  	},
							        "footerCallback"		: function ( row, data, start, end, display ) {
							        	var fSumCol		= iftn(ncmTablesHTML.opts.footerSumCol,[]);

							        	if(fSumCol.length > 0){
								            var api = this.api();
									        api.columns(fSumCol, {page:'current'}).every(function () {
									        	var $selector 	= $(this.nodes());
												var suma 		= 0, type, value = 0, out;

												$selector.each(function(){
													value 	= $(this).data('order');
													type 	= $(this).data('format');
													var parentTr = $(this).parent('tr');
													if(value != '' && !parentTr.hasClass('hidden-print noxls')){
														if(value < 0){
															suma 	-= parseFloat( Math.abs(value) );
														}else{
															suma 	+= parseFloat(value);
														}
													}
												});

												out = suma;
												var percent = (type == 'percent') ? '%' : '';
												if(type == 'money'){
													out = formatNumber(suma,ncmTablesHTML.opts.currency,ncmTablesHTML.opts.decimal,ncmTablesHTML.opts.thousand);
												}else if(isFloat(suma)){
													out = formatNumber(suma,'','yes') + percent;
												}else if(isInt(suma)){
													out = formatNumber(suma,'','no') + percent;
												}else{
													out = 0;
												}
												$(this.footer()).html(out);
									        });
								        }
							        }
								};

								ncmTablesHTML.loadTo = parseInt( ncmTablesHTML.opts.offset ) + parseInt( ncmTablesHTML.opts.limit );

								//spinner(ncmTablesHTML.opts.container, 'show');

								ncmTablesHTML.opts.el.html('<tr><td><img src="/images/bg-report-list.png" width="100%" class="m-t-md"></td></tr>');

								ncmTablesHTML.getData(ncmTablesHTML.opts.url, ncmTablesHTML.opts.iniData, function(data){
									ncmTablesHTML.cacheData = data;
									ncmTablesHTML.toFeed(data);
								});
							},
							cacheData : false,
							getData : function(url,iniData,callback){
								if(!validity(iniData)){
									var xhr = ncmHelpers.load({
										url 		: url,
										httpType 	: 'GET',
										hideLoader 	: true,
										type 		: 'json',
										success 	: function(data){
											callback && callback(data);
										}
									});
									window.xhrs.push(xhr);
								}else{
									callback && callback(iniData);
								}
							},
							tableOps : function(opts){
								var menuTop = '';
								var menuBottom = '';
								if(ncmHelpers.validInObj(opts,'menuTop')){
									menuTop = opts.menuTop;
								}

								if(ncmHelpers.validInObj(opts,'menuBottom')){
									menuBottom = opts.menuBottom;
								}
								
								var out = 	'<span class="dropdown" title="Opciones" data-placement="right">' +
											'	<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown">' +
											'   	<span class="material-icons">more_horiz</span>' +
											'	</a>' +
											'	<ul class="dropdown-menu animated fadeIn speed-4x" role="menu">' +
													menuTop +
											'  		<li>' +
											'			<a class="exportTable text-default" data-table="' + ncmTablesHTML.opts.tableName + '" data-name="' + ncmTablesHTML.opts.fileTitle + '" href="#">' +
											'				<span class="material-icons m-r-sm">get_app</span> A Excel' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="selectAllRows text-default" href="#">' +
											'				<span class="material-icons m-r-sm">checklist</span> Seleccionar todo' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="hideSelectedRows text-default" href="#">' +
											'				<span class="material-icons m-r-sm selectCounter">visibility_off</span> Mostrar/Ocultar' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="text-default" href="javascript:window.print();">' +
											'				<span class="material-icons m-r-sm">print</span> Imprimir' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="text-default manualSort" href="#">' +
											'				<span class="material-icons m-r-sm">drag_indicator</span> Orden Manual' +
											'			</a>' +
											'		</li>' +
													menuBottom +
											'	</ul>' +
											'</span>';

								return out;
							},
							toggleColumns(options,oTable){
								if(!validity(options.menu) || !simpleStorage){
									return '';
								}

								var name 			= 'col' + options.name;
								var menu 			= options.menu;

								if(simpleStorage.hasKey(name)){
									menu 	= simpleStorage.get(name);
								}else{
									simpleStorage.set(name, menu);
								}

								var html = 	'<span class="dropdown" title="Mostrar/Ocultar Columnas" data-placement="right">' +
											'	<ul class="dropdown-menu animated fadeIn speed-4x" id="groupActionsMenu">';
														$.each(menu,function(i,val){
															oTable.column(i).visible(val.visible);

															if(val.name){
																var icon = 'text-white';

																if(val.visible){
																	icon = 'text-info';
																}

																html += '<li><a href="#" data-column="' + i + '" class="toggle-col colTglBtn' + val.index + ' text-default">' +
																		'	<i class="material-icons m-r-xs ' + icon + '">check</i>' + val.name + 
																		'</a></li>';
															}
														});
									html += '		<li><a href="#" class="resetCols"><span class="text-danger"><i class="material-icons m-r-xs">update</i> Restaurar</span></a></li>' +	
											'	</ul>' +
											'	<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown" id="groupActions"><span class="material-icons">view_column</span></a>' +
											'</span>';

								return html;
							},
							loadMoreBtn : function(){
								return ncmTablesHTML.tableId() + 'Btn';
							},
							tableId : function(){
								return ncmTablesHTML.opts.table.replace('.', '').replace('#','');
							},
							loadTo : 0,
							events : function(){

								ncmHelpers.onClickWrap('#' + ncmTablesHTML.loadMoreBtn(),function(event,tis){

									var load 		= ncmTablesHTML.opts.url + "&part=true&offset=" + ncmTablesHTML.loadTo;
									spinner(ncmTablesHTML.opts.container, 'show');
									$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargando...').addClass('disabled');

									var xhr = ncmHelpers.load({
										url 		: load,
										httpType 	: 'GET',
										hideLoader 	: true,
										
										success 	: function(mdata){
											
											spinner(ncmTablesHTML.opts.container, 'hide');

											ncmTablesHTML.loadTo = parseInt( ncmTablesHTML.loadTo ) + parseInt( ncmTablesHTML.opts.limit );

											if(!mdata){
												$('#' + ncmTablesHTML.loadMoreBtn()).addClass('disabled').text('No hay más resultados');
												message('Ya no hay resultados','warning');
												return false;
											}

											$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargar Más').removeClass('disabled');

											var rows 	= explodes('[@]',mdata);

											$.each(rows,function(i,row){
												if(row){
													ncmTablesHTML.oTable.row.add($(row));
												}
											});

											ncmTablesHTML.oTable.draw();

											var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);

											$('[data-toggle="tooltip"]').tooltip();

											$('a.scrollToTop, a.scrollToBottom').hide();
											$('a.scrollToBottom').show();

											ncmTablesHTML.events();

											ncmDTHideRows(ncmTablesHTML.oTable.rows( '.internal' ).nodes().to$(),function(){
												ncmTablesHTML.oTable.draw();
											},false,true);
										}
									});

									window.xhrs.push(xhr);

								});

								ncmHelpers.onClickWrap('#' + ncmTablesHTML.loadMoreBtn() + 'all',function(event,tis){

									if(!ncmTablesHTML.opts.nolimit){
										return false;
									}

									var load 		= ncmTablesHTML.opts.url + "&part=true&offset=" + ncmTablesHTML.loadTo + '&limit=' + ncmTablesHTML.loadBulkLimit;
									
									spinner(ncmTablesHTML.opts.container, 'show');
									$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargando...').addClass('disabled');

									var xhr = ncmHelpers.load({
										url 		: load,
										httpType 	: 'GET',
										hideLoader 	: true,
										success 	: function(mdata){
									
											spinner(ncmTablesHTML.opts.container, 'hide');
											if(!mdata){
												$('#' + ncmTablesHTML.loadMoreBtn()).addClass('disabled').text('No hay más resultados');
												$('#' + ncmTablesHTML.loadMoreBtn() + 'all').addClass('hidden');
												message('Ya no hay resultados','warning');
												return false;
											}

											$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargar Más').removeClass('disabled');

											var rows 	= explodes('[@]',mdata);

											ncmTablesHTML.loadTo 			= parseInt( ncmTablesHTML.loadTo ) + parseInt( ncmTablesHTML.loadBulkLimit );

											/*oTable.row.add($(rows.join())).draw();
											var rowsInTable = $(table + ' tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);*/

											$.each(rows,function(i,row){
												if(row){
													ncmTablesHTML.oTable.row.add($(row));
												}
											});

											ncmTablesHTML.oTable.draw();
											var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);
											$('[data-toggle="tooltip"]').tooltip();

											$('a.scrollToTop, a.scrollToBottom').hide();
											$('a.scrollToBottom').show();
											ncmTablesHTML.events();
											ncmDTHideRows(ncmTablesHTML.oTable.rows( '.internal' ).nodes().to$(),function(){
												ncmTablesHTML.oTable.draw();
											},false,true);
										}
									});

									window.xhrs.push(xhr);

								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .selectAllRows',function(event,tis){
									var $allRows 	= ncmTablesHTML.opts.el.find('tbody tr');

									$.each($allRows,function(){
										shiftKeyRow.shiftKeyCallBack(event,$(this));
									});
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .hideSelectedRows',function(event,tis){
									var $selected 	= ncmTablesHTML.opts.el.find('tbody tr.selected');

									ncmDTHideRows($selected,function(){
										ncmTablesHTML.unSelectRows();
										ncmTablesHTML.oTable.draw();
									},function(){
										ncmTablesHTML.opts.el.find('tbody tr').removeClass(hideClasses + ' b-info b-l');
										ncmTablesHTML.oTable.draw();
									});

								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .exportTable',function(event,tis){
									var theTable 	= tis.data('table');
									var name 		= tis.data('name');
									table2Xlsx(theTable,name);
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .manualSort',function(event,tis){
									var $sort = ncmTablesHTML.opts.el.find('tbody');

									if(ncmTablesHTML.sortable){
										ncmTablesHTML.sortable = false;
										$sort.find('tr').removeClass('grab grabbing').addClass('pointer');

										$sort.sortable("destroy");
										message('Orden manual inhabilitado','success');
									}else{
										ncmTablesHTML.sortable = true;
										$sort.find('tr').removeClass('pointer').addClass('grab');

										if(!isMobile.phone){
											$sort.sortable({
												start: function(event, ui) {
										            ui.item.css({'opacity':'0.6'}).toggleClass('grab grabbing');
										        },
										        stop: function(event, ui) {
										            ui.item.css({'opacity':'1'}).toggleClass('grab grabbing');
										        }
											});
											message('Orden manual habilitado','success');
										}
									}
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' a.toggle-col',function(event,tis){
									var name 	= 'col' + ncmTablesHTML.opts.colsFilter.name;
									var menu 	= ncmTablesHTML.opts.colsFilter.menu;
									var saved 	= simpleStorage.get(name);
									var index 	= tis.data('column');
								    var column 	= ncmTablesHTML.oTable.column(index);

								    if(!column.visible()){
								    	column.visible(true);
								    	tis.find('i').removeClass('text-white').addClass('text-info');
								    	saved[index].visible = true;
								    	simpleStorage.set(name, saved);
								    }else{
								    	column.visible(false);
								    	tis.find('i').removeClass('text-info').addClass('text-white');
								    	saved[index].visible = false;
								    	simpleStorage.set(name, saved);
								    }
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' a.resetCols',function(event,tis){
									var name 	= 'col' + ncmTablesHTML.opts.colsFilter.name;

									simpleStorage.deleteKey(name);
									//simpleStorage.flush();
									location.reload();
								});

								
								var shiftKeyRow = {
									shiftKeyCallBack : function(event,tis){
										var classes = 'selected bg-light dker b-l b-info b-3x';

										if(!tis.hasClass('selected')){
											tis.addClass(classes);
										}else{
											tis.removeClass(classes);
										}

										var rowsInTable = ncmTablesHTML.oTable.rows('.selected').count();

										if(rowsInTable > 0){
											$('.hideSelectedRows .selectCounter').removeClass('material-icons').html('<span class="badge bg-info">' + rowsInTable + '</span>');
										}else{
											$('.hideSelectedRows .selectCounter').addClass('material-icons').html('visibility_off');
										}
										
										ncmTablesHTML.shiftClickCB && ncmTablesHTML.shiftClickCB(event,tis);
									}
								};
								
								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' ' + ncmTablesHTML.opts.table + ' tbody tr.clickrow',function(event,tis){
									$(ncmTablesHTML.opts.container + ' ' + ncmTablesHTML.opts.table + ' tbody tr.editting').removeClass('editting');
									tis.addClass('editting');

									ncmTablesHTML.opts.clickCB && ncmTablesHTML.opts.clickCB(event,tis);
								},false,shiftKeyRow);

								if(isMobile.any){
									$(ncmTablesHTML.opts.container + ' ' + ncmTablesHTML.opts.table + ' tbody tr.clickrow').off('press').on('press',function(event){
										shiftKeyRow.shiftKeyCallBack(event,$(this));
									});
								}

								ncmTablesHTML.oTable.off('draw').on('draw', function () {
									var rowsInTable = ncmTablesHTML.oTable.rows(':visible').count();
									$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);
								    ncmTablesHTML.events();
								} );

								ncmUI.setDarkMode.autoSelected();
								
							},
							DTOpts : {},
							sortable : false,
							DTStart: function(){
								if( $.fn.DataTable.isDataTable(ncmTablesHTML.opts.table) ){
									ncmTablesHTML.oTable.destroy();
								}
								$.fn.dataTable.ext.errMode 	= 'none';
								ncmTablesHTML.oTable 		= ncmTablesHTML.opts.el.DataTable(ncmTablesHTML.DTOpts);
							},
							toFeed : function(data){
								if(data.length < 1){
									var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
									ncmTablesHTML.opts.el.html(noContent);
									//spinner(ncmTablesHTML.opts.container, 'hide');
									callback && callback();
									return false;
								}

								ncmTablesHTML.opts.el.html(data);

								ncmTablesHTML.DTStart();

								$.each(ncmTablesHTML.opts.hiddenColumns,function(i){
									ncmTablesHTML.oTable.column(i).visible(false);
								});

								if(ncmTablesHTML.opts.allowChild){
									ncmTablesHTML.oTable.rows().every(function () {
										var arr 	= this.data();
										var arra 	= Object.keys(arr);
								    	var a1 		= arr[arra.length-1];

								    	if(a1 && a1 != ""){
								    		this.child(a1).show();
										    this.nodes().to$().addClass('shown');	
								    		var id 		= this.nodes().to$().data('id');
								    		var hidden 	= (ncmTablesHTML.opts.allowChildHide) ? 'hidden ' : '';
								    		this.nodes().to$().closest('tr').next('tr').addClass(hidden + 'childRow' + id + ' ' + ncmTablesHTML.opts.allowChildBg);
									    }
								    });
								}

								ncmTablesHTML.opts.el.width('100%');
								$('div.dataTables_filter input').addClass('form-control rounded pull-right').attr('placeholder','Filtrar listado');
								$('div.dataTables_filter').addClass('col-xs-12');
								$('div.dataTables_filter label').addClass('block');

								if(ncmTablesHTML.opts.hideFilter){
									$('div.dataTables_filter input').addClass('hidden');
								}

								//Filters and tools
								if(validity(ncmTablesHTML.opts.colsFilter) || validity(ncmTablesHTML.opts.ncmTools)){
									var colsBtn = '';
									if(ncmTablesHTML.opts.colsFilter){
										colsBtn = ncmTablesHTML.toggleColumns( ncmTablesHTML.opts.colsFilter, ncmTablesHTML.oTable );
									}

									var fltr = 	'<div class="col-md-9 no-padder m-b-xs">' + 
												'	<span class="btn-group">' +
														ncmTablesHTML.tableOps(ncmTablesHTML.opts.ncmTools.ops) +
														colsBtn + 
														ncmTablesHTML.opts.ncmTools.left + 
												'	</span>' +
												'</div>';
									fltr 	+= '<div class="col-md-3 no-padder m-b-xs">' + ncmTablesHTML.opts.ncmTools.right + '</div>';

									$('.ncmTableTools' + ncmTablesHTML.tableId()).html(fltr);
								}
								//

								//load more btn
								var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 2;

								$('.lodMoreBtnHolder').remove();

								//if(rowsInTable < limit){
									//no muestro el boton de cargar mas si hay menos resultados que el limite
								//}else{
									var loadMore 	= 	'<div class="col-xs-12 text-center hidden-print lodMoreBtnHolder">' +
														'	<div class="text-center text-sm">Mostrando <span id="' + ncmTablesHTML.loadMoreBtn() + 'cnt">' + rowsInTable + '</span> líneas</div>' +
													    '	<a href="#" class="btn btn-lg btn-rounded btn-dark text-u-c font-bold hidden" id="' + ncmTablesHTML.loadMoreBtn() + '">Cargar Más</a>';
									if(ncmTablesHTML.opts.nolimit){
										loadMore   += 	'	<a href="#" class="text-u-c text-xs hidden block m-t" id="' + ncmTablesHTML.loadMoreBtn() + 'all">Cargar masivamente</a>';
									}
									
									loadMore 	   +=	'</div>';
								//}

								if(!window.standAlone){
									scrollToTopNcm('remove');
									scrollToBottomNcm('remove');
									scrollToTopNcm();
									scrollToBottomNcm();
								}

								if(!ncmTablesHTML.opts.noMoreBtn){
									$(ncmTablesHTML.opts.container).append(loadMore);
								}

								ncmTablesHTML.events();

								$('#' + ncmTablesHTML.loadMoreBtn()).removeClass('hidden');
								$('#' + ncmTablesHTML.loadMoreBtn() + 'all').removeClass('hidden');
								$('[data-toggle="tooltip"]').tooltip();

								ncmDTHideRows(ncmTablesHTML.oTable.rows( '.internal' ).nodes().to$(),function(){
									ncmTablesHTML.oTable.draw();
								},false,true);

								ncmTablesHTML.callback && ncmTablesHTML.callback(ncmTablesHTML.oTable,this);
							},
							unSelectRows : function(){
								var $el = ncmTablesHTML.opts.el.find('tr.selected');
								$el.each(function(){
									$(this).removeClass('selected bg-light dker');
								});
								$('.hideSelectedRows .selectCounter').addClass('material-icons').html('visibility_off');
							}
						};

	

	return ncmTablesHTML.init(options,callback);
}

var ncmDataTablesReset = function(oTable,tableOps){
	var $newData 	= $(tableOps.iniData).find('tr');
	var newDataArr 	= [];

	$.each($newData,function(i,data){
		var newRow 	= $(data).get(0).outerHTML;
		newDataArr.push(newRow);
	});

	oTable.rows().remove();
	var arrTotals = newDataArr.length - 1;
	$.each(newDataArr,function(i,data){
		if(data && i > 0 && i < arrTotals){
			oTable.row.add($(data));
		}
	});
	oTable.draw();
	spinner(tableOps.container, 'hide');
}

var ncmDataTablesJson = function(options,callback){
	/**/
	var ncmTablesHTML = {
							opts 	: {},
							oTable 	: false,
							loadBulkLimit : 1000,
							callback : false,
							init : function(info,callback){
								ncmTablesHTML.opts 		= info;
								ncmTablesHTML.opts.el 	= $(info.table);
								ncmTablesHTML.callback 	= callback;

								ncmTablesHTML.DTOpts = {
									"dom"					: "<'row'<'col-md-9 col-sm-6 text-left ncmTableTools" + ncmTablesHTML.tableId() + " hidden-print'B><'col-md-3 col-sm-6 no-padder hidden-print'f>> <'col-sm-12 no-padder't><'col-xs-12 m-b text-center'>",
									"deferRender" 			: true,
									"orderClasses" 			: false,
									"paging"				: false,
									"pageLength"			: ncmTablesHTML.opts.limit,
									"destroy"				: true,
									"stateSave"				: true,
									"ordering"				: true,
							        "order"					: ncmTablesHTML.opts.sort ? [[ ncmTablesHTML.opts.sort, "desc" ]] : false,
							        "bSortClasses"			: false,
							        "columnDefs" 			: [
							        							{ targets : 'hidden', searchable: false, visible: false },
							        							{ targets : 'no-search', searchable: false, orderable: true },
							        							{ targets : 'no-order', searchable: true, orderable: false },
       															{ targets : 'ignored', searchable: false, orderable: false }
       														],
							        "language"				: 	{
							        								"sSearch" 		: "",
																    "decimal"		: ncmTablesHTML.opts.decimal,
																    "thousands"		: ncmTablesHTML.opts.thousand,
																    "zeroRecords"	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> No pudimos encontrar lo que busca</div><div>Intente utilizando otra combinación de palabras</div></div>',
																    "emptyTable" 	: '<div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3">No hay información disponible</div></div>'
															  	},
									"data" 					: ncmTablesHTML.opts.iniData,
									"columns" 				: ncmTablesHTML.opts.columns,
							        "footerCallback"		: function ( row, data, start, end, display ) {
							        	var fSumCol		= iftn(ncmTablesHTML.opts.footerSumCol,[]);
							        	if(fSumCol.length > 0){
								            var api = this.api();
									        api.columns(fSumCol, {page:'current'}).every(function () {
									        	var $selector 	= $(this.nodes());
												var suma 		= 0, type, value = 0, out;

												$selector.each(function(){
													value 	= $(this).data('order');
													type 	= $(this).data('format');
													var parentTr = $(this).parent('tr');
													if(value != '' && !parentTr.hasClass('hidden-print noxls')){
														if(value < 0){
															suma 	-= parseFloat( Math.abs(value) );
														}else{
															suma 	+= parseFloat(value);
														}
													}
												});

												out = suma;
												var percent = (type == 'percent') ? '%' : '';
												if(type == 'money'){
													out = formatNumber(suma,ncmTablesHTML.opts.currency,ncmTablesHTML.opts.decimal,ncmTablesHTML.opts.thousand);
												}else if(isFloat(suma)){
													out = formatNumber(suma,'','yes') + percent;
												}else if(isInt(suma)){
													out = formatNumber(suma,'','no') + percent;
												}else{
													out = 0;
												}
												$(this.footer()).html(out);
									        });
								        }
							        }
								};

								ncmTablesHTML.loadTo = parseInt( ncmTablesHTML.opts.offset ) + parseInt( ncmTablesHTML.opts.limit );

								//spinner(ncmTablesHTML.opts.container, 'show');

								ncmTablesHTML.opts.el.html('<tr><td><img src="/images/bg-report-list.png" width="100%" class="m-t-md"></td></tr>');

								ncmTablesHTML.getData(ncmTablesHTML.opts.url, ncmTablesHTML.opts.iniData, function(data){
									ncmTablesHTML.cacheData = data;
									ncmTablesHTML.toFeed(data);
								});
							},
							cacheData : false,
							getData : function(url,iniData,callback){
								if(!validity(iniData)){
									var xhr = ncmHelpers.load({
										url 		: url,
										httpType 	: 'GET',
										hideLoader 	: true,
										type 		: 'json',
										success 	: function(data){
											callback && callback(data);
										}
									});
									window.xhrs.push(xhr);
								}else{
									callback && callback(iniData);
								}
							},
							tableOps : function(opts){
								var out = 	'<span class="dropdown" title="Opciones" data-placement="right">' +
											'	<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown">' +
											'   	<span class="material-icons">more_horiz</span>' +
											'	</a>' +
											'	<ul class="dropdown-menu animated fadeIn speed-4x" role="menu">' +
											'  		<li>' +
											'			<a class="exportTable text-default" data-table="' + ncmTablesHTML.opts.tableName + '" data-name="' + ncmTablesHTML.opts.fileTitle + '" href="#">' +
											'				<span class="material-icons m-r-sm">get_app</span> A Excel' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="hideSelectedRows text-default hidden-xs" href="#">' +
											'				<span class="material-icons m-r-sm selectCounter">visibility_off</span> Mostrar/Ocultar' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="text-default" href="javascript:window.print();">' +
											'				<span class="material-icons m-r-sm">print</span> Imprimir' +
											'			</a>' +
											'		</li>' +
											'   	<li>' + 
											'			<a class="text-default manualSort hidden-xs" href="#">' +
											'				<span class="material-icons m-r-sm">drag_indicator</span> Orden Manual' +
											'			</a>' +
											'		</li>' +
											'	</ul>' +
											'</span>';

								return out;
							},
							toggleColumns(options,oTable){
								if(!validity(options.menu) || !simpleStorage){
									return '';
								}

								var name 			= 'col' + options.name;
								var menu 			= options.menu;

								if(simpleStorage.hasKey(name)){
									menu 	= simpleStorage.get(name);
								}else{
									simpleStorage.set(name, menu);
								}

								var html = 	'<span class="dropdown" title="Mostrar/Ocultar Columnas" data-placement="right">' +
											'	<ul class="dropdown-menu animated fadeIn speed-4x" id="groupActionsMenu">';
														$.each(menu,function(i,val){
															if(ncmHelpers.validInObj(val,'visible')){
																oTable.column(i).visible(val.visible);

																if(val.name){
																	var icon = 'text-white';

																	if(val.visible){
																		icon = 'text-info';
																	}

																	html += '<li><a href="#" data-column="' + i + '" class="toggle-col colTglBtn' + val.index + ' text-default">' +
																			'	<i class="material-icons m-r-xs ' + icon + '">check</i>' + val.name + 
																			'</a></li>';
																}
															}
														});
									html += '		<li><a href="#" class="resetCols"><span class="text-danger"><i class="material-icons m-r-xs">update</i> Restaurar</span></a></li>' +	
											'	</ul>' +
											'	<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown" id="groupActions"><span class="material-icons">view_column</span></a>' +
											'</span>';

								return html;
							},
							loadMoreBtn : function(){
								return ncmTablesHTML.tableId() + 'Btn';
							},
							tableId : function(){
								return ncmTablesHTML.opts.table.replace('.', '').replace('#','');
							},
							loadTo : 0,
							events : function(){

								ncmHelpers.onClickWrap('#' + ncmTablesHTML.loadMoreBtn(),function(event,tis){

									var load 		= ncmTablesHTML.opts.url + "&part=true&offset=" + ncmTablesHTML.loadTo;
									spinner(ncmTablesHTML.opts.container, 'show');
									$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargando...').addClass('disabled');

									var xhr = ncmHelpers.load({
										url 		: load,
										httpType 	: 'GET',
										hideLoader 	: true,
										
										success 	: function(mdata){
											
											spinner(ncmTablesHTML.opts.container, 'hide');

											ncmTablesHTML.loadTo = parseInt( ncmTablesHTML.loadTo ) + parseInt( ncmTablesHTML.opts.limit );

											if(!mdata){
												$('#' + ncmTablesHTML.loadMoreBtn()).addClass('disabled').text('No hay más resultados');
												message('Ya no hay resultados','warning');
												return false;
											}

											$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargar Más').removeClass('disabled');

											var rows 	= explodes('[@]',mdata);

											$.each(rows,function(i,row){
												if(row){
													ncmTablesHTML.oTable.row.add($(row));
												}
											});

											ncmTablesHTML.oTable.draw();

											var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);

											$('[data-toggle="tooltip"]').tooltip();

											$('a.scrollToTop, a.scrollToBottom').hide();
											$('a.scrollToBottom').show();

											ncmTablesHTML.events();
										}
									});

									window.xhrs.push(xhr);

								});

								ncmHelpers.onClickWrap('#' + ncmTablesHTML.loadMoreBtn() + 'all',function(event,tis){

									if(!ncmTablesHTML.opts.nolimit){
										return false;
									}

									var load 		= ncmTablesHTML.opts.url + "&part=true&offset=" + ncmTablesHTML.loadTo + '&limit=' + ncmTablesHTML.loadBulkLimit;
									
									spinner(ncmTablesHTML.opts.container, 'show');
									$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargando...').addClass('disabled');

									var xhr = ncmHelpers.load({
										url 		: load,
										httpType 	: 'GET',
										hideLoader 	: true,
										success 	: function(mdata){
									
											spinner(ncmTablesHTML.opts.container, 'hide');
											if(!mdata){
												$('#' + ncmTablesHTML.loadMoreBtn()).addClass('disabled').text('No hay más resultados');
												$('#' + ncmTablesHTML.loadMoreBtn() + 'all').addClass('hidden');
												message('Ya no hay resultados','warning');
												return false;
											}

											$('#' + ncmTablesHTML.loadMoreBtn()).text('Cargar Más').removeClass('disabled');

											var rows 	= explodes('[@]',mdata);

											ncmTablesHTML.loadTo 			= parseInt( ncmTablesHTML.loadTo ) + parseInt( ncmTablesHTML.loadBulkLimit );

											/*oTable.row.add($(rows.join())).draw();
											var rowsInTable = $(table + ' tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);*/

											$.each(rows,function(i,row){
												if(row){
													ncmTablesHTML.oTable.row.add($(row));
												}
											});

											ncmTablesHTML.oTable.draw();
											var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 1;
											$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);
											$('[data-toggle="tooltip"]').tooltip();

											$('a.scrollToTop, a.scrollToBottom').hide();
											$('a.scrollToBottom').show();
											ncmTablesHTML.events();
										}
									});

									window.xhrs.push(xhr);

								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .hideSelectedRows',function(event,tis){
									var $selected 	= ncmTablesHTML.opts.el.find('tbody tr.selected');

									ncmDTHideRows($selected,function(){
										ncmTablesHTML.unSelectRows();
										ncmTablesHTML.oTable.draw();
									},function(){
										ncmTablesHTML.opts.el.find('tbody tr').removeClass(hideClasses + ' b-info b-l');
										ncmTablesHTML.oTable.draw();
									});

								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .exportTable',function(event,tis){
									var theTable 	= tis.data('table');
									var name 		= tis.data('name');
									table2Xlsx(theTable,name);
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' .manualSort',function(event,tis){
									var $sort = ncmTablesHTML.opts.el.find('tbody');

									if(ncmTablesHTML.sortable){
										ncmTablesHTML.sortable = false;
										$sort.find('tr').removeClass('grab grabbing').addClass('pointer');

										$sort.sortable("destroy");
										message('Orden manual inhabilitado','success');
									}else{
										ncmTablesHTML.sortable = true;
										$sort.find('tr').removeClass('pointer').addClass('grab');

										if(!isMobile.phone){
											$sort.sortable({
												start: function(event, ui) {
										            ui.item.css({'opacity':'0.6'}).toggleClass('grab grabbing');
										        },
										        stop: function(event, ui) {
										            ui.item.css({'opacity':'1'}).toggleClass('grab grabbing');
										        }
											});
											message('Orden manual habilitado','success');
										}
									}
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' a.toggle-col',function(event,tis){
									var name 	= 'col' + ncmTablesHTML.opts.colsFilter.name;
									var menu 	= ncmTablesHTML.opts.colsFilter.menu;
									var saved 	= simpleStorage.get(name);
									var index 	= tis.data('column');
								    var column 	= ncmTablesHTML.oTable.column(index);

								    if(!column.visible()){
								    	column.visible(true);
								    	tis.find('i').removeClass('text-white').addClass('text-info');
								    	saved[index].visible = true;
								    	simpleStorage.set(name, saved);
								    }else{
								    	column.visible(false);
								    	tis.find('i').removeClass('text-info').addClass('text-white');
								    	saved[index].visible = false;
								    	simpleStorage.set(name, saved);
								    }
								});

								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' a.resetCols',function(event,tis){
									var name 	= 'col' + ncmTablesHTML.opts.colsFilter.name;

									simpleStorage.deleteKey(name);
									//simpleStorage.flush();
									location.reload();
								});

								
								var shiftKeyRow = {
									shiftKeyCallBack : function(event,tis){
										var classes = 'selected bg-light dker b-l b-info b-3x';

										if(!tis.hasClass('selected')){
											tis.addClass(classes);
										}else{
											tis.removeClass(classes);
										}

										var rowsInTable = ncmTablesHTML.oTable.rows('.selected').count();

										if(rowsInTable > 0){
											$('.hideSelectedRows .selectCounter').removeClass('material-icons').html('<span class="badge bg-info">' + rowsInTable + '</span>');
										}else{
											$('.hideSelectedRows .selectCounter').addClass('material-icons').html('visibility_off');
										}
										
										ncmTablesHTML.shiftClickCB && ncmTablesHTML.shiftClickCB(event,tis);
									}
								};
								
								ncmHelpers.onClickWrap(ncmTablesHTML.opts.container + ' ' + ncmTablesHTML.opts.table + ' tbody tr.clickrow',function(event,tis){
									$(ncmTablesHTML.opts.container + ' ' + ncmTablesHTML.opts.table + ' tbody tr.editting').removeClass('editting');
									tis.addClass('editting');

									ncmTablesHTML.opts.clickCB && ncmTablesHTML.opts.clickCB(event,tis);
								},false,shiftKeyRow);

								ncmTablesHTML.oTable.off('draw').on('draw', function () {
									var rowsInTable = ncmTablesHTML.oTable.rows(':visible').count();
									$('#' + ncmTablesHTML.loadMoreBtn() + 'cnt').text(rowsInTable);
								    ncmTablesHTML.events();
								} );

								ncmUI.setDarkMode.autoSelected();
								
							},
							DTOpts : {},
							sortable : false,
							DTStart: function(){
								if( $.fn.DataTable.isDataTable(ncmTablesHTML.opts.table) ){
									ncmTablesHTML.oTable.destroy();
								}
								$.fn.dataTable.ext.errMode 	= 'none';
								ncmTablesHTML.oTable 		= ncmTablesHTML.opts.el.DataTable(ncmTablesHTML.DTOpts);
							},
							toFeed : function(data){
								/*if(data.length < 1){
									var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="https://assets.encom.app/images/emptystate7.png" height="140"> <h1 class="font-bold">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
									ncmTablesHTML.opts.el.html(noContent);
									//spinner(ncmTablesHTML.opts.container, 'hide');
									callback && callback();
									return false;
								}*/

								//ncmTablesHTML.opts.el.html(data);

								ncmTablesHTML.DTStart();

								$.each(ncmTablesHTML.opts.hiddenColumns,function(i){
									ncmTablesHTML.oTable.column(i).visible(false);
								});

								if(ncmTablesHTML.opts.allowChild){
									ncmTablesHTML.oTable.rows().every(function () {
										var arr 	= this.data();
										var arra 	= Object.keys(arr);
								    	var a1 		= arr[arra.length-1];

								    	if(a1 && a1 != ""){
								    		this.child(a1).show();
										    this.nodes().to$().addClass('shown');	
								    		var id 		= this.nodes().to$().data('id');
								    		var hidden 	= (ncmTablesHTML.opts.allowChildHide) ? 'hidden ' : '';
								    		this.nodes().to$().closest('tr').next('tr').addClass(hidden + 'childRow' + id + ' ' + ncmTablesHTML.opts.allowChildBg);
									    }
								    });
								}

								ncmTablesHTML.opts.el.width('100%');
								$('div.dataTables_filter input').addClass('form-control rounded pull-right').attr('placeholder','Filtrar listado');
								$('div.dataTables_filter').addClass('col-xs-12');
								$('div.dataTables_filter label').addClass('block');

								if(ncmTablesHTML.opts.hideFilter){
									$('div.dataTables_filter input').addClass('hidden');
								}

								//Filters and tools
								if(validity(ncmTablesHTML.opts.colsFilter) || validity(ncmTablesHTML.opts.ncmTools)){
									var colsBtn = '';
									if(ncmTablesHTML.opts.colsFilter){
										colsBtn = ncmTablesHTML.toggleColumns( ncmTablesHTML.opts.colsFilter, ncmTablesHTML.oTable );
									}

									var fltr = 	'<div class="col-md-9 no-padder m-b-xs">' + 
												'	<span class="btn-group">' +
														ncmTablesHTML.tableOps() +
														colsBtn + 
														ncmTablesHTML.opts.ncmTools.left + 
												'	</span>' +
												'</div>';
									fltr 	+= '<div class="col-md-3 no-padder m-b-xs">' + ncmTablesHTML.opts.ncmTools.right + '</div>';

									$('.ncmTableTools' + ncmTablesHTML.tableId()).html(fltr);
								}
								//

								//load more btn
								var rowsInTable = ncmTablesHTML.oTable.rows().count();//ncmTablesHTML.opts.el.find('tr').length - 2;

								$('.lodMoreBtnHolder').remove();

								//if(rowsInTable < limit){
									//no muestro el boton de cargar mas si hay menos resultados que el limite
								//}else{
									var loadMore 	= 	'<div class="col-xs-12 text-center hidden-print lodMoreBtnHolder">' +
														'	<div class="text-center text-sm">Mostrando <span id="' + ncmTablesHTML.loadMoreBtn() + 'cnt">' + rowsInTable + '</span> líneas</div>' +
													    '	<a href="#" class="btn btn-lg btn-rounded btn-dark text-u-c font-bold hidden" id="' + ncmTablesHTML.loadMoreBtn() + '">Cargar Más</a>';
									if(ncmTablesHTML.opts.nolimit){
										loadMore   += 	'	<a href="#" class="text-u-c text-xs hidden block m-t" id="' + ncmTablesHTML.loadMoreBtn() + 'all">Cargar masivamente</a>';
									}
									
									loadMore 	   +=	'</div>';
								//}

								if(!window.standAlone){
									scrollToTopNcm('remove');
									scrollToBottomNcm('remove');
									scrollToTopNcm();
									scrollToBottomNcm();
								}

								if(!ncmTablesHTML.opts.noMoreBtn){
									$(ncmTablesHTML.opts.container).append(loadMore);
								}

								ncmTablesHTML.events();

								$('#' + ncmTablesHTML.loadMoreBtn()).removeClass('hidden');
								$('#' + ncmTablesHTML.loadMoreBtn() + 'all').removeClass('hidden');
								$('[data-toggle="tooltip"]').tooltip();

								ncmTablesHTML.callback && ncmTablesHTML.callback(ncmTablesHTML.oTable,this);
							},
							unSelectRows : function(){
								var $el = ncmTablesHTML.opts.el.find('tr.selected');
								$el.each(function(){
									$(this).removeClass('selected bg-light dker');
								});
								$('.hideSelectedRows .selectCounter').addClass('material-icons').html('visibility_off');
							}
						};

	

	return ncmTablesHTML.init(options,callback);
}


var ncmListOrdering = function(id,$placeholder,data,callback){
	var head = 	'<div class="col-xs-12 wrapper panel m-n">' + 
				' <p>Arrastre las categorías para reordenar</p>' +
				'<table class="table" id="' + id + '"><tbody>';
	var body = '';
	var foot = '</tbody></table></div>';

	$.each(data,function(i,val){
		body += '<tr data-id="' + val.id + '" data-order="' + val.sort + '">' + 
				'	<td class="draggable font-bold">' + val.name + '</td>' +
		 		'</tr>';
	});

	var out = head + body + foot;

	$placeholder.html(out);

	$('table#' + id + ' tbody').sortable({
        stop: function( ) {
        	var obj = [];
            $("#" + id + ' td').each(function(){
            	var ids 	= $(this).data('id');
            	var order 	= $(this).data('order');
            	obj.push({ id : ids, order : order });
            });

            callback && callback(obj);
        }
    });

	$('table#' + id + ' tbody').disableSelection();
};


function fullScreenTextSearch(element,searchBox){
	ncmHelpers.fullScreenTextSearch(element,searchBox);
	return false;
	
	jQuery.expr[":"].Contains = jQuery.expr.createPseudo(function(arg) {
	    return function( elem ) {
	        return jQuery(elem).text().toUpperCase().indexOf(arg.toUpperCase()) >= 0;
	    };
	});

	$(searchBox).on('keyup',function(){
		var tis 	= $(this);
		var value 	= tis.val();

		if(value.length){
			$(element).hide();
			var found = $(element + ':Contains("' + value + '")');
			if(found.length){
				var family = found.data('family');
				$('[data-family="' + family + '"]').each(function(){
					$(this).show();
				});
			}
		}else{
			$(element).show();
		}
	});
}

function tableColumnsFilterBldr(options,oTable){
	if(!validity(options.menu)){
		return '';
	}

	var name 			= 'col' + options.name;
	var menu 			= options.menu;

	if(simpleStorage.hasKey(name)){
		menu 	= simpleStorage.get(name);
	}else{
		simpleStorage.set(name, menu);
	}

	var html = 	'	<ul class="dropdown-menu animated fadeIn speed-4x" id="groupActionsMenu">' +
				'		<li> <span class="arrow top"></span> </li>';
							$.each(menu,function(i,val){
								oTable.column(i).visible(val.visible);

								if(val.name){
									var icon = 'text-white';

									if(val.visible){
										icon = 'text-info';
									}

									html += '<li><a href="#" data-column="' + i + '" class="toggle-col colTglBtn' + val.index + ' text-default">' +
											'	<i class="material-icons m-r-xs ' + icon + '">check</i>' + val.name + 
											'</a></li>';
								}
							});
		html += '		<li><a href="#" class="resetCols"><span class="text-danger"><i class="material-icons m-r-xs">update</i> Restaurar</span></a></li>' +	
				'	</ul>' +
				'	<a href="#" class="btn btn-default dropdown-toggle" data-toggle="dropdown" id="groupActions">Columnas <span class="caret"></span></a>';

	
	$('[data-toggle="tooltip"]').tooltip();

	return html;
}


var menuToggler = function() {
	$('html').click(function(){
		$('.menuTogglered').hide();
	});
	onClickWrap('.menuToggle',function(event,tis){
		var id = tis.data('menu-toggle');
		$(id).toggle();
	});
	/*$(".menuTogglered").hover(function() {
		
	}, function() {
		$(this).hide();
	});*/
};

menuToggler();

var printAction = function(body, callback) {
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
};

var divider = function(val1,val2){
	if(val1>0 && val2>0){
		if(val1>val2){
			var out = (val1/val2);
		}else{
			var out = (val2/val1);
		}
	}else{
		var out = 0;
	}
	
	return out;
}

var logoUpload = function(){
  var formdata = false;
  
  if (window.FormData) {
      formdata = new FormData();
  }
  
  $(document).on('change','#image',function(evt){
    var file  = this.files[0];
      var name  = file.name;
      var size  = file.size;
      var type  = file.type;
      var go    = false;
      var $this   = $(this);
      var url   = $(this).attr('data-url');
      thalog(type+' '+size);

      if(size > 500000 || !type || (type != 'image/jpeg' && type != 'image/png')){
        alert('La imagen debe ser JPG o PNG y debe de pesar menos de 500KB');
      }else{

      var reader;
    
      if (!!type.match(/image.*/)) {
        if ( window.FileReader ) {
          reader = new FileReader();
          reader.onloadend = function (e) { 
            
          };
          reader.readAsDataURL(file);
        }
        if (formdata) {
          formdata.append("image", file);
        }
      } 

    
      if (formdata) {
        //spinner('.aside-lg', 'show');
        $.ajax({
          url: url,
          type: "POST",
          data: formdata,
          processData: false,
          contentType: false,
          success: function (res) {
            
            var img = 'https://assets.encom.app/src.php?src='+res+'&w=220';
            if(res == 'false'){
              img = 'images/add.png';
            }
            $('.itemImg').attr('src',img);
            $('#imgThumbLetters').hide();
            $('.item-overlay').removeClass('bg-light dk active').addClass('opacity');
            
            $this.replaceWith($this.val('').clone( true ));
            console.log(res);
            spinner('.aside-lg', 'hide');
            $('input#image').closest("form")[0].reset();
          },
          fail: function(){
            $('input#image').closest("form")[0].reset();
          }
        });
      }
    }
  });
};

var imageUpload = function(id,callback){
	$(id).on('change',function(evt){
 		var file 	= this.files[0];
	    var name 	= file.name;
	    var size 	= file.size;
	    var type 	= file.type;
	    var go 		= false;
	    var $this 	= $(this);
	    var url 	= $(this).attr('data-url');
		thalog(type+' '+size);

	    if(size > 1000000 || !type || (type != 'image/jpeg' && type != 'image/png' && type != 'image/gif')){
	    	alert('La imagen debe ser JPG, PNG o GIF y debe de pesar menos de 1MB');
	    }else{
	    	var tmp = URL.createObjectURL(file);
			console.log('adding '+tmp);
			URL.revokeObjectURL(tmp);
			callback && callback(tmp);
		}
 	});
};

function loadTypeAhead(element,options){
    $(element).typeahead(options); 
};

var loadForm = function(url,container,callback){ 
	
	if(!url){
		return false;
	}

	spinner('body', 'show');
	var $container = $(container);

	$.get(url,function(result){
		$container.html(result);
		spinner('body', 'hide');
		callback && callback($container);
	});
};

var openCloseFormPanel = function(action, container, slot, callback){ 
	thalog('openCloseFormPanel fn');
	if(action == 'open'){
		$(container).removeClass('col-sm-12').addClass('col-sm-6');
		$(slot).show();
	}else{
		$(container).removeClass('col-sm-6').addClass('col-sm-12');
		$(slot).hide();
	}
	callback && callback();
};

var addRemoveTextBox = function(addbtn,rmbtn,holder,boxes,callback){
 	thalog('addRemoveTextBox fn'); 
 	ncmHelpers.onClickWrap(addbtn,function(event,tis){	
		$(boxes).appendTo(holder).each(function(){
			console.log('callbacking addremovetexxtbox');
			callback && callback();
		});
    });

    ncmHelpers.onClickWrap(rmbtn,function(event,tis){	
    	var $last 	= $(holder + ' .TextBoxDiv').last();
    	var id 		= $last.find('.id').val();
    	var index 	= tis.data('index');
    	
    	if(!id){
    		
    		if(index > -1){
    			$(holder + ' .TextBoxDiv[data-index="' + index + '"]').remove();
    		}else{
    			$last.remove();	
    		}

    	}else{

    		confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var url = '?action=delete&id='+id;

					$.get(url, function(response) {

						if(response == 'true'){
							$last.remove();
						}else{
							message('Error al procesar','danger');
						}

					});

				}
			});
			
    	}

     });
}

var manageListLines = function(options){

    onClickWrap(options.addBtn,function(event,tis){	
    	console.log(options);
		$(options.template).appendTo(options.holder).each(function(){
			options.added && options.added();
		});
    });
 	
    onClickWrap(options.removeBtn,function(event,tis){
    	var id 		= tis.find('input').val();
    	var $line 	= $(options.holder + '.line' + id);
    	
		confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
			if (e) {
				var url = options.deleteUrl + '&id=' + id;

				$.get(url, function(response) {
					if(response == 'true'){
						$line.remove();
						options.removed && options.removed();
					}else{
						message('Error al procesar','danger');
					}
				});
			}
		});
     });
}

function autoFilterInputTable($input,filter){
	var e 	= jQuery.Event("keyup");
	e.which = 50;
	$input.val(filter).trigger(e);
}

var select2Ajax = function(options){
	var el 			= options.element;
	var url 		= options.url;
	var dtype 		= options.type;
	var minLng 		= options.min || 3;

	if($.type(el) === "string"){
		var $el = $(el);
	}else{
		var $el = el;
	}

	if($el.length && $el.data("select2")){
		//$el.select2('destroy');
	}

	$(el).select2({
        theme     	: "bootstrap",
        language 	: 'es',
        ajax      	: {
        	url       : url,
            dataType  : 'json',
            delay     : 350,
            data      : function(params) {
                return {
                    q : params.term // search term
                };
            },
            templateResult	: function(result, container) {
		        if (!result.id) {
		            return result.text;
		        }
		        container.className += ' needsclick';
		        return result.text;
		    },
            processResults  : function(data, params) {
                var resData = [];
                data.forEach(function(value) {
	                var searchTerm 	= $.trim(params.term).toLowerCase();
	                var snameL 		= $.trim(value.sname);
	                var stinL 		= $.trim(value.stin);
	                var sskuL 		= $.trim(value.ssku);

					if(dtype == 'contact'){
						if (snameL.indexOf(searchTerm) != -1){
						  resData.push(value);
						}

						if (stinL.indexOf(searchTerm) != -1){
						  resData.push(value);
						}
					}else{
						if (snameL.indexOf(searchTerm) != -1){
						  resData.push(value);
						}

						if (sskuL.indexOf(searchTerm) != -1){
						  resData.push(value);
						}
					}

                });
                return {
                    results: $.map(resData, function(datta) {
                    	if(dtype == 'contact'){
                    		return {
                    			id    	: datta.uid,
	                            text  	: datta.name,
	                            sname 	: datta.secondname,
	                            tin 	: datta.stin
	                        }
                    	}else{
                    		return {
                    			id    : datta.id,
	                            text  : datta.name,
	                            uom   : datta.uom,
	                            cost  : datta.cost,
	                            tax   : datta.tax
	                        }
                    	}
                        
                    })
                };
            },
            cache 	: true
        },
        debug 		: true,
        minimumInputLength: minLng
    });

    $(el).each(function(index, ele){
    	var $containr = $(ele).data('select2').$container;
		$containr.find('*').addClass('needsclick');
		options.onLoad && options.onLoad(ele,$containr);
	});

	$(el).off('select2:select').on('select2:select', function(e){
      var elm = e.params.data.element;
      $elm    = $(elm);
      $t      = $(this);
      $t.append($elm);
      $t.trigger('change.select2');
    });

	$(el).off('change').on('change',function(){
		var data    = $(this).select2('data')[0];
		options.onChange && options.onChange($(this),data);
	});

	onClickWrap('.select2-selection__choice__remove',function(event,tis){
		tis.closest("li").remove();
	},false,true);
};

var select2Simple = function(el,parent,onChange,destroy){

	if($.type(el) === "string"){
		var $el = $(el);
	}else{
		var $el = el;
	}

	if($el.data("select2") && destroy){
		$el.select2('destroy');
	}

	parent = (parent) ? parent : 'body';
	
	$el.select2({
					placeholder 	: "Seleccione",
					theme 			: "bootstrap",
					language 		: 'es',
					templateResult 	: function(result, container) {
					    if (!result.id) {
					        return result.text;
					    }
					    container.className += ' needsclick';
					    return result.text;
					}
                });

	$el.off('select2:select').on('select2:select', function(e){
      var elm = e.params.data.element;
      $elm    = $(elm);
      $t      = $(this);
      $t.append($elm);
      $t.trigger('change.select2');
    });

    $el.off('change').on('change',function(){
		var data    = $(this).select2('data')[0];
		onChange && onChange($(this),data);
	});

	$el.each(function(index, el){
		if($(el).data('select2')){
			$(el).data('select2').$container.addClass('needsclick');
		}
	});
};

(function( $ ) {
    $.fn.afterAnimation = function(callback) {
    	thalog('afterAnimation plgn');

    	if(window.noanimate){
    		callback && callback();
    	}else{
	    	return this.each(function() {

		    	var t, el = document.createElement("fakeelement"), transitionEvent;

				var transitions = {
					"transition"      : "transitionend",
					"OTransition"     : "oTransitionEnd",
					"MozTransition"   : "transitionend",
					"WebkitTransition": "webkitTransitionEnd",

					"animation"      : "animationend",
				    "OAnimation"     : "oAnimationEnd",
				    "MozAnimation"   : "animationend",
				    "WebkitAnimation": "webkitAnimationEnd"
				}

				for (t in transitions){
					if (el.style[t] !== undefined){
					  transitionEvent = transitions[t];
					}
				}

				//'webkitAnimatinEnd oAnimationEnd MSAnimationEnd animationend transitionend webkitTransitionEnd oTransitionEnd MSTransitionEnd'
			    $(this).one(transitionEvent,function(){
					callback && callback($(this));
				});

		    });
	    }

	};
}( jQuery ));

var table2Xlsx = function(table,name){
	$('#' + table).find('.noxls').hide();
	var wb 	= XLSX.utils.table_to_book($('#' + table)[0],{display:true,raw:true});
	XLSX.writeFile(wb, name + '.xlsx');
	$('#' + table).find('.noxls').show();
};


//notify

var ncmNotify = {
	events : function(){
		onClickWrap('#notify .notifySectionTitle',function(event,tis){
		  tis.next().toggleClass('hidden');
		});

		onClickWrap('.notifybtn',function(event,tis){
			$notify 		= $('#notify');
			$notifyList 	= $('#notifyList');
			$notify.toggleClass('hidden');

			var hour = moment().format('HH:mm');
			var date = moment().format('dddd, D MMM YYYY');
			$('#notify .notifyHour').text(hour);
			$('#notify .notifyDate').text(date);

			if($notify.is(':visible')){
				spinner('#notify', 'show');
				$.get('/a_dashboard?widget=notifications&type=notes',function(result){
					spinner('#notify', 'hide');
					var list = ncmNotify.buildNotifyLists(result);
					if(list){
						$notifyList.html(list);
					}
				});

				if(window.snapper && isMobile.phone){
					window.snapper.disable();
				}
			}else{
				if(window.snapper && isMobile.phone){
					window.snapper.enable();
				}
			}
		});

		onClickWrap('.notifyTabBtn',function(event,tis){
			$notify 		= $('#notify');
			$notifyList 	= $('#newsTab .list-group');
			var displayed 	= false;

			if($notify.is(':visible') && !displayed){
				spinner('#notify', 'show');
				$.get('/a_dashboard?widget=notifications&type=news',function(result){
					spinner('#notify', 'hide');
					var list = ncmNotify.buildNotifyLists(result);
					if(list){
						$notifyList.html(list);
						displayed = true;
					}
				});
			}
		});
	},
	checkNewNotify : function(){
		if(window.isUserActive && !window.standAlone){
			$.get('/a_dashboard?widget=notificationsCount',function(result){
				if(validity(result.count) > 0){
					$('.notifybtncount').removeClass('hidden').text(result.count);
				}else{
					$('.notifybtncount').addClass('hidden').text(0);
				}
			});
			ncmNotify.events();
		}
	},
	buildNotifyLists : function(data){
	
		var list 		= '';
		var key 		= '';
		var blockStart 	= '<div class="col-xs-12 no-padder bg-black text-white r-3x m-b animated fadeInUp speed-3x">';
		var blockTitle 	= '<a href="#" class="col-xs-12 text-muted font-bold text-xs text-u-c wrapper-sm notifySectionTitle">';
		var blockTitleEnd = '<i class="material-icons pull-right"> keyboard_arrow_down </i> </a>';
		var blockRowHolder = '<div class="notifyRowHolder">';
		var blockRowHolderEnd = '<span class="col-xs-12 wrapper-sm text-white"></span></div>';
		var divEnd 		= '</div>';


		var notArr = data.reduce((r, a) => {
			r[a.title] = [...r[a.title] || [], a];
			return r;
		}, {});

		var i = 0;
		$.each(notArr,function(key,value){

			title = (key == 'important') ? 'Importante' : key;

			list += blockStart + blockTitle + title + blockTitleEnd + blockRowHolder;

			$.each(value,function(a,b){
				var link 	= iftn(b.link,'#');
				if(link != '#'){
					var linkicon = '<i class="material-icons pull-right">keyboard_arrow_right</i>';
				}else{
					var linkicon = '';
				}

				list += '<a href="' + link + '" class="col-xs-12 b-b b-black wrapper-sm text-white" target="_blank">' +
	                	'	<div class="text-xs text-muted">Hace ' + b.timeago + linkicon + '</div>' +
	                	'	<div>' + b.message + '</div>' +
	              		'</a>';
			});

	        list += blockRowHolderEnd + divEnd;

	        i++;
				
		});

		return list;
	}
};

var scrollToTopNcm = function(action){
	if(action == 'remove'){
		$('a.scrollToTop').remove();
		return true;
	}

	var btn = 	'<a href="#" style="display:none; position:fixed; bottom:30px; right:110px;" class="btn btn-rounded btn-xl btn-icon hidden-print btn-dark all-shadows scrollToTop">' + 
				'	<i class="material-icons">keyboard_arrow_up</i>' + 
				'</a>';

	$('body').append(btn);

	$('section.scrollable').scroll(function(){
	  if ($(this).scrollTop() > 400) {
	    $('a.scrollToTop').show();
	  } else {
	    $('a.scrollToTop').hide();
	  }
	});

	onClickWrap('a.scrollToTop',function(event,tis){
		$('html, body, section.scrollable').animate({scrollTop : 0},800);
	  	return false;
	},false,true);
};

var scrollToBottomNcm = function(action){
	if(action == 'remove'){
		$('a.scrollToBottom').remove();
		return true;
	}
	var btn = 	'<a href="#" style="display:none; position:fixed; bottom:30px; right:110px;" class="btn btn-rounded btn-xl btn-icon hidden-print btn-dark all-shadows scrollToBottom">' + 
				'	<i class="material-icons">keyboard_arrow_down</i>' + 
				'</a>';

	$('body').append(btn);

	if($('section.scrollable').scrollTop() < 400) {
		$('a.scrollToBottom').show();
	}

	$('section.scrollable').scroll(function(){
	  if ($(this).scrollTop() < 400) {
	    $('a.scrollToBottom').show();
	  } else {
	    $('a.scrollToBottom').hide();
	  }
	});

	onClickWrap('a.scrollToBottom',function(event,tis){
		$('html, body, section.scrollable').animate({scrollTop: $('.table-responsive').height()},800);
	  	return false;
	},false,true);
};

var trackEvents = function(event,data){
	/*ga('send', {
				  hitType: 'event',
				  eventCategory: 'Videos',
				  eventAction: 'play',
				  eventLabel: 'Fall Campaign'
				});*/

	mixpanel.track(
	    event,
	    data
	);
};


//#########	EXECUTIONS ON LOAD
ncmNotify.checkNewNotify();
setInterval(function(){
	ncmNotify.checkNewNotify();
},60000);

//Verifico periodicamente si la sesión expiró o nó
if(!validity(noSessionCheck)){
	setInterval(function(){
		
		$.get('/includes/secure?js=true',function(result){
			if(result == 'expired'){
				window.onbeforeunload = null;
				alert('Su sesión ha expirado, por favor vuelva a iniciar sesión');
				location.reload();
			}
		});
		
	}, 600000);
}

//fullscreen
ncmHelpers.onClickWrap('a.ncmFullscreenMode',function(e,tis){
	$(document).toggleFullScreen();

	$(document).off("fullscreenchange").on("fullscreenchange", function() {
		if(!$(document).fullScreen()){
			tis.find('i').text('fullscreen');
		}else{
			tis.find('i').text('fullscreen_exit');
		}
    });
});

ncmHelpers.onClickWrap('a.ncmDarkMode',function(e,tis){
	if(ncmUI.setDarkMode.isSet){
		simpleStorage.set('darkMode',false);
		location.reload();
	}else{
		simpleStorage.set('darkMode',true);
		//ncmUI.setDarkMode.setDark();
		location.reload();
	}
});

//Reminders 
var checkReminder = function(){
	if(window.isUserActive && !window.standAlone){
		$.get('/a_dashboard?widget=getReminders',function(result){
			if(result){
				$.each(result,function(key,val){
					var bg = 'gradBgGray';
					$.toast({
					    text 				: val.note,
					    heading 			: 'Recordatorio',
					    icon 				: false,
					    showHideTransition 	: 'fade',
					    allowToastClose 	: true,
					    hideAfter 			: false,
					    stack 				: 6,
					    position 			: { bottom: '10px', left: '85px' },
					    textAlign 			: 'left',
					    loader 				: false,
					    beforeShow 			: function () {
					    	if(val.type == 'success'){
					    		bg = 'gradBgGreen';
					    	}else if(val.type == 'danger'){
					    		bg = 'gradBgRed';
					    	}else if(val.type == 'warning'){
					    		bg = 'gradBgYellow';
					    	}else if(val.type == 'default'){
					    		bg = 'gradBgGray';
					    	}

					    	$('.jq-toast-heading').addClass('font-bold');
					    	$('.close-jq-toast-single').addClass('text-lg').html('<i class="material-icons">check</i>');
					    	$('.jq-toast-single').addClass('r-3x text-white all-shadows ' + bg);
					    }, // will be triggered before the toast is shown
					    afterShown 			: function () {}, // will be triggered after the toat has been shown
					    beforeHide 			: function () {

					    }, // will be triggered before the toast gets hidden
					    afterHidden 		: function () {}  // will be triggered after the toast has been hidden
					});
				});
			}
		});
	}
};

//checkReminder();
setInterval(function(){
//	checkReminder();
},100000);

if(isMobile.phone){
	window.snapper = new Snap({
						          element 		: $('#content')[0],
						          disable 		: 'right',
						          touchToDrag 	: false
						        });

	onClickWrap('#openMobileMenu',function(event,tis){

		if(window.snapper.state().state == "left"){
			window.snapper.close();
		}else{
			window.snapper.open('left');
		}
		
	});
}


$('.hoverMenu').hover(
	function(){ $(this).addClass('b-l') },
	function(){ $(this).removeClass('b-l') }
);



onClickWrap('.navigateAway',function(event,tis){
	spinner('body', 'show');
	$('#toastnlogo').addClass('animated infinite pulse');
	window.location.href = tis.attr('href');
});


var detectIfUserIddle = function(){
	if(window.standAlone){
		window.isUserActive = false;
		return false;
	}

	var timeoutID = null;

	$(document).on('mousemove mousedown keypress DOMMouseScroll mousewheel touchmove touchend MSPointerMove',function(){
		if (timeoutID !== null) {
	        window.isUserActive = true;
	        window.clearTimeout(timeoutID);
	    }

	    timeoutID = window.setTimeout(function(){
			 window.isUserActive = false;
		}, 6000);
	});
};

detectIfUserIddle();

var ncmiGuiderConfig = {

	shape 			: 2,
    shapeBorderRadius: 20,
    overlayColor 	: '#3b464d',
    modalContentColor: '#9badb9',
    modalTypeColor  : '#9badb9',
    bgColor         : '#3b464d',
    titleColor      : '#fff',
    btnColor        : '#4cb6cb',
    btnHoverColor   : '#3f9eb1',
    paginationColor : '#4cb6cb',
    timerColor      : '#4cb6cb',
    keyboard 		: false,
    overlayClickable: false,
    intro    		: {},
    continue 		: {
		enable 		: false,
		title 		: '¿Continuar dónde quedamos?',
	    content 	: 'Puede continuar o volver a iniciar desde el comienzo.'
	},
	tourMap: {
        bgColor 	: '#1D75DE',
        titleColor 	: '#fff',
        btnColor 	: '#fff',
        btnHoverColor: '#eee',
        itemColor 	: '#fff',
        itemHoverColor: '#ddd',
        itemActiveColor: '#545a5f',
        itemActiveBg: '#fff',
        itemNumColor: '#ddd',
        checkColor 	: '#fff',
        checkReadyColor: '#1ab667'
    },
    setTipStyle 	: 	function(){
	                      $.each(ncmiGuiderConfig.steps,function(i,val){
	                        ncmiGuiderConfig.steps[i].bgColor           = '#1D75DE';
	                        ncmiGuiderConfig.steps[i].titleColor        = '#fff';
	                        ncmiGuiderConfig.steps[i].modalContentColor = '#fff';
	                        ncmiGuiderConfig.steps[i].paginationColor   = '#fff';
	                        ncmiGuiderConfig.steps[i].timerColor        = '#fff';
	                        ncmiGuiderConfig.steps[i].btnColor          = '#fff';
	                        ncmiGuiderConfig.steps[i].btnColor          = '#EDEDED';                                   
	                      });
	                    },
	start 			: function(){
		ncmiGuiderConfig.setTipStyle();
		iGuider(ncmiGuiderConfig);
	},
	scrollToIt 		: function(target){
		                var nowScroll = $('#bodyContent').scrollTop() - 70;
		                var newScroll = target.offset().top + nowScroll;
		                $('#bodyContent').stop(true).animate({scrollTop:newScroll},200);
		              }
};

//######### END