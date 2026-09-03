/**
 * Textos legales del sitio (Términos y Condiciones, Política de Privacidad).
 *
 * Son CONTENIDO ESTRUCTURADO, no JSX: la misma fuente alimenta a las páginas
 * públicas (`/terminos`, `/privacidad`) y al exportador que genera
 * `content/sitio/*.md` para el agente de atención. Si se edita una cláusula,
 * se edita acá y las dos superficies quedan sincronizadas.
 *
 * Los textos usan los tokens de mercado de `markets.ts` ({docFiscal},
 * {organismo}) y NUNCA literales de un país. Los montos salen de
 * `marketMoney(...)`, nunca escritos a mano.
 */

import { getMarket, marketMoney } from "@/lib/site/markets"

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
  razonSocial: "Brixton S.A.",
  documento: "80164242-6",
  domicilio: market.contacto.direccion,
  telefono: market.contacto.telefono,
  emailGeneral: "hola@punto.la",
  emailLegal: "legal@punto.la",
  sitio: "punto.la",
  app: "app.punto.la",
  vigencia: "3 de septiembre de 2026",
} as const

const PRECIO = marketMoney(market.plan.precio, market)
const PERIODO = market.plan.periodo
const CREDITOS = new Intl.NumberFormat("es-PY").format(market.plan.creditosIa)

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
        `Para cualquier tema de estos términos escribinos a ${EMPRESA.emailLegal}. Para soporte, a ${EMPRESA.emailGeneral} o al ${EMPRESA.telefono}.`,
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
        `Punto tiene un solo plan, con todo el sistema incluido. El precio es de ${PRECIO} ${PERIODO}, en ${market.moneda.codigo}.`,
        "El ciclo es mensual y se renueva automáticamente mientras la cuenta esté activa. Cada renovación se cobra por adelantado, al inicio del período.",
        "El cobro se procesa a través de dLocal Go, que acepta tarjeta y transferencia local. Los datos completos de la tarjeta se ingresan en el entorno del procesador de pagos: nosotros no los vemos ni los guardamos.",
        "Los precios se expresan con los impuestos que correspondan según la normativa vigente. Por cada cobro emitimos el comprobante fiscal a nombre de los datos que el comercio haya cargado en su cuenta.",
      ],
    },
    {
      titulo: "Créditos de IA",
      parrafos: [
        `El plan incluye ${CREDITOS} créditos de inteligencia artificial por mes, que cubren el uso de Punto AI: preguntar por los números del negocio, pedir un reporte, leer una factura de compra con la cámara.`,
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
      titulo: "Cancelación, mora y baja",
      parrafos: [
        "No hay contrato de permanencia. El comercio puede dar de baja la cuenta cuando quiera, avisándonos por los canales de contacto. La baja se hace efectiva al final del ciclo ya pagado: hasta esa fecha el servicio sigue funcionando completo.",
        "Si un cobro falla, reintentamos y avisamos. Si la deuda persiste, la cuenta se suspende: primero se bloquea la operación (no se puede vender ni emitir) y después queda en modo de solo lectura, para que el comercio pueda consultar y exportar su información.",
        `Tras la baja, el comercio tiene 30 días corridos para exportar sus datos. Vencido ese plazo podemos eliminar la información operativa de la cuenta, salvo lo que estemos obligados a conservar por normativa fiscal. Para coordinar una exportación escribinos a ${EMPRESA.emailGeneral}.`,
      ],
    },
    {
      titulo: "Reembolsos",
      parrafos: [
        "La suscripción mensual no es reembolsable. Al dar de baja no se devuelve la parte del mes en curso: el servicio queda disponible hasta que termine el ciclo pagado.",
        "Si hubo un cobro duplicado o un error de facturación imputable a nosotros, lo devolvemos íntegro. Escribinos dentro de los 30 días del cobro y lo resolvemos.",
        `Los créditos de IA comprados aparte tampoco son reembolsables una vez acreditados en la cuenta. Cualquier reclamo se canaliza por ${EMPRESA.emailGeneral}.`,
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
        "Las ventanas de mantenimiento programado se avisan con anticipación y se eligen en horarios de baja actividad. Las incidencias no programadas se comunican por los canales de soporte.",
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
        `El soporte online funciona 24/7 por WhatsApp al ${EMPRESA.telefono}, por el chat del sitio y por ${EMPRESA.emailGeneral}.`,
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
        `Consultas generales y soporte: ${EMPRESA.emailGeneral} · ${EMPRESA.telefono}`,
        `Temas legales: ${EMPRESA.emailLegal}`,
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
        `Para cualquier tema de privacidad o para ejercer derechos sobre tus datos, escribinos a ${EMPRESA.emailLegal}.`,
      ],
    },
    {
      titulo: "Los dos roles: cuándo decidimos nosotros y cuándo decide el comercio",
      parrafos: [
        "Esta es la sección más importante de todo el documento, porque define quién responde por qué.",
        "Respecto de los datos de la CUENTA del comercio — el teléfono del titular, el nombre, el email, el {docFiscal}, los usuarios del equipo, los datos de facturación — nosotros somos los responsables: decidimos para qué se usan y respondemos por su tratamiento.",
        "Respecto de los datos que el comercio CARGA sobre sus propios clientes — nombres, teléfonos, direcciones, historial de compras, cuentas corrientes — el responsable es el comercio. Él decide qué carga, para qué y con qué base legal. Nosotros actuamos como encargados del tratamiento: solo procesamos esos datos siguiendo sus instrucciones y para que el sistema funcione.",
        "En criollo: si sos cliente de un comercio que usa Punto y querés saber por qué tienen tu teléfono, el que te tiene que responder es ese comercio. Nosotros lo asistimos técnicamente, pero no decidimos qué datos suyos guarda.",
      ],
    },
    {
      titulo: "Qué datos tratamos",
      parrafos: [
        "Agrupados por origen, esto es lo que pasa por el sistema:",
      ],
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
        "Punto AI, el asistente del sistema, y la lectura automática de facturas de compra funcionan con modelos de lenguaje que corremos a través de OpenRouter, un proveedor que enruta la consulta al modelo elegido.",
        "Qué se envía: la pregunta que escribe el usuario, más los datos del negocio que hacen falta para responderla (por ejemplo, el resumen de ventas del período consultado). En la lectura de facturas se envía la foto o el archivo del comprobante.",
        "Qué no se envía: nada más que eso. El asistente no vuelca la base de datos del comercio al modelo, y lo que puede leer o modificar está limitado por los permisos del usuario que lo está usando.",
        "OpenRouter y los proveedores de modelos actúan como subencargados, bajo compromisos contractuales de confidencialidad. Los datos enviados no se usan para entrenar modelos de terceros.",
      ],
    },
    {
      titulo: "Con quién compartimos",
      parrafos: [
        "Compartimos datos solo con los proveedores necesarios para que el servicio funcione, y solo lo mínimo que cada uno necesita. Todos actúan como subencargados del tratamiento.",
      ],
      tabla: {
        headers: ["Proveedor", "Para qué"],
        filas: [
          [
            "dLocal Go",
            "Cobro de la suscripción y de los packs de créditos de IA (checkout hosteado).",
          ],
          [
            "DigitalOcean",
            "Infraestructura donde corre el sistema y almacenamiento de los archivos que el comercio sube (fotos de productos, adjuntos).",
          ],
          [
            "Factomate / Automate",
            "Proveedor habilitado que transmite los documentos electrónicos a {organismo}.",
          ],
          [
            "OpenRouter",
            "Proveedor de los modelos de IA que usan el asistente y la lectura de facturas.",
          ],
          [
            "Mailgun / SendGrid",
            "Envío de los emails transaccionales del sistema.",
          ],
          ["Twilio", "Envío de SMS."],
          [
            "Evolution API (WhatsApp)",
            "Envío del código de verificación en el alta de la cuenta.",
          ],
          [
            "Fish",
            `Webchat de atención al cliente embebido en ${EMPRESA.sitio}, operado por ${EMPRESA.razonSocial} (mismo grupo empresario)`,
          ],
          [
            "Bancard",
            "Medio de pago con QR que el COMERCIO habilita para cobrarle a sus clientes dentro de la caja. No es un cobro de Punto.",
          ],
        ],
      },
    },
    {
      titulo: "Cookies y tecnologías similares",
      parrafos: [
        "En la aplicación usamos cookies y almacenamiento local estrictamente necesarios: mantener la sesión iniciada, recordar el dispositivo de caja emparejado, guardar las preferencias de la interfaz y permitir que el punto de venta funcione sin conexión.",
        `En el sitio ${EMPRESA.sitio} el único script de terceros es el webchat de atención (Fish), que guarda un identificador de conversación para que no se pierda el hilo si recargás la página.`,
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
        "Tras la baja, hay 30 días corridos para exportar la información. Vencido ese plazo podemos eliminar los datos operativos de la cuenta.",
        "Hay una excepción: los documentos electrónicos emitidos y los respaldos contables se conservan por el plazo que exige la normativa fiscal, aunque la cuenta se haya dado de baja. No podemos borrarlos antes.",
        "Los registros técnicos y de auditoría se conservan por períodos acotados, los necesarios para seguridad y diagnóstico.",
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
        "Contenemos el incidente, evaluamos el alcance y notificamos a los comercios afectados sin demora indebida, apenas tengamos confirmado qué datos se vieron involucrados y qué recomendamos hacer. Si corresponde, notificamos también a la autoridad competente.",
        "Cuando el comercio sea el responsable de los datos afectados (los de sus propios clientes), le damos la información que necesite para cumplir con sus propias obligaciones de notificación.",
      ],
    },
    {
      titulo: "Tus derechos",
      parrafos: [
        "Toda persona cuyos datos tratamos puede pedirnos acceder a ellos, rectificarlos, actualizarlos, solicitar su supresión, pedir una copia en formato portable u oponerse a determinados tratamientos.",
        `Para ejercerlos, escribí a ${EMPRESA.emailLegal} indicando qué querés hacer. Para proteger tus datos de un tercero que se haga pasar por vos, vamos a pedirte que verifiques tu identidad — normalmente confirmando el control del teléfono o del email asociados.`,
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
        `Si detectamos que se cargaron datos de un menor sin base legal, o si nos lo informan a ${EMPRESA.emailLegal}, actuamos para eliminarlos.`,
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
        `Privacidad y ejercicio de derechos: ${EMPRESA.emailLegal}`,
        `Consultas generales y soporte: ${EMPRESA.emailGeneral} · ${EMPRESA.telefono}`,
      ],
    },
  ],
}

/** Los dos documentos legales, para el footer y el exportador. */
export const DOCUMENTOS_LEGALES: DocumentoLegal[] = [TERMINOS, PRIVACIDAD]
