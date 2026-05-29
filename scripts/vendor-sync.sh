#!/bin/bash
# vendor-sync.sh — sincroniza las libs JS vendoreables desde node_modules → assets/vendor/js.
#
# Fuente de verdad: package.json (versiones pineadas) + node_modules. Este script copia el
# dist oficial de cada lib al nombre versionado que esperan filesCompiler.php / build.sh.
# Idempotente: correr tras `npm install`/`npm update` para refrescar los vendoreados.
#
# Uso: bash scripts/vendor-sync.sh   (o: npm run vendor)
#
# SÓLO cubre las libs que están en npm Y cuyo dist coincide byte-idéntico con el archivo servido.
# Libs verificadas y gestionadas en dos fases:
#   Fase A (2026-05-28): jquery, moment, ismobilejs, mousetrap, jquery.actual, lz-string,
#                        chart.js, sweetalert2, mustache, leaflet, qrious.
#   Fase B (2026-05-29): bootstrap@3/4 (alias bootstrap3/bootstrap4), eonasdan-bootstrap-datetimepicker,
#                        leaflet-routing-machine, libphonenumber-js, offline-js, pouchdb, push.js.
#
# Nota pouchdb: si `npm install` falla en pouchdb/leveldown (ej. Dropbox strips execute bits),
# usar: npm install --ignore-scripts   — el dist browser no requiere la compilación nativa.
#
# Manuales permanentes (no en npm o dist difiere del vendoreado):
#   fastclick, datatables.net, fingerprintjs (Fase B: difieren del dist npm),
#   chosen, jquery.number, simpleStorage, jquery.geolocation, jquery.toast, jquery.fullscreen,
#   qz-tray, jsrsasign, rsvp, moment-locale-es, select2, select2-i18n-es, daterangepicker,
#   snap, chartjs-chart-treemap, chartjs-plugin-annotation y otros plugins jQuery custom.

set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NM="$ROOT/node_modules"
DEST="$ROOT/assets/vendor/js"

if [ ! -d "$NM" ]; then
  echo "✗ node_modules no existe. Corré 'npm install' primero." >&2
  exit 1
fi

# Mapa: "<dist-relativo-en-node_modules> => <nombre-destino-en-assets/vendor/js>"
MAP=(
  # Fase A
  "jquery/dist/jquery.min.js => jquery-3.6.3.min.js"
  "moment/min/moment-with-locales.min.js => moment-2.24.0-with-locales.min.js"
  "ismobilejs/isMobile.min.js => isMobile-0.4.1.min.js"
  "mousetrap/mousetrap.min.js => mousetrap-1.6.3.min.js"
  "jquery.actual/jquery.actual.min.js => jquery.actual-1.0.19.min.js"
  "lz-string/libs/lz-string.min.js => lz-string-1.4.4.min.js"
  "chart.js/dist/Chart.min.js => Chart-2.9.4.min.js"
  "sweetalert2/dist/sweetalert2.min.js => sweetalert2-7.33.1.min.js"
  "mustache/mustache.min.js => mustache-4.0.1.min.js"
  "leaflet/dist/leaflet.js => leaflet-1.7.1.js"
  "qrious/dist/qrious.min.js => qrious.min.js"
  # Fase B
  "bootstrap3/dist/js/bootstrap.min.js => bootstrap-3.4.1.min.js"
  "bootstrap4/dist/js/bootstrap.min.js => bootstrap-4.5.2.min.js"
  "eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js => bootstrap-datetimepicker-4.17.47.min.js"
  "leaflet-routing-machine/dist/leaflet-routing-machine.js => leaflet-routing-machine-3.2.12.js"
  "libphonenumber-js/bundle/libphonenumber-js.min.js => libphonenumber-1.6.8.min.js"
  "offline-js/offline.min.js => offline-0.7.19.min.js"
  "pouchdb/dist/pouchdb.min.js => pouchdb-7.2.1.min.js"
  "push.js/bin/push.min.js => push-1.0.8.min.js"
)

changed=0
for entry in "${MAP[@]}"; do
  src="${entry%% => *}"
  dst="${entry##* => }"
  if [ ! -f "$NM/$src" ]; then
    echo "✗ falta en node_modules: $src (¿npm install?)" >&2
    exit 1
  fi
  if cmp -s "$NM/$src" "$DEST/$dst"; then
    echo "= $dst (sin cambios)"
  else
    cp "$NM/$src" "$DEST/$dst"
    echo "↻ $dst (actualizado desde node_modules/$src)"
    changed=$((changed + 1))
  fi
done

echo ""
echo "vendor-sync: ${#MAP[@]} libs verificadas, $changed actualizadas."
