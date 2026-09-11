/**
 * El ORDEN de resolución de un código escaneado es un contrato, no un detalle
 * de implementación: barcode → sku → id.
 *
 * Existe este test porque el catálogo NO garantiza unicidad —ni entre ítems
 * (dos artículos pueden compartir código y gana el primero, decisión del
 * owner) ni entre CAMPOS (el código de barras de un artículo puede ser el SKU
 * de otro)—, así que ante un mismo escaneo puede haber más de un candidato
 * legítimo. Si el desempate viviera en cada pantalla, el mismo código
 * agregaría un producto distinto según qué componente atendió el scan. Acá se
 * fija cuál gana.
 */

import { describe, expect, it } from "vitest"
import { findItemByCode, searchItems } from "@/lib/catalog/search"
import type { PosItem } from "@/lib/types/pos-bootstrap"

function item(partial: Partial<PosItem> & { id: string }): PosItem {
  return {
    name: "Producto",
    sku: null,
    barcode: null,
    price: 0,
    taxIncluded: null,
    taxId: null,
    categoryId: null,
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "producto",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
    ...partial,
  }
}

describe("findItemByCode", () => {
  it("resuelve por código de barras", () => {
    const items = [
      item({ id: "a", name: "Milanesa", sku: "MIL" }),
      item({ id: "b", name: "Cerveza", barcode: "7840001000018" }),
    ]
    expect(findItemByCode(items, "7840001000018")?.id).toBe("b")
  })

  it("el código de barras GANA sobre el SKU de otro artículo", () => {
    // El caso que justifica el orden: el mismo string es el SKU de uno y el
    // código impreso en el envase del otro. Un lector emite lo IMPRESO, así
    // que el ítem correcto es el que lo tiene como barcode.
    const items = [
      item({ id: "sku-owner", sku: "7840001000018" }),
      item({ id: "barcode-owner", barcode: "7840001000018" }),
    ]
    expect(findItemByCode(items, "7840001000018")?.id).toBe("barcode-owner")
  })

  it("cae al SKU cuando ningún artículo tiene ese código de barras", () => {
    const items = [item({ id: "a", sku: "MIL-NAP" }), item({ id: "b", barcode: "999" })]
    expect(findItemByCode(items, "MIL-NAP")?.id).toBe("a")
  })

  it("cae al id como último recurso (etiquetas impresas por Punto)", () => {
    const items = [item({ id: "item-001", sku: "MIL" })]
    expect(findItemByCode(items, "item-001")?.id).toBe("item-001")
  })

  it("con el código repetido entre artículos gana el primero del catálogo", () => {
    // Sin UNIQUE en la base (decisión explícita): el desempate tiene que ser
    // determinístico igual, o el mismo escaneo cobra distinto según el orden
    // en que vino el bootstrap.
    const items = [
      item({ id: "primero", barcode: "7840001000018" }),
      item({ id: "segundo", barcode: "7840001000018" }),
    ]
    expect(findItemByCode(items, "7840001000018")?.id).toBe("primero")
  })

  it("devuelve null si el código no es de nadie, y ante un código vacío", () => {
    const items = [item({ id: "a", sku: "MIL", barcode: "111" })]
    expect(findItemByCode(items, "no-existe")).toBeNull()
    expect(findItemByCode(items, "")).toBeNull()
    expect(findItemByCode(items, "   ")).toBeNull()
  })

  it("ignora mayúsculas y acentos, igual que el resto de los buscadores", () => {
    const items = [item({ id: "a", barcode: "AbC-123" })]
    expect(findItemByCode(items, "abc-123")?.id).toBe("a")
  })
})

describe("searchItems", () => {
  it("encuentra por código de barras tipeado a mano", () => {
    // El cajero copia los dígitos cuando el lector falla o la etiqueta está
    // arrugada: si la búsqueda manual no mirara el campo, solo serviría con el
    // scanner andando.
    const items = [
      item({ id: "a", name: "Milanesa", sku: "MIL" }),
      item({ id: "b", name: "Cerveza", barcode: "7840001000018" }),
    ]
    expect(searchItems(items, "78400010").map((i) => i.id)).toEqual(["b"])
  })

  it("el match exacto de código de barras va antes que el parcial de nombre", () => {
    const items = [
      item({ id: "parcial", name: "Pack 7840001000018 unidades" }),
      item({ id: "exacto", name: "Cerveza", barcode: "7840001000018" }),
    ]
    expect(searchItems(items, "7840001000018")[0]?.id).toBe("exacto")
  })
})
