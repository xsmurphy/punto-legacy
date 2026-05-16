<!-- REGLA: Actualizar cuando se agregue un servicio nuevo, cambie la comunicación entre
     componentes, o se modifique un god node. NO actualizar por cambios internos a un módulo. -->

# 02 — Arquitectura

## Vista de 30 segundos

```
┌─────────────────────────────────────────────────────────────┐
│                        BROWSER                               │
│  ┌──────────┐    ┌──────────┐    ┌──────────────────────┐  │
│  │  /app    │    │  /panel  │    │  standalone (KDS,CDS) │  │
│  └────┬─────┘    └────┬─────┘    └──────────┬───────────┘  │
└───────┼────────────────┼─────────────────────┼──────────────┘
        │ HTTP           │ HTTP                │ WebSocket
        ▼                ▼                     ▼
┌───────────────┐  ┌───────────────┐  ┌──────────────────┐
│  PHP /app     │  │  PHP /panel   │  │  ws-server       │
│  (action.php) │  │  (API/*.php)  │  │  (Node.js:6001)  │
└───────┬───────┘  └───────┬───────┘  └────────┬─────────┘
        │                  │                    │
        ▼                  ▼                    ▼
┌──────────────────────────────────────┐  ┌─────────┐
│         PostgreSQL 16                 │  │  Redis  │
│  (puntoDB — multi-tenant por         │  │  7      │
│   companyId en cada tabla)           │  │         │
└──────────────────────────────────────┘  └─────────┘
                                               ▲
                                               │ Pub/Sub
                                    ┌──────────┘
                                    │
                              PHP wsPublish()
                          (fsockopen → RESP raw)
```

## Flujo de datos principal

1. **Request HTTP** → PHP valida JWT (cookie `_jwt` o `_jwt_panel`) → ejecuta lógica → responde JSON
2. **Evento real-time** → PHP `wsPublish()` → Redis PUBLISH → ws-server → broadcast a clientes suscritos
3. **Facturación electrónica** → PHP → EFATech/TaxPro API → respuesta → guarda en BD

## Patrones arquitectónicos

| Patrón | Dónde |
|--------|-------|
| Monolito con API REST emergente | `/panel/API/*.php` (93 endpoints) |
| Action dispatcher | `/app/action.php` (80+ acciones vía param `l=`) |
| Pub/Sub bridge | PHP → Redis → Node.js WS → Browser |
| JSONB extensible | Columnas `config`, `data`, `meta` en tablas principales |
| UUID v7 como PK | Todas las tablas (via `ncmInsert()`) |
| Multi-tenant por filtro | `companyId` en WHERE de toda query |

## God nodes (más rompen si se tocan mal)

Derivados de `graphify-out/GRAPH_REPORT.md` (medido sobre 2555 nodos / 4058 edges).
Para detalle vivo: leer ese reporte antes de tocar estas funciones.

### Funciones críticas (medidas)

| Función | Edges | Dónde | Qué hace |
|---------|------:|-------|----------|
| `ncmExecute()` | 124 | `panel/includes/functions.php` + duplicado en `app/` | Ejecutor de queries con cache. Todo pasa por acá. |
| `make_xlsx_lib()` | 82 | exports | Generador de archivos XLSX |
| `validity()` | 80 | `functions.php` | Validador genérico de datos |
| `simple_html_dom_node` | 49 | vendor | Parser HTML |
| `iftn()` | 46 | `functions.php` | Helper if-then-null |
| `toUTF8()` | 40 | `functions.php` | Normalización de encoding |
| `DB` | 26 | clase global | Wrapper de ADOdb |
| `getROC()` | 23 | `functions.php` | Cálculo de ROC (retorno sobre capital) |

### Archivos críticos (por tamaño + responsabilidad)

| Archivo | Por qué es god |
|---------|---------------|
| `panel/includes/functions.php` (282KB) | Host de `ncmExecute()`, `validity()`, `iftn()`, `toUTF8()` |
| `app/includes/functions.php` | Duplicado parcial — cambios al panel suelen requerir sync acá |
| `app/action.php` (143KB) | Dispatcher de 80+ acciones del POS |
| `panel/API/lib/api_middleware.php` | Auth de los endpoints migrados |
| `app/includes/jwt_middleware.php` | Auth de /app |
| `ws-server/index.js` | Único archivo del WS |

**Cross-coupling observado**: muchas funciones de `app/includes/functions.php` llaman
a funciones de `panel/includes/functions.php`. No son módulos independientes.

## Comunicación entre módulos

| De → A | Mecanismo | Ejemplo |
|--------|-----------|---------|
| Browser → PHP | HTTP (fetch/AJAX) | Login, CRUD, queries |
| PHP → Browser (real-time) | Redis Pub/Sub → ws-server → WebSocket | Orden nueva en KDS |
| PHP → API externa | HTTP client (curl) | Facturación electrónica, SMS |
| App ↔ Panel | Comparten BD directamente | Misma PostgreSQL, mismo schema |

## Decisiones arquitectónicas vigentes

- **No microservicios** (excepto ws-server) — el monolito funciona y se moderniza in-place
- **No ORM moderno** — ADOdb es legacy pero funcional; las queries son explícitas
- **API dentro de panel/** — no se separa en repo aparte (no vale la pena aún)
- **Agente IA como microservicio Python separado** — no toca el monolito PHP
