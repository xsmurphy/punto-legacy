/**
 * Intercepting route — cuando el user navega a `/settings` DESDE otra
 * ruta del panel (click en "Configuración" del dropdown user, deep-link
 * desde otro panel), este page intercepta la navegación y se renderea
 * en el slot `modal` del layout, ENCIMA del `children` actual.
 *
 * Resultado: la página de fondo (dashboard, reports, lo que sea) NO se
 * desmonta — sigue visible debajo del modal. Eso es el comportamiento
 * "modal normal" que se esperaba.
 *
 * Si el user accede directo por URL (https://.../settings con reload
 * o link externo), el intercept NO matchea (no hay ruta "anterior") y
 * Next sirve `app/(panel)/settings/page.tsx` como página normal — sigue
 * mostrando el mismo Dialog, con la diferencia de que no hay fondo de
 * página. Acceptable como fallback.
 *
 * Comportamiento de cierre: cuando el SettingsPage llama `router.back()`,
 * Next cierra el intercept y vuelve a la URL previa SIN unmount del fondo
 * (no hay back/forward del browser).
 *
 * Re-exporta sin modificar para mantener una sola fuente del componente.
 */
export { default } from "../../settings/page"
