import Link from "next/link"

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  seccionId,
  type DocumentoLegal,
  type SeccionLegal,
} from "@/lib/site/legal"
import { applyMarketTerms } from "@/lib/site/markets"

/**
 * Renderer compartido de un documento legal (`/terminos`, `/privacidad`).
 *
 * El contenido llega estructurado desde `lib/site/legal.ts` — acá solo se
 * decide cómo se ve. Los tokens de mercado ({docFiscal}, {organismo}) se
 * resuelven en el render, así que el texto fuente no depende del país.
 */

const t = (s: string) => applyMarketTerms(s)

function Seccion({
  seccion,
  numero,
}: {
  seccion: SeccionLegal
  numero: number
}) {
  const id = seccionId(seccion.titulo)

  return (
    <section id={id} className="scroll-mt-24">
      {/* razón: escala display del sitio de marketing, no la del panel (§14) */}
      <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
        <span className="mr-2 text-muted-foreground tabular-nums">
          {numero}.
        </span>
        {t(seccion.titulo)}
      </h2>

      <div className="mt-4 flex flex-col gap-4">
        {seccion.parrafos.map((p) => (
          <p key={p} className="text-base text-pretty text-muted-foreground">
            {t(p)}
          </p>
        ))}

        {seccion.lista ? (
          <ul className="flex flex-col gap-2 pl-1">
            {seccion.lista.map((item) => (
              <li
                key={item}
                className="flex gap-3 text-base text-pretty text-muted-foreground"
              >
                <span
                  aria-hidden
                  className="mt-2.5 size-1.5 shrink-0 rounded-full bg-border"
                />
                <span>{t(item)}</span>
              </li>
            ))}
          </ul>
        ) : null}

        {seccion.tabla ? (
          <div className="overflow-x-auto rounded-xl border">
            <Table>
              <TableHeader>
                <TableRow>
                  {seccion.tabla.headers.map((h) => (
                    <TableHead key={h}>{t(h)}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {seccion.tabla.filas.map((fila) => (
                  <TableRow key={fila[0]}>
                    {fila.map((celda, i) => (
                      <TableCell
                        key={`${fila[0]}-${i}`}
                        className={
                          i === 0
                            ? "align-top font-medium whitespace-nowrap"
                            : "align-top text-muted-foreground"
                        }
                      >
                        {t(celda)}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </div>
    </section>
  )
}

export function LegalPage({ doc }: { doc: DocumentoLegal }) {
  return (
    <div className="pt-16">
      <div className="mx-auto w-full max-w-6xl px-4 py-16 md:px-6 md:py-24">
        <header className="flex max-w-3xl flex-col gap-4">
          <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
            Legal
          </p>
          {/* razón: escala display del sitio de marketing, no la del panel (§14) */}
          <h1 className="text-4xl font-semibold tracking-tight text-balance md:text-5xl">
            {doc.titulo}
          </h1>
          <p className="text-sm text-muted-foreground">
            Última actualización: {doc.actualizado}
          </p>
          <p className="text-lg text-pretty text-muted-foreground">
            {t(doc.intro)}
          </p>
        </header>

        {/* El índice acompaña la lectura como columna fija en desktop; en
            mobile queda como tarjeta arriba del cuerpo. El texto conserva su
            medida de lectura (~75ch) — el ancho extra lo ocupa el índice, no
            el párrafo. */}
        <div className="mt-12 grid gap-10 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:gap-16">
          <nav
            aria-label="Contenido del documento"
            className="rounded-2xl border bg-muted/40 p-6 lg:sticky lg:top-24 lg:max-h-[calc(100svh-8rem)] lg:self-start lg:overflow-y-auto lg:rounded-none lg:border-0 lg:border-l lg:bg-transparent lg:py-0 lg:pr-0 lg:pl-6"
          >
            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
              Contenido
            </p>
            <ol className="mt-4 flex flex-col gap-2">
              {doc.secciones.map((s, i) => (
                <li key={s.titulo} className="flex gap-3 text-sm">
                  <span className="text-muted-foreground tabular-nums">
                    {i + 1}.
                  </span>
                  <Link
                    href={`${doc.url}#${seccionId(s.titulo)}`}
                    className="text-pretty underline-offset-4 hover:underline"
                  >
                    {t(s.titulo)}
                  </Link>
                </li>
              ))}
            </ol>
          </nav>

          <article className="flex max-w-3xl flex-col gap-12">
            {doc.secciones.map((s, i) => (
              <Seccion key={s.titulo} seccion={s} numero={i + 1} />
            ))}
          </article>
        </div>
      </div>
    </div>
  )
}
