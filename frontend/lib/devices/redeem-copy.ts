/**
 * Qué se le dice a quien está conectando un dispositivo y no pudo.
 *
 * Vive fuera de las pantallas porque ahora son DOS las que lo muestran: la
 * página `/connect/{id}` y el formulario de vinculación de "no conectado", que
 * canjea sin navegar. Dos copias del mismo diccionario terminan divergiendo
 * —una aprende un motivo nuevo y la otra muestra el código crudo— y el motivo
 * crudo en pantalla es justo lo que la Regla #8 de context/14 prohíbe.
 *
 * `reason` acepta dos cosas: un código corto (los que arma el canje) o el
 * mensaje textual que devolvió la API. Lo desconocido que sea texto se muestra
 * tal cual —el backend escribe para el comerciante, no para el log—, y lo que
 * no, cae en una salida genérica con la acción a tomar.
 */

const CODE_COPY: Record<string, { title: string; detail: string }> = {
  "invalid-format": {
    title: "Link inválido",
    detail: "La dirección está incompleta o mal copiada. Pedí que te reenvíen el link entero.",
  },
  "not-found": {
    title: "Link inválido",
    detail: "Esta invitación no existe o fue cancelada. Pedí una nueva desde Configuración › Dispositivos.",
  },
  expired: {
    title: "Link vencido",
    detail: "Las invitaciones caducan por seguridad. Pedí una nueva desde Configuración › Dispositivos.",
  },
  "in-use": {
    title: "Link ya usado",
    detail:
      "Este link se abrió en otro dispositivo. Cada link conecta un solo dispositivo: pedí uno nuevo desde Configuración › Dispositivos.",
  },
  "config-error": {
    title: "No se pudo contactar al servidor",
    detail: "Revisá la conexión e intentá de nuevo. Si sigue, avisale al administrador.",
  },
  // El link es válido, pero es de otra pantalla del comercio. Se dice llano y
  // sin nombrar módulos ni tipos internos: lo único accionable es pedir el que
  // corresponde.
  "foreign-module": {
    title: "Este link es de otro tipo de pantalla",
    detail: "Pedí el link de esta pantalla desde Configuración › Dispositivos.",
  },
}

export interface RedeemErrorCopy {
  title: string
  detail: string
}

export function redeemErrorCopy(reason: string): RedeemErrorCopy {
  const known = CODE_COPY[reason]
  if (known) return known
  return {
    title: "No se pudo conectar el dispositivo",
    detail:
      reason && reason !== "unknown" && reason !== "error"
        ? reason
        : "Pedile al administrador que genere un link nuevo y volvé a abrirlo.",
  }
}
