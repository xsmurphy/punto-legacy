# 82 — Base de conocimiento de Punto AI (carga en /admin)

> Pedido del owner (2026-09-17): una sección en `/admin` para subir archivos
> `.md` con la base de conocimiento de Punto, que use el bot.
>
> **Estado: plan sin implementar.** D1 cerrada por el owner; D2-D8 PROPUESTAS
> sin su OK. Leer §6 arquitecturas rechazadas antes de proponer nada.

## 1. El hueco que cierra

Punto AI conoce sus tools, no el producto. El prompt del panel
(`app/api/agent/chat/route.ts`) se lo dice explícito: nunca afirmar que Punto
"no tiene" algo, porque que una tool no ofrezca un dato no significa que el
sistema no lo soporte. Hoy, ante "¿cómo configuro la impresora de cocina?", el
agente no tiene de dónde responder y lo mejor que puede hacer es mandar al
panel.

La base de conocimiento es esa fuente: documentación de Punto escrita por
Punto, que el agente consulta para responder el CÓMO.

## 2. Estado actual (verificado 2026-09-17)

- **Cero infraestructura de recuperación.** Ni `pgvector`, ni embeddings, ni
  `tsvector`, ni una tool que busque en documentos.
- **La BD es `postgres:16-alpine`** (`docker-compose.yml`, `context/06`), que
  NO trae `pgvector`. Sí trae las extensiones contrib (`unaccent` entre ellas).
- **Ya existe OTRA base de conocimiento, para otro bot.** `content/sitio/*.md`
  se genera en cada build desde el código del sitio y alimenta un agente de
  atención al cliente fuera de este repo (`context/61`). No se toca ni se
  reusa: es contenido comercial para prospectos, no documentación operativa
  para un cajero.

## 3. Decisiones

### D1 — La base es para Punto AI de los comercios. **Cerrada por el owner (2026-09-17).**

El asistente del panel y de la caja. No el agente de atención de
`content/sitio`. La base es de Punto y la leen todos los comercios.

### D2 — Global, sin `companyId`. *(propuesta)*

Es documentación del producto, igual para todos. La tabla no lleva
`companyId` y la lectura no se scopea por tenant.

Esto NO abre un canal entre tenants: el contenido lo escribe solo Punto desde
`/admin`, y ningún tenant puede escribir en la base. Un tenant que quiera
contarle su negocio al agente tiene su propio campo, `agentBusinessContext`
(`context/69`), que sí es por empresa.

### D3 — Recuperación por full-text de Postgres, no por vectores. *(propuesta)*

`tsvector` con configuración `spanish` + `unaccent`, índice GIN, ranking con
`ts_rank_cd`. Cero infraestructura nueva: todo es nativo del Postgres que ya
corre.

Por qué no `pgvector` en la v1: la imagen de producción no lo trae, y sumarlo
es cambiar la imagen de la base de datos de un sistema con clientes
facturando. Además hace falta un proveedor de embeddings, y el agente corre
por OpenRouter (`context/17`), así que embeddings es otra integración con su
propio costo y su propio punto de falla.

Para una base de documentación de producto —vocabulario acotado, preguntas que
nombran la función ("impresora", "timbrado", "cierre de caja")— el full-text
cubre bien. Si la F4 mide que la recuperación falla en preguntas reales, el
vector se suma como COLUMNA sobre los mismos fragmentos (búsqueda híbrida), no
como sistema aparte.

### D4 — El agente la consulta con una tool, no se inyecta en el prompt. *(propuesta)*

Tool de lectura `search_punto_help(query)`: devuelve los 3-5 fragmentos más
relevantes con el título del documento y la sección de donde salen.

No se inyecta la base entera en el system prompt por dos razones: se pagaría
en CADA request (mismo argumento que D3 de `context/69`, multiplicado por el
tamaño de la base), y con muchos documentos el modelo pierde lo relevante entre
lo que no lo es.

Regla que se suma al prompt: preguntas de CÓMO usar Punto se responden con lo
que devuelva la tool; si no devuelve nada relevante, se dice que no está
documentado y se ofrece el canal de soporte — nunca se inventa un paso ni se
afirma que la función no existe.

### D5 — Fragmentar por encabezado. *(propuesta)*

Cada `.md` se parte por sus secciones (`##`), guardando la ruta de encabezados
(`Documento > Sección > Subsección`). Así el agente recibe una sección
coherente, no un corte arbitrario a mitad de paso, y puede decir de dónde sale.
Secciones muy largas se subdividen por párrafo con un tope de tamaño.

### D6 — Subir el mismo archivo REEMPLAZA, en una transacción. *(propuesta)*

La identidad del documento es su nombre (slug). Volver a subir `impresoras.md`
reemplaza todos sus fragmentos de forma atómica: borrar los viejos e insertar
los nuevos en la misma transacción, así el agente nunca lee un documento a
medio actualizar. Desde `/admin` también se desactiva (deja de aparecer en
búsquedas sin borrarse) o se elimina. Cada carga, reemplazo y baja queda en la
auditoría de admin con quién y cuándo.

### D7 — Consumidores: panel y caja, con el mismo catálogo. *(propuesta)*

La tool vive en el catálogo compartido `frontend/lib/agent/read-tools.ts`.

⚠ **El agente de la caja usa una ALLOWLIST explícita** (`lib/pos/agent-tools.ts`),
así que no la hereda sola: hay que sumarla ahí a propósito. Del lado del
backend, el endpoint de búsqueda tiene que aceptar el realm de la caja (Bearer
del device), igual que el resto de lo que lee el POS — nunca cookies.

El MCP (`context/58`) queda fuera de esta iteración: es Punto como fuente de
DATOS del comercio, no un canal de ayuda del producto. Se suma si se pide.

### D8 — El contenido es DATO, aunque lo escriba Punto. *(propuesta)*

Lo que devuelve la tool entra a la conversación como resultado de tool, nunca
como parte del system prompt. Aunque el autor sea Punto, un `.md` puede traer
por accidente texto con forma de instrucción (un ejemplo de conversación, una
nota interna), y no debe poder cambiar las reglas del agente.

## 4. Modelo *(propuesta)*

Lowercase sin comillas, como todo el schema desde la mig 150.

```sql
CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE TABLE kb_document (
  documentid  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  slug        VARCHAR(160) NOT NULL UNIQUE,   -- identidad: nombre del archivo
  title       VARCHAR(200) NOT NULL,          -- primer # del .md, o el slug
  body        TEXT NOT NULL,                  -- el .md completo, para reprocesar
  isactive    BOOLEAN NOT NULL DEFAULT TRUE,
  updatedby   UUID,                           -- admin_user
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE kb_chunk (
  chunkid     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  documentid  UUID NOT NULL REFERENCES kb_document ON DELETE CASCADE,
  headingpath TEXT NOT NULL,                  -- "Impresoras > Cocina > Conectar"
  content     TEXT NOT NULL,
  sort        INT NOT NULL,
  search      TSVECTOR NOT NULL               -- título + ruta + contenido, pesados
);

CREATE INDEX idx_kb_chunk_search ON kb_chunk USING GIN (search);
```

`body` guarda el `.md` original para poder re-fragmentar si cambia la regla de
D5, sin pedirle al owner que vuelva a subir todo.

⚠ `unaccent` NO es `IMMUTABLE`, así que no se puede usar dentro de una columna
generada ni de un índice de expresión. El `tsvector` se calcula en el servicio
al insertar (con la ruta de encabezados pesada más que el contenido), y la
consulta aplica `unaccent` al texto buscado.

## 5. Fases *(propuestas)*

| Fase | Qué |
|---|---|
| **K1** | Migración + servicio de carga (fragmentado, reemplazo atómico, auditoría) + sección en `/admin` (subir varios `.md`, listar, desactivar, eliminar) |
| **K2** | Endpoint de búsqueda + tool `search_punto_help` + regla en el prompt del panel |
| **K3** | Sumarla al agente de la caja (allowlist + realm device) |
| **K4** | Medir: registrar búsquedas sin resultado relevante, para saber qué falta documentar y si hace falta el vector de D3 |

K4 es la que convierte la base en algo que mejora: las preguntas sin respuesta
son la lista de documentación pendiente.

## 6. Arquitecturas RECHAZADAS

- **Reusar `content/sitio/*.md`.** Es contenido comercial para prospectos,
  generado desde el código del sitio y consumido por otro bot. Mezclarlo
  confunde "qué vende Punto" con "cómo se usa Punto".
- **`pgvector` en la v1.** Cambia la imagen de la BD de producción y suma un
  proveedor de embeddings. Ver D3: si hace falta, entra como columna híbrida
  sobre las mismas tablas.
- **Inyectar la base entera en el system prompt.** Costo en cada request y
  pérdida de relevancia. Ver D4.
- **Guardar los `.md` solo en S3 y buscar leyéndolos.** La búsqueda necesita el
  texto indexado en la base; S3 no aporta nada que `kb_document.body` no tenga.
- **Base por tenant.** La documentación del producto es una sola. Lo que es de
  cada comercio ya tiene su lugar en `context/69`.
- **Fragmentar por cantidad fija de caracteres.** Corta pasos a la mitad y
  pierde de qué sección sale cada fragmento. Ver D5.
