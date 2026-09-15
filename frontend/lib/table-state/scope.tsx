"use client"

/**
 * De QUIÉN son las preferencias de los listados que se renderizan adentro.
 *
 * Cada realm tiene su propia fuente de identidad y no se mezclan (un cliente
 * HTTP = un realm): el panel la saca del bootstrap del panel
 * (`PanelAuthGuard`), /admin del admin logueado (`AdminAuthGuard`). Por eso la
 * identidad la INYECTA el guard de cada realm con este provider y el
 * `<DataTable>` no llama a ningún endpoint para averiguarla.
 *
 * La caja (`PosAuthGuard`) no lo monta: hoy no hay ningún `<DataTable>` en el
 * POS. El día que haya uno, su identidad es empresa del bootstrap del device +
 * el OPERADOR del PIN (`useLockStore().activeUser`), no el usuario del device:
 * la tablet la comparten varias personas.
 *
 * Tres estados:
 *   - `namespace: undefined` → identidad todavía cargando. El listado muestra
 *     skeleton en vez de filas: si pintara filas sin filtrar y un instante
 *     después las filtrara, el usuario leería "no se guardó el filtro".
 *   - `namespace: null` → no hay identidad (bootstrap falló, realm sin
 *     provider). El listado funciona igual, sin persistencia.
 *   - `namespace: string` → preferencias de esa empresa + usuario.
 */

import * as React from "react"

export type TableStateNamespace = string | null | undefined

const TableStateScopeContext = React.createContext<TableStateNamespace>(null)

export function TableStateScopeProvider({
  namespace,
  children,
}: {
  namespace: TableStateNamespace
  children: React.ReactNode
}) {
  return (
    <TableStateScopeContext.Provider value={namespace}>{children}</TableStateScopeContext.Provider>
  )
}

export function useTableStateNamespace(): TableStateNamespace {
  return React.useContext(TableStateScopeContext)
}
