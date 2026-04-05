<?php
require_once(__DIR__ . '/../includes/cors.php');

$countries = '{  
   "AR":{  
      "name":"Argentina",
      "native":"Argentina",
      "phone":"54",
      "continent":"SA",
      "capital":"Buenos Aires",
      "currency":{  
         "symbol":"AR$",
         "name":"Argentine Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"ARS",
         "name_plural":"Argentine pesos",
         "vat":"21",
         "vat_name":"IVA"
      },
      "tin":"CUIT",
      "languages":"es,gn",
      "iso":"ARG"
   },
   "BO":{  
      "name":"Bolivia",
      "native":"Bolivia",
      "phone":"591",
      "continent":"SA",
      "capital":"Sucre",
      "currency":{  
         "symbol":"Bs",
         "name":"Boliviano",
         "symbol_native":"Bs",
         "decimal_digits":2,
         "rounding":0,
         "code":"BOB",
         "name_plural":"Bolivianos",
         "vat":"13",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es,ay,qu",
      "iso":"BOL"
   },
   "BR":{  
      "name":"Brazil",
      "native":"Brasil",
      "phone":"55",
      "continent":"SA",
      "capital":"Bras\u00edlia",
      "currency":{  
         "symbol":"R$",
         "name":"Brazilian Real",
         "symbol_native":"R$",
         "decimal_digits":2,
         "rounding":0,
         "code":"BRL",
         "name_plural":"Brazilian reals",
         "vat":"17",
         "vat_name":"IPI"
      },
      "tin":"CPF\/CNPJ",
      "languages":"pt",
      "iso":"BRA"
   },
   "CL":{  
      "name":"Chile",
      "native":"Chile",
      "phone":"56",
      "continent":"SA",
      "capital":"Santiago",
      "currency":{  
         "symbol":"$",
         "name":"Chilean Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"CLP",
         "name_plural":"Chilean Pesos",
         "vat":"19",
         "vat_name":"IVA"
      },
      "tin":"RUT",
      "languages":"es",
      "iso":"CHL"
   },
   "CO":{  
      "name":"Colombia",
      "native":"Colombia",
      "phone":"57",
      "continent":"SA",
      "capital":"Bogot\u00e1",
      "currency":{  
         "symbol":"CO$",
         "name":"Colombian Peso",
         "symbol_native":"$",
         "decimal_digits":0,
         "rounding":0,
         "code":"COP",
         "name_plural":"Colombian pesos",
         "vat":"16",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"COL"
   },
   "CR":{  
      "name":"Costa Rica",
      "native":"Costa Rica",
      "phone":"506",
      "continent":"NA",
      "capital":"San Jos\u00e9",
      "currency":{  
         "symbol":"\u20a1",
         "name":"Costa Rican Col\u00f3n",
         "symbol_native":"\u20a1",
         "decimal_digits":0,
         "rounding":0,
         "code":"CRC",
         "name_plural":"Costa Rican col\u00f3ns",
         "vat":"13",
         "vat_name":"IV"
      },
      "tin":"NITE",
      "languages":"es",
      "iso":"CRI"
   },
   "CU":{  
      "name":"Cuba",
      "native":"Cuba",
      "phone":"53",
      "continent":"NA",
      "capital":"Havana",
      "languages":"es",
      "iso":"CUB"
   },
   "EC":{  
      "name":"Ecuador",
      "native":"Ecuador",
      "phone":"593",
      "continent":"SA",
      "capital":"Quito",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RUC",
      "languages":"es",
      "iso":"ECU"
   },
   "SV":{  
      "name":"El Salvador",
      "native":"El Salvador",
      "phone":"503",
      "continent":"NA",
      "capital":"San Salvador",
      "currency":{  
         "symbol":"$",
         "name":"US Doallar",
         "symbol_native":"$\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US Dollars",
         "vat":"13",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"SLV"
   },
   "ES":{  
      "name":"Spain",
      "native":"Espa\u00f1a",
      "phone":"34",
      "continent":"EU",
      "capital":"Madrid",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros",
         "vat":"21",
         "vat_name":"IVA"
      },
      "tin":"NIF\/CIF",
      "languages":"es,eu,ca,gl,oc",
      "iso":"ESP"
   },
   "GT":{  
      "name":"Guatemala",
      "native":"Guatemala",
      "phone":"502",
      "continent":"NA",
      "capital":"Guatemala City",
      "currency":{  
         "symbol":"GTQ",
         "name":"Guatemalan Quetzal",
         "symbol_native":"Q",
         "decimal_digits":2,
         "rounding":0,
         "code":"GTQ",
         "name_plural":"Guatemalan quetzals",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RTU",
      "languages":"es",
      "iso":"GTM"
   },
   "HT":{  
      "name":"Haiti",
      "native":"Ha\u00efti",
      "phone":"509",
      "continent":"NA",
      "capital":"Port-au-Prince",
      "languages":"fr,ht",
      "iso":"HTI"
   },
   "HN":{  
      "name":"Honduras",
      "native":"Honduras",
      "phone":"504",
      "continent":"NA",
      "capital":"Tegucigalpa",
      "currency":{  
         "symbol":"HNL",
         "name":"Honduran Lempira",
         "symbol_native":"L",
         "decimal_digits":2,
         "rounding":0,
         "code":"HNL",
         "name_plural":"Honduran lempiras",
         "vat":"15",
         "vat_name":"ISV"
      },
      "tin":"RTN",
      "languages":"es",
      "iso":"HND"
   },
   "MX":{  
      "name":"Mexico",
      "native":"M\u00e9xico",
      "phone":"52",
      "continent":"NA",
      "capital":"Mexico City",
      "currency":{  
         "symbol":"MX$",
         "name":"Mexican Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"MXN",
         "name_plural":"Mexican pesos",
         "vat":"16",
         "vat_name":"IVA"
      },
      "tin":"RFC",
      "languages":"es",
      "iso":"MEX"
   },
   "NI":{  
      "name":"Nicaragua",
      "native":"Nicaragua",
      "phone":"505",
      "continent":"NA",
      "capital":"Managua",
      "currency":{  
         "symbol":"C$",
         "name":"Nicaraguan C\u00f3rdoba",
         "symbol_native":"C$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NIO",
         "name_plural":"Nicaraguan c\u00f3rdobas",
         "vat":"15",
         "vat_name":"IVA"
      },
      "languages":"es",
      "iso":"NIC"
   },
   "PA":{  
      "name":"Panama",
      "native":"Panam\u00e1",
      "phone":"507",
      "continent":"NA",
      "capital":"Panama City",
      "currency":{  
         "symbol":"\u0e3f",
         "name":"Balboa",
         "symbol_native":"\u0e3f",
         "decimal_digits":2,
         "rounding":0,
         "code":"PAB",
         "name_plural":"Balboas",
         "vat":"7"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"PAN"
   },
   "PY":{  
      "name":"Paraguay",
      "native":"Paraguay",
      "phone":"595",
      "continent":"SA",
      "capital":"Asunci\u00f3n",
      "currency":{  
         "symbol":"\u20b2",
         "name":"Paraguayan Guarani",
         "symbol_native":"\u20b2",
         "decimal_digits":0,
         "rounding":0,
         "code":"PYG",
         "name_plural":"Paraguayan guaranis",
         "vat":"10",
         "vat_name":"IVA"
      },
      "tin":"RUC",
      "languages":"es,gn",
      "iso":"PRY"
   },
   "PE":{  
      "name":"Peru",
      "native":"Per\u00fa",
      "phone":"51",
      "continent":"SA",
      "capital":"Lima",
      "currency":{  
         "symbol":"S\/.",
         "name":"Peruvian Nuevo Sol",
         "symbol_native":"S\/.",
         "decimal_digits":2,
         "rounding":0,
         "code":"PEN",
         "name_plural":"Peruvian nuevos soles",
         "vat":"18",
         "vat_name":"IGV"
      },
      "tin":"RUC",
      "languages":"es",
      "iso":"PER"
   },
   "PR":{  
      "name":"Puerto Rico",
      "native":"Puerto Rico",
      "phone":"1787",
      "continent":"NA",
      "capital":"San Juan",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars",
         "vat":"11,5"
      },
      "languages":"es,en",
      "iso":"PRI"
   },
   "DO":{  
      "name":"Dominican Republic",
      "native":"Rep\u00fablica Dominicana",
      "phone":"1809",
      "continent":"NA",
      "capital":"Santo Domingo",
      "currency":{  
         "symbol":"RD$",
         "name":"Dominican Peso",
         "symbol_native":"RD$",
         "decimal_digits":2,
         "rounding":0,
         "code":"DOP",
         "name_plural":"Dominican pesos",
         "vat":"18",
         "vat_name":"ITBIS"
      },
      "tin":"RNC",
      "languages":"es",
      "iso":"DOM"
   },
   "UY":{  
      "name":"Uruguay",
      "native":"Uruguay",
      "phone":"598",
      "continent":"SA",
      "capital":"Montevideo",
      "currency":{  
         "symbol":"$",
         "name":"Uruguayan Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"UYU",
         "name_plural":"Uruguayan Pesos",
         "vat":"22",
         "vat_name":"IVA"
      },
      "tin":"RUT",
      "languages":"es",
      "iso":"URY"
   },
   "VE":{  
      "name":"Venezuela",
      "native":"Venezuela",
      "phone":"58",
      "continent":"SA",
      "capital":"Caracas",
      "currency":{  
         "symbol":"Bs.F.",
         "name":"Venezuelan Bol\u00edvar",
         "symbol_native":"Bs.F.",
         "decimal_digits":2,
         "rounding":0,
         "code":"VEF",
         "name_plural":"Venezuelan bol\u00edvars",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RIF",
      "languages":"es",
      "iso":"VEN"
   },
   "US":{  
      "name":"United States",
      "native":"United States",
      "phone":"1",
      "continent":"NA",
      "capital":"Washington D.C.",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "tin":"SSN\/TIN",
      "languages":"en",
      "iso":"USA"
   },
   "CA":{  
      "name":"Canada",
      "native":"Canada",
      "phone":"1",
      "continent":"NA",
      "capital":"Ottawa",
      "currency":{  
         "symbol":"CA$",
         "name":"Canadian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"CAD",
         "name_plural":"Canadian dollars"
      },
      "languages":"en,fr",
      "iso":"CAN"
   },
   "AD":{  
      "name":"Andorra",
      "native":"Andorra",
      "phone":"376",
      "continent":"EU",
      "capital":"Andorra la Vella",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"ca",
      "iso":"AND"
   },
   "AE":{  
      "name":"United Arab Emirates",
      "native":"\u062f\u0648\u0644\u0629 \u0627\u0644\u0625\u0645\u0627\u0631\u0627\u062a \u0627\u0644\u0639\u0631\u0628\u064a\u0629 \u0627\u0644\u0645\u062a\u062d\u062f\u0629",
      "phone":"971",
      "continent":"AS",
      "capital":"Abu Dhabi",
      "currency":{  
         "symbol":"AED",
         "name":"United Arab Emirates Dirham",
         "symbol_native":"\u062f.\u0625.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"AED",
         "name_plural":"UAE dirhams"
      },
      "languages":"ar",
      "iso":"ARE"
   },
   "AF":{  
      "name":"Afghanistan",
      "native":"\u0627\u0641\u063a\u0627\u0646\u0633\u062a\u0627\u0646",
      "phone":"93",
      "continent":"AS",
      "capital":"Kabul",
      "currency":{  
         "symbol":"Af",
         "name":"Afghan Afghani",
         "symbol_native":"\u060b",
         "decimal_digits":0,
         "rounding":0,
         "code":"AFN",
         "name_plural":"Afghan Afghanis"
      },
      "languages":"ps,uz,tk",
      "iso":"AFG"
   },
   "AG":{  
      "name":"Antigua and Barbuda",
      "native":"Antigua and Barbuda",
      "phone":"1268",
      "continent":"NA",
      "capital":"Saint John\'s",
      "languages":"en",
      "iso":"ATG"
   },
   "AI":{  
      "name":"Anguilla",
      "native":"Anguilla",
      "phone":"1264",
      "continent":"NA",
      "capital":"The Valley",
      "languages":"en",
      "iso":"AIA"
   },
   "AL":{  
      "name":"Albania",
      "native":"Shqip\u00ebria",
      "phone":"355",
      "continent":"EU",
      "capital":"Tirana",
      "currency":{  
         "symbol":"ALL",
         "name":"Albanian Lek",
         "symbol_native":"Lek",
         "decimal_digits":0,
         "rounding":0,
         "code":"ALL",
         "name_plural":"Albanian lek\u00eb"
      },
      "languages":"sq",
      "iso":"ALB"
   },
   "AM":{  
      "name":"Armenia",
      "native":"\u0540\u0561\u0575\u0561\u057d\u057f\u0561\u0576",
      "phone":"374",
      "continent":"AS",
      "capital":"Yerevan",
      "currency":{  
         "symbol":"AMD",
         "name":"Armenian Dram",
         "symbol_native":"\u0564\u0580.",
         "decimal_digits":0,
         "rounding":0,
         "code":"AMD",
         "name_plural":"Armenian drams"
      },
      "languages":"hy,ru",
      "iso":"ARM"
   },
   "AO":{  
      "name":"Angola",
      "native":"Angola",
      "phone":"244",
      "continent":"AF",
      "capital":"Luanda",
      "languages":"pt",
      "iso":"AGO"
   },
   "AQ":{  
      "name":"Antarctica",
      "native":"Antarctica",
      "phone":"",
      "continent":"AN",
      "capital":"",
      "languages":"",
      "iso":"ATA"
   },
   "AS":{  
      "name":"American Samoa",
      "native":"American Samoa",
      "phone":"1684",
      "continent":"OC",
      "capital":"Pago Pago",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en,sm",
      "iso":"ASM"
   },
   "AT":{  
      "name":"Austria",
      "native":"\u00d6sterreich",
      "phone":"43",
      "continent":"EU",
      "capital":"Vienna",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"de",
      "iso":"AUT"
   },
   "AU":{  
      "name":"Australia",
      "native":"Australia",
      "phone":"61",
      "continent":"OC",
      "capital":"Canberra",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"AUS"
   },
   "AW":{  
      "name":"Aruba",
      "native":"Aruba",
      "phone":"297",
      "continent":"NA",
      "capital":"Oranjestad",
      "languages":"nl,pa",
      "iso":"ABW"
   },
   "AX":{  
      "name":"\u00c5land",
      "native":"\u00c5land",
      "phone":"358",
      "continent":"EU",
      "capital":"Mariehamn",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"sv",
      "iso":"ALA"
   },
   "AZ":{  
      "name":"Azerbaijan",
      "native":"Az\u0259rbaycan",
      "phone":"994",
      "continent":"AS",
      "capital":"Baku",
      "currency":{  
         "symbol":"man.",
         "name":"Azerbaijani Manat",
         "symbol_native":"\u043c\u0430\u043d.",
         "decimal_digits":2,
         "rounding":0,
         "code":"AZN",
         "name_plural":"Azerbaijani manats"
      },
      "languages":"az,hy",
      "iso":"AZE"
   },
   "BA":{  
      "name":"Bosnia and Herzegovina",
      "native":"Bosna i Hercegovina",
      "phone":"387",
      "continent":"EU",
      "capital":"Sarajevo",
      "currency":{  
         "symbol":"KM",
         "name":"Bosnia-Herzegovina Convertible Mark",
         "symbol_native":"KM",
         "decimal_digits":2,
         "rounding":0,
         "code":"BAM",
         "name_plural":"Bosnia-Herzegovina convertible marks"
      },
      "languages":"bs,hr,sr",
      "iso":"BIH"
   },
   "BB":{  
      "name":"Barbados",
      "native":"Barbados",
      "phone":"1246",
      "continent":"NA",
      "capital":"Bridgetown",
      "languages":"en",
      "iso":"BRB"
   },
   "BD":{  
      "name":"Bangladesh",
      "native":"Bangladesh",
      "phone":"880",
      "continent":"AS",
      "capital":"Dhaka",
      "currency":{  
         "symbol":"Tk",
         "name":"Bangladeshi Taka",
         "symbol_native":"\u09f3",
         "decimal_digits":2,
         "rounding":0,
         "code":"BDT",
         "name_plural":"Bangladeshi takas"
      },
      "languages":"bn",
      "iso":"BGD"
   },
   "BE":{  
      "name":"Belgium",
      "native":"Belgi\u00eb",
      "phone":"32",
      "continent":"EU",
      "capital":"Brussels",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"nl,fr,de",
      "iso":"BEL"
   },
   "BF":{  
      "name":"Burkina Faso",
      "native":"Burkina Faso",
      "phone":"226",
      "continent":"AF",
      "capital":"Ouagadougou",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr,ff",
      "iso":"BFA"
   },
   "BG":{  
      "name":"Bulgaria",
      "native":"\u0411\u044a\u043b\u0433\u0430\u0440\u0438\u044f",
      "phone":"359",
      "continent":"EU",
      "capital":"Sofia",
      "currency":{  
         "symbol":"BGN",
         "name":"Bulgarian Lev",
         "symbol_native":"\u043b\u0432.",
         "decimal_digits":2,
         "rounding":0,
         "code":"BGN",
         "name_plural":"Bulgarian leva"
      },
      "languages":"bg",
      "iso":"BGR"
   },
   "BH":{  
      "name":"Bahrain",
      "native":"\u200f\u0627\u0644\u0628\u062d\u0631\u064a\u0646",
      "phone":"973",
      "continent":"AS",
      "capital":"Manama",
      "currency":{  
         "symbol":"BD",
         "name":"Bahraini Dinar",
         "symbol_native":"\u062f.\u0628.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"BHD",
         "name_plural":"Bahraini dinars"
      },
      "languages":"ar",
      "iso":"BHR"
   },
   "BI":{  
      "name":"Burundi",
      "native":"Burundi",
      "phone":"257",
      "continent":"AF",
      "capital":"Bujumbura",
      "currency":{  
         "symbol":"FBu",
         "name":"Burundian Franc",
         "symbol_native":"FBu",
         "decimal_digits":0,
         "rounding":0,
         "code":"BIF",
         "name_plural":"Burundian francs"
      },
      "languages":"fr,rn",
      "iso":"BDI"
   },
   "BJ":{  
      "name":"Benin",
      "native":"B\u00e9nin",
      "phone":"229",
      "continent":"AF",
      "capital":"Porto-Novo",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"BEN"
   },
   "BL":{  
      "name":"Saint Barth\u00e9lemy",
      "native":"Saint-Barth\u00e9lemy",
      "phone":"590",
      "continent":"NA",
      "capital":"Gustavia",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"BLM"
   },
   "BM":{  
      "name":"Bermuda",
      "native":"Bermuda",
      "phone":"1441",
      "continent":"NA",
      "capital":"Hamilton",
      "languages":"en",
      "iso":"BMU"
   },
   "BN":{  
      "name":"Brunei",
      "native":"Negara Brunei Darussalam",
      "phone":"673",
      "continent":"AS",
      "capital":"Bandar Seri Begawan",
      "currency":{  
         "symbol":"BN$",
         "name":"Brunei Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"BND",
         "name_plural":"Brunei dollars"
      },
      "languages":"ms",
      "iso":"BRN"
   },
   "BQ":{  
      "name":"Bonaire",
      "native":"Bonaire",
      "phone":"5997",
      "continent":"NA",
      "capital":"Kralendijk",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"nl",
      "iso":"BES"
   },
   "BS":{  
      "name":"Bahamas",
      "native":"Bahamas",
      "phone":"1242",
      "continent":"NA",
      "capital":"Nassau",
      "languages":"en",
      "iso":"BHS"
   },
   "BT":{  
      "name":"Bhutan",
      "native":"\u02bcbrug-yul",
      "phone":"975",
      "continent":"AS",
      "capital":"Thimphu",
      "languages":"dz",
      "iso":"BTN"
   },
   "BV":{  
      "name":"Bouvet Island",
      "native":"Bouvet\u00f8ya",
      "phone":"",
      "continent":"AN",
      "capital":"",
      "currency":{  
         "symbol":"Nkr",
         "name":"Norwegian Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"NOK",
         "name_plural":"Norwegian kroner"
      },
      "languages":"",
      "iso":"BVT"
   },
   "BW":{  
      "name":"Botswana",
      "native":"Botswana",
      "phone":"267",
      "continent":"AF",
      "capital":"Gaborone",
      "currency":{  
         "symbol":"BWP",
         "name":"Botswanan Pula",
         "symbol_native":"P",
         "decimal_digits":2,
         "rounding":0,
         "code":"BWP",
         "name_plural":"Botswanan pulas"
      },
      "languages":"en,tn",
      "iso":"BWA"
   },
   "BY":{  
      "name":"Belarus",
      "native":"\u0411\u0435\u043b\u0430\u0440\u0443\u0301\u0441\u044c",
      "phone":"375",
      "continent":"EU",
      "capital":"Minsk",
      "currency":{  
         "symbol":"BYR",
         "name":"Belarusian Ruble",
         "symbol_native":"BYR",
         "decimal_digits":0,
         "rounding":0,
         "code":"BYR",
         "name_plural":"Belarusian rubles"
      },
      "languages":"be,ru",
      "iso":"BLR"
   },
   "BZ":{  
      "name":"Belize",
      "native":"Belize",
      "phone":"501",
      "continent":"NA",
      "capital":"Belmopan",
      "currency":{  
         "symbol":"BZ$",
         "name":"Belize Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"BZD",
         "name_plural":"Belize dollars"
      },
      "languages":"en,es",
      "iso":"BLZ"
   },
   "CC":{  
      "name":"Cocos [Keeling] Islands",
      "native":"Cocos (Keeling) Islands",
      "phone":"61",
      "continent":"AS",
      "capital":"West Island",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"CCK"
   },
   "CD":{  
      "name":"Democratic Republic of the Congo",
      "native":"R\u00e9publique d\u00e9mocratique du Congo",
      "phone":"243",
      "continent":"AF",
      "capital":"Kinshasa",
      "currency":{  
         "symbol":"CDF",
         "name":"Congolese Franc",
         "symbol_native":"FrCD",
         "decimal_digits":2,
         "rounding":0,
         "code":"CDF",
         "name_plural":"Congolese francs"
      },
      "languages":"fr,ln,kg,sw,lu",
      "iso":"COD"
   },
   "CF":{  
      "name":"Central African Republic",
      "native":"K\u00f6d\u00f6r\u00f6s\u00ease t\u00ee B\u00eaafr\u00eeka",
      "phone":"236",
      "continent":"AF",
      "capital":"Bangui",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"fr,sg",
      "iso":"CAF"
   },
   "CG":{  
      "name":"Republic of the Congo",
      "native":"R\u00e9publique du Congo",
      "phone":"242",
      "continent":"AF",
      "capital":"Brazzaville",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"fr,ln",
      "iso":"COG"
   },
   "CH":{  
      "name":"Switzerland",
      "native":"Schweiz",
      "phone":"41",
      "continent":"EU",
      "capital":"Bern",
      "languages":"de,fr,it",
      "iso":"CHE"
   },
   "CI":{  
      "name":"Ivory Coast",
      "native":"C\u00f4te d\'Ivoire",
      "phone":"225",
      "continent":"AF",
      "capital":"Yamoussoukro",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"CIV"
   },
   "CK":{  
      "name":"Cook Islands",
      "native":"Cook Islands",
      "phone":"682",
      "continent":"OC",
      "capital":"Avarua",
      "currency":{  
         "symbol":"NZ$",
         "name":"New Zealand Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NZD",
         "name_plural":"New Zealand dollars"
      },
      "languages":"en",
      "iso":"COK"
   },
   "CM":{  
      "name":"Cameroon",
      "native":"Cameroon",
      "phone":"237",
      "continent":"AF",
      "capital":"Yaound\u00e9",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"en,fr",
      "iso":"CMR"
   },
   "CN":{  
      "name":"China",
      "native":"\u4e2d\u56fd",
      "phone":"86",
      "continent":"AS",
      "capital":"Beijing",
      "currency":{  
         "symbol":"CN\u00a5",
         "name":"Chinese Yuan",
         "symbol_native":"CN\u00a5",
         "decimal_digits":2,
         "rounding":0,
         "code":"CNY",
         "name_plural":"Chinese yuan"
      },
      "languages":"zh",
      "iso":"CHN"
   },
   "CV":{  
      "name":"Cape Verde",
      "native":"Cabo Verde",
      "phone":"238",
      "continent":"AF",
      "capital":"Praia",
      "currency":{  
         "symbol":"CV$",
         "name":"Cape Verdean Escudo",
         "symbol_native":"CV$",
         "decimal_digits":2,
         "rounding":0,
         "code":"CVE",
         "name_plural":"Cape Verdean escudos"
      },
      "languages":"pt",
      "iso":"CPV"
   },
   "CW":{  
      "name":"Curacao",
      "native":"Cura\u00e7ao",
      "phone":"5999",
      "continent":"NA",
      "capital":"Willemstad",
      "languages":"nl,pa,en",
      "iso":"CUW"
   },
   "CX":{  
      "name":"Christmas Island",
      "native":"Christmas Island",
      "phone":"61",
      "continent":"AS",
      "capital":"Flying Fish Cove",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"CXR"
   },
   "CY":{  
      "name":"Cyprus",
      "native":"\u039a\u03cd\u03c0\u03c1\u03bf\u03c2",
      "phone":"357",
      "continent":"EU",
      "capital":"Nicosia",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"el,tr,hy",
      "iso":"CYP"
   },
   "CZ":{  
      "name":"Czech Republic",
      "native":"\u010cesk\u00e1 republika",
      "phone":"420",
      "continent":"EU",
      "capital":"Prague",
      "currency":{  
         "symbol":"K\u010d",
         "name":"Czech Republic Koruna",
         "symbol_native":"K\u010d",
         "decimal_digits":2,
         "rounding":0,
         "code":"CZK",
         "name_plural":"Czech Republic korunas"
      },
      "languages":"cs,sk",
      "iso":"CZE"
   },
   "DE":{  
      "name":"Germany",
      "native":"Deutschland",
      "phone":"49",
      "continent":"EU",
      "capital":"Berlin",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"de",
      "iso":"DEU"
   },
   "DJ":{  
      "name":"Djibouti",
      "native":"Djibouti",
      "phone":"253",
      "continent":"AF",
      "capital":"Djibouti",
      "currency":{  
         "symbol":"Fdj",
         "name":"Djiboutian Franc",
         "symbol_native":"Fdj",
         "decimal_digits":0,
         "rounding":0,
         "code":"DJF",
         "name_plural":"Djiboutian francs"
      },
      "languages":"fr,ar",
      "iso":"DJI"
   },
   "DK":{  
      "name":"Denmark",
      "native":"Danmark",
      "phone":"45",
      "continent":"EU",
      "capital":"Copenhagen",
      "currency":{  
         "symbol":"Dkr",
         "name":"Danish Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"DKK",
         "name_plural":"Danish kroner"
      },
      "languages":"da",
      "iso":"DNK"
   },
   "DM":{  
      "name":"Dominica",
      "native":"Dominica",
      "phone":"1767",
      "continent":"NA",
      "capital":"Roseau",
      "languages":"en",
      "iso":"DMA"
   },
   "DZ":{  
      "name":"Algeria",
      "native":"\u0627\u0644\u062c\u0632\u0627\u0626\u0631",
      "phone":"213",
      "continent":"AF",
      "capital":"Algiers",
      "currency":{  
         "symbol":"DA",
         "name":"Algerian Dinar",
         "symbol_native":"\u062f.\u062c.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"DZD",
         "name_plural":"Algerian dinars"
      },
      "languages":"ar",
      "iso":"DZA"
   },
   "EE":{  
      "name":"Estonia",
      "native":"Eesti",
      "phone":"372",
      "continent":"EU",
      "capital":"Tallinn",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"et",
      "iso":"EST"
   },
   "EG":{  
      "name":"Egypt",
      "native":"\u0645\u0635\u0631\u200e",
      "phone":"20",
      "continent":"AF",
      "capital":"Cairo",
      "currency":{  
         "symbol":"EGP",
         "name":"Egyptian Pound",
         "symbol_native":"\u062c.\u0645.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"EGP",
         "name_plural":"Egyptian pounds"
      },
      "languages":"ar",
      "iso":"EGY"
   },
   "EH":{  
      "name":"Western Sahara",
      "native":"\u0627\u0644\u0635\u062d\u0631\u0627\u0621 \u0627\u0644\u063a\u0631\u0628\u064a\u0629",
      "phone":"212",
      "continent":"AF",
      "capital":"El Aai\u00fan",
      "languages":"es",
      "iso":"ESH"
   },
   "ER":{  
      "name":"Eritrea",
      "native":"\u12a4\u122d\u1275\u122b",
      "phone":"291",
      "continent":"AF",
      "capital":"Asmara",
      "currency":{  
         "symbol":"Nfk",
         "name":"Eritrean Nakfa",
         "symbol_native":"Nfk",
         "decimal_digits":2,
         "rounding":0,
         "code":"ERN",
         "name_plural":"Eritrean nakfas"
      },
      "languages":"ti,ar,en",
      "iso":"ERI"
   },
   "ET":{  
      "name":"Ethiopia",
      "native":"\u12a2\u1275\u12ee\u1335\u12eb",
      "phone":"251",
      "continent":"AF",
      "capital":"Addis Ababa",
      "currency":{  
         "symbol":"Br",
         "name":"Ethiopian Birr",
         "symbol_native":"Br",
         "decimal_digits":2,
         "rounding":0,
         "code":"ETB",
         "name_plural":"Ethiopian birrs"
      },
      "languages":"am",
      "iso":"ETH"
   },
   "FI":{  
      "name":"Finland",
      "native":"Suomi",
      "phone":"358",
      "continent":"EU",
      "capital":"Helsinki",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fi,sv",
      "iso":"FIN"
   },
   "FJ":{  
      "name":"Fiji",
      "native":"Fiji",
      "phone":"679",
      "continent":"OC",
      "capital":"Suva",
      "languages":"en,fj,hi,ur",
      "iso":"FJI"
   },
   "FK":{  
      "name":"Falkland Islands",
      "native":"Falkland Islands",
      "phone":"500",
      "continent":"SA",
      "capital":"Stanley",
      "languages":"en",
      "iso":"FLK"
   },
   "FM":{  
      "name":"Micronesia",
      "native":"Micronesia",
      "phone":"691",
      "continent":"OC",
      "capital":"Palikir",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"FSM"
   },
   "FO":{  
      "name":"Faroe Islands",
      "native":"F\u00f8royar",
      "phone":"298",
      "continent":"EU",
      "capital":"T\u00f3rshavn",
      "currency":{  
         "symbol":"Dkr",
         "name":"Danish Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"DKK",
         "name_plural":"Danish kroner"
      },
      "languages":"fo",
      "iso":"FRO"
   },
   "FR":{  
      "name":"France",
      "native":"France",
      "phone":"33",
      "continent":"EU",
      "capital":"Paris",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"FRA"
   },
   "GA":{  
      "name":"Gabon",
      "native":"Gabon",
      "phone":"241",
      "continent":"AF",
      "capital":"Libreville",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"fr",
      "iso":"GAB"
   },
   "GB":{  
      "name":"United Kingdom",
      "native":"United Kingdom",
      "phone":"44",
      "continent":"EU",
      "capital":"London",
      "currency":{  
         "symbol":"\u00a3",
         "name":"British Pound Sterling",
         "symbol_native":"\u00a3",
         "decimal_digits":2,
         "rounding":0,
         "code":"GBP",
         "name_plural":"British pounds sterling"
      },
      "languages":"en",
      "iso":"GBR"
   },
   "GD":{  
      "name":"Grenada",
      "native":"Grenada",
      "phone":"1473",
      "continent":"NA",
      "capital":"St. George\'s",
      "languages":"en",
      "iso":"GRD"
   },
   "GE":{  
      "name":"Georgia",
      "native":"\u10e1\u10d0\u10e5\u10d0\u10e0\u10d7\u10d5\u10d4\u10da\u10dd",
      "phone":"995",
      "continent":"AS",
      "capital":"Tbilisi",
      "currency":{  
         "symbol":"GEL",
         "name":"Georgian Lari",
         "symbol_native":"GEL",
         "decimal_digits":2,
         "rounding":0,
         "code":"GEL",
         "name_plural":"Georgian laris"
      },
      "languages":"ka",
      "iso":"GEO"
   },
   "GF":{  
      "name":"French Guiana",
      "native":"Guyane fran\u00e7aise",
      "phone":"594",
      "continent":"SA",
      "capital":"Cayenne",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"GUF"
   },
   "GG":{  
      "name":"Guernsey",
      "native":"Guernsey",
      "phone":"44",
      "continent":"EU",
      "capital":"St. Peter Port",
      "currency":{  
         "symbol":"\u00a3",
         "name":"British Pound Sterling",
         "symbol_native":"\u00a3",
         "decimal_digits":2,
         "rounding":0,
         "code":"GBP",
         "name_plural":"British pounds sterling"
      },
      "languages":"en,fr",
      "iso":"GGY"
   },
   "GH":{  
      "name":"Ghana",
      "native":"Ghana",
      "phone":"233",
      "continent":"AF",
      "capital":"Accra",
      "currency":{  
         "symbol":"GH\u20b5",
         "name":"Ghanaian Cedi",
         "symbol_native":"GH\u20b5",
         "decimal_digits":2,
         "rounding":0,
         "code":"GHS",
         "name_plural":"Ghanaian cedis"
      },
      "languages":"en",
      "iso":"GHA"
   },
   "GI":{  
      "name":"Gibraltar",
      "native":"Gibraltar",
      "phone":"350",
      "continent":"EU",
      "capital":"Gibraltar",
      "languages":"en",
      "iso":"GIB"
   },
   "GL":{  
      "name":"Greenland",
      "native":"Kalaallit Nunaat",
      "phone":"299",
      "continent":"NA",
      "capital":"Nuuk",
      "currency":{  
         "symbol":"Dkr",
         "name":"Danish Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"DKK",
         "name_plural":"Danish kroner"
      },
      "languages":"kl",
      "iso":"GRL"
   },
   "GM":{  
      "name":"Gambia",
      "native":"Gambia",
      "phone":"220",
      "continent":"AF",
      "capital":"Banjul",
      "languages":"en",
      "iso":"GMB"
   },
   "GN":{  
      "name":"Guinea",
      "native":"Guin\u00e9e",
      "phone":"224",
      "continent":"AF",
      "capital":"Conakry",
      "currency":{  
         "symbol":"FG",
         "name":"Guinean Franc",
         "symbol_native":"FG",
         "decimal_digits":0,
         "rounding":0,
         "code":"GNF",
         "name_plural":"Guinean francs"
      },
      "languages":"fr,ff",
      "iso":"GIN"
   },
   "GP":{  
      "name":"Guadeloupe",
      "native":"Guadeloupe",
      "phone":"590",
      "continent":"NA",
      "capital":"Basse-Terre",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"GLP"
   },
   "GQ":{  
      "name":"Equatorial Guinea",
      "native":"Guinea Ecuatorial",
      "phone":"240",
      "continent":"AF",
      "capital":"Malabo",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"es,fr",
      "iso":"GNQ"
   },
   "GR":{  
      "name":"Greece",
      "native":"\u0395\u03bb\u03bb\u03ac\u03b4\u03b1",
      "phone":"30",
      "continent":"EU",
      "capital":"Athens",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"el",
      "iso":"GRC"
   },
   "GS":{  
      "name":"South Georgia and the South Sandwich Islands",
      "native":"South Georgia",
      "phone":"500",
      "continent":"AN",
      "capital":"King Edward Point",
      "currency":{  
         "symbol":"\u00a3",
         "name":"British Pound Sterling",
         "symbol_native":"\u00a3",
         "decimal_digits":2,
         "rounding":0,
         "code":"GBP",
         "name_plural":"British pounds sterling"
      },
      "languages":"en",
      "iso":"SGS"
   },
   "GU":{  
      "name":"Guam",
      "native":"Guam",
      "phone":"1671",
      "continent":"OC",
      "capital":"Hag\u00e5t\u00f1a",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en,ch,es",
      "iso":"GUM"
   },
   "GW":{  
      "name":"Guinea-Bissau",
      "native":"Guin\u00e9-Bissau",
      "phone":"245",
      "continent":"AF",
      "capital":"Bissau",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"pt",
      "iso":"GNB"
   },
   "GY":{  
      "name":"Guyana",
      "native":"Guyana",
      "phone":"592",
      "continent":"SA",
      "capital":"Georgetown",
      "languages":"en",
      "iso":"GUY"
   },
   "HK":{  
      "name":"Hong Kong",
      "native":"\u9999\u6e2f",
      "phone":"852",
      "continent":"AS",
      "capital":"City of Victoria",
      "currency":{  
         "symbol":"HK$",
         "name":"Hong Kong Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"HKD",
         "name_plural":"Hong Kong dollars"
      },
      "languages":"zh,en",
      "iso":"HKG"
   },
   "HM":{  
      "name":"Heard Island and McDonald Islands",
      "native":"Heard Island and McDonald Islands",
      "phone":"",
      "continent":"AN",
      "capital":"",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"HMD"
   },
   "HR":{  
      "name":"Croatia",
      "native":"Hrvatska",
      "phone":"385",
      "continent":"EU",
      "capital":"Zagreb",
      "currency":{  
         "symbol":"kn",
         "name":"Croatian Kuna",
         "symbol_native":"kn",
         "decimal_digits":2,
         "rounding":0,
         "code":"HRK",
         "name_plural":"Croatian kunas"
      },
      "languages":"hr",
      "iso":"HRV"
   },
   "HU":{  
      "name":"Hungary",
      "native":"Magyarorsz\u00e1g",
      "phone":"36",
      "continent":"EU",
      "capital":"Budapest",
      "currency":{  
         "symbol":"Ft",
         "name":"Hungarian Forint",
         "symbol_native":"Ft",
         "decimal_digits":0,
         "rounding":0,
         "code":"HUF",
         "name_plural":"Hungarian forints"
      },
      "languages":"hu",
      "iso":"HUN"
   },
   "ID":{  
      "name":"Indonesia",
      "native":"Indonesia",
      "phone":"62",
      "continent":"AS",
      "capital":"Jakarta",
      "currency":{  
         "symbol":"Rp",
         "name":"Indonesian Rupiah",
         "symbol_native":"Rp",
         "decimal_digits":0,
         "rounding":0,
         "code":"IDR",
         "name_plural":"Indonesian rupiahs"
      },
      "languages":"id",
      "iso":"IDN"
   },
   "IE":{  
      "name":"Ireland",
      "native":"\u00c9ire",
      "phone":"353",
      "continent":"EU",
      "capital":"Dublin",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"ga,en",
      "iso":"IRL"
   },
   "IL":{  
      "name":"Israel",
      "native":"\u05d9\u05b4\u05e9\u05b0\u05c2\u05e8\u05b8\u05d0\u05b5\u05dc",
      "phone":"972",
      "continent":"AS",
      "capital":"Jerusalem",
      "currency":{  
         "symbol":"\u20aa",
         "name":"Israeli New Sheqel",
         "symbol_native":"\u20aa",
         "decimal_digits":2,
         "rounding":0,
         "code":"ILS",
         "name_plural":"Israeli new sheqels"
      },
      "languages":"he,ar",
      "iso":"ISR"
   },
   "IM":{  
      "name":"Isle of Man",
      "native":"Isle of Man",
      "phone":"44",
      "continent":"EU",
      "capital":"Douglas",
      "currency":{  
         "symbol":"\u00a3",
         "name":"British Pound Sterling",
         "symbol_native":"\u00a3",
         "decimal_digits":2,
         "rounding":0,
         "code":"GBP",
         "name_plural":"British pounds sterling"
      },
      "languages":"en,gv",
      "iso":"IMN"
   },
   "IN":{  
      "name":"India",
      "native":"\u092d\u093e\u0930\u0924",
      "phone":"91",
      "continent":"AS",
      "capital":"New Delhi",
      "currency":{  
         "symbol":"Rs",
         "name":"Indian Rupee",
         "symbol_native":"\u099f\u0995\u09be",
         "decimal_digits":2,
         "rounding":0,
         "code":"INR",
         "name_plural":"Indian rupees"
      },
      "languages":"hi,en",
      "iso":"IND"
   },
   "IO":{  
      "name":"British Indian Ocean Territory",
      "native":"British Indian Ocean Territory",
      "phone":"246",
      "continent":"AS",
      "capital":"Diego Garcia",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"IOT"
   },
   "IQ":{  
      "name":"Iraq",
      "native":"\u0627\u0644\u0639\u0631\u0627\u0642",
      "phone":"964",
      "continent":"AS",
      "capital":"Baghdad",
      "currency":{  
         "symbol":"IQD",
         "name":"Iraqi Dinar",
         "symbol_native":"\u062f.\u0639.\u200f",
         "decimal_digits":0,
         "rounding":0,
         "code":"IQD",
         "name_plural":"Iraqi dinars"
      },
      "languages":"ar,ku",
      "iso":"IRQ"
   },
   "IR":{  
      "name":"Iran",
      "native":"\u0627\u06cc\u0631\u0627\u0646",
      "phone":"98",
      "continent":"AS",
      "capital":"Tehran",
      "currency":{  
         "symbol":"IRR",
         "name":"Iranian Rial",
         "symbol_native":"\ufdfc",
         "decimal_digits":0,
         "rounding":0,
         "code":"IRR",
         "name_plural":"Iranian rials"
      },
      "languages":"fa",
      "iso":"IRN"
   },
   "IS":{  
      "name":"Iceland",
      "native":"\u00cdsland",
      "phone":"354",
      "continent":"EU",
      "capital":"Reykjavik",
      "currency":{  
         "symbol":"Ikr",
         "name":"Icelandic Kr\u00f3na",
         "symbol_native":"kr",
         "decimal_digits":0,
         "rounding":0,
         "code":"ISK",
         "name_plural":"Icelandic kr\u00f3nur"
      },
      "languages":"is",
      "iso":"ISL"
   },
   "IT":{  
      "name":"Italy",
      "native":"Italia",
      "phone":"39",
      "continent":"EU",
      "capital":"Rome",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"it",
      "iso":"ITA"
   },
   "JE":{  
      "name":"Jersey",
      "native":"Jersey",
      "phone":"44",
      "continent":"EU",
      "capital":"Saint Helier",
      "currency":{  
         "symbol":"\u00a3",
         "name":"British Pound Sterling",
         "symbol_native":"\u00a3",
         "decimal_digits":2,
         "rounding":0,
         "code":"GBP",
         "name_plural":"British pounds sterling"
      },
      "languages":"en,fr",
      "iso":"JEY"
   },
   "JM":{  
      "name":"Jamaica",
      "native":"Jamaica",
      "phone":"1876",
      "continent":"NA",
      "capital":"Kingston",
      "currency":{  
         "symbol":"J$",
         "name":"Jamaican Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"JMD",
         "name_plural":"Jamaican dollars"
      },
      "languages":"en",
      "iso":"JAM"
   },
   "JO":{  
      "name":"Jordan",
      "native":"\u0627\u0644\u0623\u0631\u062f\u0646",
      "phone":"962",
      "continent":"AS",
      "capital":"Amman",
      "currency":{  
         "symbol":"JD",
         "name":"Jordanian Dinar",
         "symbol_native":"\u062f.\u0623.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"JOD",
         "name_plural":"Jordanian dinars"
      },
      "languages":"ar",
      "iso":"JOR"
   },
   "JP":{  
      "name":"Japan",
      "native":"\u65e5\u672c",
      "phone":"81",
      "continent":"AS",
      "capital":"Tokyo",
      "currency":{  
         "symbol":"\u00a5",
         "name":"Japanese Yen",
         "symbol_native":"\uffe5",
         "decimal_digits":0,
         "rounding":0,
         "code":"JPY",
         "name_plural":"Japanese yen"
      },
      "languages":"ja",
      "iso":"JPN"
   },
   "KE":{  
      "name":"Kenya",
      "native":"Kenya",
      "phone":"254",
      "continent":"AF",
      "capital":"Nairobi",
      "currency":{  
         "symbol":"Ksh",
         "name":"Kenyan Shilling",
         "symbol_native":"Ksh",
         "decimal_digits":2,
         "rounding":0,
         "code":"KES",
         "name_plural":"Kenyan shillings"
      },
      "languages":"en,sw",
      "iso":"KEN"
   },
   "KG":{  
      "name":"Kyrgyzstan",
      "native":"\u041a\u044b\u0440\u0433\u044b\u0437\u0441\u0442\u0430\u043d",
      "phone":"996",
      "continent":"AS",
      "capital":"Bishkek",
      "languages":"ky,ru",
      "iso":"KGZ"
   },
   "KH":{  
      "name":"Cambodia",
      "native":"K\u00e2mp\u016dch\u00e9a",
      "phone":"855",
      "continent":"AS",
      "capital":"Phnom Penh",
      "currency":{  
         "symbol":"KHR",
         "name":"Cambodian Riel",
         "symbol_native":"\u17db",
         "decimal_digits":2,
         "rounding":0,
         "code":"KHR",
         "name_plural":"Cambodian riels"
      },
      "languages":"km",
      "iso":"KHM"
   },
   "KI":{  
      "name":"Kiribati",
      "native":"Kiribati",
      "phone":"686",
      "continent":"OC",
      "capital":"South Tarawa",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"KIR"
   },
   "KM":{  
      "name":"Comoros",
      "native":"Komori",
      "phone":"269",
      "continent":"AF",
      "capital":"Moroni",
      "currency":{  
         "symbol":"CF",
         "name":"Comorian Franc",
         "symbol_native":"FC",
         "decimal_digits":0,
         "rounding":0,
         "code":"KMF",
         "name_plural":"Comorian francs"
      },
      "languages":"ar,fr",
      "iso":"COM"
   },
   "KN":{  
      "name":"Saint Kitts and Nevis",
      "native":"Saint Kitts and Nevis",
      "phone":"1869",
      "continent":"NA",
      "capital":"Basseterre",
      "languages":"en",
      "iso":"KNA"
   },
   "KP":{  
      "name":"North Korea",
      "native":"\ubd81\ud55c",
      "phone":"850",
      "continent":"AS",
      "capital":"Pyongyang",
      "languages":"ko",
      "iso":"PRK"
   },
   "KR":{  
      "name":"South Korea",
      "native":"\ub300\ud55c\ubbfc\uad6d",
      "phone":"82",
      "continent":"AS",
      "capital":"Seoul",
      "currency":{  
         "symbol":"\u20a9",
         "name":"South Korean Won",
         "symbol_native":"\u20a9",
         "decimal_digits":0,
         "rounding":0,
         "code":"KRW",
         "name_plural":"South Korean won"
      },
      "languages":"ko",
      "iso":"KOR"
   },
   "KW":{  
      "name":"Kuwait",
      "native":"\u0627\u0644\u0643\u0648\u064a\u062a",
      "phone":"965",
      "continent":"AS",
      "capital":"Kuwait City",
      "currency":{  
         "symbol":"KD",
         "name":"Kuwaiti Dinar",
         "symbol_native":"\u062f.\u0643.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"KWD",
         "name_plural":"Kuwaiti dinars"
      },
      "languages":"ar",
      "iso":"KWT"
   },
   "KY":{  
      "name":"Cayman Islands",
      "native":"Cayman Islands",
      "phone":"1345",
      "continent":"NA",
      "capital":"George Town",
      "languages":"en",
      "iso":"CYM"
   },
   "KZ":{  
      "name":"Kazakhstan",
      "native":"\u049a\u0430\u0437\u0430\u049b\u0441\u0442\u0430\u043d",
      "phone":"76,77",
      "continent":"AS",
      "capital":"Astana",
      "currency":{  
         "symbol":"KZT",
         "name":"Kazakhstani Tenge",
         "symbol_native":"\u0442\u04a3\u0433.",
         "decimal_digits":2,
         "rounding":0,
         "code":"KZT",
         "name_plural":"Kazakhstani tenges"
      },
      "languages":"kk,ru",
      "iso":"KAZ"
   },
   "LA":{  
      "name":"Laos",
      "native":"\u0eaa\u0e9b\u0e9b\u0ea5\u0eb2\u0ea7",
      "phone":"856",
      "continent":"AS",
      "capital":"Vientiane",
      "languages":"lo",
      "iso":"LAO"
   },
   "LB":{  
      "name":"Lebanon",
      "native":"\u0644\u0628\u0646\u0627\u0646",
      "phone":"961",
      "continent":"AS",
      "capital":"Beirut",
      "currency":{  
         "symbol":"LB\u00a3",
         "name":"Lebanese Pound",
         "symbol_native":"\u0644.\u0644.\u200f",
         "decimal_digits":0,
         "rounding":0,
         "code":"LBP",
         "name_plural":"Lebanese pounds"
      },
      "languages":"ar,fr",
      "iso":"LBN"
   },
   "LC":{  
      "name":"Saint Lucia",
      "native":"Saint Lucia",
      "phone":"1758",
      "continent":"NA",
      "capital":"Castries",
      "languages":"en",
      "iso":"LCA"
   },
   "LI":{  
      "name":"Liechtenstein",
      "native":"Liechtenstein",
      "phone":"423",
      "continent":"EU",
      "capital":"Vaduz",
      "currency":{  
         "symbol":"CHF",
         "name":"Swiss Franc",
         "symbol_native":"CHF",
         "decimal_digits":2,
         "rounding":0.05,
         "code":"CHF",
         "name_plural":"Swiss francs"
      },
      "languages":"de",
      "iso":"LIE"
   },
   "LK":{  
      "name":"Sri Lanka",
      "native":"\u015br\u012b la\u1e43k\u0101va",
      "phone":"94",
      "continent":"AS",
      "capital":"Colombo",
      "currency":{  
         "symbol":"SLRs",
         "name":"Sri Lankan Rupee",
         "symbol_native":"SL Re",
         "decimal_digits":2,
         "rounding":0,
         "code":"LKR",
         "name_plural":"Sri Lankan rupees"
      },
      "languages":"si,ta",
      "iso":"LKA"
   },
   "LR":{  
      "name":"Liberia",
      "native":"Liberia",
      "phone":"231",
      "continent":"AF",
      "capital":"Monrovia",
      "languages":"en",
      "iso":"LBR"
   },
   "LS":{  
      "name":"Lesotho",
      "native":"Lesotho",
      "phone":"266",
      "continent":"AF",
      "capital":"Maseru",
      "languages":"en,st",
      "iso":"LSO"
   },
   "LT":{  
      "name":"Lithuania",
      "native":"Lietuva",
      "phone":"370",
      "continent":"EU",
      "capital":"Vilnius",
      "currency":{  
         "symbol":"Lt",
         "name":"Lithuanian Litas",
         "symbol_native":"Lt",
         "decimal_digits":2,
         "rounding":0,
         "code":"LTL",
         "name_plural":"Lithuanian litai"
      },
      "languages":"lt",
      "iso":"LTU"
   },
   "LU":{  
      "name":"Luxembourg",
      "native":"Luxembourg",
      "phone":"352",
      "continent":"EU",
      "capital":"Luxembourg",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr,de,lb",
      "iso":"LUX"
   },
   "LV":{  
      "name":"Latvia",
      "native":"Latvija",
      "phone":"371",
      "continent":"EU",
      "capital":"Riga",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"lv",
      "iso":"LVA"
   },
   "LY":{  
      "name":"Libya",
      "native":"\u200f\u0644\u064a\u0628\u064a\u0627",
      "phone":"218",
      "continent":"AF",
      "capital":"Tripoli",
      "currency":{  
         "symbol":"LD",
         "name":"Libyan Dinar",
         "symbol_native":"\u062f.\u0644.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"LYD",
         "name_plural":"Libyan dinars"
      },
      "languages":"ar",
      "iso":"LBY"
   },
   "MA":{  
      "name":"Morocco",
      "native":"\u0627\u0644\u0645\u063a\u0631\u0628",
      "phone":"212",
      "continent":"AF",
      "capital":"Rabat",
      "currency":{  
         "symbol":"MAD",
         "name":"Moroccan Dirham",
         "symbol_native":"\u062f.\u0645.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"MAD",
         "name_plural":"Moroccan dirhams"
      },
      "languages":"ar",
      "iso":"MAR"
   },
   "MC":{  
      "name":"Monaco",
      "native":"Monaco",
      "phone":"377",
      "continent":"EU",
      "capital":"Monaco",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"MCO"
   },
   "MD":{  
      "name":"Moldova",
      "native":"Moldova",
      "phone":"373",
      "continent":"EU",
      "capital":"Chi\u0219in\u0103u",
      "currency":{  
         "symbol":"MDL",
         "name":"Moldovan Leu",
         "symbol_native":"MDL",
         "decimal_digits":2,
         "rounding":0,
         "code":"MDL",
         "name_plural":"Moldovan lei"
      },
      "languages":"ro",
      "iso":"MDA"
   },
   "ME":{  
      "name":"Montenegro",
      "native":"\u0426\u0440\u043d\u0430 \u0413\u043e\u0440\u0430",
      "phone":"382",
      "continent":"EU",
      "capital":"Podgorica",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"sr,bs,sq,hr",
      "iso":"MNE"
   },
   "MF":{  
      "name":"Saint Martin",
      "native":"Saint-Martin",
      "phone":"590",
      "continent":"NA",
      "capital":"Marigot",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"en,fr,nl",
      "iso":"MAF"
   },
   "MG":{  
      "name":"Madagascar",
      "native":"Madagasikara",
      "phone":"261",
      "continent":"AF",
      "capital":"Antananarivo",
      "currency":{  
         "symbol":"MGA",
         "name":"Malagasy Ariary",
         "symbol_native":"MGA",
         "decimal_digits":0,
         "rounding":0,
         "code":"MGA",
         "name_plural":"Malagasy Ariaries"
      },
      "languages":"fr,mg",
      "iso":"MDG"
   },
   "MH":{  
      "name":"Marshall Islands",
      "native":"M\u0327aje\u013c",
      "phone":"692",
      "continent":"OC",
      "capital":"Majuro",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en,mh",
      "iso":"MHL"
   },
   "MK":{  
      "name":"Macedonia",
      "native":"\u041c\u0430\u043a\u0435\u0434\u043e\u043d\u0438\u0458\u0430",
      "phone":"389",
      "continent":"EU",
      "capital":"Skopje",
      "currency":{  
         "symbol":"MKD",
         "name":"Macedonian Denar",
         "symbol_native":"MKD",
         "decimal_digits":2,
         "rounding":0,
         "code":"MKD",
         "name_plural":"Macedonian denari"
      },
      "languages":"mk",
      "iso":"MKD"
   },
   "ML":{  
      "name":"Mali",
      "native":"Mali",
      "phone":"223",
      "continent":"AF",
      "capital":"Bamako",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"MLI"
   },
   "MM":{  
      "name":"Myanmar [Burma]",
      "native":"Myanma",
      "phone":"95",
      "continent":"AS",
      "capital":"Naypyidaw",
      "currency":{  
         "symbol":"MMK",
         "name":"Myanma Kyat",
         "symbol_native":"K",
         "decimal_digits":0,
         "rounding":0,
         "code":"MMK",
         "name_plural":"Myanma kyats"
      },
      "languages":"my",
      "iso":"MMR"
   },
   "MN":{  
      "name":"Mongolia",
      "native":"\u041c\u043e\u043d\u0433\u043e\u043b \u0443\u043b\u0441",
      "phone":"976",
      "continent":"AS",
      "capital":"Ulan Bator",
      "languages":"mn",
      "iso":"MNG"
   },
   "MO":{  
      "name":"Macao",
      "native":"\u6fb3\u9580",
      "phone":"853",
      "continent":"AS",
      "capital":"",
      "currency":{  
         "symbol":"MOP$",
         "name":"Macanese Pataca",
         "symbol_native":"MOP$",
         "decimal_digits":2,
         "rounding":0,
         "code":"MOP",
         "name_plural":"Macanese patacas"
      },
      "languages":"zh,pt",
      "iso":"MAC"
   },
   "MP":{  
      "name":"Northern Mariana Islands",
      "native":"Northern Mariana Islands",
      "phone":"1670",
      "continent":"OC",
      "capital":"Saipan",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en,ch",
      "iso":"MNP"
   },
   "MQ":{  
      "name":"Martinique",
      "native":"Martinique",
      "phone":"596",
      "continent":"NA",
      "capital":"Fort-de-France",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"MTQ"
   },
   "MR":{  
      "name":"Mauritania",
      "native":"\u0645\u0648\u0631\u064a\u062a\u0627\u0646\u064a\u0627",
      "phone":"222",
      "continent":"AF",
      "capital":"Nouakchott",
      "languages":"ar",
      "iso":"MRT"
   },
   "MS":{  
      "name":"Montserrat",
      "native":"Montserrat",
      "phone":"1664",
      "continent":"NA",
      "capital":"Plymouth",
      "languages":"en",
      "iso":"MSR"
   },
   "MT":{  
      "name":"Malta",
      "native":"Malta",
      "phone":"356",
      "continent":"EU",
      "capital":"Valletta",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"mt,en",
      "iso":"MLT"
   },
   "MU":{  
      "name":"Mauritius",
      "native":"Maurice",
      "phone":"230",
      "continent":"AF",
      "capital":"Port Louis",
      "currency":{  
         "symbol":"MURs",
         "name":"Mauritian Rupee",
         "symbol_native":"MURs",
         "decimal_digits":0,
         "rounding":0,
         "code":"MUR",
         "name_plural":"Mauritian rupees"
      },
      "languages":"en",
      "iso":"MUS"
   },
   "MV":{  
      "name":"Maldives",
      "native":"Maldives",
      "phone":"960",
      "continent":"AS",
      "capital":"Mal\u00e9",
      "languages":"dv",
      "iso":"MDV"
   },
   "MW":{  
      "name":"Malawi",
      "native":"Malawi",
      "phone":"265",
      "continent":"AF",
      "capital":"Lilongwe",
      "languages":"en,ny",
      "iso":"MWI"
   },
   "MY":{  
      "name":"Malaysia",
      "native":"Malaysia",
      "phone":"60",
      "continent":"AS",
      "capital":"Kuala Lumpur",
      "currency":{  
         "symbol":"RM",
         "name":"Malaysian Ringgit",
         "symbol_native":"RM",
         "decimal_digits":2,
         "rounding":0,
         "code":"MYR",
         "name_plural":"Malaysian ringgits"
      },
      "languages":"",
      "iso":"MYS"
   },
   "MZ":{  
      "name":"Mozambique",
      "native":"Mo\u00e7ambique",
      "phone":"258",
      "continent":"AF",
      "capital":"Maputo",
      "currency":{  
         "symbol":"MTn",
         "name":"Mozambican Metical",
         "symbol_native":"MTn",
         "decimal_digits":2,
         "rounding":0,
         "code":"MZN",
         "name_plural":"Mozambican meticals"
      },
      "languages":"pt",
      "iso":"MOZ"
   },
   "NA":{  
      "name":"Namibia",
      "native":"Namibia",
      "phone":"264",
      "continent":"AF",
      "capital":"Windhoek",
      "languages":"en,af",
      "iso":"NAM"
   },
   "NC":{  
      "name":"New Caledonia",
      "native":"Nouvelle-Cal\u00e9donie",
      "phone":"687",
      "continent":"OC",
      "capital":"Noum\u00e9a",
      "languages":"fr",
      "iso":"NCL"
   },
   "NE":{  
      "name":"Niger",
      "native":"Niger",
      "phone":"227",
      "continent":"AF",
      "capital":"Niamey",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"NER"
   },
   "NF":{  
      "name":"Norfolk Island",
      "native":"Norfolk Island",
      "phone":"672",
      "continent":"OC",
      "capital":"Kingston",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"NFK"
   },
   "NG":{  
      "name":"Nigeria",
      "native":"Nigeria",
      "phone":"234",
      "continent":"AF",
      "capital":"Abuja",
      "currency":{  
         "symbol":"\u20a6",
         "name":"Nigerian Naira",
         "symbol_native":"\u20a6",
         "decimal_digits":2,
         "rounding":0,
         "code":"NGN",
         "name_plural":"Nigerian nairas"
      },
      "languages":"en",
      "iso":"NGA"
   },
   "NL":{  
      "name":"Netherlands",
      "native":"Nederland",
      "phone":"31",
      "continent":"EU",
      "capital":"Amsterdam",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"nl",
      "iso":"NLD"
   },
   "NO":{  
      "name":"Norway",
      "native":"Norge",
      "phone":"47",
      "continent":"EU",
      "capital":"Oslo",
      "currency":{  
         "symbol":"Nkr",
         "name":"Norwegian Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"NOK",
         "name_plural":"Norwegian kroner"
      },
      "languages":"no,nb,nn",
      "iso":"NOR"
   },
   "NP":{  
      "name":"Nepal",
      "native":"\u0928\u092a\u0932",
      "phone":"977",
      "continent":"AS",
      "capital":"Kathmandu",
      "currency":{  
         "symbol":"NPRs",
         "name":"Nepalese Rupee",
         "symbol_native":"\u0928\u0947\u0930\u0942",
         "decimal_digits":2,
         "rounding":0,
         "code":"NPR",
         "name_plural":"Nepalese rupees"
      },
      "languages":"ne",
      "iso":"NPL"
   },
   "NR":{  
      "name":"Nauru",
      "native":"Nauru",
      "phone":"674",
      "continent":"OC",
      "capital":"Yaren",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en,na",
      "iso":"NRU"
   },
   "NU":{  
      "name":"Niue",
      "native":"Niu\u0113",
      "phone":"683",
      "continent":"OC",
      "capital":"Alofi",
      "currency":{  
         "symbol":"NZ$",
         "name":"New Zealand Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NZD",
         "name_plural":"New Zealand dollars"
      },
      "languages":"en",
      "iso":"NIU"
   },
   "NZ":{  
      "name":"New Zealand",
      "native":"New Zealand",
      "phone":"64",
      "continent":"OC",
      "capital":"Wellington",
      "currency":{  
         "symbol":"NZ$",
         "name":"New Zealand Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NZD",
         "name_plural":"New Zealand dollars"
      },
      "languages":"en,mi",
      "iso":"NZL"
   },
   "OM":{  
      "name":"Oman",
      "native":"\u0639\u0645\u0627\u0646",
      "phone":"968",
      "continent":"AS",
      "capital":"Muscat",
      "currency":{  
         "symbol":"OMR",
         "name":"Omani Rial",
         "symbol_native":"\u0631.\u0639.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"OMR",
         "name_plural":"Omani rials"
      },
      "languages":"ar",
      "iso":"OMN"
   },
   "PF":{  
      "name":"French Polynesia",
      "native":"Polyn\u00e9sie fran\u00e7aise",
      "phone":"689",
      "continent":"OC",
      "capital":"Papeet\u0113",
      "languages":"fr",
      "iso":"PYF"
   },
   "PG":{  
      "name":"Papua New Guinea",
      "native":"Papua Niugini",
      "phone":"675",
      "continent":"OC",
      "capital":"Port Moresby",
      "languages":"en",
      "iso":"PNG"
   },
   "PH":{  
      "name":"Philippines",
      "native":"Pilipinas",
      "phone":"63",
      "continent":"AS",
      "capital":"Manila",
      "currency":{  
         "symbol":"\u20b1",
         "name":"Philippine Peso",
         "symbol_native":"\u20b1",
         "decimal_digits":2,
         "rounding":0,
         "code":"PHP",
         "name_plural":"Philippine pesos"
      },
      "languages":"en",
      "iso":"PHL"
   },
   "PK":{  
      "name":"Pakistan",
      "native":"Pakistan",
      "phone":"92",
      "continent":"AS",
      "capital":"Islamabad",
      "currency":{  
         "symbol":"PKRs",
         "name":"Pakistani Rupee",
         "symbol_native":"\u20a8",
         "decimal_digits":0,
         "rounding":0,
         "code":"PKR",
         "name_plural":"Pakistani rupees"
      },
      "languages":"en,ur",
      "iso":"PAK"
   },
   "PL":{  
      "name":"Poland",
      "native":"Polska",
      "phone":"48",
      "continent":"EU",
      "capital":"Warsaw",
      "currency":{  
         "symbol":"z\u0142",
         "name":"Polish Zloty",
         "symbol_native":"z\u0142",
         "decimal_digits":2,
         "rounding":0,
         "code":"PLN",
         "name_plural":"Polish zlotys"
      },
      "languages":"pl",
      "iso":"POL"
   },
   "PM":{  
      "name":"Saint Pierre and Miquelon",
      "native":"Saint-Pierre-et-Miquelon",
      "phone":"508",
      "continent":"NA",
      "capital":"Saint-Pierre",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"SPM"
   },
   "PN":{  
      "name":"Pitcairn Islands",
      "native":"Pitcairn Islands",
      "phone":"64",
      "continent":"OC",
      "capital":"Adamstown",
      "currency":{  
         "symbol":"NZ$",
         "name":"New Zealand Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NZD",
         "name_plural":"New Zealand dollars"
      },
      "languages":"en",
      "iso":"PCN"
   },
   "PS":{  
      "name":"Palestine",
      "native":"\u0641\u0644\u0633\u0637\u064a\u0646",
      "phone":"970",
      "continent":"AS",
      "capital":"Ramallah",
      "currency":{  
         "symbol":"\u20aa",
         "name":"Israeli New Sheqel",
         "symbol_native":"\u20aa",
         "decimal_digits":2,
         "rounding":0,
         "code":"ILS",
         "name_plural":"Israeli new sheqels"
      },
      "languages":"ar",
      "iso":"PSE"
   },
   "PT":{  
      "name":"Portugal",
      "native":"Portugal",
      "phone":"351",
      "continent":"EU",
      "capital":"Lisbon",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"pt",
      "iso":"PRT"
   },
   "PW":{  
      "name":"Palau",
      "native":"Palau",
      "phone":"680",
      "continent":"OC",
      "capital":"Ngerulmud",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"PLW"
   },
   "QA":{  
      "name":"Qatar",
      "native":"\u0642\u0637\u0631",
      "phone":"974",
      "continent":"AS",
      "capital":"Doha",
      "currency":{  
         "symbol":"QR",
         "name":"Qatari Rial",
         "symbol_native":"\u0631.\u0642.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"QAR",
         "name_plural":"Qatari rials"
      },
      "languages":"ar",
      "iso":"QAT"
   },
   "RE":{  
      "name":"R\u00e9union",
      "native":"La R\u00e9union",
      "phone":"262",
      "continent":"AF",
      "capital":"Saint-Denis",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"REU"
   },
   "RO":{  
      "name":"Romania",
      "native":"Rom\u00e2nia",
      "phone":"40",
      "continent":"EU",
      "capital":"Bucharest",
      "currency":{  
         "symbol":"RON",
         "name":"Romanian Leu",
         "symbol_native":"RON",
         "decimal_digits":2,
         "rounding":0,
         "code":"RON",
         "name_plural":"Romanian lei"
      },
      "languages":"ro",
      "iso":"ROU"
   },
   "RS":{  
      "name":"Serbia",
      "native":"\u0421\u0440\u0431\u0438\u0458\u0430",
      "phone":"381",
      "continent":"EU",
      "capital":"Belgrade",
      "currency":{  
         "symbol":"din.",
         "name":"Serbian Dinar",
         "symbol_native":"\u0434\u0438\u043d.",
         "decimal_digits":0,
         "rounding":0,
         "code":"RSD",
         "name_plural":"Serbian dinars"
      },
      "languages":"sr",
      "iso":"SRB"
   },
   "RU":{  
      "name":"Russia",
      "native":"\u0420\u043e\u0441\u0441\u0438\u044f",
      "phone":"7",
      "continent":"EU",
      "capital":"Moscow",
      "currency":{  
         "symbol":"RUB",
         "name":"Russian Ruble",
         "symbol_native":"\u0440\u0443\u0431.",
         "decimal_digits":2,
         "rounding":0,
         "code":"RUB",
         "name_plural":"Russian rubles"
      },
      "languages":"ru",
      "iso":"RUS"
   },
   "RW":{  
      "name":"Rwanda",
      "native":"Rwanda",
      "phone":"250",
      "continent":"AF",
      "capital":"Kigali",
      "currency":{  
         "symbol":"RWF",
         "name":"Rwandan Franc",
         "symbol_native":"FR",
         "decimal_digits":0,
         "rounding":0,
         "code":"RWF",
         "name_plural":"Rwandan francs"
      },
      "languages":"rw,en,fr",
      "iso":"RWA"
   },
   "SA":{  
      "name":"Saudi Arabia",
      "native":"\u0627\u0644\u0639\u0631\u0628\u064a\u0629 \u0627\u0644\u0633\u0639\u0648\u062f\u064a\u0629",
      "phone":"966",
      "continent":"AS",
      "capital":"Riyadh",
      "currency":{  
         "symbol":"SR",
         "name":"Saudi Riyal",
         "symbol_native":"\u0631.\u0633.\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"SAR",
         "name_plural":"Saudi riyals"
      },
      "languages":"ar",
      "iso":"SAU"
   },
   "SB":{  
      "name":"Solomon Islands",
      "native":"Solomon Islands",
      "phone":"677",
      "continent":"OC",
      "capital":"Honiara",
      "languages":"en",
      "iso":"SLB"
   },
   "SC":{  
      "name":"Seychelles",
      "native":"Seychelles",
      "phone":"248",
      "continent":"AF",
      "capital":"Victoria",
      "languages":"fr,en",
      "iso":"SYC"
   },
   "SD":{  
      "name":"Sudan",
      "native":"\u0627\u0644\u0633\u0648\u062f\u0627\u0646",
      "phone":"249",
      "continent":"AF",
      "capital":"Khartoum",
      "currency":{  
         "symbol":"SDG",
         "name":"Sudanese Pound",
         "symbol_native":"SDG",
         "decimal_digits":2,
         "rounding":0,
         "code":"SDG",
         "name_plural":"Sudanese pounds"
      },
      "languages":"ar,en",
      "iso":"SDN"
   },
   "SE":{  
      "name":"Sweden",
      "native":"Sverige",
      "phone":"46",
      "continent":"EU",
      "capital":"Stockholm",
      "currency":{  
         "symbol":"Skr",
         "name":"Swedish Krona",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"SEK",
         "name_plural":"Swedish kronor"
      },
      "languages":"sv",
      "iso":"SWE"
   },
   "SG":{  
      "name":"Singapore",
      "native":"Singapore",
      "phone":"65",
      "continent":"AS",
      "capital":"Singapore",
      "currency":{  
         "symbol":"S$",
         "name":"Singapore Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"SGD",
         "name_plural":"Singapore dollars"
      },
      "languages":"en,ms,ta,zh",
      "iso":"SGP"
   },
   "SH":{  
      "name":"Saint Helena",
      "native":"Saint Helena",
      "phone":"290",
      "continent":"AF",
      "capital":"Jamestown",
      "languages":"en",
      "iso":"SHN"
   },
   "SI":{  
      "name":"Slovenia",
      "native":"Slovenija",
      "phone":"386",
      "continent":"EU",
      "capital":"Ljubljana",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"sl",
      "iso":"SVN"
   },
   "SJ":{  
      "name":"Svalbard and Jan Mayen",
      "native":"Svalbard og Jan Mayen",
      "phone":"4779",
      "continent":"EU",
      "capital":"Longyearbyen",
      "currency":{  
         "symbol":"Nkr",
         "name":"Norwegian Krone",
         "symbol_native":"kr",
         "decimal_digits":2,
         "rounding":0,
         "code":"NOK",
         "name_plural":"Norwegian kroner"
      },
      "languages":"no",
      "iso":"SJM"
   },
   "SK":{  
      "name":"Slovakia",
      "native":"Slovensko",
      "phone":"421",
      "continent":"EU",
      "capital":"Bratislava",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"sk",
      "iso":"SVK"
   },
   "SL":{  
      "name":"Sierra Leone",
      "native":"Sierra Leone",
      "phone":"232",
      "continent":"AF",
      "capital":"Freetown",
      "languages":"en",
      "iso":"SLE"
   },
   "SM":{  
      "name":"San Marino",
      "native":"San Marino",
      "phone":"378",
      "continent":"EU",
      "capital":"City of San Marino",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"it",
      "iso":"SMR"
   },
   "SN":{  
      "name":"Senegal",
      "native":"S\u00e9n\u00e9gal",
      "phone":"221",
      "continent":"AF",
      "capital":"Dakar",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"SEN"
   },
   "SO":{  
      "name":"Somalia",
      "native":"Soomaaliya",
      "phone":"252",
      "continent":"AF",
      "capital":"Mogadishu",
      "currency":{  
         "symbol":"Ssh",
         "name":"Somali Shilling",
         "symbol_native":"Ssh",
         "decimal_digits":0,
         "rounding":0,
         "code":"SOS",
         "name_plural":"Somali shillings"
      },
      "languages":"so,ar",
      "iso":"SOM"
   },
   "SR":{  
      "name":"Suriname",
      "native":"Suriname",
      "phone":"597",
      "continent":"SA",
      "capital":"Paramaribo",
      "languages":"nl",
      "iso":"SUR"
   },
   "SS":{  
      "name":"South Sudan",
      "native":"South Sudan",
      "phone":"211",
      "continent":"AF",
      "capital":"Juba",
      "languages":"en",
      "iso":"SSD"
   },
   "ST":{  
      "name":"S\u00e3o Tom\u00e9 and Pr\u00edncipe",
      "native":"S\u00e3o Tom\u00e9 e Pr\u00edncipe",
      "phone":"239",
      "continent":"AF",
      "capital":"S\u00e3o Tom\u00e9",
      "languages":"pt",
      "iso":"STP"
   },
   "SX":{  
      "name":"Sint Maarten",
      "native":"Sint Maarten",
      "phone":"1721",
      "continent":"NA",
      "capital":"Philipsburg",
      "languages":"nl,en",
      "iso":"SXM"
   },
   "SY":{  
      "name":"Syria",
      "native":"\u0633\u0648\u0631\u064a\u0627",
      "phone":"963",
      "continent":"AS",
      "capital":"Damascus",
      "currency":{  
         "symbol":"SY\u00a3",
         "name":"Syrian Pound",
         "symbol_native":"\u0644.\u0633.\u200f",
         "decimal_digits":0,
         "rounding":0,
         "code":"SYP",
         "name_plural":"Syrian pounds"
      },
      "languages":"ar",
      "iso":"SYR"
   },
   "SZ":{  
      "name":"Swaziland",
      "native":"Swaziland",
      "phone":"268",
      "continent":"AF",
      "capital":"Lobamba",
      "languages":"en,ss",
      "iso":"SWZ"
   },
   "TC":{  
      "name":"Turks and Caicos Islands",
      "native":"Turks and Caicos Islands",
      "phone":"1649",
      "continent":"NA",
      "capital":"Cockburn Town",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"TCA"
   },
   "TD":{  
      "name":"Chad",
      "native":"Tchad",
      "phone":"235",
      "continent":"AF",
      "capital":"N\'Djamena",
      "currency":{  
         "symbol":"FCFA",
         "name":"CFA Franc BEAC",
         "symbol_native":"FCFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XAF",
         "name_plural":"CFA francs BEAC"
      },
      "languages":"fr,ar",
      "iso":"TCD"
   },
   "TF":{  
      "name":"French Southern Territories",
      "native":"Territoire des Terres australes et antarctiques fr",
      "phone":"",
      "continent":"AN",
      "capital":"Port-aux-Fran\u00e7ais",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"ATF"
   },
   "TG":{  
      "name":"Togo",
      "native":"Togo",
      "phone":"228",
      "continent":"AF",
      "capital":"Lom\u00e9",
      "currency":{  
         "symbol":"CFA",
         "name":"CFA Franc BCEAO",
         "symbol_native":"CFA",
         "decimal_digits":0,
         "rounding":0,
         "code":"XOF",
         "name_plural":"CFA francs BCEAO"
      },
      "languages":"fr",
      "iso":"TGO"
   },
   "TH":{  
      "name":"Thailand",
      "native":"\u0e1b\u0e23\u0e30\u0e40\u0e17\u0e28\u0e44\u0e17\u0e22",
      "phone":"66",
      "continent":"AS",
      "capital":"Bangkok",
      "currency":{  
         "symbol":"\u0e3f",
         "name":"Thai Baht",
         "symbol_native":"\u0e3f",
         "decimal_digits":2,
         "rounding":0,
         "code":"THB",
         "name_plural":"Thai baht"
      },
      "languages":"th",
      "iso":"THA"
   },
   "TJ":{  
      "name":"Tajikistan",
      "native":"\u0422\u043e\u04b7\u0438\u043a\u0438\u0441\u0442\u043e\u043d",
      "phone":"992",
      "continent":"AS",
      "capital":"Dushanbe",
      "languages":"tg,ru",
      "iso":"TJK"
   },
   "TK":{  
      "name":"Tokelau",
      "native":"Tokelau",
      "phone":"690",
      "continent":"OC",
      "capital":"Fakaofo",
      "currency":{  
         "symbol":"NZ$",
         "name":"New Zealand Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NZD",
         "name_plural":"New Zealand dollars"
      },
      "languages":"en",
      "iso":"TKL"
   },
   "TL":{  
      "name":"East Timor",
      "native":"Timor-Leste",
      "phone":"670",
      "continent":"OC",
      "capital":"Dili",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"pt",
      "iso":"TLS"
   },
   "TM":{  
      "name":"Turkmenistan",
      "native":"T\u00fcrkmenistan",
      "phone":"993",
      "continent":"AS",
      "capital":"Ashgabat",
      "languages":"tk,ru",
      "iso":"TKM"
   },
   "TN":{  
      "name":"Tunisia",
      "native":"\u062a\u0648\u0646\u0633",
      "phone":"216",
      "continent":"AF",
      "capital":"Tunis",
      "currency":{  
         "symbol":"DT",
         "name":"Tunisian Dinar",
         "symbol_native":"\u062f.\u062a.\u200f",
         "decimal_digits":3,
         "rounding":0,
         "code":"TND",
         "name_plural":"Tunisian dinars"
      },
      "languages":"ar",
      "iso":"TUN"
   },
   "TO":{  
      "name":"Tonga",
      "native":"Tonga",
      "phone":"676",
      "continent":"OC",
      "capital":"Nuku\'alofa",
      "currency":{  
         "symbol":"T$",
         "name":"Tongan Pa\u02bbanga",
         "symbol_native":"T$",
         "decimal_digits":2,
         "rounding":0,
         "code":"TOP",
         "name_plural":"Tongan pa\u02bbanga"
      },
      "languages":"en,to",
      "iso":"TON"
   },
   "TR":{  
      "name":"Turkey",
      "native":"T\u00fcrkiye",
      "phone":"90",
      "continent":"AS",
      "capital":"Ankara",
      "currency":{  
         "symbol":"TL",
         "name":"Turkish Lira",
         "symbol_native":"TL",
         "decimal_digits":2,
         "rounding":0,
         "code":"TRY",
         "name_plural":"Turkish Lira"
      },
      "languages":"tr",
      "iso":"TUR"
   },
   "TT":{  
      "name":"Trinidad and Tobago",
      "native":"Trinidad and Tobago",
      "phone":"1868",
      "continent":"NA",
      "capital":"Port of Spain",
      "currency":{  
         "symbol":"TT$",
         "name":"Trinidad and Tobago Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"TTD",
         "name_plural":"Trinidad and Tobago dollars"
      },
      "languages":"en",
      "iso":"TTO"
   },
   "TV":{  
      "name":"Tuvalu",
      "native":"Tuvalu",
      "phone":"688",
      "continent":"OC",
      "capital":"Funafuti",
      "currency":{  
         "symbol":"AU$",
         "name":"Australian Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"AUD",
         "name_plural":"Australian dollars"
      },
      "languages":"en",
      "iso":"TUV"
   },
   "TW":{  
      "name":"Taiwan",
      "native":"\u81fa\u7063",
      "phone":"886",
      "continent":"AS",
      "capital":"Taipei",
      "currency":{  
         "symbol":"NT$",
         "name":"New Taiwan Dollar",
         "symbol_native":"NT$",
         "decimal_digits":2,
         "rounding":0,
         "code":"TWD",
         "name_plural":"New Taiwan dollars"
      },
      "languages":"zh",
      "iso":"TWN"
   },
   "TZ":{  
      "name":"Tanzania",
      "native":"Tanzania",
      "phone":"255",
      "continent":"AF",
      "capital":"Dodoma",
      "currency":{  
         "symbol":"TSh",
         "name":"Tanzanian Shilling",
         "symbol_native":"TSh",
         "decimal_digits":0,
         "rounding":0,
         "code":"TZS",
         "name_plural":"Tanzanian shillings"
      },
      "languages":"sw,en",
      "iso":"TZA"
   },
   "UA":{  
      "name":"Ukraine",
      "native":"\u0423\u043a\u0440\u0430\u0457\u043d\u0430",
      "phone":"380",
      "continent":"EU",
      "capital":"Kiev",
      "currency":{  
         "symbol":"\u20b4",
         "name":"Ukrainian Hryvnia",
         "symbol_native":"\u20b4",
         "decimal_digits":2,
         "rounding":0,
         "code":"UAH",
         "name_plural":"Ukrainian hryvnias"
      },
      "languages":"uk",
      "iso":"UKR"
   },
   "UG":{  
      "name":"Uganda",
      "native":"Uganda",
      "phone":"256",
      "continent":"AF",
      "capital":"Kampala",
      "currency":{  
         "symbol":"USh",
         "name":"Ugandan Shilling",
         "symbol_native":"USh",
         "decimal_digits":0,
         "rounding":0,
         "code":"UGX",
         "name_plural":"Ugandan shillings"
      },
      "languages":"en,sw",
      "iso":"UGA"
   },
   "UM":{  
      "name":"U.S. Minor Outlying Islands",
      "native":"United States Minor Outlying Islands",
      "phone":"",
      "continent":"OC",
      "capital":"",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"UMI"
   },
   "UZ":{  
      "name":"Uzbekistan",
      "native":"O\u2018zbekiston",
      "phone":"998",
      "continent":"AS",
      "capital":"Tashkent",
      "currency":{  
         "symbol":"UZS",
         "name":"Uzbekistan Som",
         "symbol_native":"UZS",
         "decimal_digits":0,
         "rounding":0,
         "code":"UZS",
         "name_plural":"Uzbekistan som"
      },
      "languages":"uz,ru",
      "iso":"UZB"
   },
   "VA":{  
      "name":"Vatican City",
      "native":"Vaticano",
      "phone":"39066,379",
      "continent":"EU",
      "capital":"Vatican City",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"it,la",
      "iso":"VAT"
   },
   "VC":{  
      "name":"Saint Vincent and the Grenadines",
      "native":"Saint Vincent and the Grenadines",
      "phone":"1784",
      "continent":"NA",
      "capital":"Kingstown",
      "languages":"en",
      "iso":"VCT"
   },
   "VG":{  
      "name":"British Virgin Islands",
      "native":"British Virgin Islands",
      "phone":"1284",
      "continent":"NA",
      "capital":"Road Town",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"VGB"
   },
   "VI":{  
      "name":"U.S. Virgin Islands",
      "native":"United States Virgin Islands",
      "phone":"1340",
      "continent":"NA",
      "capital":"Charlotte Amalie",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "languages":"en",
      "iso":"VIR"
   },
   "VN":{  
      "name":"Vietnam",
      "native":"Vi\u1ec7t Nam",
      "phone":"84",
      "continent":"AS",
      "capital":"Hanoi",
      "currency":{  
         "symbol":"\u20ab",
         "name":"Vietnamese Dong",
         "symbol_native":"\u20ab",
         "decimal_digits":0,
         "rounding":0,
         "code":"VND",
         "name_plural":"Vietnamese dong"
      },
      "languages":"vi",
      "iso":"VNM"
   },
   "VU":{  
      "name":"Vanuatu",
      "native":"Vanuatu",
      "phone":"678",
      "continent":"OC",
      "capital":"Port Vila",
      "languages":"bi,en,fr",
      "iso":"VUT"
   },
   "WF":{  
      "name":"Wallis and Futuna",
      "native":"Wallis et Futuna",
      "phone":"681",
      "continent":"OC",
      "capital":"Mata-Utu",
      "languages":"fr",
      "iso":"WLF"
   },
   "WS":{  
      "name":"Samoa",
      "native":"Samoa",
      "phone":"685",
      "continent":"OC",
      "capital":"Apia",
      "languages":"sm,en",
      "iso":"WSM"
   },
   "XK":{  
      "name":"Kosovo",
      "native":"Republika e Kosov\u00ebs",
      "phone":"377,381,386",
      "continent":"EU",
      "capital":"Pristina",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"sq,sr",
      "iso":"XKX"
   },
   "YE":{  
      "name":"Yemen",
      "native":"\u0627\u0644\u064a\u064e\u0645\u064e\u0646",
      "phone":"967",
      "continent":"AS",
      "capital":"Sana\'a",
      "currency":{  
         "symbol":"YR",
         "name":"Yemeni Rial",
         "symbol_native":"\u0631.\u064a.\u200f",
         "decimal_digits":0,
         "rounding":0,
         "code":"YER",
         "name_plural":"Yemeni rials"
      },
      "languages":"ar",
      "iso":"YEM"
   },
   "YT":{  
      "name":"Mayotte",
      "native":"Mayotte",
      "phone":"262",
      "continent":"AF",
      "capital":"Mamoudzou",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros"
      },
      "languages":"fr",
      "iso":"MYT"
   },
   "ZA":{  
      "name":"South Africa",
      "native":"South Africa",
      "phone":"27",
      "continent":"AF",
      "capital":"Pretoria",
      "currency":{  
         "symbol":"R",
         "name":"South African Rand",
         "symbol_native":"R",
         "decimal_digits":2,
         "rounding":0,
         "code":"ZAR",
         "name_plural":"South African rand"
      },
      "languages":"af,en,nr,st,ss,tn,ts,ve,xh,zu",
      "iso":"ZAF"
   },
   "ZM":{  
      "name":"Zambia",
      "native":"Zambia",
      "phone":"260",
      "continent":"AF",
      "capital":"Lusaka",
      "currency":{  
         "symbol":"ZK",
         "name":"Zambian Kwacha",
         "symbol_native":"ZK",
         "decimal_digits":0,
         "rounding":0,
         "code":"ZMK",
         "name_plural":"Zambian kwachas"
      },
      "languages":"en",
      "iso":"ZMB"
   },
   "ZW":{  
      "name":"Zimbabwe",
      "native":"Zimbabwe",
      "phone":"263",
      "continent":"AF",
      "capital":"Harare",
      "languages":"en,sn,nd",
      "iso":"ZWE"
   }
}';

$countriesHispanic = '{  
   "AR":{  
      "name":"Argentina",
      "native":"Argentina",
      "phone":"54",
      "continent":"SA",
      "capital":"Buenos Aires",
      "currency":{  
         "symbol":"AR$",
         "name":"Argentine Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"ARS",
         "name_plural":"Argentine pesos",
         "vat":"21",
         "vat_name":"IVA"
      },
      "tin":"CUIT",
      "languages":"es,gn",
      "iso":"ARG"
   },
   "BO":{  
      "name":"Bolivia",
      "native":"Bolivia",
      "phone":"591",
      "continent":"SA",
      "capital":"Sucre",
      "currency":{  
         "symbol":"Bs",
         "name":"Boliviano",
         "symbol_native":"Bs",
         "decimal_digits":2,
         "rounding":0,
         "code":"BOB",
         "name_plural":"Bolivianos",
         "vat":"13",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es,ay,qu",
      "iso":"BOL"
   },
   "BR":{  
      "name":"Brazil",
      "native":"Brasil",
      "phone":"55",
      "continent":"SA",
      "capital":"Bras\u00edlia",
      "currency":{  
         "symbol":"R$",
         "name":"Brazilian Real",
         "symbol_native":"R$",
         "decimal_digits":2,
         "rounding":0,
         "code":"BRL",
         "name_plural":"Brazilian reals",
         "vat":"17",
         "vat_name":"IPI"
      },
      "tin":"CPF\/CNPJ",
      "languages":"pt",
      "iso":"BRA"
   },
   "CL":{  
      "name":"Chile",
      "native":"Chile",
      "phone":"56",
      "continent":"SA",
      "capital":"Santiago",
      "currency":{  
         "symbol":"$",
         "name":"Chilean Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"CLP",
         "name_plural":"Chilean Pesos",
         "vat":"19",
         "vat_name":"IVA"
      },
      "tin":"RUT",
      "languages":"es",
      "iso":"CHL"
   },
   "CO":{  
      "name":"Colombia",
      "native":"Colombia",
      "phone":"57",
      "continent":"SA",
      "capital":"Bogot\u00e1",
      "currency":{  
         "symbol":"CO$",
         "name":"Colombian Peso",
         "symbol_native":"$",
         "decimal_digits":0,
         "rounding":0,
         "code":"COP",
         "name_plural":"Colombian pesos",
         "vat":"16",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"COL"
   },
   "CR":{  
      "name":"Costa Rica",
      "native":"Costa Rica",
      "phone":"506",
      "continent":"NA",
      "capital":"San Jos\u00e9",
      "currency":{  
         "symbol":"\u20a1",
         "name":"Costa Rican Col\u00f3n",
         "symbol_native":"\u20a1",
         "decimal_digits":0,
         "rounding":0,
         "code":"CRC",
         "name_plural":"Costa Rican col\u00f3ns",
         "vat":"13",
         "vat_name":"IV"
      },
      "tin":"NITE",
      "languages":"es",
      "iso":"CRI"
   },
   "CU":{  
      "name":"Cuba",
      "native":"Cuba",
      "phone":"53",
      "continent":"NA",
      "capital":"Havana",
      "languages":"es",
      "iso":"CUB"
   },
   "EC":{  
      "name":"Ecuador",
      "native":"Ecuador",
      "phone":"593",
      "continent":"SA",
      "capital":"Quito",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RUC",
      "languages":"es",
      "iso":"ECU"
   },
   "SV":{  
      "name":"El Salvador",
      "native":"El Salvador",
      "phone":"503",
      "continent":"NA",
      "capital":"San Salvador",
      "currency":{  
         "symbol":"$",
         "name":"US Doallar",
         "symbol_native":"$\u200f",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US Dollars",
         "vat":"13",
         "vat_name":"IVA"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"SLV"
   },
   "ES":{  
      "name":"Spain",
      "native":"Espa\u00f1a",
      "phone":"34",
      "continent":"EU",
      "capital":"Madrid",
      "currency":{  
         "symbol":"\u20ac",
         "name":"Euro",
         "symbol_native":"\u20ac",
         "decimal_digits":2,
         "rounding":0,
         "code":"EUR",
         "name_plural":"euros",
         "vat":"21",
         "vat_name":"IVA"
      },
      "tin":"NIF\/CIF",
      "languages":"es,eu,ca,gl,oc",
      "iso":"ESP"
   },
   "GT":{  
      "name":"Guatemala",
      "native":"Guatemala",
      "phone":"502",
      "continent":"NA",
      "capital":"Guatemala City",
      "currency":{  
         "symbol":"GTQ",
         "name":"Guatemalan Quetzal",
         "symbol_native":"Q",
         "decimal_digits":2,
         "rounding":0,
         "code":"GTQ",
         "name_plural":"Guatemalan quetzals",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RTU",
      "languages":"es",
      "iso":"GTM"
   },
   "HT":{  
      "name":"Haiti",
      "native":"Ha\u00efti",
      "phone":"509",
      "continent":"NA",
      "capital":"Port-au-Prince",
      "languages":"fr,ht",
      "iso":"HTI"
   },
   "HN":{  
      "name":"Honduras",
      "native":"Honduras",
      "phone":"504",
      "continent":"NA",
      "capital":"Tegucigalpa",
      "currency":{  
         "symbol":"HNL",
         "name":"Honduran Lempira",
         "symbol_native":"L",
         "decimal_digits":2,
         "rounding":0,
         "code":"HNL",
         "name_plural":"Honduran lempiras",
         "vat":"15",
         "vat_name":"ISV"
      },
      "tin":"RTN",
      "languages":"es",
      "iso":"HND"
   },
   "MX":{  
      "name":"Mexico",
      "native":"M\u00e9xico",
      "phone":"52",
      "continent":"NA",
      "capital":"Mexico City",
      "currency":{  
         "symbol":"MX$",
         "name":"Mexican Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"MXN",
         "name_plural":"Mexican pesos",
         "vat":"16",
         "vat_name":"IVA"
      },
      "tin":"RFC",
      "languages":"es",
      "iso":"MEX"
   },
   "NI":{  
      "name":"Nicaragua",
      "native":"Nicaragua",
      "phone":"505",
      "continent":"NA",
      "capital":"Managua",
      "currency":{  
         "symbol":"C$",
         "name":"Nicaraguan C\u00f3rdoba",
         "symbol_native":"C$",
         "decimal_digits":2,
         "rounding":0,
         "code":"NIO",
         "name_plural":"Nicaraguan c\u00f3rdobas",
         "vat":"15",
         "vat_name":"IVA"
      },
      "languages":"es",
      "iso":"NIC"
   },
   "PA":{  
      "name":"Panama",
      "native":"Panam\u00e1",
      "phone":"507",
      "continent":"NA",
      "capital":"Panama City",
      "currency":{  
         "symbol":"\u0e3f",
         "name":"Balboa",
         "symbol_native":"\u0e3f",
         "decimal_digits":2,
         "rounding":0,
         "code":"PAB",
         "name_plural":"Balboas",
         "vat":"7"
      },
      "tin":"NIT",
      "languages":"es",
      "iso":"PAN"
   },
   "PY":{  
      "name":"Paraguay",
      "native":"Paraguay",
      "phone":"595",
      "continent":"SA",
      "capital":"Asunci\u00f3n",
      "currency":{  
         "symbol":"\u20b2",
         "name":"Paraguayan Guarani",
         "symbol_native":"\u20b2",
         "decimal_digits":0,
         "rounding":0,
         "code":"PYG",
         "name_plural":"Paraguayan guaranis",
         "vat":"10",
         "vat_name":"IVA"
      },
      "tin":"RUC",
      "languages":"es,gn",
      "iso":"PRY"
   },
   "PE":{  
      "name":"Peru",
      "native":"Per\u00fa",
      "phone":"51",
      "continent":"SA",
      "capital":"Lima",
      "currency":{  
         "symbol":"S\/.",
         "name":"Peruvian Nuevo Sol",
         "symbol_native":"S\/.",
         "decimal_digits":2,
         "rounding":0,
         "code":"PEN",
         "name_plural":"Peruvian nuevos soles",
         "vat":"18",
         "vat_name":"IGV"
      },
      "tin":"RUC",
      "languages":"es",
      "iso":"PER"
   },
   "PR":{  
      "name":"Puerto Rico",
      "native":"Puerto Rico",
      "phone":"1787",
      "continent":"NA",
      "capital":"San Juan",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars",
         "vat":"11,5"
      },
      "languages":"es,en",
      "iso":"PRI"
   },
   "DO":{  
      "name":"Dominican Republic",
      "native":"Rep\u00fablica Dominicana",
      "phone":"1809",
      "continent":"NA",
      "capital":"Santo Domingo",
      "currency":{  
         "symbol":"RD$",
         "name":"Dominican Peso",
         "symbol_native":"RD$",
         "decimal_digits":2,
         "rounding":0,
         "code":"DOP",
         "name_plural":"Dominican pesos",
         "vat":"18",
         "vat_name":"ITBIS"
      },
      "tin":"RNC",
      "languages":"es",
      "iso":"DOM"
   },
   "UY":{  
      "name":"Uruguay",
      "native":"Uruguay",
      "phone":"598",
      "continent":"SA",
      "capital":"Montevideo",
      "currency":{  
         "symbol":"$",
         "name":"Uruguayan Peso",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"UYU",
         "name_plural":"Uruguayan Pesos",
         "vat":"22",
         "vat_name":"IVA"
      },
      "tin":"RUT",
      "languages":"es",
      "iso":"URY"
   },
   "VE":{  
      "name":"Venezuela",
      "native":"Venezuela",
      "phone":"58",
      "continent":"SA",
      "capital":"Caracas",
      "currency":{  
         "symbol":"Bs.F.",
         "name":"Venezuelan Bol\u00edvar",
         "symbol_native":"Bs.F.",
         "decimal_digits":2,
         "rounding":0,
         "code":"VEF",
         "name_plural":"Venezuelan bol\u00edvars",
         "vat":"12",
         "vat_name":"IVA"
      },
      "tin":"RIF",
      "languages":"es",
      "iso":"VEN"
   },
   "US":{  
      "name":"United States",
      "native":"United States",
      "phone":"1",
      "continent":"NA",
      "capital":"Washington D.C.",
      "currency":{  
         "symbol":"$",
         "name":"US Dollar",
         "symbol_native":"$",
         "decimal_digits":2,
         "rounding":0,
         "code":"USD",
         "name_plural":"US dollars"
      },
      "tin":"SSN\/TIN",
      "languages":"en",
      "iso":"USA"
   }
}';

/*$countries = json_decode('{
	"AR": {
		"name": "Argentina",
		"native": "Argentina",
		"phone": "54",
		"continent": "SA",
		"capital": "Buenos Aires",
		"currency": {
			"symbol": "AR$",
			"name": "Argentine Peso",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ARS",
			"name_plural": "Argentine pesos",
			"vat": "21",
			"vat_name": "IVA"
		},
		"tin":"CUIT",
		"languages": "es,gn"
	},
	"BO": {
		"name": "Bolivia",
		"native": "Bolivia",
		"phone": "591",
		"continent": "SA",
		"capital": "Sucre",
		"currency": {
			"symbol": "Bs",
			"name": "Boliviano",
			"symbol_native": "Bs",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BOB",
			"name_plural": "Bolivianos",
			"vat": "13",
			"vat_name": "IVA"
		},
		"tin":"NIT",
		"languages": "es,ay,qu"
	},
	"BR": {
		"name": "Brazil",
		"native": "Brasil",
		"phone": "55",
		"continent": "SA",
		"capital": "Brasília",
		"currency": {
			"symbol": "R$",
			"name": "Brazilian Real",
			"symbol_native": "R$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BRL",
			"name_plural": "Brazilian reals",
			"vat": "17",
			"vat_name": "IPI"
		},
		"tin":"CPF/CNPJ",
		"languages": "pt"
	},
	"CL": {
		"name": "Chile",
		"native": "Chile",
		"phone": "56",
		"continent": "SA",
		"capital": "Santiago",
		"currency": {
			"symbol": "$",
			"name": "Chilean Peso",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CLP",
			"name_plural": "Chilean Pesos",
			"vat": "19",
			"vat_name": "IVA"
		},
		"tin":"RUT",
		"languages": "es"
	},
	"CO": {
		"name": "Colombia",
		"native": "Colombia",
		"phone": "57",
		"continent": "SA",
		"capital": "Bogotá",
		"currency": {
			"symbol": "CO$",
			"name": "Colombian Peso",
			"symbol_native": "$",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "COP",
			"name_plural": "Colombian pesos",
			"vat": "16",
			"vat_name": "IVA"
		},
		"tin":"NIT",
		"languages": "es"
	},
	"CR": {
		"name": "Costa Rica",
		"native": "Costa Rica",
		"phone": "506",
		"continent": "NA",
		"capital": "San José",
		"currency": {
			"symbol": "₡",
			"name": "Costa Rican Colón",
			"symbol_native": "₡",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "CRC",
			"name_plural": "Costa Rican colóns",
			"vat": "13",
			"vat_name": "IV"
		},
		"tin":"NITE",
		"languages": "es"
	},
	"CU": {
		"name": "Cuba",
		"native": "Cuba",
		"phone": "53",
		"continent": "NA",
		"capital": "Havana",
		"languages": "es"
	},
	"EC": {
		"name": "Ecuador",
		"native": "Ecuador",
		"phone": "593",
		"continent": "SA",
		"capital": "Quito",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars",
			"vat":"12",
			"vat_name": "IVA"
		},
		"tin":"RUC",
		"languages": "es"
	},
	"SV": {
		"name": "El Salvador",
		"native": "El Salvador",
		"phone": "503",
		"continent": "NA",
		"capital": "San Salvador",
		"currency": {
			"symbol": "$",
			"name": "US Doallar",
			"symbol_native": "$‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US Dollars",
			"vat": "13",
			"vat_name": "IVA"
		},
		"tin":"NIT",
		"languages": "es"
	},
	"ES": {
		"name": "Spain",
		"native": "España",
		"phone": "34",
		"continent": "EU",
		"capital": "Madrid",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros",
			"vat":"21",
			"vat_name": "IVA"
		},
		"tin":"NIF/CIF",
		"languages": "es,eu,ca,gl,oc"
	},
	"GT": {
		"name": "Guatemala",
		"native": "Guatemala",
		"phone": "502",
		"continent": "NA",
		"capital": "Guatemala City",
		"currency": {
			"symbol": "GTQ",
			"name": "Guatemalan Quetzal",
			"symbol_native": "Q",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GTQ",
			"name_plural": "Guatemalan quetzals",
			"vat":"12",
			"vat_name": "IVA"
		},
		"tin":"RTU",
		"languages": "es"
	},
	"HT": {
		"name": "Haiti",
		"native": "Haïti",
		"phone": "509",
		"continent": "NA",
		"capital": "Port-au-Prince",
		"languages": "fr,ht"
	},
	"HN": {
		"name": "Honduras",
		"native": "Honduras",
		"phone": "504",
		"continent": "NA",
		"capital": "Tegucigalpa",
		"currency": {
			"symbol": "HNL",
			"name": "Honduran Lempira",
			"symbol_native": "L",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "HNL",
			"name_plural": "Honduran lempiras",
			"vat": "15",
			"vat_name": "ISV"
		},
		"tin":"RTN",
		"languages": "es"
	},
	"MX": {
		"name": "Mexico",
		"native": "México",
		"phone": "52",
		"continent": "NA",
		"capital": "Mexico City",
		"currency": {
			"symbol": "MX$",
			"name": "Mexican Peso",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MXN",
			"name_plural": "Mexican pesos",
			"vat": "16",
			"vat_name": "IVA"
		},
		"tin":"RFC",
		"languages": "es"
	},
	"NI": {
		"name": "Nicaragua",
		"native": "Nicaragua",
		"phone": "505",
		"continent": "NA",
		"capital": "Managua",
		"currency": {
			"symbol": "C$",
			"name": "Nicaraguan Córdoba",
			"symbol_native": "C$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NIO",
			"name_plural": "Nicaraguan córdobas",
			"vat": "15",
			"vat_name": "IVA"
		},
		"languages": "es"
	},
	"PA": {
		"name": "Panama",
		"native": "Panamá",
		"phone": "507",
		"continent": "NA",
		"capital": "Panama City",
		"currency": {
			"symbol": "฿",
			"name": "Balboa",
			"symbol_native": "฿",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "PAB",
			"name_plural": "Balboas",
			"vat":"7"
		},
		"tin":"NIT",
		"languages": "es"
	},
	"PY": {
		"name": "Paraguay",
		"native": "Paraguay",
		"phone": "595",
		"continent": "SA",
		"capital": "Asunción",
		"currency": {
			"symbol": "₲",
			"name": "Paraguayan Guarani",
			"symbol_native": "₲",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "PYG",
			"name_plural": "Paraguayan guaranis",
			"vat": "10",
			"vat_name": "IVA"
		},
		"tin":"RUC",
		"languages": "es,gn"
	},
	"PE": {
		"name": "Peru",
		"native": "Perú",
		"phone": "51",
		"continent": "SA",
		"capital": "Lima",
		"currency": {
			"symbol": "S/.",
			"name": "Peruvian Nuevo Sol",
			"symbol_native": "S/.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "PEN",
			"name_plural": "Peruvian nuevos soles",
			"vat": "18",
			"vat_name": "IGV"
		},
		"tin":"RUC",
		"languages": "es"
	},
	"PR": {
		"name": "Puerto Rico",
		"native": "Puerto Rico",
		"phone": "1787",
		"continent": "NA",
		"capital": "San Juan",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars",
			"vat":"11,5"
		},
		"languages": "es,en"
	},
	"DO": {
		"name": "Dominican Republic",
		"native": "República Dominicana",
		"phone": "1809",
		"continent": "NA",
		"capital": "Santo Domingo",
		"currency": {
			"symbol": "RD$",
			"name": "Dominican Peso",
			"symbol_native": "RD$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "DOP",
			"name_plural": "Dominican pesos",
			"vat": "18",
			"vat_name": "ITBIS"
		},
		"tin":"RNC",
		"languages": "es"
	},
	"UY": {
		"name": "Uruguay",
		"native": "Uruguay",
		"phone": "598",
		"continent": "SA",
		"capital": "Montevideo",
		"currency": {
			"symbol": "$",
			"name": "Uruguayan Peso",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "UYU",
			"name_plural": "Uruguayan Pesos",
			"vat": "22",
			"vat_name": "IVA"
		},
		"tin":"RUT",
		"languages": "es"
	},
	"VE": {
		"name": "Venezuela",
		"native": "Venezuela",
		"phone": "58",
		"continent": "SA",
		"capital": "Caracas",
		"currency": {
			"symbol": "Bs.F.",
			"name": "Venezuelan Bolívar",
			"symbol_native": "Bs.F.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "VEF",
			"name_plural": "Venezuelan bolívars",
			"vat":"12",
			"vat_name": "IVA"
		},
		"tin":"RIF",
		"languages": "es"
	},

	"US": {
		"name": "United States",
		"native": "United States",
		"phone": "1",
		"continent": "NA",
		"capital": "Washington D.C.",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"tin":"SSN/TIN",
		"languages": "en"
	},

	"CA": {
		"name": "Canada",
		"native": "Canada",
		"phone": "1",
		"continent": "NA",
		"capital": "Ottawa",
		"currency": {
			"symbol": "CA$",
			"name": "Canadian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CAD",
			"name_plural": "Canadian dollars"
		},
		"languages": "en,fr"
	},

	"AD": {
		"name": "Andorra",
		"native": "Andorra",
		"phone": "376",
		"continent": "EU",
		"capital": "Andorra la Vella",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "ca"
	},
	"AE": {
		"name": "United Arab Emirates",
		"native": "دولة الإمارات العربية المتحدة",
		"phone": "971",
		"continent": "AS",
		"capital": "Abu Dhabi",
		"currency": {
			"symbol": "AED",
			"name": "United Arab Emirates Dirham",
			"symbol_native": "د.إ.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AED",
			"name_plural": "UAE dirhams"
		},
		"languages": "ar"
	},
	"AF": {
		"name": "Afghanistan",
		"native": "افغانستان",
		"phone": "93",
		"continent": "AS",
		"capital": "Kabul",
		"currency": {
			"symbol": "Af",
			"name": "Afghan Afghani",
			"symbol_native": "؋",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "AFN",
			"name_plural": "Afghan Afghanis"
		},
		"languages": "ps,uz,tk"
	},
	"AG": {
		"name": "Antigua and Barbuda",
		"native": "Antigua and Barbuda",
		"phone": "1268",
		"continent": "NA",
		"capital": "Saint John\'s",
		"languages": "en"
	},
	"AI": {
		"name": "Anguilla",
		"native": "Anguilla",
		"phone": "1264",
		"continent": "NA",
		"capital": "The Valley",
		"languages": "en"
	},
	"AL": {
		"name": "Albania",
		"native": "Shqipëria",
		"phone": "355",
		"continent": "EU",
		"capital": "Tirana",
		"currency": {
			"symbol": "ALL",
			"name": "Albanian Lek",
			"symbol_native": "Lek",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "ALL",
			"name_plural": "Albanian lekë"
		},
		"languages": "sq"
	},
	"AM": {
		"name": "Armenia",
		"native": "Հայաստան",
		"phone": "374",
		"continent": "AS",
		"capital": "Yerevan",
		"currency": {
			"symbol": "AMD",
			"name": "Armenian Dram",
			"symbol_native": "դր.",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "AMD",
			"name_plural": "Armenian drams"
		},
		"languages": "hy,ru"
	},
	"AO": {
		"name": "Angola",
		"native": "Angola",
		"phone": "244",
		"continent": "AF",
		"capital": "Luanda",
		"languages": "pt"
	},
	"AQ": {
		"name": "Antarctica",
		"native": "Antarctica",
		"phone": "",
		"continent": "AN",
		"capital": "",
		"languages": ""
	},
	
	"AS": {
		"name": "American Samoa",
		"native": "American Samoa",
		"phone": "1684",
		"continent": "OC",
		"capital": "Pago Pago",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en,sm"
	},
	"AT": {
		"name": "Austria",
		"native": "Österreich",
		"phone": "43",
		"continent": "EU",
		"capital": "Vienna",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "de"
	},
	"AU": {
		"name": "Australia",
		"native": "Australia",
		"phone": "61",
		"continent": "OC",
		"capital": "Canberra",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"AW": {
		"name": "Aruba",
		"native": "Aruba",
		"phone": "297",
		"continent": "NA",
		"capital": "Oranjestad",
		"languages": "nl,pa"
	},
	"AX": {
		"name": "Åland",
		"native": "Åland",
		"phone": "358",
		"continent": "EU",
		"capital": "Mariehamn",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "sv"
	},
	"AZ": {
		"name": "Azerbaijan",
		"native": "Azərbaycan",
		"phone": "994",
		"continent": "AS",
		"capital": "Baku",
		"currency": {
			"symbol": "man.",
			"name": "Azerbaijani Manat",
			"symbol_native": "ман.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AZN",
			"name_plural": "Azerbaijani manats"
		},
		"languages": "az,hy"
	},
	"BA": {
		"name": "Bosnia and Herzegovina",
		"native": "Bosna i Hercegovina",
		"phone": "387",
		"continent": "EU",
		"capital": "Sarajevo",
		"currency": {
			"symbol": "KM",
			"name": "Bosnia-Herzegovina Convertible Mark",
			"symbol_native": "KM",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BAM",
			"name_plural": "Bosnia-Herzegovina convertible marks"
		},
		"languages": "bs,hr,sr"
	},
	"BB": {
		"name": "Barbados",
		"native": "Barbados",
		"phone": "1246",
		"continent": "NA",
		"capital": "Bridgetown",
		"languages": "en"
	},
	"BD": {
		"name": "Bangladesh",
		"native": "Bangladesh",
		"phone": "880",
		"continent": "AS",
		"capital": "Dhaka",
		"currency": {
			"symbol": "Tk",
			"name": "Bangladeshi Taka",
			"symbol_native": "৳",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BDT",
			"name_plural": "Bangladeshi takas"
		},
		"languages": "bn"
	},
	"BE": {
		"name": "Belgium",
		"native": "België",
		"phone": "32",
		"continent": "EU",
		"capital": "Brussels",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "nl,fr,de"
	},
	"BF": {
		"name": "Burkina Faso",
		"native": "Burkina Faso",
		"phone": "226",
		"continent": "AF",
		"capital": "Ouagadougou",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr,ff"
	},
	"BG": {
		"name": "Bulgaria",
		"native": "България",
		"phone": "359",
		"continent": "EU",
		"capital": "Sofia",
		"currency": {
			"symbol": "BGN",
			"name": "Bulgarian Lev",
			"symbol_native": "лв.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BGN",
			"name_plural": "Bulgarian leva"
		},
		"languages": "bg"
	},
	"BH": {
		"name": "Bahrain",
		"native": "‏البحرين",
		"phone": "973",
		"continent": "AS",
		"capital": "Manama",
		"currency": {
			"symbol": "BD",
			"name": "Bahraini Dinar",
			"symbol_native": "د.ب.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "BHD",
			"name_plural": "Bahraini dinars"
		},
		"languages": "ar"
	},
	"BI": {
		"name": "Burundi",
		"native": "Burundi",
		"phone": "257",
		"continent": "AF",
		"capital": "Bujumbura",
		"currency": {
			"symbol": "FBu",
			"name": "Burundian Franc",
			"symbol_native": "FBu",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "BIF",
			"name_plural": "Burundian francs"
		},
		"languages": "fr,rn"
	},
	"BJ": {
		"name": "Benin",
		"native": "Bénin",
		"phone": "229",
		"continent": "AF",
		"capital": "Porto-Novo",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"BL": {
		"name": "Saint Barthélemy",
		"native": "Saint-Barthélemy",
		"phone": "590",
		"continent": "NA",
		"capital": "Gustavia",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"BM": {
		"name": "Bermuda",
		"native": "Bermuda",
		"phone": "1441",
		"continent": "NA",
		"capital": "Hamilton",
		"languages": "en"
	},
	"BN": {
		"name": "Brunei",
		"native": "Negara Brunei Darussalam",
		"phone": "673",
		"continent": "AS",
		"capital": "Bandar Seri Begawan",
		"currency": {
			"symbol": "BN$",
			"name": "Brunei Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BND",
			"name_plural": "Brunei dollars"
		},
		"languages": "ms"
	},
	
	"BQ": {
		"name": "Bonaire",
		"native": "Bonaire",
		"phone": "5997",
		"continent": "NA",
		"capital": "Kralendijk",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "nl"
	},
	
	"BS": {
		"name": "Bahamas",
		"native": "Bahamas",
		"phone": "1242",
		"continent": "NA",
		"capital": "Nassau",
		"languages": "en"
	},
	"BT": {
		"name": "Bhutan",
		"native": "ʼbrug-yul",
		"phone": "975",
		"continent": "AS",
		"capital": "Thimphu",
		"languages": "dz"
	},
	"BV": {
		"name": "Bouvet Island",
		"native": "Bouvetøya",
		"phone": "",
		"continent": "AN",
		"capital": "",
		"currency": {
			"symbol": "Nkr",
			"name": "Norwegian Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NOK",
			"name_plural": "Norwegian kroner"
		},
		"languages": ""
	},
	"BW": {
		"name": "Botswana",
		"native": "Botswana",
		"phone": "267",
		"continent": "AF",
		"capital": "Gaborone",
		"currency": {
			"symbol": "BWP",
			"name": "Botswanan Pula",
			"symbol_native": "P",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BWP",
			"name_plural": "Botswanan pulas"
		},
		"languages": "en,tn"
	},
	"BY": {
		"name": "Belarus",
		"native": "Белару́сь",
		"phone": "375",
		"continent": "EU",
		"capital": "Minsk",
		"currency": {
			"symbol": "BYR",
			"name": "Belarusian Ruble",
			"symbol_native": "BYR",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "BYR",
			"name_plural": "Belarusian rubles"
		},
		"languages": "be,ru"
	},
	"BZ": {
		"name": "Belize",
		"native": "Belize",
		"phone": "501",
		"continent": "NA",
		"capital": "Belmopan",
		"currency": {
			"symbol": "BZ$",
			"name": "Belize Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "BZD",
			"name_plural": "Belize dollars"
		},
		"languages": "en,es"
	},
	
	"CC": {
		"name": "Cocos [Keeling] Islands",
		"native": "Cocos (Keeling) Islands",
		"phone": "61",
		"continent": "AS",
		"capital": "West Island",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"CD": {
		"name": "Democratic Republic of the Congo",
		"native": "République démocratique du Congo",
		"phone": "243",
		"continent": "AF",
		"capital": "Kinshasa",
		"currency": {
			"symbol": "CDF",
			"name": "Congolese Franc",
			"symbol_native": "FrCD",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CDF",
			"name_plural": "Congolese francs"
		},
		"languages": "fr,ln,kg,sw,lu"
	},
	"CF": {
		"name": "Central African Republic",
		"native": "Ködörösêse tî Bêafrîka",
		"phone": "236",
		"continent": "AF",
		"capital": "Bangui",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "fr,sg"
	},
	"CG": {
		"name": "Republic of the Congo",
		"native": "République du Congo",
		"phone": "242",
		"continent": "AF",
		"capital": "Brazzaville",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "fr,ln"
	},
	"CH": {
		"name": "Switzerland",
		"native": "Schweiz",
		"phone": "41",
		"continent": "EU",
		"capital": "Bern",
		"languages": "de,fr,it"
	},
	"CI": {
		"name": "Ivory Coast",
		"native": "Côte d\'Ivoire",
		"phone": "225",
		"continent": "AF",
		"capital": "Yamoussoukro",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"CK": {
		"name": "Cook Islands",
		"native": "Cook Islands",
		"phone": "682",
		"continent": "OC",
		"capital": "Avarua",
		"currency": {
			"symbol": "NZ$",
			"name": "New Zealand Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NZD",
			"name_plural": "New Zealand dollars"
		},
		"languages": "en"
	},
	
	"CM": {
		"name": "Cameroon",
		"native": "Cameroon",
		"phone": "237",
		"continent": "AF",
		"capital": "Yaoundé",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "en,fr"
	},
	"CN": {
		"name": "China",
		"native": "中国",
		"phone": "86",
		"continent": "AS",
		"capital": "Beijing",
		"currency": {
			"symbol": "CN¥",
			"name": "Chinese Yuan",
			"symbol_native": "CN¥",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CNY",
			"name_plural": "Chinese yuan"
		},
		"languages": "zh"
	},
	
	"CV": {
		"name": "Cape Verde",
		"native": "Cabo Verde",
		"phone": "238",
		"continent": "AF",
		"capital": "Praia",
		"currency": {
			"symbol": "CV$",
			"name": "Cape Verdean Escudo",
			"symbol_native": "CV$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CVE",
			"name_plural": "Cape Verdean escudos"
		},
		"languages": "pt"
	},
	"CW": {
		"name": "Curacao",
		"native": "Curaçao",
		"phone": "5999",
		"continent": "NA",
		"capital": "Willemstad",
		"languages": "nl,pa,en"
	},
	"CX": {
		"name": "Christmas Island",
		"native": "Christmas Island",
		"phone": "61",
		"continent": "AS",
		"capital": "Flying Fish Cove",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"CY": {
		"name": "Cyprus",
		"native": "Κύπρος",
		"phone": "357",
		"continent": "EU",
		"capital": "Nicosia",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "el,tr,hy"
	},
	"CZ": {
		"name": "Czech Republic",
		"native": "Česká republika",
		"phone": "420",
		"continent": "EU",
		"capital": "Prague",
		"currency": {
			"symbol": "Kč",
			"name": "Czech Republic Koruna",
			"symbol_native": "Kč",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "CZK",
			"name_plural": "Czech Republic korunas"
		},
		"languages": "cs,sk"
	},
	"DE": {
		"name": "Germany",
		"native": "Deutschland",
		"phone": "49",
		"continent": "EU",
		"capital": "Berlin",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "de"
	},
	"DJ": {
		"name": "Djibouti",
		"native": "Djibouti",
		"phone": "253",
		"continent": "AF",
		"capital": "Djibouti",
		"currency": {
			"symbol": "Fdj",
			"name": "Djiboutian Franc",
			"symbol_native": "Fdj",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "DJF",
			"name_plural": "Djiboutian francs"
		},
		"languages": "fr,ar"
	},
	"DK": {
		"name": "Denmark",
		"native": "Danmark",
		"phone": "45",
		"continent": "EU",
		"capital": "Copenhagen",
		"currency": {
			"symbol": "Dkr",
			"name": "Danish Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "DKK",
			"name_plural": "Danish kroner"
		},
		"languages": "da"
	},
	"DM": {
		"name": "Dominica",
		"native": "Dominica",
		"phone": "1767",
		"continent": "NA",
		"capital": "Roseau",
		"languages": "en"
	},
	
	"DZ": {
		"name": "Algeria",
		"native": "الجزائر",
		"phone": "213",
		"continent": "AF",
		"capital": "Algiers",
		"currency": {
			"symbol": "DA",
			"name": "Algerian Dinar",
			"symbol_native": "د.ج.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "DZD",
			"name_plural": "Algerian dinars"
		},
		"languages": "ar"
	},
	
	"EE": {
		"name": "Estonia",
		"native": "Eesti",
		"phone": "372",
		"continent": "EU",
		"capital": "Tallinn",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "et"
	},
	"EG": {
		"name": "Egypt",
		"native": "مصر‎",
		"phone": "20",
		"continent": "AF",
		"capital": "Cairo",
		"currency": {
			"symbol": "EGP",
			"name": "Egyptian Pound",
			"symbol_native": "ج.م.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EGP",
			"name_plural": "Egyptian pounds"
		},
		"languages": "ar"
	},
	"EH": {
		"name": "Western Sahara",
		"native": "الصحراء الغربية",
		"phone": "212",
		"continent": "AF",
		"capital": "El Aaiún",
		"languages": "es"
	},
	"ER": {
		"name": "Eritrea",
		"native": "ኤርትራ",
		"phone": "291",
		"continent": "AF",
		"capital": "Asmara",
		"currency": {
			"symbol": "Nfk",
			"name": "Eritrean Nakfa",
			"symbol_native": "Nfk",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ERN",
			"name_plural": "Eritrean nakfas"
		},
		"languages": "ti,ar,en"
	},
	"ET": {
		"name": "Ethiopia",
		"native": "ኢትዮጵያ",
		"phone": "251",
		"continent": "AF",
		"capital": "Addis Ababa",
		"currency": {
			"symbol": "Br",
			"name": "Ethiopian Birr",
			"symbol_native": "Br",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ETB",
			"name_plural": "Ethiopian birrs"
		},
		"languages": "am"
	},
	"FI": {
		"name": "Finland",
		"native": "Suomi",
		"phone": "358",
		"continent": "EU",
		"capital": "Helsinki",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fi,sv"
	},
	"FJ": {
		"name": "Fiji",
		"native": "Fiji",
		"phone": "679",
		"continent": "OC",
		"capital": "Suva",
		"languages": "en,fj,hi,ur"
	},
	"FK": {
		"name": "Falkland Islands",
		"native": "Falkland Islands",
		"phone": "500",
		"continent": "SA",
		"capital": "Stanley",
		"languages": "en"
	},
	"FM": {
		"name": "Micronesia",
		"native": "Micronesia",
		"phone": "691",
		"continent": "OC",
		"capital": "Palikir",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	"FO": {
		"name": "Faroe Islands",
		"native": "Føroyar",
		"phone": "298",
		"continent": "EU",
		"capital": "Tórshavn",
		"currency": {
			"symbol": "Dkr",
			"name": "Danish Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "DKK",
			"name_plural": "Danish kroner"
		},
		"languages": "fo"
	},
	"FR": {
		"name": "France",
		"native": "France",
		"phone": "33",
		"continent": "EU",
		"capital": "Paris",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"GA": {
		"name": "Gabon",
		"native": "Gabon",
		"phone": "241",
		"continent": "AF",
		"capital": "Libreville",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "fr"
	},
	"GB": {
		"name": "United Kingdom",
		"native": "United Kingdom",
		"phone": "44",
		"continent": "EU",
		"capital": "London",
		"currency": {
			"symbol": "£",
			"name": "British Pound Sterling",
			"symbol_native": "£",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GBP",
			"name_plural": "British pounds sterling"
		},
		"languages": "en"
	},
	"GD": {
		"name": "Grenada",
		"native": "Grenada",
		"phone": "1473",
		"continent": "NA",
		"capital": "St. George\'s",
		"languages": "en"
	},
	"GE": {
		"name": "Georgia",
		"native": "საქართველო",
		"phone": "995",
		"continent": "AS",
		"capital": "Tbilisi",
		"currency": {
			"symbol": "GEL",
			"name": "Georgian Lari",
			"symbol_native": "GEL",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GEL",
			"name_plural": "Georgian laris"
		},
		"languages": "ka"
	},
	"GF": {
		"name": "French Guiana",
		"native": "Guyane française",
		"phone": "594",
		"continent": "SA",
		"capital": "Cayenne",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"GG": {
		"name": "Guernsey",
		"native": "Guernsey",
		"phone": "44",
		"continent": "EU",
		"capital": "St. Peter Port",
		"currency": {
			"symbol": "£",
			"name": "British Pound Sterling",
			"symbol_native": "£",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GBP",
			"name_plural": "British pounds sterling"
		},
		"languages": "en,fr"
	},
	"GH": {
		"name": "Ghana",
		"native": "Ghana",
		"phone": "233",
		"continent": "AF",
		"capital": "Accra",
		"currency": {
			"symbol": "GH₵",
			"name": "Ghanaian Cedi",
			"symbol_native": "GH₵",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GHS",
			"name_plural": "Ghanaian cedis"
		},
		"languages": "en"
	},
	"GI": {
		"name": "Gibraltar",
		"native": "Gibraltar",
		"phone": "350",
		"continent": "EU",
		"capital": "Gibraltar",
		"languages": "en"
	},
	"GL": {
		"name": "Greenland",
		"native": "Kalaallit Nunaat",
		"phone": "299",
		"continent": "NA",
		"capital": "Nuuk",
		"currency": {
			"symbol": "Dkr",
			"name": "Danish Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "DKK",
			"name_plural": "Danish kroner"
		},
		"languages": "kl"
	},
	"GM": {
		"name": "Gambia",
		"native": "Gambia",
		"phone": "220",
		"continent": "AF",
		"capital": "Banjul",
		"languages": "en"
	},
	"GN": {
		"name": "Guinea",
		"native": "Guinée",
		"phone": "224",
		"continent": "AF",
		"capital": "Conakry",
		"currency": {
			"symbol": "FG",
			"name": "Guinean Franc",
			"symbol_native": "FG",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "GNF",
			"name_plural": "Guinean francs"
		},
		"languages": "fr,ff"
	},
	"GP": {
		"name": "Guadeloupe",
		"native": "Guadeloupe",
		"phone": "590",
		"continent": "NA",
		"capital": "Basse-Terre",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"GQ": {
		"name": "Equatorial Guinea",
		"native": "Guinea Ecuatorial",
		"phone": "240",
		"continent": "AF",
		"capital": "Malabo",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "es,fr"
	},
	"GR": {
		"name": "Greece",
		"native": "Ελλάδα",
		"phone": "30",
		"continent": "EU",
		"capital": "Athens",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "el"
	},
	"GS": {
		"name": "South Georgia and the South Sandwich Islands",
		"native": "South Georgia",
		"phone": "500",
		"continent": "AN",
		"capital": "King Edward Point",
		"currency": {
			"symbol": "£",
			"name": "British Pound Sterling",
			"symbol_native": "£",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GBP",
			"name_plural": "British pounds sterling"
		},
		"languages": "en"
	},
	
	"GU": {
		"name": "Guam",
		"native": "Guam",
		"phone": "1671",
		"continent": "OC",
		"capital": "Hagåtña",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en,ch,es"
	},
	"GW": {
		"name": "Guinea-Bissau",
		"native": "Guiné-Bissau",
		"phone": "245",
		"continent": "AF",
		"capital": "Bissau",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "pt"
	},
	"GY": {
		"name": "Guyana",
		"native": "Guyana",
		"phone": "592",
		"continent": "SA",
		"capital": "Georgetown",
		"languages": "en"
	},
	"HK": {
		"name": "Hong Kong",
		"native": "香港",
		"phone": "852",
		"continent": "AS",
		"capital": "City of Victoria",
		"currency": {
			"symbol": "HK$",
			"name": "Hong Kong Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "HKD",
			"name_plural": "Hong Kong dollars"
		},
		"languages": "zh,en"
	},
	"HM": {
		"name": "Heard Island and McDonald Islands",
		"native": "Heard Island and McDonald Islands",
		"phone": "",
		"continent": "AN",
		"capital": "",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	
	"HR": {
		"name": "Croatia",
		"native": "Hrvatska",
		"phone": "385",
		"continent": "EU",
		"capital": "Zagreb",
		"currency": {
			"symbol": "kn",
			"name": "Croatian Kuna",
			"symbol_native": "kn",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "HRK",
			"name_plural": "Croatian kunas"
		},
		"languages": "hr"
	},
	
	"HU": {
		"name": "Hungary",
		"native": "Magyarország",
		"phone": "36",
		"continent": "EU",
		"capital": "Budapest",
		"currency": {
			"symbol": "Ft",
			"name": "Hungarian Forint",
			"symbol_native": "Ft",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "HUF",
			"name_plural": "Hungarian forints"
		},
		"languages": "hu"
	},
	"ID": {
		"name": "Indonesia",
		"native": "Indonesia",
		"phone": "62",
		"continent": "AS",
		"capital": "Jakarta",
		"currency": {
			"symbol": "Rp",
			"name": "Indonesian Rupiah",
			"symbol_native": "Rp",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "IDR",
			"name_plural": "Indonesian rupiahs"
		},
		"languages": "id"
	},
	"IE": {
		"name": "Ireland",
		"native": "Éire",
		"phone": "353",
		"continent": "EU",
		"capital": "Dublin",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "ga,en"
	},
	"IL": {
		"name": "Israel",
		"native": "יִשְׂרָאֵל",
		"phone": "972",
		"continent": "AS",
		"capital": "Jerusalem",
		"currency": {
			"symbol": "₪",
			"name": "Israeli New Sheqel",
			"symbol_native": "₪",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ILS",
			"name_plural": "Israeli new sheqels"
		},
		"languages": "he,ar"
	},
	"IM": {
		"name": "Isle of Man",
		"native": "Isle of Man",
		"phone": "44",
		"continent": "EU",
		"capital": "Douglas",
		"currency": {
			"symbol": "£",
			"name": "British Pound Sterling",
			"symbol_native": "£",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GBP",
			"name_plural": "British pounds sterling"
		},
		"languages": "en,gv"
	},
	"IN": {
		"name": "India",
		"native": "भारत",
		"phone": "91",
		"continent": "AS",
		"capital": "New Delhi",
		"currency": {
			"symbol": "Rs",
			"name": "Indian Rupee",
			"symbol_native": "টকা",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "INR",
			"name_plural": "Indian rupees"
		},
		"languages": "hi,en"
	},
	"IO": {
		"name": "British Indian Ocean Territory",
		"native": "British Indian Ocean Territory",
		"phone": "246",
		"continent": "AS",
		"capital": "Diego Garcia",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	"IQ": {
		"name": "Iraq",
		"native": "العراق",
		"phone": "964",
		"continent": "AS",
		"capital": "Baghdad",
		"currency": {
			"symbol": "IQD",
			"name": "Iraqi Dinar",
			"symbol_native": "د.ع.‏",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "IQD",
			"name_plural": "Iraqi dinars"
		},
		"languages": "ar,ku"
	},
	"IR": {
		"name": "Iran",
		"native": "ایران",
		"phone": "98",
		"continent": "AS",
		"capital": "Tehran",
		"currency": {
			"symbol": "IRR",
			"name": "Iranian Rial",
			"symbol_native": "﷼",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "IRR",
			"name_plural": "Iranian rials"
		},
		"languages": "fa"
	},
	"IS": {
		"name": "Iceland",
		"native": "Ísland",
		"phone": "354",
		"continent": "EU",
		"capital": "Reykjavik",
		"currency": {
			"symbol": "Ikr",
			"name": "Icelandic Króna",
			"symbol_native": "kr",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "ISK",
			"name_plural": "Icelandic krónur"
		},
		"languages": "is"
	},
	"IT": {
		"name": "Italy",
		"native": "Italia",
		"phone": "39",
		"continent": "EU",
		"capital": "Rome",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "it"
	},
	"JE": {
		"name": "Jersey",
		"native": "Jersey",
		"phone": "44",
		"continent": "EU",
		"capital": "Saint Helier",
		"currency": {
			"symbol": "£",
			"name": "British Pound Sterling",
			"symbol_native": "£",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "GBP",
			"name_plural": "British pounds sterling"
		},
		"languages": "en,fr"
	},
	"JM": {
		"name": "Jamaica",
		"native": "Jamaica",
		"phone": "1876",
		"continent": "NA",
		"capital": "Kingston",
		"currency": {
			"symbol": "J$",
			"name": "Jamaican Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "JMD",
			"name_plural": "Jamaican dollars"
		},
		"languages": "en"
	},
	"JO": {
		"name": "Jordan",
		"native": "الأردن",
		"phone": "962",
		"continent": "AS",
		"capital": "Amman",
		"currency": {
			"symbol": "JD",
			"name": "Jordanian Dinar",
			"symbol_native": "د.أ.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "JOD",
			"name_plural": "Jordanian dinars"
		},
		"languages": "ar"
	},
	"JP": {
		"name": "Japan",
		"native": "日本",
		"phone": "81",
		"continent": "AS",
		"capital": "Tokyo",
		"currency": {
			"symbol": "¥",
			"name": "Japanese Yen",
			"symbol_native": "￥",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "JPY",
			"name_plural": "Japanese yen"
		},
		"languages": "ja"
	},
	"KE": {
		"name": "Kenya",
		"native": "Kenya",
		"phone": "254",
		"continent": "AF",
		"capital": "Nairobi",
		"currency": {
			"symbol": "Ksh",
			"name": "Kenyan Shilling",
			"symbol_native": "Ksh",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "KES",
			"name_plural": "Kenyan shillings"
		},
		"languages": "en,sw"
	},
	"KG": {
		"name": "Kyrgyzstan",
		"native": "Кыргызстан",
		"phone": "996",
		"continent": "AS",
		"capital": "Bishkek",
		"languages": "ky,ru"
	},
	"KH": {
		"name": "Cambodia",
		"native": "Kâmpŭchéa",
		"phone": "855",
		"continent": "AS",
		"capital": "Phnom Penh",
		"currency": {
			"symbol": "KHR",
			"name": "Cambodian Riel",
			"symbol_native": "៛",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "KHR",
			"name_plural": "Cambodian riels"
		},
		"languages": "km"
	},
	"KI": {
		"name": "Kiribati",
		"native": "Kiribati",
		"phone": "686",
		"continent": "OC",
		"capital": "South Tarawa",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"KM": {
		"name": "Comoros",
		"native": "Komori",
		"phone": "269",
		"continent": "AF",
		"capital": "Moroni",
		"currency": {
			"symbol": "CF",
			"name": "Comorian Franc",
			"symbol_native": "FC",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "KMF",
			"name_plural": "Comorian francs"
		},
		"languages": "ar,fr"
	},
	"KN": {
		"name": "Saint Kitts and Nevis",
		"native": "Saint Kitts and Nevis",
		"phone": "1869",
		"continent": "NA",
		"capital": "Basseterre",
		"languages": "en"
	},
	"KP": {
		"name": "North Korea",
		"native": "북한",
		"phone": "850",
		"continent": "AS",
		"capital": "Pyongyang",
		"languages": "ko"
	},
	"KR": {
		"name": "South Korea",
		"native": "대한민국",
		"phone": "82",
		"continent": "AS",
		"capital": "Seoul",
		"currency": {
			"symbol": "₩",
			"name": "South Korean Won",
			"symbol_native": "₩",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "KRW",
			"name_plural": "South Korean won"
		},
		"languages": "ko"
	},
	"KW": {
		"name": "Kuwait",
		"native": "الكويت",
		"phone": "965",
		"continent": "AS",
		"capital": "Kuwait City",
		"currency": {
			"symbol": "KD",
			"name": "Kuwaiti Dinar",
			"symbol_native": "د.ك.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "KWD",
			"name_plural": "Kuwaiti dinars"
		},
		"languages": "ar"
	},
	"KY": {
		"name": "Cayman Islands",
		"native": "Cayman Islands",
		"phone": "1345",
		"continent": "NA",
		"capital": "George Town",
		"languages": "en"
	},
	"KZ": {
		"name": "Kazakhstan",
		"native": "Қазақстан",
		"phone": "76,77",
		"continent": "AS",
		"capital": "Astana",
		"currency": {
			"symbol": "KZT",
			"name": "Kazakhstani Tenge",
			"symbol_native": "тңг.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "KZT",
			"name_plural": "Kazakhstani tenges"
		},
		"languages": "kk,ru"
	},
	"LA": {
		"name": "Laos",
		"native": "ສປປລາວ",
		"phone": "856",
		"continent": "AS",
		"capital": "Vientiane",
		"languages": "lo"
	},
	"LB": {
		"name": "Lebanon",
		"native": "لبنان",
		"phone": "961",
		"continent": "AS",
		"capital": "Beirut",
		"currency": {
			"symbol": "LB£",
			"name": "Lebanese Pound",
			"symbol_native": "ل.ل.‏",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "LBP",
			"name_plural": "Lebanese pounds"
		},
		"languages": "ar,fr"
	},
	"LC": {
		"name": "Saint Lucia",
		"native": "Saint Lucia",
		"phone": "1758",
		"continent": "NA",
		"capital": "Castries",
		"languages": "en"
	},
	"LI": {
		"name": "Liechtenstein",
		"native": "Liechtenstein",
		"phone": "423",
		"continent": "EU",
		"capital": "Vaduz",
		"currency": {
			"symbol": "CHF",
			"name": "Swiss Franc",
			"symbol_native": "CHF",
			"decimal_digits": 2,
			"rounding": 0.05,
			"code": "CHF",
			"name_plural": "Swiss francs"
		},
		"languages": "de"
	},
	"LK": {
		"name": "Sri Lanka",
		"native": "śrī laṃkāva",
		"phone": "94",
		"continent": "AS",
		"capital": "Colombo",
		"currency": {
			"symbol": "SLRs",
			"name": "Sri Lankan Rupee",
			"symbol_native": "SL Re",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "LKR",
			"name_plural": "Sri Lankan rupees"
		},
		"languages": "si,ta"
	},
	"LR": {
		"name": "Liberia",
		"native": "Liberia",
		"phone": "231",
		"continent": "AF",
		"capital": "Monrovia",
		"languages": "en"
	},
	"LS": {
		"name": "Lesotho",
		"native": "Lesotho",
		"phone": "266",
		"continent": "AF",
		"capital": "Maseru",
		"languages": "en,st"
	},
	"LT": {
		"name": "Lithuania",
		"native": "Lietuva",
		"phone": "370",
		"continent": "EU",
		"capital": "Vilnius",
		"currency": {
			"symbol": "Lt",
			"name": "Lithuanian Litas",
			"symbol_native": "Lt",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "LTL",
			"name_plural": "Lithuanian litai"
		},
		"languages": "lt"
	},
	"LU": {
		"name": "Luxembourg",
		"native": "Luxembourg",
		"phone": "352",
		"continent": "EU",
		"capital": "Luxembourg",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr,de,lb"
	},
	"LV": {
		"name": "Latvia",
		"native": "Latvija",
		"phone": "371",
		"continent": "EU",
		"capital": "Riga",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "lv"
	},
	"LY": {
		"name": "Libya",
		"native": "‏ليبيا",
		"phone": "218",
		"continent": "AF",
		"capital": "Tripoli",
		"currency": {
			"symbol": "LD",
			"name": "Libyan Dinar",
			"symbol_native": "د.ل.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "LYD",
			"name_plural": "Libyan dinars"
		},
		"languages": "ar"
	},
	"MA": {
		"name": "Morocco",
		"native": "المغرب",
		"phone": "212",
		"continent": "AF",
		"capital": "Rabat",
		"currency": {
			"symbol": "MAD",
			"name": "Moroccan Dirham",
			"symbol_native": "د.م.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MAD",
			"name_plural": "Moroccan dirhams"
		},
		"languages": "ar"
	},
	"MC": {
		"name": "Monaco",
		"native": "Monaco",
		"phone": "377",
		"continent": "EU",
		"capital": "Monaco",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"MD": {
		"name": "Moldova",
		"native": "Moldova",
		"phone": "373",
		"continent": "EU",
		"capital": "Chișinău",
		"currency": {
			"symbol": "MDL",
			"name": "Moldovan Leu",
			"symbol_native": "MDL",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MDL",
			"name_plural": "Moldovan lei"
		},
		"languages": "ro"
	},
	"ME": {
		"name": "Montenegro",
		"native": "Црна Гора",
		"phone": "382",
		"continent": "EU",
		"capital": "Podgorica",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "sr,bs,sq,hr"
	},
	"MF": {
		"name": "Saint Martin",
		"native": "Saint-Martin",
		"phone": "590",
		"continent": "NA",
		"capital": "Marigot",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "en,fr,nl"
	},
	"MG": {
		"name": "Madagascar",
		"native": "Madagasikara",
		"phone": "261",
		"continent": "AF",
		"capital": "Antananarivo",
		"currency": {
			"symbol": "MGA",
			"name": "Malagasy Ariary",
			"symbol_native": "MGA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "MGA",
			"name_plural": "Malagasy Ariaries"
		},
		"languages": "fr,mg"
	},
	"MH": {
		"name": "Marshall Islands",
		"native": "M̧ajeļ",
		"phone": "692",
		"continent": "OC",
		"capital": "Majuro",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en,mh"
	},
	"MK": {
		"name": "Macedonia",
		"native": "Македонија",
		"phone": "389",
		"continent": "EU",
		"capital": "Skopje",
		"currency": {
			"symbol": "MKD",
			"name": "Macedonian Denar",
			"symbol_native": "MKD",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MKD",
			"name_plural": "Macedonian denari"
		},
		"languages": "mk"
	},
	"ML": {
		"name": "Mali",
		"native": "Mali",
		"phone": "223",
		"continent": "AF",
		"capital": "Bamako",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"MM": {
		"name": "Myanmar [Burma]",
		"native": "Myanma",
		"phone": "95",
		"continent": "AS",
		"capital": "Naypyidaw",
		"currency": {
			"symbol": "MMK",
			"name": "Myanma Kyat",
			"symbol_native": "K",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "MMK",
			"name_plural": "Myanma kyats"
		},
		"languages": "my"
	},
	"MN": {
		"name": "Mongolia",
		"native": "Монгол улс",
		"phone": "976",
		"continent": "AS",
		"capital": "Ulan Bator",
		"languages": "mn"
	},
	"MO": {
		"name": "Macao",
		"native": "澳門",
		"phone": "853",
		"continent": "AS",
		"capital": "",
		"currency": {
			"symbol": "MOP$",
			"name": "Macanese Pataca",
			"symbol_native": "MOP$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MOP",
			"name_plural": "Macanese patacas"
		},
		"languages": "zh,pt"
	},
	"MP": {
		"name": "Northern Mariana Islands",
		"native": "Northern Mariana Islands",
		"phone": "1670",
		"continent": "OC",
		"capital": "Saipan",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en,ch"
	},
	"MQ": {
		"name": "Martinique",
		"native": "Martinique",
		"phone": "596",
		"continent": "NA",
		"capital": "Fort-de-France",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"MR": {
		"name": "Mauritania",
		"native": "موريتانيا",
		"phone": "222",
		"continent": "AF",
		"capital": "Nouakchott",
		"languages": "ar"
	},
	"MS": {
		"name": "Montserrat",
		"native": "Montserrat",
		"phone": "1664",
		"continent": "NA",
		"capital": "Plymouth",
		"languages": "en"
	},
	"MT": {
		"name": "Malta",
		"native": "Malta",
		"phone": "356",
		"continent": "EU",
		"capital": "Valletta",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "mt,en"
	},
	"MU": {
		"name": "Mauritius",
		"native": "Maurice",
		"phone": "230",
		"continent": "AF",
		"capital": "Port Louis",
		"currency": {
			"symbol": "MURs",
			"name": "Mauritian Rupee",
			"symbol_native": "MURs",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "MUR",
			"name_plural": "Mauritian rupees"
		},
		"languages": "en"
	},
	"MV": {
		"name": "Maldives",
		"native": "Maldives",
		"phone": "960",
		"continent": "AS",
		"capital": "Malé",
		"languages": "dv"
	},
	"MW": {
		"name": "Malawi",
		"native": "Malawi",
		"phone": "265",
		"continent": "AF",
		"capital": "Lilongwe",
		"languages": "en,ny"
	},
	
	"MY": {
		"name": "Malaysia",
		"native": "Malaysia",
		"phone": "60",
		"continent": "AS",
		"capital": "Kuala Lumpur",
		"currency": {
			"symbol": "RM",
			"name": "Malaysian Ringgit",
			"symbol_native": "RM",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MYR",
			"name_plural": "Malaysian ringgits"
		},
		"languages": ""
	},
	"MZ": {
		"name": "Mozambique",
		"native": "Moçambique",
		"phone": "258",
		"continent": "AF",
		"capital": "Maputo",
		"currency": {
			"symbol": "MTn",
			"name": "Mozambican Metical",
			"symbol_native": "MTn",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "MZN",
			"name_plural": "Mozambican meticals"
		},
		"languages": "pt"
	},
	"NA": {
		"name": "Namibia",
		"native": "Namibia",
		"phone": "264",
		"continent": "AF",
		"capital": "Windhoek",
		"languages": "en,af"
	},
	"NC": {
		"name": "New Caledonia",
		"native": "Nouvelle-Calédonie",
		"phone": "687",
		"continent": "OC",
		"capital": "Nouméa",
		"languages": "fr"
	},
	"NE": {
		"name": "Niger",
		"native": "Niger",
		"phone": "227",
		"continent": "AF",
		"capital": "Niamey",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"NF": {
		"name": "Norfolk Island",
		"native": "Norfolk Island",
		"phone": "672",
		"continent": "OC",
		"capital": "Kingston",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"NG": {
		"name": "Nigeria",
		"native": "Nigeria",
		"phone": "234",
		"continent": "AF",
		"capital": "Abuja",
		"currency": {
			"symbol": "₦",
			"name": "Nigerian Naira",
			"symbol_native": "₦",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NGN",
			"name_plural": "Nigerian nairas"
		},
		"languages": "en"
	},
	
	"NL": {
		"name": "Netherlands",
		"native": "Nederland",
		"phone": "31",
		"continent": "EU",
		"capital": "Amsterdam",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "nl"
	},
	"NO": {
		"name": "Norway",
		"native": "Norge",
		"phone": "47",
		"continent": "EU",
		"capital": "Oslo",
		"currency": {
			"symbol": "Nkr",
			"name": "Norwegian Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NOK",
			"name_plural": "Norwegian kroner"
		},
		"languages": "no,nb,nn"
	},
	"NP": {
		"name": "Nepal",
		"native": "नपल",
		"phone": "977",
		"continent": "AS",
		"capital": "Kathmandu",
		"currency": {
			"symbol": "NPRs",
			"name": "Nepalese Rupee",
			"symbol_native": "नेरू",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NPR",
			"name_plural": "Nepalese rupees"
		},
		"languages": "ne"
	},
	"NR": {
		"name": "Nauru",
		"native": "Nauru",
		"phone": "674",
		"continent": "OC",
		"capital": "Yaren",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en,na"
	},
	"NU": {
		"name": "Niue",
		"native": "Niuē",
		"phone": "683",
		"continent": "OC",
		"capital": "Alofi",
		"currency": {
			"symbol": "NZ$",
			"name": "New Zealand Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NZD",
			"name_plural": "New Zealand dollars"
		},
		"languages": "en"
	},
	"NZ": {
		"name": "New Zealand",
		"native": "New Zealand",
		"phone": "64",
		"continent": "OC",
		"capital": "Wellington",
		"currency": {
			"symbol": "NZ$",
			"name": "New Zealand Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NZD",
			"name_plural": "New Zealand dollars"
		},
		"languages": "en,mi"
	},
	"OM": {
		"name": "Oman",
		"native": "عمان",
		"phone": "968",
		"continent": "AS",
		"capital": "Muscat",
		"currency": {
			"symbol": "OMR",
			"name": "Omani Rial",
			"symbol_native": "ر.ع.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "OMR",
			"name_plural": "Omani rials"
		},
		"languages": "ar"
	},
	
	
	"PF": {
		"name": "French Polynesia",
		"native": "Polynésie française",
		"phone": "689",
		"continent": "OC",
		"capital": "Papeetē",
		"languages": "fr"
	},
	"PG": {
		"name": "Papua New Guinea",
		"native": "Papua Niugini",
		"phone": "675",
		"continent": "OC",
		"capital": "Port Moresby",
		"languages": "en"
	},
	"PH": {
		"name": "Philippines",
		"native": "Pilipinas",
		"phone": "63",
		"continent": "AS",
		"capital": "Manila",
		"currency": {
			"symbol": "₱",
			"name": "Philippine Peso",
			"symbol_native": "₱",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "PHP",
			"name_plural": "Philippine pesos"
		},
		"languages": "en"
	},
	"PK": {
		"name": "Pakistan",
		"native": "Pakistan",
		"phone": "92",
		"continent": "AS",
		"capital": "Islamabad",
		"currency": {
			"symbol": "PKRs",
			"name": "Pakistani Rupee",
			"symbol_native": "₨",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "PKR",
			"name_plural": "Pakistani rupees"
		},
		"languages": "en,ur"
	},
	"PL": {
		"name": "Poland",
		"native": "Polska",
		"phone": "48",
		"continent": "EU",
		"capital": "Warsaw",
		"currency": {
			"symbol": "zł",
			"name": "Polish Zloty",
			"symbol_native": "zł",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "PLN",
			"name_plural": "Polish zlotys"
		},
		"languages": "pl"
	},
	"PM": {
		"name": "Saint Pierre and Miquelon",
		"native": "Saint-Pierre-et-Miquelon",
		"phone": "508",
		"continent": "NA",
		"capital": "Saint-Pierre",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"PN": {
		"name": "Pitcairn Islands",
		"native": "Pitcairn Islands",
		"phone": "64",
		"continent": "OC",
		"capital": "Adamstown",
		"currency": {
			"symbol": "NZ$",
			"name": "New Zealand Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NZD",
			"name_plural": "New Zealand dollars"
		},
		"languages": "en"
	},
	
	"PS": {
		"name": "Palestine",
		"native": "فلسطين",
		"phone": "970",
		"continent": "AS",
		"capital": "Ramallah",
		"currency": {
			"symbol": "₪",
			"name": "Israeli New Sheqel",
			"symbol_native": "₪",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ILS",
			"name_plural": "Israeli new sheqels"
		},
		"languages": "ar"
	},
	"PT": {
		"name": "Portugal",
		"native": "Portugal",
		"phone": "351",
		"continent": "EU",
		"capital": "Lisbon",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "pt"
	},
	"PW": {
		"name": "Palau",
		"native": "Palau",
		"phone": "680",
		"continent": "OC",
		"capital": "Ngerulmud",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	
	"QA": {
		"name": "Qatar",
		"native": "قطر",
		"phone": "974",
		"continent": "AS",
		"capital": "Doha",
		"currency": {
			"symbol": "QR",
			"name": "Qatari Rial",
			"symbol_native": "ر.ق.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "QAR",
			"name_plural": "Qatari rials"
		},
		"languages": "ar"
	},
	"RE": {
		"name": "Réunion",
		"native": "La Réunion",
		"phone": "262",
		"continent": "AF",
		"capital": "Saint-Denis",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"RO": {
		"name": "Romania",
		"native": "România",
		"phone": "40",
		"continent": "EU",
		"capital": "Bucharest",
		"currency": {
			"symbol": "RON",
			"name": "Romanian Leu",
			"symbol_native": "RON",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "RON",
			"name_plural": "Romanian lei"
		},
		"languages": "ro"
	},
	"RS": {
		"name": "Serbia",
		"native": "Србија",
		"phone": "381",
		"continent": "EU",
		"capital": "Belgrade",
		"currency": {
			"symbol": "din.",
			"name": "Serbian Dinar",
			"symbol_native": "дин.",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "RSD",
			"name_plural": "Serbian dinars"
		},
		"languages": "sr"
	},
	"RU": {
		"name": "Russia",
		"native": "Россия",
		"phone": "7",
		"continent": "EU",
		"capital": "Moscow",
		"currency": {
			"symbol": "RUB",
			"name": "Russian Ruble",
			"symbol_native": "руб.",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "RUB",
			"name_plural": "Russian rubles"
		},
		"languages": "ru"
	},
	"RW": {
		"name": "Rwanda",
		"native": "Rwanda",
		"phone": "250",
		"continent": "AF",
		"capital": "Kigali",
		"currency": {
			"symbol": "RWF",
			"name": "Rwandan Franc",
			"symbol_native": "FR",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "RWF",
			"name_plural": "Rwandan francs"
		},
		"languages": "rw,en,fr"
	},
	"SA": {
		"name": "Saudi Arabia",
		"native": "العربية السعودية",
		"phone": "966",
		"continent": "AS",
		"capital": "Riyadh",
		"currency": {
			"symbol": "SR",
			"name": "Saudi Riyal",
			"symbol_native": "ر.س.‏",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "SAR",
			"name_plural": "Saudi riyals"
		},
		"languages": "ar"
	},
	"SB": {
		"name": "Solomon Islands",
		"native": "Solomon Islands",
		"phone": "677",
		"continent": "OC",
		"capital": "Honiara",
		"languages": "en"
	},
	"SC": {
		"name": "Seychelles",
		"native": "Seychelles",
		"phone": "248",
		"continent": "AF",
		"capital": "Victoria",
		"languages": "fr,en"
	},
	"SD": {
		"name": "Sudan",
		"native": "السودان",
		"phone": "249",
		"continent": "AF",
		"capital": "Khartoum",
		"currency": {
			"symbol": "SDG",
			"name": "Sudanese Pound",
			"symbol_native": "SDG",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "SDG",
			"name_plural": "Sudanese pounds"
		},
		"languages": "ar,en"
	},
	"SE": {
		"name": "Sweden",
		"native": "Sverige",
		"phone": "46",
		"continent": "EU",
		"capital": "Stockholm",
		"currency": {
			"symbol": "Skr",
			"name": "Swedish Krona",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "SEK",
			"name_plural": "Swedish kronor"
		},
		"languages": "sv"
	},
	"SG": {
		"name": "Singapore",
		"native": "Singapore",
		"phone": "65",
		"continent": "AS",
		"capital": "Singapore",
		"currency": {
			"symbol": "S$",
			"name": "Singapore Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "SGD",
			"name_plural": "Singapore dollars"
		},
		"languages": "en,ms,ta,zh"
	},
	"SH": {
		"name": "Saint Helena",
		"native": "Saint Helena",
		"phone": "290",
		"continent": "AF",
		"capital": "Jamestown",
		"languages": "en"
	},
	"SI": {
		"name": "Slovenia",
		"native": "Slovenija",
		"phone": "386",
		"continent": "EU",
		"capital": "Ljubljana",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "sl"
	},
	"SJ": {
		"name": "Svalbard and Jan Mayen",
		"native": "Svalbard og Jan Mayen",
		"phone": "4779",
		"continent": "EU",
		"capital": "Longyearbyen",
		"currency": {
			"symbol": "Nkr",
			"name": "Norwegian Krone",
			"symbol_native": "kr",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NOK",
			"name_plural": "Norwegian kroner"
		},
		"languages": "no"
	},
	"SK": {
		"name": "Slovakia",
		"native": "Slovensko",
		"phone": "421",
		"continent": "EU",
		"capital": "Bratislava",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "sk"
	},
	"SL": {
		"name": "Sierra Leone",
		"native": "Sierra Leone",
		"phone": "232",
		"continent": "AF",
		"capital": "Freetown",
		"languages": "en"
	},
	"SM": {
		"name": "San Marino",
		"native": "San Marino",
		"phone": "378",
		"continent": "EU",
		"capital": "City of San Marino",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "it"
	},
	"SN": {
		"name": "Senegal",
		"native": "Sénégal",
		"phone": "221",
		"continent": "AF",
		"capital": "Dakar",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"SO": {
		"name": "Somalia",
		"native": "Soomaaliya",
		"phone": "252",
		"continent": "AF",
		"capital": "Mogadishu",
		"currency": {
			"symbol": "Ssh",
			"name": "Somali Shilling",
			"symbol_native": "Ssh",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "SOS",
			"name_plural": "Somali shillings"
		},
		"languages": "so,ar"
	},
	"SR": {
		"name": "Suriname",
		"native": "Suriname",
		"phone": "597",
		"continent": "SA",
		"capital": "Paramaribo",
		"languages": "nl"
	},
	"SS": {
		"name": "South Sudan",
		"native": "South Sudan",
		"phone": "211",
		"continent": "AF",
		"capital": "Juba",
		"languages": "en"
	},
	"ST": {
		"name": "São Tomé and Príncipe",
		"native": "São Tomé e Príncipe",
		"phone": "239",
		"continent": "AF",
		"capital": "São Tomé",
		"languages": "pt"
	},
	
	"SX": {
		"name": "Sint Maarten",
		"native": "Sint Maarten",
		"phone": "1721",
		"continent": "NA",
		"capital": "Philipsburg",
		"languages": "nl,en"
	},
	"SY": {
		"name": "Syria",
		"native": "سوريا",
		"phone": "963",
		"continent": "AS",
		"capital": "Damascus",
		"currency": {
			"symbol": "SY£",
			"name": "Syrian Pound",
			"symbol_native": "ل.س.‏",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "SYP",
			"name_plural": "Syrian pounds"
		},
		"languages": "ar"
	},
	"SZ": {
		"name": "Swaziland",
		"native": "Swaziland",
		"phone": "268",
		"continent": "AF",
		"capital": "Lobamba",
		"languages": "en,ss"
	},
	"TC": {
		"name": "Turks and Caicos Islands",
		"native": "Turks and Caicos Islands",
		"phone": "1649",
		"continent": "NA",
		"capital": "Cockburn Town",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	"TD": {
		"name": "Chad",
		"native": "Tchad",
		"phone": "235",
		"continent": "AF",
		"capital": "N\'Djamena",
		"currency": {
			"symbol": "FCFA",
			"name": "CFA Franc BEAC",
			"symbol_native": "FCFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XAF",
			"name_plural": "CFA francs BEAC"
		},
		"languages": "fr,ar"
	},
	"TF": {
		"name": "French Southern Territories",
		"native": "Territoire des Terres australes et antarctiques fr",
		"phone": "",
		"continent": "AN",
		"capital": "Port-aux-Français",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"TG": {
		"name": "Togo",
		"native": "Togo",
		"phone": "228",
		"continent": "AF",
		"capital": "Lomé",
		"currency": {
			"symbol": "CFA",
			"name": "CFA Franc BCEAO",
			"symbol_native": "CFA",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "XOF",
			"name_plural": "CFA francs BCEAO"
		},
		"languages": "fr"
	},
	"TH": {
		"name": "Thailand",
		"native": "ประเทศไทย",
		"phone": "66",
		"continent": "AS",
		"capital": "Bangkok",
		"currency": {
			"symbol": "฿",
			"name": "Thai Baht",
			"symbol_native": "฿",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "THB",
			"name_plural": "Thai baht"
		},
		"languages": "th"
	},
	"TJ": {
		"name": "Tajikistan",
		"native": "Тоҷикистон",
		"phone": "992",
		"continent": "AS",
		"capital": "Dushanbe",
		"languages": "tg,ru"
	},
	"TK": {
		"name": "Tokelau",
		"native": "Tokelau",
		"phone": "690",
		"continent": "OC",
		"capital": "Fakaofo",
		"currency": {
			"symbol": "NZ$",
			"name": "New Zealand Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "NZD",
			"name_plural": "New Zealand dollars"
		},
		"languages": "en"
	},
	"TL": {
		"name": "East Timor",
		"native": "Timor-Leste",
		"phone": "670",
		"continent": "OC",
		"capital": "Dili",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "pt"
	},
	"TM": {
		"name": "Turkmenistan",
		"native": "Türkmenistan",
		"phone": "993",
		"continent": "AS",
		"capital": "Ashgabat",
		"languages": "tk,ru"
	},
	"TN": {
		"name": "Tunisia",
		"native": "تونس",
		"phone": "216",
		"continent": "AF",
		"capital": "Tunis",
		"currency": {
			"symbol": "DT",
			"name": "Tunisian Dinar",
			"symbol_native": "د.ت.‏",
			"decimal_digits": 3,
			"rounding": 0,
			"code": "TND",
			"name_plural": "Tunisian dinars"
		},
		"languages": "ar"
	},
	"TO": {
		"name": "Tonga",
		"native": "Tonga",
		"phone": "676",
		"continent": "OC",
		"capital": "Nuku\'alofa",
		"currency": {
			"symbol": "T$",
			"name": "Tongan Paʻanga",
			"symbol_native": "T$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "TOP",
			"name_plural": "Tongan paʻanga"
		},
		"languages": "en,to"
	},
	"TR": {
		"name": "Turkey",
		"native": "Türkiye",
		"phone": "90",
		"continent": "AS",
		"capital": "Ankara",
		"currency": {
			"symbol": "TL",
			"name": "Turkish Lira",
			"symbol_native": "TL",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "TRY",
			"name_plural": "Turkish Lira"
		},
		"languages": "tr"
	},
	"TT": {
		"name": "Trinidad and Tobago",
		"native": "Trinidad and Tobago",
		"phone": "1868",
		"continent": "NA",
		"capital": "Port of Spain",
		"currency": {
			"symbol": "TT$",
			"name": "Trinidad and Tobago Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "TTD",
			"name_plural": "Trinidad and Tobago dollars"
		},
		"languages": "en"
	},
	"TV": {
		"name": "Tuvalu",
		"native": "Tuvalu",
		"phone": "688",
		"continent": "OC",
		"capital": "Funafuti",
		"currency": {
			"symbol": "AU$",
			"name": "Australian Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "AUD",
			"name_plural": "Australian dollars"
		},
		"languages": "en"
	},
	"TW": {
		"name": "Taiwan",
		"native": "臺灣",
		"phone": "886",
		"continent": "AS",
		"capital": "Taipei",
		"currency": {
			"symbol": "NT$",
			"name": "New Taiwan Dollar",
			"symbol_native": "NT$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "TWD",
			"name_plural": "New Taiwan dollars"
		},
		"languages": "zh"
	},
	"TZ": {
		"name": "Tanzania",
		"native": "Tanzania",
		"phone": "255",
		"continent": "AF",
		"capital": "Dodoma",
		"currency": {
			"symbol": "TSh",
			"name": "Tanzanian Shilling",
			"symbol_native": "TSh",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "TZS",
			"name_plural": "Tanzanian shillings"
		},
		"languages": "sw,en"
	},
	"UA": {
		"name": "Ukraine",
		"native": "Україна",
		"phone": "380",
		"continent": "EU",
		"capital": "Kiev",
		"currency": {
			"symbol": "₴",
			"name": "Ukrainian Hryvnia",
			"symbol_native": "₴",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "UAH",
			"name_plural": "Ukrainian hryvnias"
		},
		"languages": "uk"
	},
	"UG": {
		"name": "Uganda",
		"native": "Uganda",
		"phone": "256",
		"continent": "AF",
		"capital": "Kampala",
		"currency": {
			"symbol": "USh",
			"name": "Ugandan Shilling",
			"symbol_native": "USh",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "UGX",
			"name_plural": "Ugandan shillings"
		},
		"languages": "en,sw"
	},
	"UM": {
		"name": "U.S. Minor Outlying Islands",
		"native": "United States Minor Outlying Islands",
		"phone": "",
		"continent": "OC",
		"capital": "",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},	
	"UZ": {
		"name": "Uzbekistan",
		"native": "O‘zbekiston",
		"phone": "998",
		"continent": "AS",
		"capital": "Tashkent",
		"currency": {
			"symbol": "UZS",
			"name": "Uzbekistan Som",
			"symbol_native": "UZS",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "UZS",
			"name_plural": "Uzbekistan som"
		},
		"languages": "uz,ru"
	},
	"VA": {
		"name": "Vatican City",
		"native": "Vaticano",
		"phone": "39066,379",
		"continent": "EU",
		"capital": "Vatican City",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "it,la"
	},
	"VC": {
		"name": "Saint Vincent and the Grenadines",
		"native": "Saint Vincent and the Grenadines",
		"phone": "1784",
		"continent": "NA",
		"capital": "Kingstown",
		"languages": "en"
	},
	
	"VG": {
		"name": "British Virgin Islands",
		"native": "British Virgin Islands",
		"phone": "1284",
		"continent": "NA",
		"capital": "Road Town",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	"VI": {
		"name": "U.S. Virgin Islands",
		"native": "United States Virgin Islands",
		"phone": "1340",
		"continent": "NA",
		"capital": "Charlotte Amalie",
		"currency": {
			"symbol": "$",
			"name": "US Dollar",
			"symbol_native": "$",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "USD",
			"name_plural": "US dollars"
		},
		"languages": "en"
	},
	"VN": {
		"name": "Vietnam",
		"native": "Việt Nam",
		"phone": "84",
		"continent": "AS",
		"capital": "Hanoi",
		"currency": {
			"symbol": "₫",
			"name": "Vietnamese Dong",
			"symbol_native": "₫",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "VND",
			"name_plural": "Vietnamese dong"
		},
		"languages": "vi"
	},
	"VU": {
		"name": "Vanuatu",
		"native": "Vanuatu",
		"phone": "678",
		"continent": "OC",
		"capital": "Port Vila",
		"languages": "bi,en,fr"
	},
	"WF": {
		"name": "Wallis and Futuna",
		"native": "Wallis et Futuna",
		"phone": "681",
		"continent": "OC",
		"capital": "Mata-Utu",
		"languages": "fr"
	},
	"WS": {
		"name": "Samoa",
		"native": "Samoa",
		"phone": "685",
		"continent": "OC",
		"capital": "Apia",
		"languages": "sm,en"
	},
	"XK": {
		"name": "Kosovo",
		"native": "Republika e Kosovës",
		"phone": "377,381,386",
		"continent": "EU",
		"capital": "Pristina",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "sq,sr"
	},
	"YE": {
		"name": "Yemen",
		"native": "اليَمَن",
		"phone": "967",
		"continent": "AS",
		"capital": "Sana\'a",
		"currency": {
			"symbol": "YR",
			"name": "Yemeni Rial",
			"symbol_native": "ر.ي.‏",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "YER",
			"name_plural": "Yemeni rials"
		},
		"languages": "ar"
	},
	"YT": {
		"name": "Mayotte",
		"native": "Mayotte",
		"phone": "262",
		"continent": "AF",
		"capital": "Mamoudzou",
		"currency": {
			"symbol": "€",
			"name": "Euro",
			"symbol_native": "€",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "EUR",
			"name_plural": "euros"
		},
		"languages": "fr"
	},
	"ZA": {
		"name": "South Africa",
		"native": "South Africa",
		"phone": "27",
		"continent": "AF",
		"capital": "Pretoria",
		"currency": {
			"symbol": "R",
			"name": "South African Rand",
			"symbol_native": "R",
			"decimal_digits": 2,
			"rounding": 0,
			"code": "ZAR",
			"name_plural": "South African rand"
		},
		"languages": "af,en,nr,st,ss,tn,ts,ve,xh,zu"
	},
	"ZM": {
		"name": "Zambia",
		"native": "Zambia",
		"phone": "260",
		"continent": "AF",
		"capital": "Lusaka",
		"currency": {
			"symbol": "ZK",
			"name": "Zambian Kwacha",
			"symbol_native": "ZK",
			"decimal_digits": 0,
			"rounding": 0,
			"code": "ZMK",
			"name_plural": "Zambian kwachas"
		},
		"languages": "en"
	},
	"ZW": {
		"name": "Zimbabwe",
		"native": "Zimbabwe",
		"phone": "263",
		"continent": "AF",
		"capital": "Harare",
		"languages": "en,sn,nd"
	}
}', true);
*/

function unescape($str){
   return json_encode(json_decode($str), JSON_UNESCAPED_UNICODE);
}

$countriesHispanic   = unescape($countriesHispanic);
$countries           = unescape($countries);

if(isset($_GET['format']) && $_GET['format'] == 'json'){
   
   http_response_code(200);
   header('Content-Type: application/json; charset=utf-8');
   if(isset($_GET['hispanic'])){      
      die($countriesHispanic);
   }else{
      die($countries);
   }
}

?>