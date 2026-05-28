# ADR-002: Admin realm separado — super-admins de plataforma en tabla propia

**Estado:** Aceptado
**Fecha:** 2026-05-28
**Deciden:** xstian (producto/arquitectura) + Claude

## Contexto

Hoy los "super-admins de plataforma" se modelan como un **tenant especial**: existe una
empresa `MASTER_COMPANY_ID` (env var) y los usuarios de esa empresa con el flag `SAAS_ADM`
activo son tratados como super-admins. El login es el mismo que cualquier tenant (tabla
`contact`, sha256+salt+HASH_TIMES) y el gate de identidad es el redirect de `@.php:11`
(`if COMPANY_ID == MASTER_COMPANY_ID → panel de admin`).

Dos problemas obligan a replantear esto:

1. **Mezcla de abstracciones**: un super-admin de plataforma no es un empleado de ninguna
   empresa. Meterlo en `contact` (con `companyId` obligatorio) es un hack. Los queries
   multi-tenant que filtran por `companyId` necesitan un carve-out para esa company especial.

2. **Seguridad del realm**: el mismo secret JWT, la misma cookie `_jwt_panel`, y el mismo
   esquema de password para tenants y admins de plataforma. Un token de tenant robado podría
   potencialmente escalarse al contexto de admin si hay un bug en el gate.

## Decisión

Crear un **admin realm criptográficamente aislado**:

- **Tabla propia `admin_user`** — sin `companyId`, password bcrypt, campos mínimos para
  auditoría (`createdBy` self-FK, `lastLoginAt`). Ver `04-modelo-de-dominio.md § admin_user`.
- **Ruta de login separada:** `/admin` (distinta de `/panel/API/auth.php`).
- **JWT separado:** cookie `_jwt_admin`, secret `ADMIN_JWT_SECRET`, claim `aud:"admin"`.
  Un `_jwt_panel` de tenant NUNCA valida en `/admin` y viceversa.
- **Password scheme independiente:** bcrypt (`password_hash` PHP) — distinto al
  sha256+salt+HASH_TIMES de `contact`. No intercambiables.
- **Franchiser NO va a `/admin`:** es un tenant con acceso cross-tenant acotado (via
  `franchiser_to_tenant`), opera sobre el mismo panel tenant. Ver ADR-001.
- **Login de tenant por teléfono (F5):** los tenants mantienen su propio login pero
  el identificador pasa a ser el teléfono (ya soportado en `findEmailOrPhoneLogin`).

### El modelo de realms

```
/panel/API/auth.php  →  contact (tenant employees)  →  _jwt_panel  (JWT_SECRET)
/admin/login         →  admin_user (plataforma)      →  _jwt_admin  (ADMIN_JWT_SECRET, aud:"admin")
```

Ningun token cruza realms. `adminMiddleware` valida `aud:"admin"` + secret propio.
`apiMiddleware` del panel NO acepta `_jwt_admin`.

### MASTER\_COMPANY\_ID post-decisión

`MASTER_COMPANY_ID` deja de ser **gate de identidad** (determinar "este usuario es admin").
Su rol residual: scope de datos de plataforma / billing. El flag `SAAS_ADM` y el redirect
de `@.php:11` siguen intactos hasta **F4** (fase de alto riesgo, va última). No tocar
esa lógica hasta entonces.

## Plan de implementación (6 fases, no big-bang)

| Fase | Scope | Riesgo |
|------|-------|--------|
| F0 ✅ | `admin_user` (migración 09) + `bootstrap_seed.php` + env vars | Bajo — solo schema, nada del runtime lo usa todavía |
| F1 | Auth `/admin` (login form, JWT, `adminMiddleware`, rate-limit) | Bajo |
| F2 | CRUD de admins en `/admin` (no desactivar el último admin activo) | Bajo |
| F3 | Home `/admin` + gestión de companies + billing (cross-tenant en `lib/admin`) | Medio |
| F4 | Desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant | **Alto** — va último |
| F5 | Login de tenant por teléfono (independiente de F1–F4) | Bajo |
| F6 | Decommission `main.php` admin + hardening + verificar aislamiento E2E | Bajo |

## Opciones consideradas

### Opción A — tabla `admin_user` propia (ELEGIDA)

**Pros:** aislamiento real de realms; password scheme independiente; no contamina el modelo
multi-tenant; escalable (RBAC de plataforma futura). **Cons:** migración de los usuarios admin
existentes; hay que construir el CRUD de admins.

### Opción B — flag en `contact` más fuerte (mejorar el gate actual)

**Pros:** cero schema nuevo. **Cons:** sigue mezclando abstracciones; el `companyId` sigue siendo
un hack; el mismo JWT secret sigue siendo un riesgo; `SAAS_ADM` es un flag frágil.

### Opción C — tabla `admin_user` pero mismo JWT secret

**Pros:** tabla propia. **Cons:** un token de tenant sigue siendo válido técnicamente en el admin
path si alguien manipula el payload. Aislamiento de secret es no-negociable para un realm de
plataforma.

## Trade-offs

- **Pagamos:** más código (nuevo realm, CRUD de admins, migrar seed). Dos rutas de auth que mantener.
- **Ganamos:** aislamiento real de seguridad; modelo de dominio limpio (admin ≠ tenant employee);
  base para RBAC de plataforma; no-regression para el panel tenant durante la migración (F0–F3
  son invisibles para tenants).

## Consecuencias

- Toda lógica nueva de administración de plataforma va en `panel/admin/` con `adminMiddleware`.
- Ningún archivo bajo `panel/admin/` debe incluir `api_middleware.php` (panel tenant).
- Al implementar F1, el BFF de `/admin/login` debe devolver la cookie `_jwt_admin` con `HttpOnly`,
  `Secure`, `SameSite=Lax` — igual que `_jwt_panel` pero con nombre y secret distintos.
- F4 requiere auditoría completa de todo el código que lee `SAAS_ADM` o compara
  `COMPANY_ID == MASTER_COMPANY_ID`. Mapear antes de ejecutar.

## Action Items

1. [x] F0: tabla `admin_user` + `bootstrap_seed.php` + env vars (commit 01a8929, 2026-05-28).
2. [ ] F1: `panel/admin/login.html` + `panel/admin/bff/login.php` + `panel/admin/lib/adminMiddleware.php`.
3. [ ] F2: CRUD de admins (list/create/update/deactivate — con guard "no desactivar el último activo").
4. [ ] F3: migrar gestión de companies y billing desde `main.php` a rutas `/admin/*`.
5. [ ] F4: auditar y desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant.
6. [ ] F5: login de tenant por teléfono (independiente).
7. [ ] F6: decommission + hardening + test de aislamiento cross-realm.
