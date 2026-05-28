<!-- Actualizar cuando: se agregue/cambie un patrón, color, clase o componente del
     manual de marca, o se documente un componente nuevo del legacy. NO es CSS vivo:
     es la referencia única para construir UI consistente reutilizando lo existente. -->

# 11 — Manual de Marca (Design System)

**Qué es:** la referencia única de la identidad visual de Punto. **NO es CSS nuevo
ni un framework** — cataloga los colores, la tipografía y las **clases que el proyecto
YA usa** (Bootstrap 3 + `panel/css/app.css` + `style.css`) para que cualquier UI nueva
salga consistente **reutilizando lo existente, sin reinventar ni rediseñar**.

> Regla de oro: al construir/tocar UI, **usá las clases y colores de este manual**.
> No inventes estilos ad-hoc ni paletas nuevas. Si falta un patrón, agregalo acá.

---

## 1. Paleta de color

Valores canónicos (extraídos de `panel/css/app.css`). La columna "clase" es cómo se
aplica hoy en el markup BS3.

| Rol | Hex | Hover | Clase / uso |
|-----|-----|-------|-------------|
| **Primario** (indigo) | `#545ca6` | `#4b5395` | `btn-primary`, acción principal |
| **Éxito** (verde) | `#1ab667` | `#17a05a` | `btn-success`, confirmaciones |
| **Info** (teal) | `#4cb6cb` | `#39adc4` | `btn-info` — **color del CTA "clásico"** (login, etc.) |
| **Advertencia** | `#fad733` | — | `btn-warning` |
| **Peligro** (rojo) | `#f05050` | — | `btn-danger`, destructivo |
| **Acento verde** | `#6ddc5f` / `#2ad980` | — | estados/badges, highlights |

**Neutrales / texto:**

| Rol | Hex | Uso |
|-----|-----|-----|
| Texto principal / headings / links | `#545a5f` | títulos, `<a>` |
| Texto base (body) / secundario | `#788188` | `body`, texto muted |
| Texto sutil | `#939aa0` | placeholders, hints |
| Fondo de página (light) | `#f7f7f7` | `bg-light` |
| Superficie (cards/modales) | `#ffffff` | `bg-white` |
| Bordes | `#dae0e3` / `#cbd5dd` | `b-light` / bordes fuertes |

**Dark mode** (`.darkMode` en `<body>`): superficies slate `#232c32` (la más oscura),
`#3b464d`, `#5a6a7a`; texto `#d9e4e6`. Gradiente del panel oscuro del login:
`linear-gradient(314deg, #0d1215, #2f3940, #232c32)` (clases `gradBgBlack animateBg`).

---

## 2. Tipografía

- **Familia:** `"Source Sans Pro", "Helvetica Neue", Helvetica, Arial, sans-serif`.
- **Base:** 14px, color `#788188`.
- **Tamaños/utilidades:** `text-xs` = 12px, `text-md`, `text-lg`. Headings `h1..h6` de BS3.
- **Énfasis:** `font-bold` (700), `text-u-c` (UPPERCASE), `text-u-l` (underline).
- Fuentes especiales (recibos): `dotmatrix`, `FontA11`, `fakereceipt` (`panel/css/font.css`).

---

## 3. Radios, sombras, spacing

- **Radio por defecto:** `2px` (flat, era BS3). Pill: `btn-rounded` = `50px`. Cajas
  redondeadas: `r-3x` = `10px`.
- **Spacing:** utilidades BS3 del proyecto — `m-t`, `m-b`, `m-l`, `m-r` (+ sufijos
  `-xs/-sm/-md/-lg`), `padder`, `no-padder` (`padding:0`), `wrapper` / `wrapper-lg`
  (`padding:30px`) / `wrapper-md`.

---

## 4. Componentes (recetas con clases reales)

### Botones

- **CTA principal "clásico"** (login y acciones primarias grandes) — teal, pill, mayúsculas:
  ```html
  <button class="btn btn-info btn-rounded btn-lg btn-block text-u-c font-bold">Ingresar</button>
  ```
- **Variantes de color:** `btn-primary` (indigo), `btn-success`, `btn-info`, `btn-warning`,
  `btn-danger`, `btn-default` (blanco/borde).
- **Tamaños:** `btn-lg`, (default), `btn-sm`, `btn-xs`. **Forma:** `btn-rounded` (pill),
  `btn-block` (ancho completo), `btn-icon`.
- **Estado de carga:** patrón legacy `helpers.btnIndicator({ btn, status:'disable', disabledText:'Verificando' })`
  (ver `panel/login.php` / `app`). Deshabilita + cambia el texto.

### Inputs y labels

- **Input underline** (estilo login — sin caja, solo línea inferior):
  ```html
  <input class="form-control input-lg no-border no-bg b-b" placeholder="...">
  ```
  (`no-border` = sin borde, `no-bg` = transparente, `b-b` = solo `border-bottom`,
  `b-light` = color de borde `#d9e4e6`, `input-lg` = alto 45px).
- **Input en caja** (forms internos): `form-control` (+ `input-lg`/`input-sm`).
- **Label:** `<label class="block font-bold text-u-c text-xs">Celular o eMail</label>`.

### Cards / paneles

- BS3 `panel` / `panel-body`, o cajas con `bg-white r-3x wrapper`. Fondos: `bg-white`,
  `bg-light`, `bg-dark`. `no-border` para quitar bordes.

### Tablas

- BS3 `table` (+ `table-striped`, `table-hover`). En el panel se usa `ncmDataTables`
  para listados (jQuery-owned, ver `08-convenciones.md §17.2`).

### Modales

- Infra compartida del proyecto: `adm()` / `#modalTiny` / `#modalNarrow` (jQuery).
  No construir modales nuevos a mano — reutilizar esa infra.

### Layout de login (split-screen)

Referencia: `panel/login.php`. Columna izquierda `col-md-7` oscura con gradiente
(`bg-dark gradBgBlack animateBg`, logo + tagline, `hidden-xs`), derecha `col-md-5`
blanca con el form. Logos: `/images/incomelogo.png` (claro, sobre oscuro),
`/images/incomeLogoLgDark.png` (oscuro, sobre blanco).

---

## 5. Cómo aplicar (resumen para construir UI)

1. **Reutilizá** las clases de arriba; no inventes CSS ni colores.
2. Color de acción principal grande = **`btn-info btn-rounded`** (teal pill).
3. Inputs de auth = **underline** (`form-control no-border no-bg b-b`); forms internos
   = `form-control` en caja.
4. Mayúsculas + bold = `text-u-c font-bold`. Spacing = utilidades `m-*`/`wrapper*`.
5. Modales/tablas = infra existente (`adm()`, `ncmDataTables`), no a mano.
6. Frontend nuevo sigue **Bootstrap 3 + jQuery** (ver `08-convenciones.md §11`).

> Este manual es la fuente; el **skill `brand-manual`** (`.claude/skills/`) lo aplica
> automáticamente al tocar/crear UI.
