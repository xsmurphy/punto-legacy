import Image from "next/image"

/**
 * Screenshot real del POS en un bloque propio debajo del hero — "así se ve
 * el sistema". Frame plano con hairline border, sin sombras (design system
 * flat).
 */
export function PosShowcase() {
  return (
    <section aria-label="El POS de Punto" className="py-16 md:py-24">
      <div className="mx-auto w-full max-w-5xl px-4 md:px-6">
        <div className="overflow-hidden rounded-2xl border bg-background p-1.5 md:p-2">
          <Image
            src="/site/pos-screenshot.png"
            alt="Pantalla de venta del POS de Punto: catálogo con fotos, carrito y total"
            width={2880}
            height={1400}
            priority
            sizes="(max-width: 1024px) 100vw, 1024px"
            className="w-full rounded-xl border"
          />
        </div>
      </div>
    </section>
  )
}
