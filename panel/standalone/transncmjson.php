<?php
require_once('sa_head.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$companyCategories  = [
  'Salud y Fitness' =>[
    'Gimnasio/Club de Bienestar'  => '0.1',
    'Entrenador Personal'         => '0.2',
    'Medicina Alternativa'        => '0.3',
    'Medicina'              => '0.4',
    'Profesional de la Salud'       => '0.5',
    'Hospital/Centro de Salud'      => '0.6',
    'Otro'                  => '0'
  ],
  'Alimentos y Bebidas'=>[
    'Panadería/Pastelería'  =>'1.1',
    'Bar/Club'    =>'1.2',
    'Cafetería'   =>'1.3',
    'Food Truck'  =>'1.4',
    'Comida Rápida' =>'1.6',
    'Restaurante'   =>'1.7',
    'Comida Saludable'  =>'1.8',
    'Vinos y Bebidas'   =>'1.9',
    'Jugos y Smoothies' =>'1.10',
    'Heladería'     =>'1.11',
    'Otro'      =>'1'
  ],
  'Retail'=>[
    'Arte/Fotografía/Filmaciones'=>'2.1',
    'Libros/Música/Videos'=>'2.2',
    'Ropa/Accesorios'=>'2.3',
    'Electrónicos/Tecnología/Informática'=>'2.4',
    'Regalos'=>'2.5',
    'Kiosco/Mercado'=>'2.6',
    'Ferretería'=>'2.7',
    'Joyas/Relojes'=>'2.8',
    'Tienda de Mascotas'=>'2.9',
    'Tienda deportiva'=>'2.10',
    'Hogar/Decoración'=>'2.11',
    'Niños/Bebés'=>'2.12',
    'Otro'=>'2'
  ],
  'Reparación'=>[
    'Servicios para automóviles'=>'3.1',
    'Ropas/Reparación de calzados/Lavandería'=>'3.3',
    'Computadoras/Electrónica'=>'3.4',
    'Hogar Servicios'=>'3.5',
    'Otro'=>'3'
  ],
  'Transporte'=>[
    'Delivery'=>'4.1',
    'Limousine'=>'4.2',
    'Taxi'=>'4.3',
    'Bus'=>'4.4',
    'Movilización'=>'4.5',
    'Other'=>'4'
  ],
  'Belleza'=>[
    'Salón de Belleza'=>'5.1',
    'Peluquería/Barbería'=>'5.2',
    'Masajes'=>'5.3',
    'Spa de Uñas'=>'5.4',
    'Spa'=>'5.5',
    'Salon de bronceado'=>'5.6',
    'Tatuajes/Piercing'=>'5.7',
    'Otro'=>'5'
  ],
  'Servicios Profesionales'=>[
    'Contabilidad'=>'6.1',
    'Consultoría'=>'6.2',
    'Diseño'=>'6.3',
    'Marketing'=>'6.4',
    'Real State'=>'6.5',
    'Otro'=>'6'
  ],
  'Educación'=>[
    'Instituto'=>'7.1',
    'Universidad'=>'7.2',
    'Cursos y Capacitaciones'=>'7.3',
    'Enseñanza On-line'=>'7.4',
    'Idiomas'=>'7.5',
    'Otro'=>'7'
  ],
  'Software'=>[
    'App'=>'8.1',
    'SaaS'=>'8.2',
    'Online Service'=>'8.3',
    'Ecommerce'=>'8.4',
    'Otro'=>'8'
  ]
];

$result     = ncmExecute('SELECT * FROM transaction WHERE transactionDate BETWEEN ? AND ? LIMIT 10000', ['2023-01-01 00:00:00', '2023-12-30 00:00:00'], false, true);
$_setting   = ncmExecute('SELECT * FROM setting LIMIT 10000',[],false,true);

$companies  = [];
$out        = [];

if($_setting){
  while (!$_setting->EOF) {
    $field                            = $_setting->fields;
    $companies[$field['companyId']]   = [
                                          'name'      => $field['settingName'],
                                          'category'  => getCompanyCategoryName($companyCategories, $field['settingCompanyCategoryId']),
                                        ];
    $_setting->MoveNext();  
  }
}


if($result){
  while (!$result->EOF) {
    $field            = $result->fields;

    $payment          = json_decode($field['transactionPaymentType'], true);
    $amount           = 0;
    $go               = false;

    if(is_array($payment)){
      foreach ($payment as $key => $value) {
        //echo $value . '<br>';
        if(in_array($value['type'], ['creditcard', 'debitcard', 'QRPayment'])){
          //$amount = $amount + (float)$value['total'];
          $go               = true;
        }
      }
    }

    if($go){

      $strtt            = strtotime($field['transactionDate']);
      $dia              = date('d', $strtt);
      $mes              = date('m', $strtt);
      $ano              = date('Y', $strtt);
      $hora             = date('h', $strtt);

      $out[] = [
                  'empresa' => $companies[$field['companyId']]['name'],
                  'rubro'   => $companies[$field['companyId']]['category'],
                  'dia'     => $dia,
                  'mes'     => $mes,
                  'ano'     => $ano,
                  'hora'    => $hora,
                  'total'   => $field['transactionTotal']
                ];

    }

    $result->MoveNext();  
  }

  $result->Close();
}


jsonDieResult($out);

dai();
?>
