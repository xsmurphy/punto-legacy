# RRHH básico + marcación de asistencia (quiosco facial)

**Estado: plan CERRADO. F0-F2 implementadas 2026-09-17; REFACTOR §9
IMPLEMENTADO 2026-09-18 (branch `rrhh/unificacion`, SIN deployar):
usuario=empleado, reloj de marcación como dispositivo propio, PIN opcional.
Ver §9.4 para lo que quedó abierto.**

## §0 El pedido

Dos pedidos del owner el 2026-09-17, que son un solo módulo:

1. Un **RRHH básico para pequeñas empresas** (5-30 empleados).
2. **Revivir la marcación de asistencia del legacy, mejorada.** El legacy era:
   QR impreso en el local, el empleado lo escaneaba desde SU celular logueado
   con PIN y con geolocalización activada. Falla estructural que motivó el
   rediseño: el PIN se presta — un empleado que sí está en el local marca por
   otro que no (buddy punching). La mejora pedida: reconocimiento facial
   usando el celular/tablet DEL COMERCIO.

Esto además destraba un pendiente del roadmap (2026-08-30): el módulo
`attendance` figura `available` en `modules-catalog.ts` pero en el stack nuevo
solo existe `api/v1/attendance.php` (el VERIFICADOR del token QR) — no hay UI
ni generador. La decisión "¿se marca `soon` o se completa?" queda respondida:
se completa con este plan, y el endpoint legacy del token derivable
(`md5(companyId.outletId)`) se elimina con él — el modelo nuevo no usa QR.

## §1 Lo que ya existe y se reusa (medio módulo está construido)

- **Empleados que operan el sistema ya son `user`** con PIN, roles y permisos,
  y sucursales asignadas (`contact_outlet`, context/25).
- **El POS ya sabe quién trabaja**: apertura/cierre de turno de caja con
  operador, ventas por empleado (Reportes › Equipo), auditoría con actor
  (`AuditActor`).
- **Egresos de caja y Finanzas** (`fin_movement`, categorías): un adelanto de
  sueldo y el pago de la liquidación son egresos que el motor ya sabe asentar.
- **La PWA del comercio** (context/72: una sola app instalable) con cámara vía
  navegador y cola offline de operaciones (`pending-ops`).

## §2 D1 CERRADA — alcance de la v1

Incluye: **legajo**, **asistencia**, **ausencias/vacaciones**, **adelantos**,
**liquidación simple + recibo en PDF**.

Fuera de la v1: reclutamiento, evaluaciones de desempeño, organigrama, y la
**nómina legal por país** (aportes patronales, aguinaldo, seguridad social).
La liquidación v1 es aritmética declarada por el comercio: remuneración según
el esquema del empleado (§5) + extras − adelantos − descuentos. El motor legal
por país se agrega después como capa, igual que la facturación electrónica
sobre la venta.

**Esquemas de remuneración (owner 2026-09-17): conviven los tres y se
combinan.** El legajo declara, por empleado:

- **Fijo** — monto por período (mensual/quincenal/semanal).
- **Por hora** — tarifa × horas trabajadas, y las horas salen del marcador de
  entrada/salida (§4). Es la fase F1 alimentando a la F4: sin marcación
  confiable no hay sueldo por hora auditable.
- **Comisión** — NO es un % plano por empleado: casos reales de ex clientes
  del legacy (owner 2026-09-17) — comisión porcentual POR PRODUCTO, monto fijo
  en moneda POR PRODUCTO, y el mismo producto comisionando distinto según el
  empleado. Es un TARIFARIO, y el molde ya existe: las listas de precio.

Combinables (base fija + comisión es el caso típico de vendedores). La
liquidación muestra cada componente por separado en el recibo.

**Tarifario de comisiones (D10 CERRADA)** — patrón lista de precios:

- `commission_scheme` con reglas por alcance: default del esquema → categoría
  → producto; cada regla es `%` o `monto fijo por unidad`. Empleados asignados
  a un esquema, con reglas PROPIAS que pisan las del esquema (mismo producto,
  comisión distinta por persona). Resolución: la más específica gana, igual
  que resuelve precios el POS.
- **La comisión se CONGELA en la línea de venta** al vender, con la regla
  vigente del vendedor de la línea — mismo patrón que el IVA y el COGS
  congelados. La liquidación SUMA lo congelado, no recalcula; una devolución
  la revierte con el mecanismo que ya revierte el costo (`flipOnReturn`).
  Contracara asumida: cambiar una regla NO re-liquida ventas pasadas — lo
  vendido comisionó con la regla de su día.

## §3 D2 CERRADA — el empleado es entidad propia, no un flag en `user`

`employee` como tabla propia, con vínculo OPCIONAL a `user`:

- Hay personal que jamás toca el sistema (cocinero, limpieza) y necesita
  legajo y marcación igual — sin inventarle un login.
- El legajo es HISTORIAL LABORAL: sobrevive al egreso y a la desactivación del
  usuario. Un `user` es una credencial; un empleado es una relación laboral
  con fechas.
- Campos v1: datos personales, documento, fecha de ingreso/egreso, puesto,
  sucursal(es), salario acordado (monto + periodicidad), adjuntos (contrato,
  cédula) y el registro de consentimiento biométrico (§5).

## §4 D3 CERRADA (dirección) — marcación en el dispositivo DEL COMERCIO

El owner cerró la inversión del modelo: la marcación deja el celular del
empleado y pasa a un dispositivo del comercio en modo quiosco. Consecuencia
que ordena todo: **QR y geolocalización dejan de existir en el caso
principal** — los dos existían solo para probar presencia en el local, y un
dispositivo fijo del comercio la prueba por sí mismo.

Flujo propuesto: el empleado se para frente al equipo → el sistema lo
identifica por la cara entre los empleados de la sucursal (1:N) → confirma
entrada/salida en un toque. Sin tipear nada.

### D4 CERRADA — la cara IDENTIFICA; nunca bloquea. La foto es la evidencia

- El reconocimiento es la identificación primaria; el **PIN queda de
  respaldo** (empleado sin enrolar, cámara rota, contraluz).
- **SIEMPRE se guarda la foto del momento de marcar**, matchee o no.
- Si la cara no matchea o se marcó por PIN, la marcación **entra igual** y
  queda **flageada** para revisión del dueño. Fail-open: mismo principio que
  la venta offline — nunca dejar a un empleado legítimo sin poder marcar; el
  fraude se ataca con evidencia y auditoría, no con un portón.
- Límite declarado (dicho al owner): un reconocedor simple puede ser engañado
  con una foto impresa. Mitigación v1: prueba de vida básica (parpadeo) + la
  foto guardada, que convierte el intento en evidencia con autor. Es control
  de asistencia de pyme, no control de acceso.

### D5 CERRADA — reconocimiento ON-DEVICE, biometría propia

- La comparación corre **en el navegador del dispositivo** (embeddings
  faciales con modelo local). Sin servicio externo: **costo cero por
  marcación**, funciona **offline**, y la cara **no sale a terceros**.
- Se persiste el **vector (embedding) + las fotos de enrolamiento** (3-5 tomas
  desde el mismo quiosco al dar de alta), server-side, y bajan al dispositivo
  como baja el catálogo. Las fotos de marcación tienen retención configurable.
- Mecánica (para no rediscutirla): el modelo (unos MB) baja una vez y queda
  cacheado en la PWA; convierte una cara en un vector de ~128-512 números que
  funciona como firma — no es la foto ni se recupera la foto desde él. **La
  fuente de verdad de los embeddings es el SERVIDOR** (alta desde cualquier
  quiosco, reposición de un equipo roto sin re-enrolar a nadie, y el borrado
  al egreso se propaga); el dispositivo solo CACHEA los de su sucursal, igual
  que cachea artículos. Marcar = capturar frame → embedding local → distancia
  contra los cacheados → menor distancia bajo umbral = identificado. Cero
  requests en el momento; la foto de evidencia sube después (o encola, §D7).
- **Dato sensible**: consentimiento explícito registrado en el legajo, borrado
  de biometría al egreso, y prohibido enviarla a servicios de terceros.
  Paraguay ya tiene ley de protección de datos personales vigente — confirmar
  plazos/retención exigibles por país ANTES de habilitar el módulo a clientes.

### D6 CERRADA — modo por sucursal

Default: quiosco facial. Alternativa por sucursal para personal de calle
(repartidores, vendedores externos): marcación desde el celular propio con
geolocalización + selfie del momento (el QR no vuelve — la selfie con flag de
revisión reemplaza al PIN prestable como evidencia).

### D7 CERRADA — offline-first

La marcación encola como operación pendiente (patrón `pending-ops` del POS) y
sincroniza al volver la red. El reconocimiento ya es local, así que sin
internet no cambia nada para el empleado.

## §5 D8 CERRADA — adelantos y liquidación pasan por Finanzas, no al lado

- **Adelanto** = egreso de caja que YA existe, con vínculo al empleado. Al
  liquidar, los adelantos del período se descuentan solos.
- **Pago de liquidación** = `fin_movement` de egreso con categoría sueldos.
  Nada de un "libro de sueldos" paralelo que después no concilie con caja.
- **Documentos impresos (owner 2026-09-17)**: el **recibo de dinero** del
  adelanto (el empleado firma que recibió) sale por la impresora de la caja
  como plantilla del sistema de impresión existente — lo que se imprime lo
  decide la PLANTILLA, regla vigente de context/18, y "Recibo" como doctype ya
  existe. La **liquidación de salarios** es documento A4 por empleado y
  período (patrón cotización-PDF, context/56: `@react-pdf/renderer`, bajo
  demanda), imprimible y descargable, con cada componente por separado (base,
  horas, comisiones, adelantos, descuentos) y espacio de firma.
- Las horas trabajadas salen de la marcación; las llegadas tarde y ausencias
  del contraste contra el horario declarado en el legajo. La liquidación LEE
  esos números pero el comercio los puede corregir a mano antes de liquidar —
  la marcación es evidencia, no sentencia.

## §6 D9 SUPERSEDED (owner 2026-09-17) — RRHH es CORE, no togglable

La D9 original (módulo `rrhh` activable por comercio) duró un día: al ver el
toggle, el owner la corrigió con la regla general que ordena TODO el catálogo:

> Los módulos BASE que se usan en todos los rubros (facturación, compras,
> inventario, items, RRHH) NO son activables. Los activables son los
> ESPECÍFICOS de rubro: producción, ecommerce, espacios, órdenes, calendario.

Consecuencia: `rrhh` salió de `modules-catalog.ts` y de `NATIVE_KEYS`, las
rutas del panel no llevan `requiresModule`, la sección de marcación del POS no
se filtra por módulo y el roster baja SIEMPRE en el bootstrap (vacío si no hay
empleados). El único gate del legajo y los reportes es el PERMISO (`hr.*`, sin
rol por defecto); el quiosco no pide permiso de operador — es del comercio.

## §7 Fases

| Fase | Qué entrega | Depende de |
|---|---|---|
| F0 | `employee` + legajo en el panel (CRUD, adjuntos, vínculo a `user`) | — |
| F1 | Quiosco de marcación en la PWA: PIN + foto SIEMPRE + reportes de asistencia (horas, tardanzas) | F0 |
| F2 | Enrolamiento + reconocimiento facial on-device + flags de mismatch + parpadeo | F1 |
| F3 | Ausencias y vacaciones (solicitud, aprobación, saldo) | F0 |
| F4 | Adelantos vinculados + liquidación + recibo PDF | F0 (F1 suma horas) |

F1 antes que F2 a propósito: el quiosco con PIN+foto ya elimina el QR, ya
junta evidencia y ya produce reportes; el facial se monta sobre un flujo
probado en vez de estrenar hardware y modelo el mismo día.

## §8 Arquitecturas rechazadas / a evitar

- **Volver al QR + PIN como camino principal** — es exactamente la falla que
  motivó el rediseño: el PIN se presta.
- **Reconocimiento facial server-side de terceros** (Rekognition y afines) —
  costo por marcación, no funciona offline y manda biometría de empleados de
  cientos de tenants a un tercero. Se reabre SOLO si la precisión on-device
  resulta insuficiente en piloto, y aun así con embeddings, no fotos.
- **La cara como gate duro** (sin match no hay marcación) — deja empleados
  legítimos sin marcar por luz/cámara y no reduce fraude más que el flag +
  foto. Rechazado por el mismo principio fail-open de la venta offline.
- **Guardar solo fotos "para revisar a mano" sin reconocimiento** — nadie
  revisa 60 fotos por día; sin match automático el flag no existe.
- **Un contador de sueldos paralelo a Finanzas** — todo pago y adelanto es un
  movimiento del ledger financiero existente; un libro aparte no concilia.
- **Meter el salario/legajo en `user`** — mezcla credencial con relación
  laboral y deja sin legajo al personal que no opera el sistema.
  **Matizado por el §9.1**: el legajo NO se metió en `contact`; se volvió un
  satélite 1:1 suyo. Las columnas de la relación laboral siguen en su propia
  tabla — lo que murió es la identidad DUPLICADA (nombre, teléfono, email) y
  la entidad paralela con vínculo opcional.


## §9 REFACTOR 2026-09-18 — usuario = empleado, reloj dedicado, PIN opcional

Tres correcciones del owner sobre lo implementado, todas en la misma
dirección: menos entidades, menos superficies, menos credenciales.

**§9.1 — D2 SUPERSEDED: una persona = UN usuario.** "No entiendo por qué está
separado usuario de empleado… una misma persona como usuario tiene un PIN y
como empleado tiene otro PIN, ¿cuál es el punto?" La entidad `employee`
separada compraba dos cosas (historial que sobrevive, personal sin login) que
se logran igual con el modelo simple: el legajo pasa a ser EXTENSIÓN del
usuario (satélite del `contact` type=0, con sus fechas), y el personal que no
opera es un usuario SIN PERMISOS. Muere `employee.userid` (el vínculo
opcional), muere el doble PIN, y las comisiones/ventas por empleado quedan
atribuidas a la misma identidad sin mapeo. Contracara resuelta: los que no
operan NO aparecen en la pantalla de bloqueo del POS (se filtra por permiso).
NO hay consideración de cobro por usuario — descartado explícito por el owner.

**§9.2 — El reloj de marcación es un DISPOSITIVO propio, no una pantalla del
POS.** "En las empresas los lectores de huella no están en el POS — ahí solo
opera el cajero." La marcación quedó en `/pos` por plomería (pairing, cámara,
offline, roster descargado), no por producto. Se corrige: tipo de dispositivo
`clock` ("Reloj de marcación") junto a caja/KDS/pantalla — se parea cualquier
tablet desde Configuración › Dispositivos, arranca a pantalla completa en
marcación y no hace NADA más. La sección Marcación se ELIMINA del menú del
POS; el roster de empleados deja de bajar a las cajas y baja SOLO al reloj.
El enrolamiento desde el legajo apunta al reloj de la sucursal.

**§9.3 — PIN de marcación OPCIONAL.** El que solo marca asistencia pone la
cara y listo; el PIN es únicamente el respaldo de quien lo tiene (los
operadores ya tienen el suyo, único). Sin rostro enrolado y sin PIN no se
puede marcar: el reloj lo dice en una línea y se resuelve enrolando.
`employee.markpinhash` muere con la unificación — un solo PIN por persona.


### §9.4 Cómo quedó implementado (2026-09-18)

**Migración 233** (`233_rrhh_usuario_unico.sql`). `employee.contactid` pasa a
ser la CLAVE PRIMARIA — no una columna más al lado de `employeeid`: el legajo
es un satélite 1:1 y dos claves para la misma fila terminan siendo dos filas.
`attendance_mark`, `employee_face`, `employee_face_enrollment` y
`employee_attachment` se repuntaron a `contactid`.

Qué borra: los legajos cuyo `userid` no resuelve a un `contact` type=0 del
mismo comercio. En el modelo nuevo esa persona no existe, no puede marcar y no
se le puede liquidar nada. En producción eso era UNA fila de prueba.

Mueren `employee.markpinhash`, `employee.fullname`, `employee.phone` y
`employee.email`. **Sobreviven** `documentnumber`, `address` y `birthdate`
aunque `contact` tenga columnas parecidas (`contactci`, `contactaddress`,
`contactbirthday`): esas son del contacto como CLIENTE, no están en ninguna
pantalla de usuarios, y el legajo necesita el documento con índice único por
comercio. Moverlos es otro slice, con su pantalla.

**Una decisión de FK que conviene no revertir sin leer esto**: la marcación
referencia `contact` y NO `employee`. Es un hecho sobre una PERSONA y las horas
que alimentan la liquidación no tienen por qué desaparecer si un día se borra el
legajo. El rostro y los adjuntos, al revés, cuelgan del LEGAJO: el
consentimiento biométrico es una columna de `employee`, así que borrar el legajo
tiene que borrar la biometría en el mismo movimiento (D5).

**El reloj** es `device.module = 'clock'`, se parea con SUCURSAL y sin caja, y
su pantalla vive en `app/(screen)/marcacion` — el grupo de las pantallas
pareadas, no `/pos`. Su bootstrap slim son DOS llamadas que ya existían:
`/v1/screens?resource=context` (nombre del comercio, sucursal, formatos, que es
lo mismo que piden el KDS y la pantalla de despacho) y
`/v1/attendance?resource=roster`, que reemplaza a la clave `employees` del
bootstrap del POS. Ninguna caja recibe ya el roster ni los rostros.

`usePairedScreen` ganó `offlineFirst`: sin eso, un fallo de red mandaba al
aparato a "no conectado", y el reloj tiene que seguir fichando sin internet
(D7). Cachea el último contexto conocido; el 401 sigue olvidando el device.

La cola de operaciones aprendió que **no todo lo que encola pertenece a una
caja**: el cerco por caja ignora las operaciones con `registerId` vacío. Sin
eso, la marcación del reloj quedaba terminal siempre y la cola no salía nunca.

**Lo que quedó ABIERTO y el owner tiene que decidir:**

1. **El tope de usuarios del plan ahora cuenta al personal que no opera.**
   `UsersService::create()` aplica `assertPlanLimit()` (`plans.max_users ×
   sucursales`), y desde que cada empleado es un usuario, cargar a la cocinera
   consume un lugar. Contradice el "NO hay consideración de cobro por usuario"
   del §9.1 sin que nadie lo haya decidido. La salida limpia sería que el tope
   cuente CREDENCIALES (usuario con rol y contraseña) y no personas, pero es un
   cambio al gate de facturación y no se tocó acá.
2. **La regla "sin PIN con un solo usuario" (`context/72` §9.3) cuenta el
   roster entero.** Un comercio unipersonal que cargue tres cocineros pasa a
   tener cuatro usuarios, así que la caja vuelve a mostrar el bloqueo. El PIN
   match del lockscreen sí ignora a quien no tiene código
   (`lock-screen.tsx:145`), pero `soleOperator()` y `/v1/unlock-sole` cuentan
   filas. No se cambió: tocar el conteo en el cliente sin tocarlo en el servidor
   los hace divergir, y es una decisión de producto.
3. **La ficha del legajo en tabs** — pedido aparte, no entra acá.

**§9.5 (owner 2026-09-18) — UX del reloj: automático de punta a punta.** La
pantalla es la CÁMARA (oscura, tipo POS, nada de cards blancas con borde): la
persona se para, el sistema la reconoce, **infiere entrada o salida solo** por
su última marcación (nadie elige nada) y saluda — "Bienvenido {nombre}" al
entrar, "Adiós {nombre}" al salir. Prohibido copy tipo "te reconocí" y
prohibido pedirle a la persona que confirme o elija tipo: a prueba de tontos,
rápido, sin pensar. Una inferencia equivocada (quedó una salida sin marcar) se
corrige en la revisión del panel, no en el reloj. El código numérico queda como
respaldo DISCRETO (acción secundaria), no como teclado protagonista. La cámara
queda viva TODO el día — que se congele entre marcaciones es bug, no estado.
