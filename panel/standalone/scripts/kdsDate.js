
      /* var ncmKDSGroupByTime = {

        separatedJSON: {},
        separatedJSONArr: {},
        init: {

        },
        load: () => {

          var success = function(data) {
            ncmKDS.lastChecked = moment().format('YYYY-MM-DD HH:mm:ss');

            ncmKDS.cachedResult = data;
            ncmKDS.oldCachedResult = ncmKDS.duplicateJson(data);

            ncmKDS.setUIX(ncmKDS.cachedResult);
            ncmKDS.loading = false;
          };

          ncmKDS.xhr = $.get('/kds.php?s=' + window.ese + '&action=lists&compTime=' + ncmKDS.computerHour, success).fail(function(jqXHR) {
            ncmKDS.loading = false;
          });

        },
        processArray: (data) => {

          data.orders.forEach(function(order) {
            var uid = order.UID;

            if (!ncmKDSGroupByTime.separatedJSON[uid]) {
              ncmKDSGroupByTime.separatedJSON[uid] = {
                "UID": uid,
                "DUE_DATE": "",
                "order_details": [],
                "order_total": order.order_total
              };
            }

            var currentDueDate = moment(order.due_date);

            var roundedMinutes = currentDueDate.minutes() < 30 ? 30 : 0;
            if (roundedMinutes === 0) {
              currentDueDate.add(1, 'hour');
            }

            currentDueDate.minutes(roundedMinutes);
            currentDueDate.seconds(0);

            ncmKDSGroupByTime.separatedJSON[uid].DUE_DATE = currentDueDate.format("YYYY-MM-DD HH:mm:ss");

            order.order_details.forEach(function(detail) {

              var orderDetails = {
                "itemId": detail.itemId,
                "itemName": detail.name, // Agregar el nombre del artículo
                "count": detail.count,
                "oQty": detail.oQty,
                // Resto de las propiedades de los detalles de la orden...
              };

              ncmKDSGroupByTime.separatedJSON[uid].order_details.push(orderDetails);

            });

          });

          ncmKDSGroupByTime.separatedJSONArr = Object.values(ncmKDSGroupByTime.separatedJSON);

          var intervalGroups = ncmKDSGroupByTime.groupOrdersByInterval(ncmKDSGroupByTime.separatedJSONArr, 30);
          var jsonResult = ncmKDSGroupByTime.prepareJSON(intervalGroups);

          return jsonResult;
        },
        groupOrdersByInterval: (orders, interval) => {

          var intervalGroups = {};

          orders?.forEach(function(order) {
            var dueDate = moment(order.DUE_DATE);
            var intervalKey = dueDate.format("YYYY-MM-DD HH:mm");

            if (!intervalGroups[intervalKey]) {
              intervalGroups[intervalKey] = [];
            }

            intervalGroups[intervalKey].push(order);
          });

          return intervalGroups;

        },
        prepareJSON: (intervalGroups) => {

          var result = [];

          for (var intervalKey in intervalGroups) {

            var orders = intervalGroups[intervalKey];
            console.log(orders);
            console.log(json);
            var interval = moment(intervalKey, "YYYY-MM-DD HH:mm");
            var intervalStart = interval.format("HH:mm");
            var intervalEnd = interval.add(30, 'minutes').format("HH:mm");
            var totalOrders = orders.length;
            var items = {};

            orders.forEach(function(order) {
              order?.order_details?.forEach(function(detail) {
                var itemName = detail.itemName; // Usar el nombre del artículo
                var itemQty = detail.count;
                items[itemName] = (items[itemName] || 0) + itemQty;
              });
            });

            var resultItem = {
              "intervalStart": intervalStart,
              "intervalEnd": intervalEnd,
              "totalOrders": totalOrders,
              "items": items
            };

            result.push(resultItem);

          }

          return result;
        }

      }; */

      /*  $(document).ready(function() {
         ncmKDSGroupByTime.init();
         console.log("aqui");
       }); */



    