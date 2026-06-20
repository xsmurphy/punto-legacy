interface Props {
  pin: string | null
}

export function PairingView({ pin }: Props) {
  const formatted = pin ? `${pin.slice(0, 3)} ${pin.slice(3)}` : "------"
  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-8 px-8">
      <div className="flex flex-col items-center gap-4 text-center">
        <h1 className="text-3xl font-semibold text-muted-foreground">
          Conectá esta pantalla
        </h1>
        <p className="text-xl text-muted-foreground">
          Ingresá este PIN desde el POS en Ajustes &rsaquo; Pantalla cliente
        </p>
      </div>
      <div
        className="font-mono font-bold tracking-widest text-brand select-none"
        style={{ fontSize: "clamp(5rem, 18vw, 10rem)", lineHeight: 1.1 }}
      >
        {formatted}
      </div>
      <div className="flex items-center gap-2 text-xl text-muted-foreground">
        <span className="inline-block size-2 rounded-full bg-brand animate-pulse" />
        <span>Esperando&hellip;</span>
      </div>
    </div>
  )
}
