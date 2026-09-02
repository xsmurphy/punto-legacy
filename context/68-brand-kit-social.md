# 68 — Brand kit para redes sociales

Kit de marca de Punto para **piezas de redes sociales** (Instagram, Facebook).
Su consumidor es Claude Design y herramientas externas de diseño, no el código
del producto.

**No es un doc de UI.** Las reglas de `context/20-design-system.md` y
`context/14-ui-conventions.md` gobiernan el producto; este doc gobierna el
feed, y en varios puntos dice lo contrario a propósito (ver §2).

---

## 1. Color — HEX derivados de los tokens

Los tokens del producto viven en OKLCH (`frontend/app/globals.css`). Las
herramientas de diseño no comen OKLCH, así que acá están convertidos a sRGB
(OKLab de Björn Ottosson, con clamp de croma por bisección al gamut sRGB).

### Marca

| Nombre | HEX | Uso en social |
|---|---|---|
| Verde Punto | `#01D7A1` | Color protagonista. Fondos, bloques, cifras destacadas, CTA |
| Verde claro | `#1EE4B2` | Realce sobre fondo oscuro, degradés, hover en mockups |

El design system declara `#01D7A1` como verde canónico; convertir el token
`oklch(0.78 0.155 169)` da `#0BD6A6`, indistinguible a ojo. **Para arte usar
`#01D7A1`** — es el hex declarado y el que ya está en los logos.

### Neutros y escala verde

| Nombre | HEX | Uso |
|---|---|---|
| Negro Punto | `#060A0E` | Fondo oscuro canónico (canvas dark del producto) |
| Grafito | `#212529` | Texto sobre fondo claro, texto sobre verde |
| Blanco | `#FFFFFF` | Fondo claro canónico, texto sobre negro/verde oscuro |
| Gris claro | `#F5F5F5` | Fondos secundarios, tarjetas sobre blanco |
| Gris borde | `#EAEEF1` | Hairlines, marcos de screenshot |
| Gris texto | `#737373` | Texto secundario sobre fondo claro |
| Gris texto dark | `#A1A1A1` | Texto secundario sobre fondo oscuro |
| Verde 2 | `#00B28A` | Segundo nivel de datos, degradé del verde |
| Verde 3 | `#00906E` | Tercer nivel |
| Verde 4 | `#006F54` | Cuarto nivel |
| Verde 5 | `#004F3B` | Quinto nivel, fondo oscuro con tinte de marca |
| Rojo | `#E40016` | Solo negativos reales (caído, error). Nunca decorativo |

### Acentos (`frontend/lib/ui/color-palette.ts`)

Seis colores del producto. En social son **secundarios**: sirven para
diferenciar categorías dentro de una misma pieza (rubros, módulos), nunca
como color dominante.

| key | HEX |
|---|---|
| `amber` | `#f59e0b` |
| `slate` | `#64748b` |
| `sky` | `#38bdf8` |
| `rose` | `#f43f5e` |
| `emerald` | `#10b981` |
| `violet` | `#8b5cf6` |

### Combinaciones aprobadas

Tres, y ninguna más: verde `#01D7A1` con texto `#212529` (la firma de la
marca); negro `#060A0E` con texto blanco y acentos verdes (la más usada en
piezas con screenshot); blanco `#FFFFFF` con texto `#212529` y acentos verdes
(precio, FAQ, informativas).

Blanco sobre verde `#01D7A1` **no pasa contraste** (el verde es claro, L≈0.78).
Sobre verde siempre va texto `#212529`.

---

## 2. El verde es protagonista en social (invertido respecto del producto)

En el producto el verde está **prohibido como `--primary`** y reservado a
acentos y charts: sobre las superficies del panel rompe legibilidad, y los CTA
son neutros a propósito.

**En social esa regla no aplica.** El feed compite por atención en un scroll
ajeno, no por legibilidad en una jornada de ocho horas: acá `#01D7A1` es lo
que hace reconocible a Punto en la miniatura — fondos enteros, bloques
grandes, cifras gigantes. Quien diseñe una pieza social no debe copiar la
regla de UI, y a la inversa, nada de este doc autoriza meter verde como
`--primary` en el producto.

---

## 3. Tipografía

- **Inter** — todo el texto. Bold/Semibold para títulos, Regular para cuerpo.
- **JetBrains Mono** — exclusivamente para cifras y números destacados
  (precio, porcentaje, cantidad, hora). Nunca para texto corrido.

Tracking negativo en títulos (`-2%` a `-4%`), igual que el producto.

### Escala por formato

| Rol | Cuadrado 1080×1080 | Retrato 1080×1350 | Story 1080×1920 |
|---|---|---|---|
| Título | 88 px | 96 px | 110 px |
| Subtítulo | 48 px | 52 px | 60 px |
| Cuerpo | 34 px | 36 px | 40 px |
| Caption / legal | 26 px | 26 px | 30 px |
| Cifra destacada (mono) | 140 px | 160 px | 190 px |

**26 px es el piso legible en móvil** para 1080 de ancho. Nada por debajo,
ni en disclaimers ni en créditos.

Márgenes de seguridad: 80 px en cuadrado y retrato. En story, 80 px laterales
y **250 px arriba y abajo** (zona de UI de Instagram).

---

## 4. Logo

Cuatro archivos en `frontend/public/logos/`. El sufijo indica **sobre qué
fondo va**, no el color del archivo.

| Archivo | Qué es | Cuándo |
|---|---|---|
| `logo_bg_light.png` (2908×994) | Logo completo | Sobre fondo claro o verde |
| `logo_bg_dark.png` (1346×461) | Logo completo | Sobre fondo oscuro |
| `icon_bg_light.png` (558×558) | Solo isotipo | Sobre fondo claro o verde |
| `icon_bg_dark.png` (558×558) | Solo isotipo | Sobre fondo oscuro |

**Logo completo o isotipo:** el logo completo va en piezas donde Punto se
presenta (feature, precio, prueba social). El isotipo va cuando la marca ya
está entendida o el espacio es chico: esquina de un carrusel, avatar, sello
sobre un screenshot.

**Clear space:** margen libre igual a **la mitad de la altura del isotipo** en
los cuatro lados. Con el isotipo a 100 px de alto, 50 px libres alrededor.
Nada entra ahí: ni texto, ni foto, ni borde.

**Tamaño mínimo:** isotipo 48 px de alto; logo completo 120 px de ancho.

**Prohibido:** estirar o deformar (la proporción se respeta siempre);
recolorear, tintar o poner en escala de grises; agregar sombra, contorno o
efecto; rotar; encerrar en una forma que no esté en el kit; apoyarlo sobre
foto con ruido o contraste variable sin un scrim (capa `#060A0E` al 45-60%)
que le dé fondo estable.

---

## 5. Voz y tono

Extraída del registro real de `content/sitio/*.md`.

**Cómo habla Punto:**

1. **Sujeto = el negocio, no el software.** La frase describe qué le pasa al
   comercio, no qué hace el sistema. "El turno cierra con números", no "el
   sistema permite cerrar el turno".
2. **Contraste con negación** — el recurso firma de la marca. Se afirma algo y
   se niega la alternativa vieja en la misma frase.
3. **Voseo cuando habla directo al lector.** "Preguntale", "si abrís", "lo que
   producís", "tal como los querés". Nunca tuteo, nunca "usted".
4. **Concreto y operativo.** Nombra objetos reales: mesa, sillón, mostrador,
   turno, comanda, vencimiento, cuenta corriente.
5. **Sin jerga corporativa ni superlativos.** No hay "solución integral",
   "potenciá", "transformación digital", "líder del mercado".

**Copy real del sitio (referencia directa):**

- "El turno cierra con números, no con memoria"
- "El traslado entre depósitos sale documentado, no anotado"
- "Se corta internet y la caja sigue vendiendo. Al volver, todo se sincroniza."
- "Los libros de venta y de compra salen del sistema, no del contador apurado"

**Anti-ejemplos — así NO suena Punto:**

- "La solución integral que potencia la transformación digital de tu comercio"
  (jerga vacía, no dice nada)
- "¡El MEJOR sistema POS del país! No te lo pierdas 🚀🔥" (superlativo sin
  respaldo, gritado, emojis en el arte)
- "Nuestro software permite al usuario gestionar eficientemente sus procesos
  de inventario" (el sujeto es el software, y "procesos" no es un objeto real)

**Emojis:** permitidos con moderación en el caption. **Nunca dentro del arte.**

---

## 6. Nada hardcodeado a un mercado

Precio, moneda, nombre del documento fiscal y organismo son **variables de
mercado** (`frontend/lib/site/markets.ts`). Hoy hay un solo mercado activo
(PY), pero las plantillas se arman con placeholders y se resuelven al momento
de publicar:

| Placeholder | Fuente en `markets.ts` | Valor PY hoy |
|---|---|---|
| `{precio}` | `plan.precio` + `moneda.prefijo` | Gs. 295.000 |
| `{periodo}` | `plan.periodo` | por mes, por sucursal |
| `{badge}` | `plan.badge` | Precio promocional |
| `{docFiscal}` | `terminos.docFiscal` | RUC |
| `{organismo}` | `terminos.organismo` | la SET |
| `{creditosIa}` | `plan.creditosIa` | 10.000 |

Prohibido escribir "Gs.", "295.000", "RUC" o "SET" literales en una plantilla.
Los montos de ejemplo dentro de mockups se escalan con `ejemplos.escala` y
`ejemplos.redondeo` del mercado.

---

## 7. Plantillas de posteo

Screenshots disponibles en `frontend/public/site/`.

### (a) Feature / módulo destacado

- **Objetivo:** mostrar una capacidad concreta del producto funcionando.
- **Formato:** retrato 1080×1350.
- **Estructura:** título (2 líneas máx, arriba) → screenshot del producto en
  marco con esquina redondeada 24 px y borde `#EAEEF1` (o `#1A1D1F` en dark),
  ocupando el 55% central → una línea de cuerpo abajo → isotipo en la esquina.
- **Colores:** fondo `#060A0E`, título blanco con una palabra clave en
  `#01D7A1`, marco del screenshot con glow verde sutil.
- **Screenshots:** `/site/pos-cobro.png`, `/site/kds.png`,
  `/site/panel-screenshot-dark.png`, `/site/ai-screenshot.png`,
  `/site/despacho.png`, `/site/customer-display.png`.
- **Copy:** "La comanda entra sola a su estación. Nadie grita el pedido."

### (b) Rubro spotlight

- **Objetivo:** que un rubro específico se reconozca en la pieza.
- **Formato:** cuadrado 1080×1080.
- **Estructura:** foto del rubro a sangre completa → scrim `#060A0E` al 55%
  desde abajo → título sobre el scrim en el tercio inferior → logo completo
  arriba a la izquierda.
- **Colores:** foto + scrim + texto blanco; el nombre del rubro en `#01D7A1`.
- **Fotos:** `/site/rubro-restaurantes.jpg`, `/site/rubro-retail.jpg`,
  `/site/rubro-salud-y-belleza.jpg`, `/site/mockup-barber.jpg`,
  `/site/mockup-retail.jpg`.
- **Copy:** "Turnos cortos, sillones llenos, nadie esperando de más."

### (c) Tip operativo de caja

- **Objetivo:** utilidad pura, sin vender. Construye autoridad.
- **Formato:** cuadrado 1080×1080 (o carrusel de 3).
- **Estructura:** sin screenshot. Bloque verde a sangre → número o palabra
  ancla en JetBrains Mono gigante arriba → tip en 2 líneas al centro →
  isotipo abajo a la derecha.
- **Colores:** fondo `#01D7A1` pleno, todo el texto `#212529`.
- **Copy:** "Arqueo a ciegas. El cajero cuenta primero y ve la diferencia
  después: así el número es real."

### (d) Precio / oferta

- **Objetivo:** comunicar el plan sin letra chica.
- **Formato:** retrato 1080×1350.
- **Estructura:** badge `{badge}` arriba (pill verde, texto `#212529`) →
  `{precio}` en JetBrains Mono 160 px al centro → `{periodo}` debajo en gris →
  lista de 4 bullets de lo incluido → logo completo abajo.
- **Colores:** fondo `#FFFFFF`, texto `#212529`, precio y badge en `#01D7A1`.
- **Copy:** "{precio} {periodo}. Sin contrato, sin permanencia y sin módulos
  que se desbloquean pagando de más."

### (e) Prueba social / dato del producto

- **Objetivo:** un dato verificable del producto o una cita de cliente.
- **Formato:** cuadrado 1080×1080.
- **Estructura:** cifra en JetBrains Mono 140 px arriba (o comillas de
  apertura si es cita) → dato o cita en 2-3 líneas → atribución en caption →
  isotipo abajo.
- **Colores:** fondo `#060A0E`, cifra en `#01D7A1`, cuerpo blanco, atribución
  `#A1A1A1`.
- **Screenshots de apoyo:** `/site/reportes-stats.png`,
  `/site/cliente-comportamiento.png`, `/site/pos-success.png`.
- **Copy:** "Se cortó internet tres veces esa semana. No perdimos una sola
  venta."

---

## 8. Qué NO hacer

1. **No usar el verde como `--primary` del producto** por haber leído este
   doc. La regla de social muere en el feed.
2. **No emojis dentro del arte.** Solo en el caption, con moderación.
3. **No hex fuera de este doc.** Si falta un color, se deriva de un token de
   `globals.css`, no se inventa.
4. **No superlativos ni claims sin respaldo** ("el mejor", "el más usado",
   "líder"). Punto afirma lo que el producto hace.
5. **No inventar features.** Todo claim sale de `content/sitio/*.md`. Si no
   está ahí, no se publica.
6. **No screenshots de mentira.** Se usan los de `frontend/public/site/`, no
   mockups fabricados con datos falsos.
7. **No "ENCOM"** en ninguna pieza. La marca es Punto.
8. **No hardcodear precio, moneda ni `{docFiscal}`** en una plantilla.
9. **No sumar una tercera familia tipográfica.** Inter y JetBrains Mono.
10. **Los tres límites duros que más se violan:** blanco sobre verde, texto
    por debajo de 26 px, y logo estirado o sobre foto sin scrim.

---

## Procedencia

- **Fecha:** 2026-09-02.
- **Fuentes:** `frontend/app/globals.css` (tokens OKLCH, bloques `:root` y
  `.dark`), `context/20-design-system.md` (§1 Identidad, §2 Tokens, §3 Color),
  `frontend/lib/ui/color-palette.ts` (acentos), `content/sitio/*.md` (voz,
  tono y claims), `frontend/lib/site/markets.ts` (variables de mercado),
  `frontend/public/logos/` y `frontend/public/site/` (assets).
- **Los HEX de §1 son derivados, no fuente.** Si cambian los tokens OKLCH de
  `globals.css`, **hay que regenerar los hex de este doc** — el script de
  conversión (OKLab + clamp de croma por bisección) se rehace en el momento;
  no editar los valores a mano ni asumir que siguen vigentes.
