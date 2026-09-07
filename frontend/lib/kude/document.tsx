/* eslint-disable jsx-a11y/alt-text -- <Image> de @react-pdf no es una imagen del DOM: no acepta alt. */
import {
  Document,
  Image,
  Page,
  StyleSheet,
  Text,
  View,
  renderToBuffer,
} from "@react-pdf/renderer"

import {
  consultationUrl,
  formatAmount,
  formatQuantity,
  groupCdc,
  type KudeItem,
  type KudePayload,
} from "./types"

/**
 * Template FIJO del KuDE — K1/D2 de `context/73-kude-propio.md`.
 *
 * DISEÑO FIJO, no personalizable: el tenant cambia el CONTENIDO (su logo, sus
 * datos), nunca la forma. No comparte motor con el document builder del panel
 * (decisión explícita del owner; además ese motor no pagina).
 *
 * El orden y los bloques salen del MT v150 §13.4 (encabezado / ítems /
 * totales / consulta SIFEN / QR). Lo que no tenemos no se dibuja: cada bloque
 * opcional se omite entero en vez de imprimir un rótulo vacío.
 *
 * Tipografía Helvetica: viene con el PDF, no se descarga nada. Un render que
 * depende de bajar una fuente es un render que falla sin red.
 */

const styles = StyleSheet.create({
  page: {
    paddingTop: 28,
    paddingBottom: 36,
    paddingHorizontal: 28,
    fontFamily: "Helvetica",
    fontSize: 8,
    color: "#111111",
  },

  header: { flexDirection: "row", marginBottom: 10 },
  emitterCol: { flex: 1, paddingRight: 12 },
  logo: { width: 90, maxHeight: 46, objectFit: "contain", marginBottom: 6 },
  emitterName: { fontSize: 12, fontFamily: "Helvetica-Bold", marginBottom: 2 },
  emitterLine: { fontSize: 8, color: "#333333", marginBottom: 1 },

  stampBox: {
    width: 210,
    borderWidth: 1,
    borderColor: "#111111",
    borderStyle: "solid",
    padding: 8,
  },
  stampTitle: {
    fontSize: 9,
    fontFamily: "Helvetica-Bold",
    textAlign: "center",
    marginBottom: 6,
  },
  stampNumber: {
    fontSize: 13,
    fontFamily: "Helvetica-Bold",
    textAlign: "center",
    marginBottom: 6,
  },
  stampLine: { fontSize: 8, marginBottom: 1 },

  section: {
    borderWidth: 1,
    borderColor: "#999999",
    borderStyle: "solid",
    padding: 6,
    marginBottom: 8,
  },
  sectionTitle: {
    fontSize: 7,
    fontFamily: "Helvetica-Bold",
    color: "#555555",
    marginBottom: 3,
    textTransform: "uppercase",
  },
  row: { flexDirection: "row" },
  half: { flex: 1, paddingRight: 8 },

  label: { fontFamily: "Helvetica-Bold" },

  tableHead: {
    flexDirection: "row",
    backgroundColor: "#EEEEEE",
    borderWidth: 1,
    borderColor: "#999999",
    borderStyle: "solid",
    paddingVertical: 3,
    paddingHorizontal: 2,
  },
  tableRow: {
    flexDirection: "row",
    borderLeftWidth: 1,
    borderRightWidth: 1,
    borderBottomWidth: 1,
    borderColor: "#CCCCCC",
    borderStyle: "solid",
    paddingVertical: 3,
    paddingHorizontal: 2,
  },
  cellHead: { fontSize: 7, fontFamily: "Helvetica-Bold" },
  cDescription: { flex: 1, paddingHorizontal: 2 },
  cQty: { width: 42, textAlign: "right", paddingHorizontal: 2 },
  cPrice: { width: 58, textAlign: "right", paddingHorizontal: 2 },
  cAmount: { width: 58, textAlign: "right", paddingHorizontal: 2 },

  totalsWrap: { flexDirection: "row", marginTop: 8 },
  totalsSpacer: { flex: 1 },
  totalsBox: { width: 250 },
  totalRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    paddingVertical: 2,
  },
  totalStrong: {
    flexDirection: "row",
    justifyContent: "space-between",
    borderTopWidth: 1,
    borderColor: "#111111",
    borderStyle: "solid",
    paddingTop: 3,
    marginTop: 2,
    fontFamily: "Helvetica-Bold",
    fontSize: 10,
  },

  qrSection: { flexDirection: "row", marginTop: 10, alignItems: "flex-start" },
  qrImage: { width: 80, height: 80, marginRight: 10 },
  cdcText: { fontSize: 9, fontFamily: "Helvetica-Bold", letterSpacing: 0.4 },

  footer: {
    position: "absolute",
    bottom: 16,
    left: 28,
    right: 28,
    textAlign: "center",
    fontSize: 7,
    color: "#888888",
  },
})

/** Importe de la línea en la columna de SU afectación de IVA. */
function amountFor(item: KudeItem, rate: number): number {
  return item.taxRate === rate ? item.total : 0
}

interface KudeTemplateProps {
  payload: KudePayload
  /** QR fiscal ya rasterizado (data URL PNG). null = la emisión no trajo J002. */
  qrImage: string | null
  /** Logo del tenant en data URL. null = no tiene, o no se pudo bajar. */
  logoImage: string | null
}

export function KudeTemplate({ payload, qrImage, logoImage }: KudeTemplateProps) {
  const { document: doc, emitter, receiver, items, totals, format } = payload
  const money = (value: number) => formatAmount(value, format)
  const consulta = consultationUrl(doc.qrData)

  return (
    <Document title={`${doc.title} ${doc.number}`.trim()}>
      <Page size="A4" style={styles.page}>
        {/* Encabezado: emisor a la izquierda, identificación fiscal del
            documento a la derecha, que es donde se la busca. */}
        <View style={styles.header}>
          <View style={styles.emitterCol}>
            {logoImage ? <Image src={logoImage} style={styles.logo} /> : null}
            <Text style={styles.emitterName}>{emitter.name}</Text>
            {emitter.tradeName && emitter.tradeName !== emitter.name ? (
              <Text style={styles.emitterLine}>{emitter.tradeName}</Text>
            ) : null}
            {emitter.activity ? (
              <Text style={styles.emitterLine}>{emitter.activity}</Text>
            ) : null}
            {emitter.address ? (
              <Text style={styles.emitterLine}>{emitter.address}</Text>
            ) : null}
            {emitter.city ? <Text style={styles.emitterLine}>{emitter.city}</Text> : null}
            {emitter.phone ? (
              <Text style={styles.emitterLine}>Tel.: {emitter.phone}</Text>
            ) : null}
            {emitter.email ? <Text style={styles.emitterLine}>{emitter.email}</Text> : null}
          </View>

          <View style={styles.stampBox}>
            <Text style={styles.stampTitle}>{doc.title}</Text>
            <Text style={styles.stampLine}>RUC: {emitter.ruc}</Text>
            {emitter.stamp ? (
              <>
                <Text style={styles.stampLine}>Timbrado N°: {emitter.stamp.number}</Text>
                {emitter.stamp.start ? (
                  <Text style={styles.stampLine}>
                    Inicio de vigencia: {emitter.stamp.start}
                  </Text>
                ) : null}
              </>
            ) : null}
            <Text style={styles.stampNumber}>{doc.number}</Text>
            <Text style={styles.stampLine}>Fecha de emisión: {doc.issuedAt}</Text>
            <Text style={styles.stampLine}>Condición de venta: {doc.condition}</Text>
            <Text style={styles.stampLine}>
              Moneda: {doc.currency}
              {doc.exchangeRate ? ` — Tipo de cambio: ${money(doc.exchangeRate)}` : ""}
            </Text>
          </View>
        </View>

        {/* Receptor */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Datos del receptor</Text>
          <View style={styles.row}>
            <View style={styles.half}>
              <Text>
                <Text style={styles.label}>Nombre o razón social: </Text>
                {receiver.name || "Sin nombre"}
              </Text>
              {receiver.address ? (
                <Text>
                  <Text style={styles.label}>Dirección: </Text>
                  {receiver.address}
                </Text>
              ) : null}
            </View>
            <View style={styles.half}>
              {receiver.ruc ? (
                <Text>
                  <Text style={styles.label}>RUC: </Text>
                  {receiver.ruc}
                </Text>
              ) : null}
              {receiver.documentId ? (
                <Text>
                  <Text style={styles.label}>Documento de identidad: </Text>
                  {receiver.documentId}
                </Text>
              ) : null}
            </View>
          </View>
        </View>

        {/* Ítems: el valor de venta va desglosado por afectación de IVA
            (exentas / 5% / 10%), como pide el §13.4. */}
        <View style={styles.tableHead}>
          <Text style={[styles.cellHead, styles.cDescription]}>Descripción</Text>
          <Text style={[styles.cellHead, styles.cQty]}>Cant.</Text>
          <Text style={[styles.cellHead, styles.cPrice]}>P. unitario</Text>
          <Text style={[styles.cellHead, styles.cAmount]}>Exentas</Text>
          <Text style={[styles.cellHead, styles.cAmount]}>Gravadas 5%</Text>
          <Text style={[styles.cellHead, styles.cAmount]}>Gravadas 10%</Text>
        </View>
        {items.map((item, index) => (
          <View style={styles.tableRow} key={`${item.description}-${index}`} wrap={false}>
            <Text style={styles.cDescription}>{item.description}</Text>
            <Text style={styles.cQty}>{formatQuantity(item.quantity, format)}</Text>
            <Text style={styles.cPrice}>{money(item.unitPrice)}</Text>
            <Text style={styles.cAmount}>{item.taxRate === 0 ? money(item.total) : ""}</Text>
            <Text style={styles.cAmount}>{item.taxRate === 5 ? money(amountFor(item, 5)) : ""}</Text>
            <Text style={styles.cAmount}>
              {item.taxRate === 10 ? money(amountFor(item, 10)) : ""}
            </Text>
          </View>
        ))}

        {/* Totales y liquidación del IVA por tasa */}
        <View style={styles.totalsWrap}>
          <View style={styles.totalsSpacer} />
          <View style={styles.totalsBox}>
            <View style={styles.totalRow}>
              <Text>Subtotal exentas</Text>
              <Text>{money(totals.exempt)}</Text>
            </View>
            <View style={styles.totalRow}>
              <Text>Subtotal gravadas 5%</Text>
              <Text>{money(totals.taxed5)}</Text>
            </View>
            <View style={styles.totalRow}>
              <Text>Subtotal gravadas 10%</Text>
              <Text>{money(totals.taxed10)}</Text>
            </View>
            <View style={styles.totalStrong}>
              <Text>Total {format.currency}</Text>
              <Text>{money(totals.total)}</Text>
            </View>
            <View style={[styles.totalRow, { marginTop: 6 }]}>
              <Text>Liquidación IVA 5%</Text>
              <Text>{money(totals.iva5)}</Text>
            </View>
            <View style={styles.totalRow}>
              <Text>Liquidación IVA 10%</Text>
              <Text>{money(totals.iva10)}</Text>
            </View>
            <View style={styles.totalRow}>
              <Text style={styles.label}>Total IVA</Text>
              <Text style={styles.label}>{money(totals.ivaTotal)}</Text>
            </View>
          </View>
        </View>

        {/* Consulta pública: QR fiscal (J002) + CDC en once grupos de cuatro */}
        <View style={styles.qrSection}>
          {qrImage ? <Image src={qrImage} style={styles.qrImage} /> : null}
          <View style={{ flex: 1 }}>
            <Text style={styles.sectionTitle}>Consulta del documento electrónico</Text>
            <Text style={styles.cdcText}>{groupCdc(doc.cdc)}</Text>
            {consulta ? (
              <Text style={{ marginTop: 3, color: "#333333" }}>{consulta}</Text>
            ) : null}
            <Text style={{ marginTop: 6, color: "#555555" }}>
              Este documento es la representación gráfica de un Documento Electrónico. Su
              validez se verifica en el enlace de consulta o escaneando el código.
            </Text>
          </View>
        </View>

        {/* D3 — pie de marca, en todos los tenants. */}
        <Text
          style={styles.footer}
          render={({ pageNumber, totalPages }) =>
            `Documento generado por Punto — punto.la${
              totalPages > 1 ? `   |   Página ${pageNumber} de ${totalPages}` : ""
            }`
          }
          fixed
        />
      </Page>
    </Document>
  )
}

/**
 * PDF del KuDE, listo para servir.
 *
 * El render vive ACÁ y no en el route handler para que el JSX quede en el
 * único archivo que es un componente: el route arma la respuesta HTTP, este
 * módulo arma el documento.
 */
export function renderKudePdf(props: KudeTemplateProps): Promise<Buffer> {
  return renderToBuffer(<KudeTemplate {...props} />)
}
