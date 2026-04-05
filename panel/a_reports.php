<?php
include_once('includes/top_includes.php');
topHook();
accessControl([0]);
?>


  <div class="col-md-10 col-md-offset-1 col-sm-12 col-xs-12 wrapper text-right">
    <?=headerPrint();?>
    <a href="https://docs.encom.app/panel-de-control/reportes" class="m-r-sm" target="_blank" data-toggle="tooltip" data-placement="left" title="" data-original-title="Visitar el centro de ayuda">
      <i class="material-icons text-info m-b-xs">help_outline</i>
    </a>
    <span class="font-bold h1" id="pageTitle">
      Reportes
    </span>
  </div>
    
  <div class="row">
    <div class="col-md-5 col-md-offset-1 col-sm-6 col-xs-12">
      <div class="col-xs-12 panel m-b wrapper r-24x">
        <div class="h4 font-bold m-b-sm">
          Ventas
        </div>

        <p class="m-b text-muted">Analiza el rendimiento de tu empresa</p>

        <div class="list-group no-radius no-border auto text-md">
          <a href="/@#report_summary" class="list-group-item"> <span class="text-info"> Resumen </span></a>
          <a href="/@#report_transactions" class="list-group-item"> <span class="text-info"> Transacciones </span></a>
          <a href="/@#report_products" class="list-group-item"> <span class="text-info"> Productos y Servicios </span></a>
          <a href="/@#report_customers" class="list-group-item"> <span class="text-info"> Análisis de Clientes </span> <span class="badge bg-danger pull-right hidden">Nuevo</span></a>
          <a href="/@#report_users" class="list-group-item"> <span class="text-info"> Staff y Usuarios </span></a>
          <a href="/@#report_categories" class="list-group-item"> <span class="text-info"> Categorías </span></a>
          <a href="/@#report_brands" class="list-group-item"> <span class="text-info"> Marcas </span></a>
          <a href="/@#report_p_methods" class="list-group-item"> <span class="text-info"> Medios de Pago </span></a>
        </div>
      </div>

      <div class="col-xs-12 panel m-b wrapper r-24x">
        <div class="h4 font-bold m-b-sm">
          Inventario
        </div>
        <p class="m-b text-muted">Conoce tu inventario con información detallada</p>
        <div class="list-group no-radius no-border auto text-md"> 
          <a href="/@#report_inventory" class="list-group-item"> <span class="text-info">Movimientos</span></a>
          <a href="/@#report_stock" class="list-group-item"> <span class="text-info">Niveles de Stock</span></a>
          <a href="/@#inventory_count" class="list-group-item"> <span class="text-info">Conteo</span></a>
          <a href="/@#report_production" class="list-group-item"> <span class="text-info">Producción</span></a>
          <a href="/@#report_giftCards" class="list-group-item"> <span class="text-info">Gift Cards</span></a>
        </div>
      </div>      
    </div>

  	<div class="col-md-5 col-sm-6 col-xs-12">
      <div class="col-xs-12 panel m-b wrapper r-24x">
        <div class="h4 font-bold m-b-sm">
          Administrativos y Financieros
        </div>
        <p class="m-b text-muted">Administra cada aspecto de tu empresa </p>
        <div class="list-group no-radius no-border auto text-md"> 
          <a href="/@#report_open_invoices" class="list-group-item"> <span class="text-info"> Cuentas por Cobrar </span></a> 
          <a href="/@#report_open_invoices?state=outcome" class="list-group-item"> <span class="text-info"> Cuentas por Pagar </span></a> 
          <a href="/@#report_summary_year" class="list-group-item"> <span class="text-info"> Resumen Anual </span></a>
          <a href="/@#report_cashflow" class="list-group-item"> <span class="text-info"> Flujo de Caja </span></a>
          <a href="/@#report_purchases" class="list-group-item"> <span class="text-info"> Compras y Gastos </span></a>
          <a href="/@#report_vpayments" class="list-group-item"> <span class="text-info"> Pagos ePOS </span></a>
          <a href="/@#report_drawers" class="list-group-item"> <span class="text-info"> Control de Cajas </span></a>
          <a href="/@#report_expenses" class="list-group-item"> <span class="text-info"> Movimientos de Caja </span></a>
          <a href="/@#report_schedule" class="list-group-item"> <span class="text-info"> Agendamientos </span></a>
          <a href="/@#report_recurring" class="list-group-item"> <span class="text-info"> Facturas Recurrentes </span></a>
          <a href="/@#report_orders" class="list-group-item"> <span class="text-info">Órdenes</span></a>
          <a href="/@#report_satisfaction" class="list-group-item"> <span class="text-info"> Calificación de Clientes </span></a>  

        </div>
      </div>
  	</div>

  </div>
  <script>
    ncmHelpers.preCachePages(['report_transactions','report_products','report_transactions','report_users']);
  </script>

<?php
include_once('includes/compression_end.php');
dai();
?>