/**
 * Store del lock screen del POS.
 *
 * `locked === true` → el overlay del lock screen tapa todo y captura input.
 * Desbloquea con PIN validado contra los users locales del catalog store
 * (precacheados en el bootstrap — sin roundtrip al backend).
 *
 * Lo dispara el item "Bloquear" del menú de usuario en /pos.
 *
 * ── El lock screen es SIEMPRE lo primero (owner, 2026-08-24) ─────────────
 * Al abrir la app y ante CUALQUIER recarga, lo primero que se ve es el lock
 * screen. Sin excepciones: ni por cantidad de operadores, ni por "ya se
 * desbloqueó antes en esta pestaña". Por eso `locked` arranca en `true` y NO
 * se persiste — un estado rehidratado no puede dejar la caja abierta.
 *
 * Esto REVIERTE la decisión anterior (2026-06-28), que persistía `locked` en
 * sessionStorage justamente para que un F5 no volviera a pedir el PIN: el
 * caso doloroso era el reload automático por ChunkLoadError tras un deploy
 * (`lib/pos/chunk-error-reload.ts`), que relockeaba al cajero en hora pico.
 * El owner la revirtió a sabiendas, y el dato que la hace barata es que el
 * carrito NO persiste (no hay `persist()` en `lib/cart/store.ts`): una
 * recarga ya pierde la venta en curso, así que volver a pedir el PIN no
 * agrega ninguna pérdida nueva.
 *
 * Corolario: `autoLockDone` (el flag de "ya auto-lockeé una vez por sesión")
 * dejó de existir. Su única razón de ser era no relockear tras un remount o
 * un F5, que es exactamente el comportamiento que ahora se busca.
 *
 * `sessionStorage` sigue siendo la storage correcta para lo que SÍ persiste
 * (`activeUser`, `operatorToken`): sobrevive recargas de ESA pestaña, pero
 * cerrar la app lo tira.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

interface LockState {
  /**
   * Arranca en `true` y NO se persiste: abrir la app o recargarla muestra el
   * lock screen, siempre (ver docblock). Solo `unlock()` lo baja, y solo hasta
   * la próxima carga de la página.
   */
  locked: boolean
  activeUser: { id: string; name: string } | null
  /**
   * Afirmación de operador firmada por la API (`OperatorAssertion`), obtenida
   * al validar el PIN contra el server.
   *
   * ── Por qué hace falta, si ya está `activeUser` ──────────────────────────
   *
   * `activeUser` sale del match LOCAL del PIN contra los hashes precacheados:
   * es suficiente para pintar "Bienvenido, Ana" y para atribuir la venta, pero
   * el backend no tiene por qué creerle — es un dato que el cliente eligió.
   * Para AUTORIZAR por persona (la exclusividad de espacios: "este espacio es de
   * otro mozo") hace falta algo que el browser no pueda fabricar, y eso es
   * este token: lo firma el server, y solo lo entrega tras verificar el PIN
   * contra `contact.pinhash`.
   *
   * Puede ser null aunque haya `activeUser`: el match local funciona offline y
   * la llamada al server no. Es el estado correcto — sin conexión no hay
   * identidad probada, y las operaciones que la exigen (Espacios) son
   * online-only de todos modos.
   */
  operatorToken: string | null
  /**
   * Permisos del operador identificado, tal como los devolvió `/api/pos/unlock`
   * al validar el PIN contra la BD: los `pos.*` más la allowlist puntual de
   * claves de panel que gobiernan algo que se ve en la caja (hoy
   * `reports.sales.view`, que decide si el asistente puede consultar ventas —
   * ver `api/v1/unlock-pin.php`).
   *
   * ── Por qué viven acá y no en el catálogo ────────────────────────────────
   *
   * Porque son un hecho sobre la PERSONA, no sobre el comercio ni sobre el
   * dispositivo. El roster del bootstrap (`catalog store`) proyecta a propósito
   * id/name/pinhash y nada más: es un payload que queda cacheado en una tablet
   * compartida, y el rol de cada empleado no tiene por qué estar ahí. Estos
   * permisos, en cambio, llegan solo a quien acaba de probar su PIN y se van
   * con él.
   *
   * Mismo ciclo de vida que `operatorToken` — se limpian en `lock()` y en
   * `reset()`, se persisten en la recarga — porque son el MISMO hecho probado
   * por el server en la misma respuesta. Separarlos permitiría el estado
   * incoherente de "tengo los permisos del encargado pero no la prueba de que
   * soy el encargado", que es justo lo que el backend rechazaría.
   *
   * Vacío no es "error": es el estado de un operador sin permisos POS extra, y
   * también el de un desbloqueo OFFLINE (donde tampoco hay token). Los
   * consumidores solo lo usan para no mentir en la UI — autorizar es del
   * backend.
   */
  operatorPermissions: string[]
  lock: () => void
  unlock: () => void
  setActiveUser: (user: { id: string; name: string } | null) => void
  setOperatorToken: (token: string | null) => void
  setOperatorPermissions: (permissions: string[]) => void
  /** Reset completo (logout / re-pair). */
  reset: () => void
}

export const useLockStore = create<LockState>()(
  persist(
    (set) => ({
      locked: true,
      activeUser: null,
      operatorToken: null,
      operatorPermissions: [],
      // Bloquear TIRA la afirmación firmada del operador: es una prueba de
      // identidad de alguien que acaba de irse de la caja, y no hay ninguna
      // operación que deba poder ejecutar en su nombre mientras no vuelva a
      // tipear su PIN. El desbloqueo pide una nueva (`/api/pos/unlock`), así
      // que no se pierde nada más que la ventana de riesgo. `activeUser`, en
      // cambio, se conserva: es solo a quién saludar y a quién atribuir, y el
      // próximo PIN lo sobrescribe. Los permisos se van CON el token: son la
      // capacidad de esa misma persona, y conservarlos sin la prueba de
      // identidad solo habilitaría una UI optimista que el backend rechaza.
      lock: () => set({ locked: true, operatorToken: null, operatorPermissions: [] }),
      unlock: () => set({ locked: false }),
      setActiveUser: (user) => set({ activeUser: user }),
      setOperatorToken: (token) => set({ operatorToken: token }),
      setOperatorPermissions: (permissions) => set({ operatorPermissions: permissions }),
      reset: () =>
        set({ locked: true, activeUser: null, operatorToken: null, operatorPermissions: [] }),
    }),
    {
      name: "punto.pos.lock",
      storage: createJSONStorage(() => sessionStorage),
      // v1 persistía `locked` y `autoLockDone`. Subir la versión descarta esas
      // entradas viejas en vez de arrastrarlas.
      version: 2,
      /** Solo el estado — las acciones no se serializan. */
      partialize: (s) => ({
        activeUser: s.activeUser,
        // El token sobrevive a la RECARGA pero no al BLOQUEO manual. No es una
        // inconsistencia, son dos eventos distintos:
        //
        //   - Recarga (F5, service worker, ChunkLoadError): la caja sigue
        //     atendida por la misma persona. Se persiste porque sin él el mozo
        //     perdería el acceso a SU propio espacio aunque vuelva a tipear el PIN
        //     offline (el `/api/pos/unlock` que lo re-emite necesita red).
        //   - Bloqueo manual (`lock()`): el operador se fue de la caja. Ahí el
        //     token se tira en el acto — ver la acción `lock` arriba.
        //
        // En ambos casos la pantalla queda bloqueada y no se emite ninguna
        // request, así que el token persistido no habilita nada por sí solo.
        // sessionStorage (no localStorage) le pone el techo correcto: cerrar la
        // app lo tira, como a la identidad que afirma.
        operatorToken: s.operatorToken,
        // Viajan con el token, por la misma razón y con el mismo techo: tras
        // una recarga el encargado tiene que seguir pudiendo intervenir el espacio
        // ajena sin volver a tipear el PIN, y tras un bloqueo no.
        operatorPermissions: s.operatorPermissions,
      }),
      /**
       * `locked: true` tiene que GANARLE a cualquier cosa que venga de la
       * storage. El merge default de zustand es un shallow `{...current,
       * ...persisted}`: bastaría con que una entrada vieja (v1) trajera
       * `locked: false` para reabrir la caja sin PIN. Se fuerza explícito.
       *
       * La rehidratación de `createJSONStorage(() => sessionStorage)` es
       * SÍNCRONA y ocurre al crear el store, así que no hay ventana de paint
       * con la caja desbloqueada: el primer render ya ve `locked: true`.
       */
      merge: (persisted, current) => ({
        ...current,
        ...(persisted as Partial<LockState> | undefined),
        locked: true,
      }),
    },
  ),
)
