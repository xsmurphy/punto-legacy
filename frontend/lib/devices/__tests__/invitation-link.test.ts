import { describe, expect, it } from "vitest"
import { parseInvitationId } from "../invitation-link"

const ID = "3f2b1c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d"

describe("parseInvitationId", () => {
  it("acepta el link entero", () => {
    expect(parseInvitationId(`https://app.punto.la/connect/${ID}`)).toBe(ID)
  })

  it("acepta el uuid pelado", () => {
    expect(parseInvitationId(ID)).toBe(ID)
  })

  it("tolera espacios alrededor", () => {
    expect(parseInvitationId(`  https://app.punto.la/connect/${ID}  `)).toBe(ID)
  })

  it("lo encuentra dentro del texto de un mensaje", () => {
    // Cómo llega de verdad: pegado desde WhatsApp, con texto alrededor.
    expect(
      parseInvitationId(`Conectá la tablet acá: https://app.punto.la/connect/${ID} gracias`),
    ).toBe(ID)
  })

  it("normaliza a minúsculas", () => {
    expect(parseInvitationId(`https://app.punto.la/connect/${ID.toUpperCase()}`)).toBe(ID)
  })

  it("ignora query string y fragmento", () => {
    expect(parseInvitationId(`app.punto.la/connect/${ID}?utm=wa#top`)).toBe(ID)
  })

  it("devuelve null cuando no hay uuid", () => {
    expect(parseInvitationId("")).toBeNull()
    expect(parseInvitationId("https://app.punto.la/connect/")).toBeNull()
    expect(parseInvitationId("pegá el link acá")).toBeNull()
  })

  it("devuelve null con un uuid truncado", () => {
    expect(parseInvitationId("3f2b1c4d-5e6f-4a7b-8c9d-0e1f2a3b")).toBeNull()
  })
})
