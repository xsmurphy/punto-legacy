#!/bin/bash
# vendor-sync.sh — sincroniza las libs JS vendoreables desde node_modules → assets/vendor/js.
#
# Fuente de verdad: package.json (versiones pineadas) + node_modules. Este script copia el
# dist oficial de cada lib al nombre versionado que esperan filesCompiler.php / build.sh.
# Idempotente: correr tras `npm install`/`npm update` para refrescar los vendoreados.
#
# Uso: bash scripts/vendor-sync.sh   (o: npm run vendor)
#
# SÓLO cubre las libs que están en npm Y cuyo dist coincide con el archivo servido (verificado
# byte-idéntico al cablear, 2026-05-28). Los plugins jQuery viejos/custom sin npm limpio
# (chosen, jquery.number, simpleStorage, jquery.geolocation, jquery.toast, jquery.fullscreen,
# qz-tray, jsrsasign, rsvp, pouchdb, datatables, offline, fastclick, push, libphonenumber,
# fingerprintjs, leaflet-routing-machine, bootstrap, datetimepicker) siguen como copias
# manuales commiteadas en assets/vendor/js — ver 06-infraestructura.md.

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
