/**
 * Parseo del link de conexión de un dispositivo.
 *
 * El link que genera el panel es `<APP_URL>/connect/{uuid}`. Normalmente el
 * usuario lo abre tocándolo, pero hay un caso donde eso no alcanza: la PWA
 * instalada. En iOS, tocar el link desde WhatsApp abre SAFARI, no la app
 * instalada — y la app instalada tiene su propio `localStorage`, así que el
 * pareo hecho en Safari no le sirve. La única forma de meter el link DENTRO de
 * la app instalada es pegarlo en el formulario de
 * `components/layout/device-not-connected.tsx` y navegar internamente.
 *
 * Por eso esto acepta tanto el link entero (con o sin protocolo, con o sin
 * query, rodeado del texto del mensaje) como el uuid pelado.
 */

const UUID_RE = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i

/**
 * Extrae el id de invitación de lo que el usuario haya pegado, o `null` si no
 * hay ninguno. Normaliza a minúsculas: los endpoints validan contra un regex
 * de uuid y Postgres compara `uuid` sin distinguir caso, pero dejarlo canónico
 * evita sorpresas en el camino (comparaciones de strings, logs, cache keys).
 */
export function parseInvitationId(input: string): string | null {
  const match = UUID_RE.exec(input.trim())
  return match ? match[0].toLowerCase() : null
}
