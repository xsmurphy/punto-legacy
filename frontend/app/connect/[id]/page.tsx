import { ConnectView } from "./connect-view"
import { InvalidLink } from "./invalid-link"

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

interface ConnectPageProps {
  params: Promise<{ id: string }>
}

/**
 * Página de pareo. Sólo valida el formato del id y delega.
 *
 * ── Por qué el `open()` ya NO se hace acá ───────────────────────────────────
 *
 * Antes este Server Component llamaba a `?resource=open` durante el SSR. Con
 * el canje de un solo uso (mig 171) eso dejó de ser viable: la primera
 * apertura devuelve un SECRETO de sesión de pairing que hay que conservar para
 * poder recargar la página y para canjear el token, y un Server Component no
 * puede escribir cookies ni tocar localStorage. El resultado era que cada
 * reload llegaba al backend sin identidad y no había forma de distinguirlo de
 * un segundo navegador abriendo el mismo link.
 *
 * Moverlo al cliente además pone la sesión de pairing donde conceptualmente
 * vive: en el navegador del DISPOSITIVO que se está conectando, no en el
 * servidor de Next que hace de intermediario.
 */
export default async function ConnectPage({ params }: ConnectPageProps) {
  const { id } = await params

  if (!UUID_RE.test(id)) {
    return <InvalidLink reason="invalid-format" />
  }

  return <ConnectView invitationId={id} />
}
