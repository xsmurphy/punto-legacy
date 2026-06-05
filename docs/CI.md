# CI — Punto POS

Configuración mínima de Continuous Integration con GitHub Actions.

## Workflow `.github/workflows/ci.yml`

Se dispara en `push` a `main` y en `pull_request` a `main`. 3 jobs en paralelo:

### `php-lint`
- Setup: PHP 8.4 (ubuntu-latest)
- Detecta archivos `.php` cambiados vs base (`HEAD~` en push, base branch en PR)
- Corre `php -l` sobre cada uno
- Excluye: `vendor/`, `cach/`, `node_modules/`
- Falla el job si algún archivo tiene parse error

### `js-syntax`
- Setup: Node 20 (ubuntu-latest)
- Detecta archivos `.js` cambiados vs base
- Corre `node --check` sobre cada uno
- Excluye: `vendor/`, `cach/`, `node_modules/`, `*.min.js`
- Falla el job si algún archivo tiene syntax error

### `composer-validate`
- Setup: PHP 8.4 + composer v2
- Corre `composer validate --strict` en `app/composer.json` y `panel/composer.json`
- Garantiza que los manifestos están bien formados

## Diseño: ¿por qué solo archivos cambiados?

El repo tiene **deuda histórica** (3 archivos PHP rotos en `panel/` documentados abajo). Validar el repo entero bloquearía cada PR.

El CI checkea **solo lo que el PR tocó** (`git diff --diff-filter=ACMR`). Así:
- Bugs viejos no bloquean PRs nuevos (no introducidos por el cambio).
- Bugs nuevos sí bloquean (deuda nueva no se acumula).
- Cuando alguien toque un archivo roto, debe arreglarlo o el CI lo rechaza.

## Scripts locales

Reproducir lo que hace el CI desde el dev shell:

```bash
# Lint JS (todos los archivos no-minificados)
npm run lint:js

# Lint PHP (app + panel, no-vendor/cach)
npm run lint:php

# Ambos
npm run lint
```

Composer dedicado por módulo:

```bash
(cd app && composer lint:strict)
(cd panel && composer lint:strict)
```

## `.editorconfig`

Estándar de formato en raíz:
- UTF-8, LF, final newline, trim trailing whitespace
- 2 espacios general (4 en `.php`, tab en `Makefile`)
- Excepciones: `vendor/`, `cach/`, `*.min.{js,css}` no se enforzan

## Deuda histórica detectada por linter

Estos archivos rotos NO bloquean PRs (el CI usa diff), pero deben arreglarse cuando se toquen:

| Archivo | Error | Línea |
|---|---|---|
| `panel/a_report_schedule.php` | `Unclosed '{'` | 449 |
| `panel/a_report_production.php` | `Unclosed '{'` | 421 |
| `panel/languages/en.php` | `syntax error, unexpected ','` | 45 |

**3 de 378 archivos PHP en el repo (0.8%)**. Confirma que la deuda es chica y aislada.

## Próximos pasos (no en este slice)

Listado en orden de ROI esperado:

1. **PHPUnit** — empezar con tests para `api/lib/services/*` (los Services nuevos con namespace).
2. **Psalm** — static analysis para `api/lib/` (código moderno con tipos).
3. **php-cs-fixer** — formatter automático (PSR-12) sobre `api/lib/`.
4. **ESLint** — sobre los módulos JS pequeños (`api-client.js`, `bff/lib/*.js`), evitando `app/scripts/app.js` hasta el split.
5. **Playwright** — E2E del POS (login + venta simple).
6. **Auto-fix** del workflow CI (corre `composer install`, `npm ci`, etc. en setup).

Cada uno es un sub-slice. Ninguno toca código de prod.
