/**
 * Textos legales del sitio (Términos y Condiciones, Política de Privacidad,
 * Política de Reembolsos).
 *
 * Son CONTENIDO ESTRUCTURADO, no JSX: la misma fuente alimenta a las páginas
 * públicas (`/terminos`, `/privacidad`, `/reembolsos`) y al exportador que genera
 * `content/sitio/*.md` para el agente de atención. Si se edita una cláusula,
 * se edita acá y las dos superficies quedan sincronizadas.
 *
 * Los textos usan los tokens de mercado de `markets.ts` ({docFiscal},
 * {organismo}) y NUNCA literales de un país. Precios y cantidades incluidas
 * NO se escriben acá: el contrato remite a la página de precios, que es la
 * lista vigente. Así sumar o cambiar un plan no obliga a tocar los legales.
 */

import { getMarket } from "@/lib/site/markets"

export type SeccionLegal = {
  titulo: string
  parrafos: string[]
  lista?: string[]
  tabla?: { headers: string[]; filas: string[][] }
}

export type DocumentoLegal = {
  titulo: string
  /** Ruta pública del documento. */
  url: string
  /** Fecha de vigencia / última actualización, en texto. */
  actualizado: string
  /** Bajada que abre el documento, antes del índice. */
  intro: string
  secciones: SeccionLegal[]
}

const market = getMarket()

/** Identificación de la empresa que presta el servicio. */
export const EMPRESA = {
  razonSocial: "Brixton Capital S.A.",
  documento: "80164242-6",
  domicilio: market.contacto.direccion,
  telefono: market.contacto.telefono,
  /** Canal único de contacto: soporte, legales, privacidad y facturación. */
  email: "info@punto.la",
  sitio: "punto.la",
  app: "app.punto.la",
  vigencia: "3 de septiembre de 2026",
} as const

/**
 * Ruta de la política de reembolsos. Vive suelta porque los Términos la
 * citan y se declaran antes que el documento.
 */
const REEMBOLSOS_URL = "/reembolsos"

/** Ancla estable de una sección, para el índice del documento. */
export function seccionId(titulo: string): string {
  return titulo
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "") // marcas diacríticas sueltas tras NFD
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
}

/* ------------------------------------------------------------------ */
/* Términos y Condiciones                                              */
/* ------------------------------------------------------------------ */

export const TERMINOS: DocumentoLegal = {
  titulo: "Términos y Condiciones",
  url: "/terminos",
  actualizado: EMPRESA.vigencia,
  intro:
    `Estos términos regulan el uso de Punto, el sistema de punto de venta y gestión que ofrecemos en ${EMPRESA.sitio} y ${EMPRESA.app}. ` +
    "Al crear una cuenta o usar el servicio, el comercio acepta lo que sigue. Está escrito para que lo entienda quien lleva el negocio, no solo un abogado.",
  secciones: [
    {
      titulo: "Quiénes somos",
      parrafos: [
        `El servicio lo presta ${EMPRESA.razonSocial}, con {docFiscal} ${EMPRESA.documento} y domicilio en ${EMPRESA.domicilio}.`,
        `${EMPRESA.sitio} es el sitio público donde contamos qué hace Punto y cuánto cuesta. ${EMPRESA.app} es la aplicación: el punto de venta, el panel de administración y todo lo que el comercio opera con su cuenta.`,
        `Tenemos un solo canal de contacto para todo — soporte, facturación, temas legales y privacidad: ${EMPRESA.email}. También atendemos por WhatsApp al ${EMPRESA.telefono}.`,
      ],
    },
    {
      titulo: "Qué es Punto y qué no es",
      parrafos: [
        "Punto es un software que se usa por internet (no se instala ni se compra una licencia perpetua). Sirve para vender y cobrar, emitir comprobantes, controlar stock, administrar clientes y ver los números del negocio.",
        "Punto es una herramienta: ordena y muestra la información que el comercio carga y las operaciones que registra. No garantizamos resultados comerciales — ni volumen de ventas, ni margen, ni crecimiento.",
        "Punto no es asesoría contable, tributaria ni legal. Los reportes, libros y comprobantes que genera son insumos para el contador del comercio, no un reemplazo de su criterio profesional ni de las obligaciones que el comercio tenga frente a {organismo}.",
      ],
    },
    {
      titulo: "Cuenta, alta y responsabilidad de las credenciales",
      parrafos: [
        "El alta de la cuenta se hace con un número de teléfono, que se verifica con un código de un solo uso enviado por WhatsApp. Ese teléfono identifica al titular de la cuenta.",
        "El titular puede crear los usuarios que necesite y asignarles roles y permisos. Cada usuario opera con su propio acceso, y las cajas se desbloquean con un PIN personal.",
        "El titular es responsable de lo que hagan los usuarios que creó: qué permisos les dio, qué operaciones registraron y qué datos cargaron. Nosotros no podemos distinguir a un usuario legítimo de alguien que consiguió sus credenciales.",
      ],
      lista: [
        "Mantené el teléfono del titular bajo control: con él se recupera el acceso a toda la cuenta.",
        "No compartas usuarios ni PIN entre personas — el registro de auditoría deja de servir para nada.",
        "Dá de baja al usuario cuando alguien se va del equipo, el mismo día.",
        "Avisanos apenas sospeches un acceso no autorizado.",
      ],
    },
    {
      titulo: "Plan, precio y forma de pago",
      parrafos: [
        `El comercio contrata el plan que elija entre los publicados en ${EMPRESA.sitio}/precios. Ahí figuran, siempre vigentes, el precio de cada uno, sobre qué se cobra y qué incluye. Se factura en ${market.moneda.codigo}.`,
        "El ciclo es mensual y se renueva automáticamente mientras la cuenta esté activa. Cada renovación se cobra por adelantado, al inicio del período.",
        "El cobro se procesa a través de un procesador de pagos externo, con los medios que estén habilitados en cada momento. Los datos completos de la tarjeta se ingresan en el entorno del procesador: nosotros no los vemos ni los guardamos.",
        "Los precios que publicamos incluyen los impuestos aplicables: lo que se ve es lo que se paga. Por cada cobro emitimos el comprobante fiscal a nombre de los datos que el comercio tenga cargados en su cuenta.",
      ],
    },
    {
      titulo: "Créditos de IA",
      parrafos: [
        `El plan contratado incluye una cantidad de créditos de inteligencia artificial por mes, publicada en ${EMPRESA.sitio}/precios. Cubren el uso de Punto AI: preguntar por los números del negocio, pedir un reporte, leer una factura de compra con la cámara.`,
        "Los créditos se renuevan al inicio de cada ciclo mensual y no se acumulan: lo que no se usa en el mes se pierde.",
        "Cuando se agotan, el resto del sistema sigue funcionando con normalidad — solo dejan de estar disponibles las funciones de IA hasta la renovación. Si el comercio necesita más, puede comprar créditos adicionales.",
      ],
    },
    {
      titulo: "Facturación electrónica",
      parrafos: [
        "Punto emite los documentos electrónicos del comercio y los transmite a {organismo} a través de un proveedor habilitado. La emisión es ilimitada: no cobramos por comprobante.",
        "El timbrado, los puntos de expedición, los datos fiscales de la empresa y el cumplimiento de las obligaciones tributarias son responsabilidad del comercio. Punto no es agente fiscal ni representante del comercio ante la autoridad tributaria.",
        "No respondemos por rechazos, observaciones o multas originados en datos cargados por el comercio (timbrado vencido, {docFiscal} del cliente incorrecto, tasas mal configuradas), ni por interrupciones del servicio de la autoridad tributaria o del proveedor de transmisión.",
      ],
    },
    {
      titulo: "Responsabilidad del comercio por el uso legal del sistema",
      parrafos: [
        "Punto pone la herramienta; lo que se hace con ella lo decide el comercio. La información que se carga, los comprobantes que se emiten, las operaciones que se registran y las que se dejan de registrar son actos del comercio y de las personas a las que le dio acceso.",
        `${EMPRESA.razonSocial} no audita, no supervisa y no valida el contenido que el comercio carga, y no responde por el uso que se le dé al sistema en infracción de la ley. Esto incluye, sin limitarse a ello, la evasión o elusión de tributos, la omisión o adulteración de operaciones, la emisión de comprobantes que no respalden operaciones reales, el lavado de activos, y el incumplimiento de la normativa laboral, de defensa del consumidor o de protección de datos personales vigente en el país donde el comercio opera.`,
        `Si una autoridad o un tercero le reclama a ${EMPRESA.razonSocial} por un hecho de esa clase atribuible al comercio, el comercio nos mantiene indemnes: asume su propia defensa y responde por los costos, multas o condenas que se deriven.`,
        "Detectar un uso de este tipo nos habilita a suspender o dar de baja la cuenta de inmediato y sin reembolso, y a responder los requerimientos que nos haga una autoridad competente en el marco de sus facultades.",
      ],
    },
    {
      titulo: "Cancelación, mora y baja",
      parrafos: [
        `No hay contrato de permanencia ni cargo por cancelar. El comercio da de baja la cuenta cuando quiere, escribiéndonos a ${EMPRESA.email}. La baja se hace efectiva al final del ciclo ya pagado: hasta esa fecha el servicio sigue funcionando completo y después no se renueva.`,
        "Si un cobro falla, lo reintentamos durante los 7 días corridos siguientes y avisamos por los canales de contacto de la cuenta. Pasados esos 7 días sin regularizar, la cuenta se suspende: no se puede vender ni emitir, y el panel queda en modo de solo lectura para que el comercio consulte y exporte su información.",
        `Tras la baja, el comercio tiene 30 días corridos para exportar sus datos. Vencido ese plazo eliminamos la información operativa de la cuenta, salvo lo que la normativa fiscal nos obliga a conservar. Para coordinar una exportación escribinos a ${EMPRESA.email}.`,
      ],
    },
    {
      titulo: "Reembolsos",
      parrafos: [
        "La suscripción se paga por adelantado y no se devuelve la parte no usada del mes en curso: el servicio queda disponible hasta que termine el ciclo pagado.",
        "Tampoco se devuelve una vez iniciada la puesta en marcha de la cuenta —configuración, alta de sucursales y usuarios, importación de datos, acompañamiento—, porque ese trabajo consume recursos desde el primer día y no se recupera.",
        "Sí devolvemos el dinero cuando el cobro no correspondía —un cobro duplicado, un error de facturación nuestro, un cobro posterior a una baja ya efectiva— y cuando una falla del servicio atribuible a Punto impide operar y no la corregimos en un plazo razonable.",
        `El detalle completo —qué cubre, cómo se pide, en cuánto se responde y en cuánto se acredita— está en la Política de Reembolsos, en ${EMPRESA.sitio}${REEMBOLSOS_URL}. Esa política forma parte de estos términos.`,
      ],
    },
    {
      titulo: "Propiedad de los datos",
      parrafos: [
        "Los datos que el comercio carga y genera en Punto son del comercio: su catálogo, sus clientes, sus ventas, sus comprobantes, su stock.",
        "Nosotros los tratamos únicamente para prestar el servicio, darle soporte y cumplir obligaciones legales. No los vendemos, no los cedemos a terceros con fines comerciales y no los usamos para publicidad.",
        "El comercio puede exportar su información cuando quiera desde el panel, y pedirnos una exportación asistida si necesita un formato distinto.",
      ],
    },
    {
      titulo: "Uso aceptable",
      parrafos: [
        "Punto se usa para operar un negocio legítimo. Al usarlo, el comercio se compromete a no hacer nada de lo siguiente:",
      ],
      lista: [
        "Registrar operaciones de actividades ilegales o usar el sistema para simular operaciones inexistentes.",
        "Ocultar, suprimir o alterar operaciones ya registradas para declarar menos de lo que corresponde, o llevar registros paralelos con ese fin.",
        "Emitir comprobantes a nombre de terceros sin autorización, o usar timbrados que no le pertenezcan.",
        "Enviar mensajes masivos no solicitados desde los canales del sistema.",
        "Intentar eludir límites técnicos, acceder a datos de otros comercios, o hacer ingeniería inversa del software.",
        "Revender, sublicenciar o dar acceso al sistema a terceros como si fuera un servicio propio.",
        "Compartir credenciales entre personas o entre comercios distintos.",
      ],
    },
    {
      titulo: "Disponibilidad, mantenimiento y modo offline",
      parrafos: [
        "Trabajamos para que el servicio esté disponible todo el tiempo, pero ningún sistema lo está al 100%. Puede haber interrupciones por mantenimiento, por fallas de nuestros proveedores de infraestructura o por causas fuera de nuestro control.",
        "Las ventanas de mantenimiento programado se avisan con al menos 48 horas de anticipación y se hacen de madrugada, en el horario de menor actividad. Las incidencias no programadas se comunican por los canales de soporte mientras se están resolviendo.",
        "El punto de venta funciona sin internet: la venta se emite igual y queda guardada en el dispositivo. Lo que necesita conexión es la sincronización — enviar la operación a la nube, transmitir el documento electrónico y compartir el estado con otras cajas. Mientras no haya conexión, esas partes quedan pendientes y se resuelven solas al volver.",
        "No ofrecemos un acuerdo de nivel de servicio (SLA) con compromisos de disponibilidad medidos, salvo que se firme un contrato corporativo específico que lo incluya.",
      ],
    },
    {
      titulo: "Propiedad intelectual",
      parrafos: [
        `El software, el diseño, la documentación, la marca Punto y todo lo que compone el servicio son propiedad de ${EMPRESA.razonSocial} o de quienes nos licenciaron esos elementos.`,
        "Mientras la cuenta esté activa y al día, el comercio recibe una licencia de uso no exclusiva, no transferible y revocable, limitada a operar su propio negocio.",
        "Esa licencia no incluye derecho a copiar el software, derivar productos de él, usar la marca sin autorización escrita, ni ofrecerlo a terceros.",
      ],
    },
    {
      titulo: "Cambios de estos términos y del precio",
      parrafos: [
        "Podemos actualizar estos términos: cambia el producto, cambian las normas, aparecen situaciones que el texto no contemplaba. Los cambios se avisan con al menos 15 días de anticipación por los canales de contacto de la cuenta y publicando la nueva versión con su fecha de vigencia.",
        "Los cambios de precio se avisan con al menos 30 días de anticipación y nunca se aplican de forma retroactiva al ciclo en curso.",
        "Si el comercio no está de acuerdo con un cambio, puede dar de baja la cuenta sin penalidad antes de que entre en vigencia. Seguir usando el servicio después de esa fecha implica aceptar la nueva versión.",
      ],
    },
    {
      titulo: "Soporte",
      parrafos: [
        `El soporte online funciona 24/7 por WhatsApp al ${EMPRESA.telefono}, por el chat del sitio y por ${EMPRESA.email}.`,
        "Cubre el uso del sistema: cómo hacer algo, revisar una configuración, entender un comportamiento, resolver un problema técnico de la plataforma.",
        "No cubre la operación del negocio del comercio: no cargamos su catálogo por él en el día a día, no registramos sus ventas ni tomamos decisiones contables o fiscales por él. La puesta en marcha inicial sí está acompañada.",
      ],
    },
    {
      titulo: "Limitación de responsabilidad",
      parrafos: [
        'El servicio se presta "tal como está" y "según disponibilidad". Hacemos nuestro mejor esfuerzo, y aun así el software puede tener errores.',
        "En la medida que lo permita la ley, no respondemos por daños indirectos, lucro cesante, pérdida de oportunidades comerciales, ni por pérdida de datos que el comercio pudo haber exportado y no exportó.",
        "Nuestra responsabilidad total acumulada por cualquier reclamo relacionado con el servicio no supera el monto que el comercio nos haya pagado en los 12 meses anteriores al hecho que originó el reclamo.",
        "Tampoco respondemos por las consecuencias del uso que el comercio le dé al sistema en infracción de la ley, según lo previsto en la sección sobre responsabilidad del comercio por el uso legal del sistema.",
        "Nada de lo anterior limita responsabilidades que la ley declare no renunciables.",
      ],
    },
    {
      titulo: "Ley aplicable y jurisdicción",
      parrafos: [
        "Estos términos se rigen por las leyes de la República del Paraguay.",
        "Cualquier controversia se somete a los tribunales ordinarios de la ciudad de Asunción, con renuncia a cualquier otro fuero. Antes de llegar ahí, preferimos hablarlo: escribinos y buscamos una solución.",
      ],
    },
    {
      titulo: "Contacto",
      parrafos: [
        `${EMPRESA.razonSocial} — {docFiscal} ${EMPRESA.documento}`,
        EMPRESA.domicilio,
        `${EMPRESA.email} · ${EMPRESA.telefono}`,
      ],
    },
  ],
}

/* ------------------------------------------------------------------ */
/* Política de Privacidad                                              */
/* ------------------------------------------------------------------ */

export const PRIVACIDAD: DocumentoLegal = {
  titulo: "Política de Privacidad",
  url: "/privacidad",
  actualizado: EMPRESA.vigencia,
  intro:
    "Esta política explica qué datos personales tratamos cuando un comercio usa Punto, para qué los usamos, con quién los compartimos y qué puede hacer cada persona con los suyos. " +
    "Está escrita en criollo a propósito: una política que nadie entiende no protege a nadie.",
  secciones: [
    {
      titulo: "Quién es responsable",
      parrafos: [
        `${EMPRESA.razonSocial}, con {docFiscal} ${EMPRESA.documento} y domicilio en ${EMPRESA.domicilio}, es quien presta el servicio Punto y quien responde por el tratamiento descripto en esta política.`,
        `Para cualquier tema de privacidad o para ejercer derechos sobre tus datos, escribinos a ${EMPRESA.email}.`,
      ],
    },
    {
      titulo:
        "Los dos roles: cuándo decidimos nosotros y cuándo decide el comercio",
      parrafos: [
        "Esta es la sección más importante de todo el documento, porque define quién responde por qué.",
        "Respecto de los datos de la CUENTA del comercio — el teléfono del titular, el nombre, el email, el {docFiscal}, los usuarios del equipo, los datos de facturación — nosotros somos los responsables: decidimos para qué se usan y respondemos por su tratamiento.",
        "Respecto de los datos que el comercio CARGA sobre sus propios clientes — nombres, teléfonos, direcciones, historial de compras, cuentas corrientes — el responsable es el comercio. Él decide qué carga, para qué y con qué base legal. Nosotros actuamos como encargados del tratamiento: solo procesamos esos datos siguiendo sus instrucciones y para que el sistema funcione.",
        "En criollo: si sos cliente de un comercio que usa Punto y querés saber por qué tienen tu teléfono, el que te tiene que responder es ese comercio. Nosotros lo asistimos técnicamente, pero no decidimos qué datos suyos guarda.",
      ],
    },
    {
      titulo: "Qué datos tratamos",
      parrafos: ["Agrupados por origen, esto es lo que pasa por el sistema:"],
      lista: [
        "De la cuenta: teléfono del titular, nombre, email, {docFiscal} y razón social de la empresa, sucursales, cajas, usuarios del equipo con sus roles y permisos.",
        "De la operación: ventas, comprobantes emitidos, compras, movimientos de stock, cajas abiertas y cerradas, órdenes, y los contactos que el comercio carga sobre sus propios clientes y proveedores.",
        "Técnicos: dirección IP, tipo de dispositivo y navegador, sesiones activas, registros de actividad y auditoría (quién hizo qué y cuándo), y registros de error para diagnosticar fallas.",
        "De facturación: plan contratado, historial de cobros y su estado, comprobantes que emitimos al comercio. Los datos completos de la tarjeta quedan en el procesador de pagos, no en nuestros sistemas.",
      ],
    },
    {
      titulo: "Para qué los usamos",
      parrafos: [
        "Tratamos los datos únicamente para operar el servicio que el comercio contrató:",
      ],
      lista: [
        "Prestar el servicio: que la caja venda, que el panel muestre los números, que el stock se descuente.",
        "Autenticar: verificar el teléfono en el alta, validar el PIN de caja, mantener las sesiones y detectar accesos indebidos.",
        "Cobrar la suscripción y los créditos adicionales, y emitir los comprobantes correspondientes.",
        "Emitir y transmitir los documentos electrónicos del comercio a {organismo}.",
        "Dar soporte: entender qué pasó cuando algo falla, y responder consultas.",
        "Cumplir obligaciones legales, contables y fiscales, y responder requerimientos de autoridad competente.",
      ],
    },
    {
      titulo: "No vendemos datos ni hacemos publicidad con ellos",
      parrafos: [
        "No vendemos, alquilamos ni cedemos datos personales a terceros con fines comerciales.",
        "No usamos los datos del comercio ni los de sus clientes para publicidad de terceros, ni construimos perfiles publicitarios. El sitio no tiene píxeles de redes sociales ni herramientas de analítica publicitaria.",
        "No usamos los datos de un comercio para beneficiar a otro. Cada cuenta está aislada de las demás.",
      ],
    },
    {
      titulo: "Inteligencia artificial",
      parrafos: [
        "Punto AI, el asistente del sistema, y la lectura automática de facturas de compra funcionan con modelos de lenguaje que corremos a través de un proveedor externo que enruta cada consulta al modelo elegido.",
        "Qué se envía: la pregunta que escribe el usuario, más los datos del negocio que hacen falta para responderla (por ejemplo, el resumen de ventas del período consultado). En la lectura de facturas se envía la foto o el archivo del comprobante.",
        "Qué no se envía: nada más que eso. El asistente no vuelca la base de datos del comercio al modelo, y lo que puede leer o modificar está limitado por los permisos del usuario que lo está usando.",
        "Ese proveedor y los modelos que enruta actúan como subencargados, bajo compromisos contractuales de confidencialidad. Los datos enviados no se usan para entrenar modelos de terceros.",
      ],
    },
    {
      titulo: "Con quién compartimos",
      parrafos: [
        "Compartimos datos solo con los proveedores necesarios para que el servicio funcione, y solo lo mínimo que cada uno necesita. Todos actúan como subencargados del tratamiento, bajo compromisos contractuales de confidencialidad y seguridad.",
        "Los listamos por categoría y no por nombre porque un proveedor puede cambiar sin que cambie el tratamiento. La lista actualizada de los proveedores concretos que usamos en cada categoría se entrega a pedido: escribinos a " +
          EMPRESA.email +
          ".",
      ],
      tabla: {
        headers: ["Categoría de proveedor", "Para qué"],
        filas: [
          [
            "Procesamiento de pagos",
            "Cobro de la suscripción y de los packs de créditos de IA, y devolución de los reembolsos que correspondan.",
          ],
          [
            "Infraestructura y almacenamiento en la nube",
            "Servidores donde corre el sistema y guardado de los archivos que el comercio sube (fotos de productos, adjuntos).",
          ],
          [
            "Facturación electrónica",
            "Proveedor habilitado que transmite los documentos electrónicos a {organismo}.",
          ],
          [
            "Modelos de inteligencia artificial",
            "Generación de las respuestas del asistente y lectura automática de las facturas de compra.",
          ],
          [
            "Envío de email y SMS",
            "Notificaciones y comunicaciones transaccionales del sistema.",
          ],
          [
            "Mensajería instantánea",
            "Envío del código de verificación en el alta de la cuenta.",
          ],
          [
            "Chat de atención al cliente",
            `Atención embebida en ${EMPRESA.sitio}, operada por ${EMPRESA.razonSocial}`,
          ],
          [
            "Pasarelas de cobro del comercio",
            "Medios de pago que el comercio habilita para cobrarle a sus clientes desde la caja. No son cobros de Punto: los datos van directo del cliente a la pasarela.",
          ],
        ],
      },
    },
    {
      titulo: "Cookies y tecnologías similares",
      parrafos: [
        "En la aplicación usamos cookies y almacenamiento local estrictamente necesarios: mantener la sesión iniciada, recordar el dispositivo de caja emparejado, guardar las preferencias de la interfaz y permitir que el punto de venta funcione sin conexión.",
        `En el sitio ${EMPRESA.sitio} el único script de terceros es el del chat de atención, que guarda un identificador de conversación para que no se pierda el hilo si recargás la página.`,
        "No usamos cookies publicitarias, de perfilado de terceros ni de redes sociales. No hay píxeles de seguimiento.",
        "Podés bloquear o borrar cookies desde la configuración de tu navegador. Si bloqueás las de la aplicación, no vas a poder iniciar sesión ni operar la caja: son las que sostienen el acceso.",
      ],
    },
    {
      titulo: "Transferencias internacionales",
      parrafos: [
        "Los servidores donde corre Punto y varios de los proveedores listados arriba están fuera de Paraguay. Eso significa que los datos se transfieren y se procesan en otros países.",
        "Esas transferencias se hacen bajo compromisos contractuales de confidencialidad y seguridad con cada proveedor, limitados a las finalidades descriptas en esta política.",
      ],
    },
    {
      titulo: "Cuánto tiempo conservamos los datos",
      parrafos: [
        "Mientras la cuenta esté activa, conservamos los datos para que el comercio pueda operar y consultar su historial.",
        "Tras la baja, hay 30 días corridos para exportar la información. Vencido ese plazo eliminamos los datos operativos de la cuenta.",
        "Hay una excepción: los documentos electrónicos emitidos y los respaldos contables se conservan por el plazo que exige la normativa fiscal, aunque la cuenta se haya dado de baja. No podemos borrarlos antes.",
        "Los registros técnicos de error se conservan 90 días, los necesarios para diagnosticar una falla. El registro de auditoría de operaciones acompaña a la cuenta mientras esté activa: es parte del historial del negocio.",
      ],
    },
    {
      titulo: "Seguridad",
      parrafos: [
        "Aplicamos medidas técnicas y organizativas razonables para proteger la información:",
      ],
      lista: [
        "Cifrado del tráfico en tránsito (HTTPS) en todo el sistema.",
        "Contraseñas y PIN almacenados con funciones de hash, nunca en texto plano.",
        "Aislamiento por comercio: cada cuenta opera sobre su propio espacio de datos y las consultas están acotadas a él.",
        "Control de acceso granular por permisos y roles, definidos por el titular de la cuenta.",
        "Registro de auditoría de las operaciones sensibles, con identificación del usuario que las hizo.",
        "Acceso restringido de nuestro equipo, limitado a lo necesario para soporte y operación.",
      ],
      // Sin prometer infalibilidad: ningún sistema lo es.
    },
    {
      titulo: "Notificación de incidentes",
      parrafos: [
        "Ninguna medida de seguridad es infalible. Si ocurre una brecha que afecte datos personales, la tratamos como incidente prioritario.",
        "Contenemos el incidente, evaluamos el alcance y notificamos a los comercios afectados dentro de las 72 horas de confirmado qué datos se vieron involucrados, junto con lo que recomendamos hacer. Si la normativa lo exige, notificamos también a la autoridad competente.",
        "Cuando el comercio sea el responsable de los datos afectados (los de sus propios clientes), le damos la información que necesite para cumplir con sus propias obligaciones de notificación.",
      ],
    },
    {
      titulo: "Tus derechos",
      parrafos: [
        "Toda persona cuyos datos tratamos puede pedirnos acceder a ellos, rectificarlos, actualizarlos, solicitar su supresión, pedir una copia en formato portable u oponerse a determinados tratamientos.",
        `Para ejercerlos, escribí a ${EMPRESA.email} indicando qué querés hacer. Para proteger tus datos de un tercero que se haga pasar por vos, vamos a pedirte que verifiques tu identidad — normalmente confirmando el control del teléfono o del email asociados.`,
        "Respondemos dentro de los 15 días hábiles de recibido el pedido verificado. Si el caso requiere más tiempo, te avisamos por qué y en cuánto lo resolvemos.",
        "Si el pedido es sobre datos que un comercio cargó sobre vos como su cliente, te vamos a derivar a ese comercio, que es el responsable — y lo asistimos para que pueda responderte.",
      ],
    },
    {
      titulo: "Datos de los clientes del comercio",
      parrafos: [
        "Cuando un comercio carga en Punto los datos de sus clientes, sigue siendo él el responsable frente a esas personas.",
        "Eso implica que el comercio debe tener una base legal para tratar esos datos, informar a sus clientes qué hace con ellos, y responder los pedidos de acceso, rectificación o supresión que reciba.",
        "Nosotros lo asistimos técnicamente: le damos las herramientas para buscar, editar, exportar y eliminar la información de un contacto, y respondemos sus consultas sobre cómo hacerlo.",
      ],
    },
    {
      titulo: "Menores de edad",
      parrafos: [
        "Punto es un servicio para comercios y sus equipos de trabajo. No está dirigido a menores de edad ni recolectamos datos de menores a sabiendas.",
        `Si detectamos que se cargaron datos de un menor sin base legal, o si nos lo informan a ${EMPRESA.email}, los eliminamos dentro de los 5 días hábiles.`,
      ],
    },
    {
      titulo: "Cambios a esta política",
      parrafos: [
        "Si cambia lo que hacemos con los datos, actualizamos esta política y publicamos la nueva versión con su fecha de vigencia.",
        "Los cambios relevantes se avisan además por los canales de contacto de la cuenta, con al menos 15 días de anticipación.",
      ],
    },
    {
      titulo: "Contacto",
      parrafos: [
        `${EMPRESA.razonSocial} — {docFiscal} ${EMPRESA.documento}`,
        EMPRESA.domicilio,
        `${EMPRESA.email} · ${EMPRESA.telefono}`,
      ],
    },
  ],
}

/* ------------------------------------------------------------------ */
/* Política de Reembolsos                                              */
/* ------------------------------------------------------------------ */

export const REEMBOLSOS: DocumentoLegal = {
  titulo: "Política de Reembolsos",
  url: REEMBOLSOS_URL,
  actualizado: EMPRESA.vigencia,
  intro:
    "Esta política dice cuándo devolvemos el dinero y cuándo no, en qué plazo y cómo se pide. " +
    "Forma parte de los Términos y Condiciones de Punto.",
  secciones: [
    {
      titulo: "Qué cubre esta política",
      parrafos: [
        "Punto le cobra al comercio dos cosas, y ninguna más: la suscripción al plan contratado y los packs de créditos de IA que compre aparte cuando quiere más de los incluidos en ese plan.",
        "No cobramos por comprobante emitido, por usuario, por producto ni por transacción. No hay costo de instalación, de puesta en marcha ni de baja. Si alguna vez ves un cargo distinto de esos dos conceptos, escribinos: es un error y lo devolvemos.",
        "Esta política aplica a los cobros que hace Punto. No aplica a los cobros que el comercio le hace a sus propios clientes desde la caja: esos son entre el comercio y su cliente, y la devolución la resuelve el comercio con sus propias reglas.",
      ],
    },
    {
      titulo: "Regla general: el mes empezado no se devuelve",
      parrafos: [
        "La suscripción se paga por adelantado al inicio de cada ciclo mensual. Al dar de baja no devolvemos la parte no usada del mes en curso.",
        "Lo que sí garantizamos es que ese mes se presta completo: el servicio queda disponible con todas sus funciones hasta el último día del ciclo pagado, y después no se renueva ni se vuelve a cobrar.",
        "No hay contrato de permanencia, ni cargo por cancelar, ni monto mínimo. Dar de baja es escribir un mensaje.",
      ],
    },
    {
      titulo: "La puesta en marcha, una vez iniciada, no se devuelve",
      parrafos: [
        "Contratar Punto no es descargar un archivo: apenas se confirma el pago empieza un trabajo concreto sobre la cuenta. Crearla y configurarla, dar de alta sucursales, cajas y usuarios con sus permisos, importar el catálogo y los clientes, dejar operativa la facturación electrónica, y acompañar al equipo del comercio en la puesta en marcha.",
        "Ese trabajo consume horas de nuestro equipo, infraestructura y servicios de terceros desde el primer día, y no se recupera si el comercio decide después no seguir. Por eso, una vez iniciada la puesta en marcha no devolvemos el importe pagado.",
        "Vale igual si la cuenta se terminó usando poco o nada: lo que consume el recurso es el trabajo hecho y el servicio puesto a disposición, no cuánto se lo haya usado.",
        "Esto no toca las cuatro situaciones que enumera «Cuándo sí devolvemos». Un cobro duplicado, un error de facturación nuestro, un cobro posterior a una baja o una falla que impida operar se devuelven igual, esté la puesta en marcha iniciada o no.",
      ],
    },
    {
      titulo: "Cuándo sí devolvemos",
      parrafos: [
        "Hay cuatro situaciones concretas en las que la devolución corresponde y la hacemos sin discutir:",
      ],
      lista: [
        "Cobro duplicado: se cobró dos veces el mismo ciclo. Devolvemos el importe duplicado, íntegro.",
        "Error de facturación imputable a Punto: se cobró un monto distinto del que corresponde al plan, o se cobró una sucursal que no está dada de alta. Devolvemos la diferencia.",
        "Cobro posterior a una baja ya efectiva: el comercio pidió la baja y el sistema igual cobró el ciclo siguiente. Devolvemos ese cobro completo.",
        "Falla del servicio atribuible a Punto que impide operar y que no logramos corregir en un plazo razonable. En ese caso el reembolso es proporcional a los días del ciclo que no se prestaron.",
      ],
    },
    {
      titulo: "Créditos de IA",
      parrafos: [
        "Los créditos mensuales que trae el plan son parte de la suscripción, no un producto aparte: no se reembolsan por separado, no se acumulan de un mes al otro y no se convierten en dinero ni en descuento.",
        "Los packs de créditos que el comercio compra aparte no son reembolsables una vez acreditados en la cuenta, porque quedan disponibles para usar desde ese mismo momento.",
        "La excepción son los casos de la sección anterior: si el pack se cobró dos veces, se cobró por error nuestro o se cobró después de una baja, lo devolvemos igual que la suscripción.",
      ],
    },
    {
      titulo: "Cómo se pide un reembolso",
      parrafos: [
        `El pedido se hace por escrito a ${EMPRESA.email}, dentro de los 30 días corridos contados desde la fecha del cobro. Pasado ese plazo no procesamos el reclamo.`,
        "Para poder resolverlo sin idas y vueltas, el mensaje tiene que incluir:",
      ],
      lista: [
        "El nombre de la empresa y su {docFiscal}, tal como figuran en la cuenta.",
        "La fecha y el monto del cobro que se reclama.",
        "El motivo: cuál de los casos de esta política aplica.",
      ],
      // El acuse es lo primero que se responde; el análisis puede llevar más.
    },
    {
      titulo: "Plazos y forma de devolución",
      parrafos: [
        "Respondemos todo pedido de reembolso dentro de los 5 días hábiles de recibido, diciendo si corresponde o no y por qué.",
        "La devolución se hace por el mismo medio de pago con el que se cobró, a través del procesador que tomó el cobro. No devolvemos en efectivo, ni a una cuenta distinta, ni como crédito para usar en el sistema.",
        "Una vez aprobado, Punto ordena la devolución dentro de los 5 días hábiles. La acreditación efectiva depende del emisor de la tarjeta o del banco del comercio y suele tomar entre 5 y 15 días hábiles adicionales. Ese último tramo no lo controlamos y no prometemos una fecha: lo que sí hacemos es darte el comprobante de la devolución ordenada para que puedas reclamarle a tu banco si se demora.",
      ],
    },
    {
      titulo: "Contracargos",
      parrafos: [
        `Si hay un cobro que no reconocés, escribinos primero a ${EMPRESA.email}. Casi todo se resuelve más rápido por acá que por el banco: nosotros vemos el cobro en el momento y podemos devolverlo directamente.`,
        "Si en cambio se abre un contracargo con el banco o la tarjeta, el proceso pasa a manos del emisor y puede tardar semanas. Mientras dure la disputa, la cuenta puede quedar suspendida.",
        "Un contracargo resuelto a favor del comercio cierra el tema y no genera ningún cargo adicional de nuestra parte.",
      ],
    },
    {
      titulo: "Impuestos",
      parrafos: [
        "El reembolso incluye los impuestos que se hayan cobrado sobre el importe devuelto: se devuelve lo que efectivamente se pagó, no el monto sin impuestos.",
        "Cuando el cobro original tenía comprobante fiscal, emitimos el documento que corresponde a la devolución y se lo mandamos al comercio para que su contador lo registre.",
      ],
    },
    {
      titulo: "Contacto",
      parrafos: [
        `${EMPRESA.razonSocial} — {docFiscal} ${EMPRESA.documento}`,
        EMPRESA.domicilio,
        `${EMPRESA.email} · ${EMPRESA.telefono}`,
      ],
    },
  ],
}

/** Los documentos legales, para el footer, el sitemap y el exportador. */
export const DOCUMENTOS_LEGALES: DocumentoLegal[] = [
  TERMINOS,
  PRIVACIDAD,
  REEMBOLSOS,
]
