		$(function(){
			window.xhrs = [];
			if(isMobile.phone){
				var wH = $(window).height();
				$('#bodyContent').css( {height : (wH - 50) + 'px'} );
			}

			carryOn();
			
			$(window).trigger('hashchange');

			$(document).off('shown.bs.modal','.modal').on('shown.bs.modal','.modal', function (e) {
				ncmHelpers.onClickWrap('.print',function(event,tis){
					var el = tis.data('element');
					$(el).print();
				});	
			});

			onClickWrap('.print',function(event,tis){
				var el = tis.data('element');
				$(el).print();
			});	
		});

		