"use client"

/**
 * Lock screen del POS — el cajero "bloquea" la caja y debe ingresar su PIN
 * (4 dígitos) para reanudar. No hay input visible: captura las teclas a
 * nivel window y muestra 4 círculos que se llenan a medida que se tipea.
 *
 * Se muestra SIEMPRE al abrir la app y ante cualquier recarga (owner
 * 2026-08-24) — ver `lib/pos/lock-store.ts`. También lo dispara el item
 * "Bloquear" del menú de usuario.
 *
 * Roster: los operadores contra los que se valida bajan DENTRO del bootstrap
 * del POS (`/v1/bootstrap` → `users`, proyección id/name/pinhash de los
 * habilitados en la sucursal del device) y quedan en el snapshot offline, así
 * que el PIN se valida sin red. NO se pide a `/v1/users`: ese endpoint exige
 * `contacts.user.view`, permiso que el rol `device` no tiene desde la mig 162.
 *
 * PIN: validado localmente con SHA-256 (Web Crypto API) contra los hashes del catalog store.
 * Decision del owner (2026-06-25): SHA-256 (más simple, más rápido en browser, matchea legacy).
 * POST best-effort a /api/pos/audit-unlock solo para logging — si falla (offline),
 * el unlock ya ocurrio de todas formas.
 *
 * UX:
 *   - Logo Punto centrado.
 *   - 4 círculos: vacío (solo borde) → relleno (pop animado al pintar).
 *   - Tipeás un dígito → pinta un círculo + animate-pin-pop.
 *   - Backspace borra el último.
 *   - Llega a 4 → valida. OK → unlock(). Falla → shake + reset + mensaje sutil.
 *   - ESC no desbloquea (es lock real).
 */

import * as React from "react"
import { Lock, KeyRound, CloudAlert, MonitorSmartphone } from "lucide-react"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { useLockStore } from "@/lib/pos/lock-store"
import { useCatalogStore } from "@/lib/catalog/store"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import { posFetch } from "@/lib/api/pos-fetch"

const PIN_LENGTH = 4

export function LockScreen() {
  const locked = useLockStore((s) => s.locked)
  const unlock = useLockStore((s) => s.unlock)
  const setActiveUser = useLockStore((s) => s.setActiveUser)
  const setOperatorToken = useLockStore((s) => s.setOperatorToken)
  const setOperatorPermissions = useLockStore((s) => s.setOperatorPermissions)
  const outletName = useCatalogStore((s) => s.outlet?.name)
  const users = useCatalogStore((s) => s.users)
  const catalogStatus = useCatalogStore((s) => s.status)
  // El roster puede venir del snapshot offline. Importa para el aviso de abajo:
  // un roster vacío traído de la cache no prueba que el comercio no tenga PINs,
  // solo que este device no los tiene todavía.
  const catalogFromCache = useOfflineSyncStore((s) => s.catalogFromCache)
  // El bootstrap que hidrató el catálogo no traía la clave `users` — distinto
  // de traerla vacía. Ver el bloque de las tres causas más abajo.
  const rosterMissing = useCatalogStore((s) => s.rosterMissing)

  const [pin, setPin] = React.useState("")
  const [shake, setShake] = React.useState(false)
  const [error, setError] = React.useState(false)
  // Versión del slot que recién se "pintó", para retrigger del bounce sin
  // re-animar los anteriores cuando React reusa el mismo DOM.
  const [poppedIndex, setPoppedIndex] = React.useState(-1)

  // Ref al input invisible — necesario para abrir el teclado virtual en mobile.
  const hiddenInputRef = React.useRef<HTMLInputElement>(null)

  // Resetear estado cada vez que entra en locked (evita PIN colgado).
  React.useEffect(() => {
    if (locked) {
      setPin("")
      setShake(false)
      setError(false)
      setPoppedIndex(-1)
    }
  }, [locked])

  // Captura de teclas mientras está locked.
  React.useEffect(() => {
    if (!locked) return
    const onKey = (e: KeyboardEvent) => {
      // Sin modificadores — no atrapamos atajos del sistema.
      if (e.metaKey || e.ctrlKey || e.altKey) return

      if (e.key === "Backspace") {
        e.preventDefault()
        setError(false)
        setPin((prev) => prev.slice(0, -1))
        return
      }

      // Dígitos 0-9 (pad o número de fila).
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setError(false)
        setPin((prev) => {
          if (prev.length >= PIN_LENGTH) return prev
          const next = prev + e.key
          setPoppedIndex(next.length - 1)
          return next
        })
      }
    }
    window.addEventListener("keydown", onKey, true)
    return () => window.removeEventListener("keydown", onKey, true)
  }, [locked])

  // Validar al llegar a 4 dígitos — match local con SHA-256 contra los hashes del store.
  // Best-effort POST a /api/pos/audit-unlock para logging (no bloquea el unlock si falla).
  React.useEffect(() => {
    if (pin.length !== PIN_LENGTH) return
    const id = setTimeout(async () => {
      // SHA-256 via Web Crypto API — sync feel, sub-ms compute
      const enc = new TextEncoder().encode(pin)
      const buf = await crypto.subtle.digest("SHA-256", enc)
      const hashArr = Array.from(new Uint8Array(buf))
      const pinHash = hashArr.map(b => b.toString(16).padStart(2, "0")).join("")

      let matched: { id: string; name: string } | null = null
      for (const u of users) {
        if (!u.pinhash) continue
        if (u.pinhash === pinHash) {
          matched = { id: u.id, name: u.name }
          break
        }
      }
      if (matched) {
        // Audit best-effort — no bloquear si falla (offline).
        posFetch("/api/pos/audit-unlock", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ contactId: matched.id }),
        }).catch(() => undefined)

        // Afirmación de operador: el match de arriba es LOCAL y le sirve al
        // front (saber a quién saludar, a quién atribuir la venta), pero el
        // backend no puede creerle a un dato que el browser calculó. Este POST
        // revalida el PIN server-side y devuelve un token firmado que prueba
        // la identidad en las requests siguientes — es lo que hace cumplible
        // la exclusividad de mesas (ver lib/pos/lock-store.ts).
        //
        // Best-effort deliberado, igual que el audit: el lockscreen tiene que
        // seguir desbloqueando SIN RED (el POS es offline-first y el PIN ya se
        // verificó localmente). Sin token el mozo opera normal; solo pierde
        // acceso a las mesas asignadas a otro, que son online-only igual.
        //
        // La misma respuesta trae los permisos `pos.*` de esta persona. Se
        // guardan juntos y se limpian juntos: son el mismo hecho probado por el
        // server, y sirven para que la caja no muestre habilitado lo que el
        // backend va a rechazar (ni apagado lo que un encargado sí puede
        // hacer). Sin red quedan vacíos, igual que el token — offline no hay
        // identidad probada y las acciones que la exigen son online-only.
        setOperatorToken(null)
        setOperatorPermissions([])
        // De quién es ESTA request. La respuesta llega tarde y el estado pudo
        // cambiar de dueño mientras viajaba:
        //
        //   Ana desbloquea con red lenta → Ana bloquea → Bruno desbloquea →
        //   llega la respuesta de Ana → Bruno queda operando con el token Y los
        //   permisos de Ana.
        //
        // Con el token era atribución equivocada; con los permisos adentro es
        // escalación de privilegios (un cajero heredando el override de un
        // encargado). Por eso se descarta contra el estado FRESCO del store
        // (`getState()`, no las closures de este render, que son del momento en
        // que se disparó la request): si desbloqueó otra persona, o si la caja
        // volvió a bloquearse —`lock()` tira el token a propósito y una
        // respuesta en vuelo no puede resucitarlo—, la respuesta se tira.
        const requestedBy = matched.id
        posFetch("/api/pos/unlock", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ pin }),
        })
          .then(async (res) => {
            if (!res.ok) return
            const json = (await res.json().catch(() => null)) as
              | { ok?: boolean; operatorToken?: string | null; permissions?: unknown }
              | null
            if (!json?.ok) return
            const now = useLockStore.getState()
            if (now.locked || now.activeUser?.id !== requestedBy) return
            if (typeof json.operatorToken === "string") {
              setOperatorToken(json.operatorToken)
            }
            if (Array.isArray(json.permissions)) {
              setOperatorPermissions(json.permissions.filter((p): p is string => typeof p === "string"))
            }
          })
          .catch(() => undefined)

        setActiveUser({ id: matched.id, name: matched.name })
        toast.success(`Bienvenido, ${matched.name}`)
        unlock()
      } else {
        setShake(true)
        setError(true)
        setTimeout(() => {
          setShake(false)
          setPin("")
          setPoppedIndex(-1)
        }, 420)
      }
    }, 160)
    return () => clearTimeout(id)
  }, [pin, unlock, users, setActiveUser, setOperatorToken, setOperatorPermissions])

  // Escape hatch (P1): si ningún operador del roster tiene PIN, el lock screen
  // se convierte en un deadlock permanente (no hay hash contra el que comparar).
  // Solo aplica cuando el catálogo ya terminó de hidratar (`ready` o `error`);
  // mientras `idle`/`loading`, users=[] es transitorio y mostrar el aviso sería
  // un falso positivo (el bootstrap aún no llegó).
  //
  // ── Por qué son TRES carteles y no uno ─────────────────────────────────────
  //
  // "No tengo PINs contra los que validar" tiene causas distintas, y cada una
  // se arregla en un lugar distinto. Un cartel único obliga a elegir una y
  // MIENTE en las otras dos — que es lo que pasó dos veces:
  //
  //   2026-08-24: un 403 de `/v1/users` (el device perdió `contacts.user.view`
  //   en la mig 162) degradaba a lista vacía y se pintaba como "no hay PINs
  //   configurados". Dato falso, y mandaba a buscar el problema al panel.
  //
  //   2026-08-25: el bootstrap contestado con la sesión del PANEL (sin la
  //   clave `users`, que se sirve solo a `pos-app`) también degradaba a lista
  //   vacía, y el iPhone recién pareado acusó al comercio de no tener códigos
  //   cargados cuando los tenía. Causa raíz en `bootstrap-source.ts` y en el
  //   gate del BFF; este cartel es la última línea, no el arreglo.
  //
  // Las tres causas, en orden de prioridad:
  //
  //   1. SNAPSHOT offline sin PINs (`catalogFromCache`): no prueba nada sobre
  //      el comercio, solo que este device todavía no bajó un roster bueno.
  //      Salida: red.
  //   2. Roster AUSENTE (`rosterMissing`): la respuesta no vino con la lista de
  //      operadores de este dispositivo — `/api` más viejo que el front, o una
  //      sesión que no es la del device. Tampoco dice nada del comercio.
  //      Salida: recargar y, si insiste, reconectar el dispositivo.
  //   3. Roster de RED, presente y sin PINs: recién acá es la verdad del
  //      comercio. Salida: cargar el código en el panel.
  //
  // `catalogSettled` incluye `error` por defensa: hoy el layout gatea en
  // `catalogStatus === 'ready'`, así que este componente no se monta en error
  // y esa rama es inalcanzable desde `/pos`. Se mantiene para que montarlo en
  // otro lado no reviva el falso positivo de `[].every() === true`.
  const catalogSettled = catalogStatus === "ready" || catalogStatus === "error"
  const noPinsToValidate = catalogSettled && users.every((u) => !u.pinhash)

  if (!locked) return null

  // Mientras el catálogo carga, evitar render del lock real (compara contra
  // users=[]) y del aviso de "no hay PINs" (falso positivo). Spinner mínimo.
  if (!catalogSettled) {
    return (
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Cargando"
        className="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-4 bg-background"
      >
        <PuntoLogo variant="mark" className="size-[35px] animate-pulse" />
      </div>
    )
  }

  // Sin hashes contra los que validar: explicar la causa REAL en vez del lock.
  // Ver el bloque de arriba — cada causa tiene su mensaje y su salida.
  if (noPinsToValidate) {
    const cause = catalogFromCache ? "cache" : rosterMissing ? "missing" : "empty"
    const copy = {
      cache: {
        icon: CloudAlert,
        title: "La caja no tiene todavía la lista de operadores",
        description:
          "Está operando con los datos guardados de la última conexión, y ahí no hay ningún operador con código POS. Conectala a internet y reintentá para traer el listado al día.",
      },
      missing: {
        icon: MonitorSmartphone,
        title: "Esta caja no recibió la lista de operadores",
        description:
          "El servidor respondió, pero sin los operadores de este dispositivo. No es un problema de los códigos del comercio: es esta caja, que no está pidiendo los datos como dispositivo. Reintentá; si vuelve a pasar, reconectá el dispositivo con un link nuevo desde Ajustes → Dispositivos en el panel.",
      },
      empty: {
        icon: KeyRound,
        title: "Ningún usuario de esta sucursal tiene código POS",
        description: `Para desbloquear ${outletName ? `${outletName} ` : ""}hace falta que al menos un usuario habilitado en la sucursal tenga su código POS de 4 dígitos cargado, desde Ajustes → Equipo en el panel.`,
      },
    }[cause]

    return (
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Pantalla bloqueada"
        className="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-8 bg-background p-6"
      >
        <PuntoLogo variant="mark" className="size-[35px]" />
        <EmptyState
          ghost={false}
          icon={copy.icon}
          title={copy.title}
          description={copy.description}
          actions={
            <Button size="lg" onClick={() => window.location.reload()}>
              Reintentar
            </Button>
          }
        />
      </div>
    )
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Pantalla bloqueada"
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-background"
    >
      {/*
       * Input invisible — captura el teclado virtual en mobile cuando el
       * usuario toca la zona de los círculos. En desktop el listener global
       * de keydown sigue funcionando igual sin importar el foco.
       *
       * Usamos sr-only en lugar de display:none/visibility:hidden para que el
       * browser permita el focus y abra el teclado del OS en mobile.
       * inputMode="numeric" + pattern="[0-9]*" dan el teclado numérico en iOS/Android.
       */}
      <input
        ref={hiddenInputRef}
        className="sr-only"
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        aria-hidden="false"
        aria-label="Ingrese su código de acceso"
        autoComplete="off"
        autoCorrect="off"
        autoCapitalize="off"
        spellCheck={false}
        readOnly={false}
        onChange={(e) => {
          // Los teclados virtuales disparan onChange (no siempre keydown).
          // Tomamos solo el último carácter ingresado y lo procesamos.
          const last = e.target.value.slice(-1)
          if (/^[0-9]$/.test(last)) {
            setError(false)
            setPin((prev) => {
              if (prev.length >= PIN_LENGTH) return prev
              const next = prev + last
              setPoppedIndex(next.length - 1)
              return next
            })
          }
          // Vaciamos el input para que el próximo dígito se capture igual.
          e.target.value = ""
        }}
        onKeyDown={(e) => {
          // Backspace desde teclado virtual → borrar último dígito.
          if (e.key === "Backspace") {
            e.preventDefault()
            setError(false)
            setPin((prev) => prev.slice(0, -1))
          }
        }}
      />

      {/* Logo — max 35px de alto, junto a los círculos. */}
      <div className={cn("mb-6", shake && "animate-pin-shake")}>
        <PuntoLogo variant="mark" className="size-[35px]" />
      </div>

      {/* PIN dots: 30px de diámetro, 52px de separación.
          Al tocar esta zona en mobile se abre el teclado virtual. */}
      <div
        role="button"
        tabIndex={-1}
        aria-label="Toca para ingresar tu código"
        onClick={() => hiddenInputRef.current?.focus()}
        className={cn(
          "flex items-center cursor-pointer",
          shake && "animate-pin-shake",
        )}
        style={{ gap: 52 }}
      >
        {Array.from({ length: PIN_LENGTH }).map((_, i) => {
          const filled = i < pin.length
          const justPopped = i === poppedIndex && filled
          return (
            <span
              key={i}
              className={cn(
                "block rounded-full border-2 transition-colors duration-150",
                filled
                  ? "border-foreground bg-foreground"
                  : "border-foreground/40 bg-transparent",
                justPopped && "animate-pin-pop",
              )}
              style={{ width: 30, height: 30 }}
            />
          )
        })}
      </div>

      {/* Ícono de candado + texto + nombre de la empresa. */}
      <div className="mt-8 flex flex-col items-center gap-1">
        <Lock
          aria-hidden
          className={cn(
            "size-4 transition-colors",
            error ? "text-destructive" : "text-muted-foreground",
          )}
        />
        <p
          className={cn(
            "text-xs transition-colors",
            error ? "font-semibold text-destructive" : "text-muted-foreground",
          )}
        >
          {error ? "Código incorrecto" : "Ingrese su código de usuario"}
        </p>
        {!error && outletName && (
          <p className="text-sm font-bold text-foreground">{outletName}</p>
        )}
      </div>
    </div>
  )
}
