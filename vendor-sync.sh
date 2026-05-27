#!/bin/bash
# vendor-sync — reproduce los vendor JS pineados desde node_modules
# Uso: ./vendor-sync.sh          (copia node_modules → assets/vendor/js y verifica)
#       ./vendor-sync.sh --check  (solo verifica byte-identidad, no copia)
#
# Las versiones están pineadas EXACTAS en package.json (NO usar ^/~). El front
# legacy depende de versiones congeladas (jQuery 3.6.3, Chart 2.9.4, etc.); un
# upgrade silencioso rompería comportamiento. Este script garantiza que el
# archivo vendoreado == el dist del paquete npm pineado.
#
# Libs que NO se gestionan por npm (se mantienen como archivos versionados en
# assets/vendor/js/ porque el build npm difiere del vendoreado o no es npmeable):
#   select2, fastclick, bootstrap, daterangepicker, bootstrap-datetimepicker,
#   snap, jsrsasign, qz-tray, fingerprintjs, leaflet-routing-machine,
#   moment-locale-es, select2-i18n-es, alpinejs (ya vendoreado aparte).
set -e

ROOT="$(cd "$(dirname "$0")" && pwd)"
V="$ROOT/assets/vendor/js"
MODE="${1:-sync}"

# Mapa: <ruta dist en node_modules> | <archivo vendoreado>
PAIRS="
node_modules/jquery/dist/jquery.min.js|jquery-3.6.3.min.js
node_modules/jquery-ui-dist/jquery-ui.min.js|jquery-ui-1.12.1.min.js
node_modules/jquery.actual/jquery.actual.min.js|jquery.actual-1.0.19.min.js
node_modules/chart.js/dist/Chart.min.js|Chart-2.9.4.min.js
node_modules/moment/min/moment-with-locales.min.js|moment-2.24.0-with-locales.min.js
node_modules/sweetalert2/dist/sweetalert2.min.js|sweetalert2-7.33.1.min.js
node_modules/mustache/mustache.min.js|mustache-4.0.1.min.js
node_modules/handlebars/dist/handlebars.min.js|handlebars-4.7.7.min.js
node_modules/leaflet/dist/leaflet.js|leaflet-1.7.1.js
node_modules/lz-string/libs/lz-string.min.js|lz-string-1.4.4.min.js
node_modules/mousetrap/mousetrap.min.js|mousetrap-1.6.3.min.js
node_modules/xlsx/dist/xlsx.full.min.js|xlsx-0.16.2.full.min.js
node_modules/html2canvas/dist/html2canvas.min.js|html2canvas-1.3.2.min.js
node_modules/jsbarcode/dist/JsBarcode.all.min.js|JsBarcode-3.11.0.min.js
node_modules/qrious/dist/qrious.min.js|qrious.min.js
node_modules/jspdf/dist/jspdf.umd.min.js|jspdf-2.4.0.umd.min.js
node_modules/ismobilejs/isMobile.min.js|isMobile-0.4.1.min.js
"

if [ ! -d "$ROOT/node_modules" ]; then
  echo "node_modules ausente — corré 'npm install' primero." >&2
  exit 1
fi

fail=0
# here-string (no pipe) para que $fail viva en el shell padre y el exit no mienta
while IFS='|' read -r src dst; do
  [ -z "$src" ] && continue
  srcp="$ROOT/$src"
  dstp="$V/$dst"
  if [ ! -f "$srcp" ]; then echo "FALTA-SRC   $src"; fail=1; continue; fi
  if [ "$MODE" = "--check" ]; then
    if cmp -s "$srcp" "$dstp"; then echo "OK          $dst"; else echo "DIFIERE     $dst"; fail=1; fi
  else
    if ! cp "$srcp" "$dstp"; then echo "FALLO-CP    $dst"; fail=1; continue; fi
    echo "synced      $dst"
  fi
done <<< "$PAIRS"

[ "$MODE" = "--check" ] && echo "(verificación solo-lectura completa)"
exit "$fail"
