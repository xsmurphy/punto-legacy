# 03 — Listas de precio

> Estado del doc: verificado contra código 2026-08-16
> Responsable de la última verificación: sesión 2026-08-16 (docs impuestos + listas de precio)

## 1. Qué resuelve

Permite cobrar un precio distinto al de catálogo según quién compra o dónde
—descuentos por cliente, recargos por sucursal/canal, ajustes generales o
precios fijos por ítem— sin tocar `item.itemPrice`. Resuelve la pregunta "¿a
cuánto le vendo ESTO a ESTE cliente en ESTA sucursal ahora mismo?".

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `price_list` | Encabezado: `priceListName`, `defaultAdjustment` (DECIMAL(6,2), % ajuste global — negativo=descuento, positivo=recargo), `validFrom`/`validTo`, `status` (bool). | Sin `validFrom`/`validTo` = vigente siempre. `status=false` la desactiva aunque esté en rango. |
| `price_list_item` | Override por ítem: `fixedPrice` (DECIMAL(15,2), absoluto) o `itemAdjustment` (DECIMAL(6,2), % que reemplaza el `defaultAdjustment`). `UNIQUE(priceListId, itemId)`. | **Mutuamente excluyentes** — si `fixedPrice` está seteado, `itemAdjustment` se descarta al guardar (`PriceListService.php:254-257`). Sin columna `updatedAt` (mig 32) — solo `createdAt`, lo que rompe el modelo de delta incremental que sí tiene `item`/`contact` (ver `context/44-listas-de-precio-offline.md`). |
| `contact.data->>'priceListId'` | Lista asignada al cliente (JSONB). | String plano, sin validar que la lista exista/esté vigente en el momento de asignarla — la vigencia se chequea recién al resolver. |
| `outlet.data->>'priceListId'` | Lista default de la sucursal (JSONB). | Mismo patrón que la de contacto. |

## 3. Reglas de negocio

1. **Prioridad de resolución de la lista activa**: `$overridePriceListId` (manual del cajero) > `contact.data->>'priceListId'` > `outlet.data->>'priceListId'` (`PriceListService::resolveActiveList()`, `api/lib/services/PriceListService.php:417-459`, candidatos armados en ese orden en la línea 424-428). Si un candidato de mayor prioridad existe pero no está vigente, cae al SIGUIENTE nivel de prioridad, no a "sin lista" directamente (comentario explícito en línea 455). El cajero elige la lista manual desde el drawer de opciones de venta (`frontend/components/register/sale-options-drawer.tsx:351-354,734`, guardada en `useCartStore.priceListId`).
2. **Vigencia**: la query de `resolveActiveList` filtra `status = true AND (validFrom IS NULL OR validFrom <= now()) AND (validTo IS NULL OR validTo >= now())` (`PriceListService.php:436-439`) — una lista vencida o desactivada simplemente no matchea y el resolver prueba el siguiente candidato de la cadena de prioridad; si ninguno vigente, se factura a precio base (`PriceListService.php:300-306` / `350-361`).
3. **Ajuste general vs override por ítem — orden de aplicación** (`applyList()`, `PriceListService.php:496-522`): `fixedPrice` del ítem (si existe, ignora todo lo demás) > `itemAdjustment` del ítem (reemplaza el `defaultAdjustment` de la lista) > `defaultAdjustment` de la lista > precio base sin tocar. Es jerarquía estricta, no acumulativa — un ítem con `itemAdjustment=-10` en una lista con `defaultAdjustment=-30` cobra -10%, no -40%.
4. **La resolución es server-side y es una MUTACIÓN sin queryKey.** El POS resuelve precios con `POST /v1/price_resolve` (`useResolvePrices`, `frontend/hooks/use-price-lists.ts:113-128`), disparado por `usePriceContext` (`frontend/hooks/use-price-context.ts`) cuando cambia cliente/lista manual/set de ítems no-overridden, con debounce de 300ms. Al ser `useMutation` (no `useQuery`), React Query no la invalida sola cuando cambian los datos server-side — de ahí el bug real ya corregido: un carrito armado ANTES de que un admin editara `defaultAdjustment` seguía cobrando el precio resuelto viejo, porque nada disparaba una re-resolución (comentario explícito en `use-price-context.ts:26-31`, commit `d1a2b4c7`). Hoy se re-resuelve por dos vías: (a) el `lineKey` derivado del efecto cambia cuando cambia el set de ítems/precio base del carrito, y (b) `priceResolveNonce` (`usePosUIStore`), que `useRealtimeSync` incrementa al recibir un evento WS de entity `'price-list'` (antes publicaba como `'item'`, alias muerto que no lo disparaba — mismo commit `d1a2b4c7`, `api/bootstrap.php` §`syncSectionAfterMutation`) — así un carrito ya armado se re-resuelve en caliente si el admin edita la lista mientras el cliente sigue en caja.
5. **Offline: NO funciona todavía — hueco abierto de plata.** `usePriceContext` atrapa cualquier error del POST (`onError`, `use-price-context.ts:91-95`) y deja los precios en su valor BASE, a propósito, "para no romper la venta". Consecuencia textual y verificada en el plan: **un cliente con lista de -20% que compra sin conexión paga precio lleno**, sin ningún error visible para el cajero — es exactamente el mismo patrón de riesgo silencioso que las trampas de §7. `context/44-listas-de-precio-offline.md` es un plan CERRADO PERO SIN IMPLEMENTAR (estado explícito "plan, sin implementar" al 2026-08-16): propone extraer un motor espejo TS/PHP (mismo patrón que impuestos, ver `04-impuestos.md §5`) y bajar cabecera+overrides al bootstrap, pero ninguna de sus 7 fases (D0-D6) está hecha. Hoy, sin red, el sistema factura como si el cliente no tuviera lista asignada.
6. **`PriceListService` rompió DOS VECES en silencio, mismo endpoint, mismo síntoma ("aplicar una lista no cambia nada" sin error visible)** — la trampa más importante del módulo:
   - **T4a** (`ef6bab48`): identificadores camelCase QUOTEADOS (`pl."defaultAdjustment"`, `"priceListId"`, etc.) en las queries. Postgres pliega identificadores SIN comillas a lowercase, pero uno quoteado es case-sensitive — las columnas reales son lowercase, así que cada SELECT/UPDATE/DELETE con esas referencias fallaba con "column does not exist". El front atrapaba el error en `usePriceContext` y seguía con precio base sin mostrar nada.
   - **T4b** (`e03c8a2e`), defecto independiente encontrado DESPUÉS de deployar T4a — la query ya funcionaba pero el precio seguía sin cambiar: `resolveActiveList()` devolvía `(array) $list`, y `$list` es un `CaseInsensitiveArray` (un OBJETO) — castear un objeto a array da sus propiedades privadas mangleadas, no los campos de la fila. Río abajo `$list['defaultAdjustment']` daba `null`, el ajuste quedaba en 0, y TODA lista devolvía precio base. Fix: usar `ncmRow()` (conversión única del DB layer), nunca `(array) $row` sobre un `CaseInsensitiveArray` — mismo patrón de bug documentado como invariante del DB layer en otros módulos.
   - **Por qué es la trampa más importante**: el front atrapa el fallo a propósito (regla 5, "no romper la venta") — eso significa que un bug en este servicio **no lanza ningún error observable**, solo cobra el precio equivocado en silencio. No hay log de error, no hay toast, no hay excepción — solo plata mal cobrada hasta que alguien audita manualmente contra producción (como se hizo para verificar ambos fixes: `1.200.000 → 960.000` post-fix vs `1.200.000 → 1.200.000` pre-fix).

## 4. Flujos principales

- **Resolución en caja (POS)**: `usePriceContext` arma el batch de `{itemId, basePrice}` de las líneas no-overridden del carrito y llama `POST /v1/price_resolve` con `contactId`/`outletId`/`priceListId` manual. `PriceListService::resolvePriceBatch()` (`PriceListService.php:334-402`) resuelve la lista activa UNA VEZ y aplica `applyList()` a todos los ítems en una sola query batch de overrides. `onSuccess` pisa `unitPrice` de cada línea vía `applyResolvedPrices` (`frontend/lib/cart/store.ts:1058-1090`), preservando el delta de add-ons ya sumado y marcando `basePrice` si la línea no lo tenía.
- **Precio editado a mano por el cajero**: marca `priceOverridden: true` en la línea — `usePriceContext` la excluye del batch y `usePriceContext`/`restoreBasePrices` nunca la pisan, ni siquiera al re-resolver por nonce (`use-price-context.ts:48-53`, `store.ts:1072`).
- **Sin cliente y sin lista manual**: `restoreBasePrices()` vuelve `unitPrice = basePrice` en todas las líneas no-overridden (`store.ts:1096-1104`), sin llamar al backend.
- **Admin edita una lista en caliente**: `syncSectionAfterMutation` publica evento `'price-list'` → `useRealtimeSync` bumpea `priceResolveNonce` → el efecto de `usePriceContext` se re-dispara con el mismo `lineKey` (cambia el nonce, no las líneas) → nuevo POST a `/v1/price_resolve` con los precios base actuales → el carrito ya armado recibe el precio nuevo sin que el cajero haga nada.
- **Error / borde — fallo del resolver o desconexión**: `onError` deja los precios como estaban (ver regla 5). No hay reintento automático ni aviso al cajero — el carrito sigue operable pero cobrando precio base.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| POS (carrito) | Envía `{itemId, basePrice}` por línea no-overridden; recibe `{itemId, price, priceListName}` y pisa `unitPrice` | Que el backend es la ÚNICA fuente de verdad del precio resuelto — el front nunca calcula el ajuste localmente (no hay motor espejo hoy, ver regla 5 y §7) |
| Contactos | `contact.data->>'priceListId'` determina la lista del cliente si no hay override manual | Que el campo existe y apunta a una lista real; si la lista fue borrada o no está vigente, cae en silencio al siguiente nivel de prioridad, no hay aviso al asignar |
| Sucursales (`outlet`) | `outlet.data->>'priceListId'` es el último nivel de prioridad antes del precio base | Mismo patrón — sin validación al asignar |
| Impuestos (`TaxEngine`) | El precio YA resuelto por lista (`line.unitPrice` post `applyResolvedPrices`) es el `price` que llega a `SaleService::enrichWithTaxes()` como base de cálculo del IVA | **Verificado, no asumido**: el descuento/recargo de lista SÍ afecta la base imponible — se aplica ANTES del cálculo de impuesto, no sobre un neto ya gravado. Evidencia: `create-sale.ts:327` manda `line.unitPrice` (ya ajustado por lista) como `price`; `SaleService.php:2224` usa `(float)($sD['price'] ?? 0)` como `unitPrice` del motor de impuestos sin distinguir si vino de lista o de catálogo — no hay ningún paso que "revierta" el ajuste antes de gravar |
| Sincronización | El WS publica entity `'price-list'` propia (post `d1a2b4c7`) para que el front sepa invalidar/re-resolver | Antes de `d1a2b4c7`, publicaba alias a `'item'`, que el front no escuchaba para este propósito — código muerto silencioso |

## 6. Offline (solo módulos del POS)

**No funciona.** Ver regla 5 — es el hueco más caro del módulo. La regla base del proyecto (lo que se EMITE va offline) no se cumple acá: sin conexión, el POS sigue pudiendo emitir la venta, pero la EMITE al precio equivocado si el cliente tenía lista asignada, porque no hay motor local que resuelva el ajuste sin el round-trip a `/v1/price_resolve`. `context/44-listas-de-precio-offline.md` propone el fix (motor espejo + bajar cabecera/overrides al bootstrap, mismo patrón que impuestos) pero el plan está sin implementar — ninguna de sus fases D0-D6 está hecha a la fecha de este doc.

## 7. Huecos conocidos y NO verificado

- **Hueco abierto de plata, sin mitigar hoy**: cliente con lista de descuento + caja offline = paga precio lleno, sin error visible para nadie (regla 5). No hay ni siquiera un indicador visual en el POS de "esta venta no pudo resolver lista de precio" — el fallback es transparente para el cajero.
- **NO VERIFICADO**: volumen real de `price_list_item` en producción. `context/44` lo señala explícitamente como paso 0 no confirmado (el intento de SSH a prod para contar filas fue bloqueado por el classifier de auto-mode a mitad de sesión).
- **NO VERIFICADO**: si existen tenants con `priceListId` asignado en `contact`/`outlet` apuntando a una lista ya borrada (sin FK explícito desde el JSONB hacia `price_list`, a diferencia de `price_list_item.priceListId` que sí tiene `REFERENCES ... ON DELETE CASCADE`). El código lo tolera (cae al siguiente nivel de prioridad) pero no se auditó el dato real.
- `resolvePrice()`/`resolvePriceBatch()` redondean siempre a 2 decimales (`round($price, 2)`, `PriceListService.php:302,321,355,394`) sin consultar `currencyDecimals()` del tenant (que sí usa el motor de impuestos, PY=0 decimales) — **NO VERIFICADO** si esto produce una divergencia visible en tenants de 0 decimales (el precio resuelto podría llevar centavos que el resto del sistema no usa); no se encontró evidencia de que esto cause un bug reportado, se deja señalado.
- `price_list_item` no tiene `updatedAt` (mig 32) — el modelo de sync incremental que usan `item`/`contact` no tiene de dónde leer un delta por fila acá; es la razón técnica por la que el plan offline (`context/44`) trata el override como parte del payload del ítem en vez de como su propia sección de sync.

## 8. Planes y decisiones relacionados

- `context/44-listas-de-precio-offline.md` — plan cerrado en diseño, SIN IMPLEMENTAR. Motor espejo TS/PHP (precedente: `04-impuestos.md §3.5`), sync del override enganchado al mecanismo de `item` (`context/45-satelites-item-contact-sync.md`), decisión pendiente de sign-off del owner sobre si el motor local es fallback-only o default-con-reconciliación.
- `context/43-sync-incremental.md` — modelo de delta de `item`/`contact` sobre el que se engancharía el override de lista.
- `context/15-realtime-sync-plan.md` §Fix listas de precios — el fix de invalidación en caliente (commit `d1a2b4c7`), ya en `main`.
