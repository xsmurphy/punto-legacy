import { describe, expect, it, vi } from "vitest"

import { isolateOverlaySubmit } from "@/lib/overlay-form-isolation"

/**
 * El contrato del helper que aísla el submit de los modales (ver
 * `lib/overlay-form-isolation.ts`): corta la propagación SIEMPRE y, cuando el
 * call-site pasó su propio `onSubmit` al content, lo ejecuta ANTES de cortar.
 */
function fakeSubmitEvent() {
  return { stopPropagation: vi.fn() } as unknown as React.FormEvent<HTMLElement>
}

describe("isolateOverlaySubmit", () => {
  it("corta la propagación aunque el content no tenga onSubmit propio", () => {
    const event = fakeSubmitEvent()

    isolateOverlaySubmit()(event)

    expect(event.stopPropagation).toHaveBeenCalledTimes(1)
  })

  it("compone: corre el handler del call-site y después corta", () => {
    const order: string[] = []
    const event = {
      stopPropagation: () => order.push("stop"),
    } as unknown as React.FormEvent<HTMLElement>

    isolateOverlaySubmit(() => order.push("call-site"))(event)

    expect(order).toEqual(["call-site", "stop"])
  })

  it("le pasa el mismo evento al handler del call-site", () => {
    const event = fakeSubmitEvent()
    const onSubmit = vi.fn()

    isolateOverlaySubmit(onSubmit)(event)

    expect(onSubmit).toHaveBeenCalledWith(event)
  })
})
