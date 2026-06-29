export async function parseTabularToCsv(file: File): Promise<File> {
  if (/\.csv$|\.txt$|\.tsv$/i.test(file.name)) return file
  const XLSX = await import("xlsx")
  const buf = await file.arrayBuffer()
  const wb = XLSX.read(buf, { type: "array" })
  const ws = wb.Sheets[wb.SheetNames[0]]
  const csv = XLSX.utils.sheet_to_csv(ws)
  return new File(
    [new Blob([csv], { type: "text/csv" })],
    file.name.replace(/\.(xlsx|xls)$/i, ".csv"),
    { type: "text/csv" }
  )
}
