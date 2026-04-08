
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
var chartTooltipFontColor = '#eaeef1';

var chartLineGraphOptions = {
								scales: {
								  xAxes: [{
								      display: false,
								      ticks: {
								            beginAtZero 	: true,
								            fontColor 		: chartAxisFotnColor,
								            fontFamily 		: chartFotnFamily
								        },
								        gridLines: {
								          color: chartGridColors,
								          lineWidth: 1
								        },
								        zeroLineColor 	: chartGridColors
								  }],
								  yAxes: [{
								        ticks: {
								            beginAtZero 	: true,
								            fontColor 		: chartAxisFotnColor,
								            fontFamily 		: chartFotnFamily
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
						            callbacks: {
						                labelColor: function(tooltipItem, chart) {
						                    return {
						                        backgroundColor: chartTooltipBg
						                    }
						                },
						                labelTextColor:function(tooltipItem, chart){
						                    return chartTooltipFontColor;
						                }
						            }
						        }
							};

var chartBarStackedGraphOptions = {
						      	title: {
									display: false
								},
								responsive: true,
								scales: {
									xAxes: [{
										display: false,
										stacked: true,
										ticks: {
								            beginAtZero 	: true,
								            fontColor 		: chartAxisFotnColor,
								            fontFamily 		: chartFotnFamily
								        },
								        gridLines: {
								          color: chartGridColors,
								          lineWidth: 1
								        },
								        zeroLineColor 	: chartGridColors
									}],
									yAxes: [{
										stacked: true,
										ticks: {
								            beginAtZero 	: true,
								            fontColor 		: chartAxisFotnColor,
								            fontFamily 		: chartFotnFamily
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
						            callbacks: {
						                labelColor: function(tooltipItem, chart) {
						                    return {
						                        backgroundColor: chartTooltipBg
						                    }
						                },
						                labelTextColor:function(tooltipItem, chart){
						                    return chartTooltipFontColor;
						                }
						            }
						        }
						      };

function switchit(callback,reset,el) {
	thalog('switchit fn');
	var $el = el ? el : '.switch-select';
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
	load : function(options){
		var url 		= ('url' in options) ? options.url : '',
			success 	= ('success' in options) ? options.success : '',
			fail 		= ('fail' in options) ? options.fail : '', 
			hideloader 	= ('hideLoader' in options) ? options.hideloader : false,
			data 		= ('data' in options) ? iftn(options.data,{}) : {},
			httpType 	= ('httpType' in options) ? options.httpType : 'POST',
			dataType 	= ('type' in options) ? options.type : 'text',
			$container 	= ('container' in options) ? options.container : '';

		if(!hideloader){
			helpers.loadIndicator({container:$container,status:'show'});
		}

		return $.ajax({
					    url 		: url,
					    data 		: data,
					    type 		: httpType,
					    dataType 	: dataType,
					    success 	: function(data){
					    	helpers.loadIndicator();
					        success && success(data);
					    },
					    error 		: function(data) {
					    	helpers.loadIndicator();
					        fail && fail('false');
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
		    	if(ip){
			     	var k 			= ip.countryCode;
			    }else{
			    	var k 			= 'PY';
			    }

			    var val 		= countries[k];
		        var selected 	= '<img src="' + flagsCDN + k.toLowerCase() + '.svg" width="20"> <span class="font-bold text-md m-l-sm selectedPhoneCode" data-country="' + k + '">+' + val.phone + '</span>';
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
	unHashUrl : function(){
		var noHashURL = window.location.href.replace(/#.*$/, '');
		window.history.replaceState('', document.title, noHashURL);
	},
	loadedPageCache : [],
	loadPageLoad 	: true,
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
			
			var rawHash 	= window.location.hash.substring(1);
			var hash 		= rawHash.split('&')[0];

			var hVar 		= rawHash.split('&').reduce(function (result, item) {
			    var parts 	= item.split('=');
			    result[parts[0]] = parts[1];
			    return result;
			}, {});

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

			var container 	= '#bodyContent';
			var cache 		= [];

			if( hash ){
				
				scrollToTopNcm('remove');
				scrollToBottomNcm('remove');

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
					console.log(hash,'loaded from cache');
					$(container).html(ncmHelpers.loadedPageCache[hash]);
				}else{
					console.log(hash,'loaded from url');
					var xhr = ncmHelpers.load({
								url 		: '/a_' + hash,
								container 	: container,
								success 	: function(data){
									if(data){
										ncmHelpers.loadedPageCache[hash] = data;
										$(container).html(data);

										var title 		= $('#pageTitle').text();
										document.title 	= iftn(title,'Panel de Control - Punto');
										/*window.history.pushState({
										    id : hash
										}, title, '/@#' + hash);	*/

										options.onAfter && options.onAfter();

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
		});
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
		    return dec;
		  }else{
		    return str;
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

var onClickWrap = function(element,callback,propagate,offIt){
	if(offIt){
		$(document).off('click', element);
	}

	$(document).on('click',element,function(event){
		event.preventDefault();
		if(!propagate){
			event.stopPropagation();
		}
		callback && callback(event,$(this));
	});
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
	alert : function(msg,type){
		var icon 	= 'warning';
		var title 	= msg;
		var message = '';

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
	confirm : function(title,msg,type,callback){
		type = (type == 'danger') ? 'error' : type;
		Swal.fire({
		  title: title,
		  text: msg,
		  type: type,
		  showCancelButton: true,
		  confirmButtonText: 'Aceptar',
		  cancelButtonText: 'Cancelar'
		}).then((result) => {
		  if(result.value) {
		    callback && callback(result.value);
		  }else if(result.dismiss === Swal.DismissReason.cancel) {
		    callback && callback(false);
		  }
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
	}
};

function message(message,type,duration){
	ncmDialogs.toast(message,type,duration);
	return;
	var logo 	= '/images/iconincomesmwhite.png';
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
           'Hoy' 			: [moment(), moment().endOf('day')],
           '7 Días' 		: [moment().subtract(7, 'days'), moment().endOf('day')],
           '30 Días' 		: [moment().subtract(29, 'days').startOf('day'), moment().endOf('day')],
           'Este Mes' 		: [moment().startOf('month'), moment().endOf('month')],
           'Mes Pasado' 	: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
           //'Este Año' 		: [moment().startOf('year'), moment().endOf('year')]
        };

        $el.attr('readonly','readonly').css('background-color','#fff');
	}
	

    $el.daterangepicker({
    	timePicker 			: timepicker,
    	timePicker24Hour 	: timepicker24,
    	dateLimit 			: {years:1},
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

    $('#customDateR').off('apply.daterangepicker').on('apply.daterangepicker', function(ev, picker) {
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

function formatNumber(number,currency,decimal,thousandSeparator,stringed,customDecimal){

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

	var num = $.number(number, dN, dS, tS);
	//if(currency == null){currency = 'Gs.';}
	return currency+num;
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

	$(document).off('submit',formId);
	$(document).on('submit',formId,function(e) {
		onBefore && onBefore();
		var formData = iftn(files,$(this).serialize(),new FormData(this));
		$.ajax({ // create an AJAX call...
			data: formData, // get the form data
			type: $(this).attr('method'), // GET or POST
			url: $(this).attr('action'), // the file to call
			success: function(response) { // on success..e
				if(response.error){
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
					alert('Lo sentimos, pero su plan ya ha alcanzado el límite');
					return false;
				}else{
					if(firstResp.length > 256){
						//location.reload();
					}else{
						if(firstResp){
							thalog(firstResp);
							alert(firstResp);
						}else{
							alert('ERROR: No hubo respuesta');
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

var submitForm2 = function(formId,callback,files){
	$(document).off('submit',formId);
	$(document).on('submit',formId,function(e) {
		console.log("submitForm2 fn");
		e.preventDefault();
		$(formId).ajaxSubmit({
            success: function(response){

            	if(response.error){
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
					alert('Lo sentimos, pero su plan ya ha alcanzado el límite');
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
		var secid	= tis.data('select');
		var table 	= tis.data('table');
		var valType = tis.data('valtype');
		var outlet 	= iftn(tis.data('outlet'),'false');
		var $selected = ($('#'+secid+" option:selected"))?$('#'+secid+" option:selected"):$("#"+secid+" option:first");
		var txt 	= trim($selected.text()).replace('× ', '');
		var id 		= $('#'+secid).val();
		var casee 	= tis.attr('class').split(/[ ,]+/);
		casee 		= casee[0];
		var toggle 	= $selected.attr('data-toggle');
		toggle 		= (toggle === "")?'2':toggle; 
		

		if(casee == 'editItemPart'){
			prompter('Editar ' + txt, function(str) {
										if (str) {
											if(valType == 'num'){
												var str = (+str.replace(',', '.'));
												if(isNaN(str)){
													alert('Solo puede ingresar números');
													return false;
												}
											}

											$.get(baseUrl + '?actionExtra=edit&tableExtra='+table+'&valExtra='+str+'&idExtra='+id+'&toggleExtra='+toggle, function(data) {
												thalog('#' + secid + ' option[value="' + id + '"].remove');
												thalog('.'+table+' prepend: '+data);
												$('#'+secid+' option[value="' + id + '"]').remove();
												$('.'+table).prepend(data);
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
			confirmation('Realmente desea eliminar "' + txt + '"?', function (e) {
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
										    "zeroRecords": '<div class="wrapper-sm text-muted text-center font-thin"> <div class="b h1 rounded wrapper-lg" style="width:103px; margin:0 auto;"><i class="icon-magnifier"></i></div> <span class="block m-t-md h3"> No pudimos encontrar lo que busca</span><span class="block text-sm">Intenta buscar utilizando otra de combinación de palabras</span></div>'
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

			thalog(options);

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
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="images/emptystate4.png" height="140"> <h1 class="font-thin">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
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
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="images/emptystate4.png" height="140"> <h1 class="font-thin">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
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
				$('.'+search+'Holder').append('<div class="input-group pull-right m-b-sm"> <input type="text" class="form-control rounded" id="'+search+'" placeholder="Buscar"> <span class="input-group-btn"> <button data-url="'+url+'" data-container="'+container+'" class="btn btn-default btn-rounded searchDB" data-target="#'+search+'" type="button"><i class="material-icons">search</i></button> </span> </div>');
			}

			spinner(container, 'hide');

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
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="images/emptystate4.png" height="140"> <h1 class="font-thin">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
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
			var noContent = '<div class="text-center col-xs-12 wrapper"> <img src="images/emptystate4.png" height="140"> <h1 class="font-thin">No encontramos información</h1> <div class="text-md m-t"> <p> Asegurese de haber añadido la información necesaria o escribanos directamente al chat para que le ayudemos. <br> (Psst, el chat es el circulo azul a la derecha) </p> </div></div>';
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
														    "zeroRecords"	: '<div class="text-center"><img src="/assets/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> No pudimos encontrar lo que busca</div><div>Intente utilizando otra combinación de palabras</div></div>',
														    "emptyTable" 	: '<div class="text-center"><img src="/assets/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3">No hay información disponible</div></div>'
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

			$('#' + loadMoreBtn).removeClass('hidden');
			$('#' + loadMoreBtn + 'all').removeClass('hidden');
			$('[data-toggle="tooltip"]').tooltip();
			//load more btn

			spinner(container, 'hide');

			callback && callback(oTable);
		}
	}

	feedData(url, iniData, function(data){
		toFeed(data);
	});
};

function fullScreenTextSearch(element,searchBox){
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
		html += '		<li><a href="#" class="resetCols"><span class="text-danger">Restaurar</span></a></li>' +	
				'	</ul>' +
				'	<a href="#" class="btn btn-default dropdown-toggle" data-toggle="dropdown" id="groupActions">Columnas <span class="caret"></span></a>';

	
	$('[data-toggle="tooltip"]').tooltip();
	onClickWrap('a.toggle-col',function(event,tis){
		var saved 	= simpleStorage.get(name);
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
		simpleStorage.flush();
		location.reload();
	},false,true);

	return html;
}

function tableToolsAndColumns(options){
	var oTable = options.table;

	if(!options.update){
		
		var html = 	'<span class="btn-group">' +
					'	<a href="#" class="btn btn-default dropdown-toggle" data-toggle="dropdown" id="groupActions">Columnas <span class="caret"></span></a>' +
					'	<ul class="dropdown-menu animated fadeIn" id="groupActionsMenu">' +
					'		<li> <span class="arrow top"></span> </li>';
								$.each(options.menu,function(i,val){
									html += '<li><a href="#" data-column="'+val.index+'" class="toggle-col colTglBtn'+val.index+' text-default">' +
												'<i class="material-icons m-r-xs">check_box_outline_blank</i>' + val.name + 
											'</a></li>';
								});
			html += '		<li><a href="#" class="resetCols"><span class="text-danger">Restaurar</span></a></li>' +	
					'	</ul>' +
					'</span>';

		if(validity(options.extra,'string')){
			html += iftn(options.extra);
		}else if(validity(options.extra,'array')){
			html += options.extra.join(' ');
		}

		$(options.tableWrapper + ' div.dt-buttons').append(html);
	}

	var visibleCols 	= options.columns;//indexes true false {0:true,1:false,3:false}
	var name 			= 'cols' + options.name;

	if(simpleStorage.hasKey(name)){
		visibleCols 	= simpleStorage.get(name);
	}else{
		simpleStorage.set(name, visibleCols);
	}

	$.each(visibleCols,function(i,val){
		oTable.column(i).visible(val);

		if(val){
			$('.colTglBtn' + i + ' i').text('check_box');
		}else{
			$('.colTglBtn' + i + ' i').text('check_box_outline_blank');
		}
	});
	
	if(!options.update){
		$('[data-toggle="tooltip"]').tooltip();
		onClickWrap('a.toggle-col',function(event,tis){
			var saved 	= simpleStorage.get(name);
			var index 	= tis.data('column');
		    var column 	= oTable.column(index);

		    if(!column.visible()){
		    	column.visible(true);
		    	tis.find('i').text('check_box');
		    	saved[index] = true;
		    	simpleStorage.set(name, saved);
		    }else{
		    	column.visible(false);
		    	tis.find('i').text('check_box_outline_blank');
		    	saved[index] = false;
		    	simpleStorage.set(name, saved);
		    }
		});

		onClickWrap('a.resetCols',function(event,tis){
			simpleStorage.flush();
			location.reload();
			//tableToolsAndColumns({table:oTable,update:true,name:name,columns:visibleCols});

		});
	}
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
            
            var img = '/assets/src.php?src='+res+'&w=220';
            if(res == 'false'){
              img = 'images/add.png';
            }
            $('.itemImg').attr('src',img);
            $('#imgThumbLetters').hide();
            $('.item-overlay').removeClass('bg-light dk active').addClass('opacity');
            
            $this.replaceWith($this.val('').clone( true ));
            console.log(res);
            spinner('.aside-lg', 'hide');
            $('#image').reset();
          },
          fail: function(){
            $('#image').reset();
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
    onlyClickWrap(addbtn,function(event,tis){	
		$(boxes).appendTo(holder).each(function(){
			console.log('callbacking addremovetexxtbox');
			callback && callback();
		});
    });
 	
    onlyClickWrap(rmbtn,function(event,tis){
    	var $last 	= $(holder+' .TextBoxDiv').last();
    	var id 		= $last.find('.id').val();
    	thalog('Removing');
    	if(!id){
  			$last.remove();
    	}else{
    		confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var url = '?action=delete&id='+id;
					thalog('a removeer');
					$.get(url, function(response) {
						//console.log(response);
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
		},false,true);

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
		},false,true);

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
		},false,true);
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
		var blockStart 	= '<div class="col-xs-12 no-padder bg-black-opacity-8 text-white r-3x m-b animated fadeInUp speed-3x">';
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
	});
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
	});
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
onClickWrap('a.ncmFullscreenMode',function(e,tis){
	$(document).toggleFullScreen();

	$(document).off("fullscreenchange").on("fullscreenchange", function() {
		if(!$(document).fullScreen()){
			tis.find('i').text('fullscreen');
		}else{
			tis.find('i').text('fullscreen_exit');
		}
    });
},false,true);

//Reminders 
var checkReminder = function(){
	if(window.isUserActive && !window.standAlone){
		$.get('/dashboard?widget=getReminders',function(result){
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
					    position 			: { top: '10px', left: '85px' },
					    textAlign 			: 'left',
					    loader 				: false,
					    beforeShow 			: function () {
					    	if(val.type == 'success'){
					    		bg = 'gradBgGreen';
					    	}else if(val.type == 'danger'){
					    		bg = 'gradBgRed';
					    	}else if(val.type == 'warning'){
					    		bg = 'gradBgYellow';
					    	}else{
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
		
	},true);
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

//######### END