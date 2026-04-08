#!/bin/bash
# Build script — concatena y minifica bundles JS/CSS
# Uso: ./build.sh         (todo)
#       ./build.sh app    (solo app/)
#       ./build.sh panel  (solo panel/)
set -e

ROOT="$(cd "$(dirname "$0")" && pwd)"
V="$ROOT/assets/vendor"
TERSER="$ROOT/node_modules/.bin/terser"
CSSO="$ROOT/node_modules/.bin/csso"

if [ ! -f "$TERSER" ] || [ ! -f "$CSSO" ]; then
  echo "Installing build tools..."
  cd "$ROOT" && npm install --save-dev terser csso-cli --silent
fi

concat() {
  # concat file1 file2 ... > output
  local out="$1"; shift
  > "$out"
  for f in "$@"; do
    if [ -f "$f" ]; then
      cat "$f" >> "$out"
      echo "" >> "$out"
    else
      echo "  WARN: missing $f"
    fi
  done
}

minify_js() {
  local input="$1" output="$2"
  local raw_size=$(wc -c < "$input" | tr -d ' ')
  "$TERSER" "$input" --compress --mangle -o "$output" 2>/dev/null || cp "$input" "$output"
  local min_size=$(wc -c < "$output" | tr -d ' ')
  local pct=$((100 - (min_size * 100 / raw_size)))
  echo "  JS  $(basename "$output"): ${raw_size}b → ${min_size}b (-${pct}%)"
}

minify_css() {
  local input="$1" output="$2"
  local raw_size=$(wc -c < "$input" | tr -d ' ')
  "$CSSO" "$input" -o "$output" 2>/dev/null || cp "$input" "$output"
  local min_size=$(wc -c < "$output" | tr -d ' ')
  local pct=$((100 - (min_size * 100 / raw_size)))
  echo "  CSS $(basename "$output"): ${raw_size}b → ${min_size}b (-${pct}%)"
}

# ─── APP BUNDLES ──────────────────────────────────────────────────────────
build_app() {
  echo "=== Building app/ bundles ==="
  local TMP="$ROOT/.build_tmp"
  mkdir -p "$TMP"

  # vendor.css
  concat "$TMP/app.css" \
    "$ROOT/app/css/fonts.css" \
    "$V/css/bootstrap-3.4.1.min.css" \
    "$ROOT/app/css/app.css" \
    "$V/css/animate-4.0.0.compat.min.css" \
    "$V/css/bootstrap-datetimepicker-4.17.47.min.css" \
    "$V/css/jquery.toast-1.3.2.min.css" \
    "$ROOT/panel/css/color-selector-2.css" \
    "$V/css/sweetalert2-7.33.1.min.css" \
    "$ROOT/assets/css/iguider.css" \
    "$ROOT/assets/css/iguider.theme-material.css" \
    "$ROOT/app/css/ncmCalendars.css" \
    "$ROOT/app/css/chosen.css" \
    "$ROOT/app/css/custom.css"

  local CSS_CACHE="$ROOT/app/cach/$(echo -n '1' | shasum | cut -d' ' -f1).css"
  minify_css "$TMP/app.css" "$CSS_CACHE"

  # vendor.js
  concat "$TMP/app.js" \
    "$V/js/jquery-3.6.3.min.js" \
    "$V/js/bootstrap-3.4.1.min.js" \
    "$V/js/moment-2.24.0-with-locales.min.js" \
    "$V/js/moment-locale-es.js" \
    "$V/js/jquery.dataTables-1.10.22.min.js" \
    "$V/js/isMobile-0.4.1.min.js" \
    "$V/js/offline-0.7.19.min.js" \
    "$V/js/chosen-1.8.7.min.js" \
    "$V/js/jquery.number-2.1.6.min.js" \
    "$V/js/mousetrap-1.6.3.min.js" \
    "$V/js/jquery.actual-1.0.19.min.js" \
    "$V/js/simpleStorage-0.2.1.min.js" \
    "$V/js/pouchdb-7.2.1.min.js" \
    "$V/js/lz-string-1.4.4.min.js" \
    "$ROOT/panel/scripts/written-number.min.js" \
    "$V/js/rsvp-4.8.5.min.js" \
    "$ROOT/app/scripts/sha256.js" \
    "$V/js/jsrsasign-all-min.js" \
    "$V/js/qz-tray-2.2.1.min.js" \
    "$ROOT/app/scripts/sign-message.js" \
    "$V/js/bootstrap-datetimepicker-4.17.47.min.js" \
    "$ROOT/panel/scripts/color-selector-2.js" \
    "$V/js/libphonenumber-1.6.8.min.js" \
    "$V/js/Chart-2.9.4.min.js" \
    "$V/js/sweetalert2-7.33.1.min.js" \
    "$V/js/push-1.0.8.min.js" \
    "$V/js/mustache-4.0.1.min.js" \
    "$ROOT/app/scripts/iguider.stub.js" \
    "$V/js/jquery.fullscreen-1.1.5.min.js" \
    "$V/js/jquery.toast-1.3.2.min.js" \
    "$ROOT/app/scripts/ncm-ws.js" \
    "$ROOT/panel/scripts/rb.min.js" \
    "$ROOT/panel/scripts/num2word.js" \
    "$ROOT/panel/scripts/documentPrintBuilder.source.js" \
    "$ROOT/app/scripts/globalv2.js"

  local JS_CACHE="$ROOT/app/cach/$(echo -n '1' | shasum | cut -d' ' -f1).js"
  minify_js "$TMP/app.js" "$JS_CACHE"

  rm -rf "$TMP"
  echo ""
}

# ─── PANEL BUNDLES ────────────────────────────────────────────────────────
build_panel() {
  echo "=== Building panel/ bundles ==="
  local TMP="$ROOT/.build_tmp"
  mkdir -p "$TMP"

  # initials.js
  concat "$TMP/initials.js" \
    "$V/js/jquery-3.6.3.min.js" \
    "$V/js/jquery-ui-1.12.1.min.js" \
    "$V/js/bootstrap-3.4.1.min.js" \
    "$V/js/jquery.number-2.1.6.min.js" \
    "$V/js/jquery.dataTables-1.10.22.min.js" \
    "$V/js/jquery.mask-1.14.11.js" \
    "$V/js/moment-2.24.0-with-locales.min.js" \
    "$V/js/moment-locale-es.js" \
    "$V/js/daterangepicker-3.1.min.js" \
    "$V/js/bootstrap-datetimepicker-4.17.47.min.js" \
    "$V/js/jquery.form-4.2.1.min.js" \
    "$V/js/fastclick-1.0.6.min.js" \
    "$V/js/isMobile-0.4.1.min.js" \
    "$V/js/jquery.finger-0.1.6.min.js"

  # Need to inject the widget bridge after jquery-ui
  # Insert after jquery-ui in the concatenated file
  local BRIDGE='$.widget.bridge("uitooltip", $.ui.tooltip); $.widget.bridge("uibutton", $.ui.button);'
  # Rebuild: jquery + jqueryui + bridge + rest
  > "$TMP/initials_final.js"
  cat "$V/js/jquery-3.6.3.min.js" >> "$TMP/initials_final.js"; echo "" >> "$TMP/initials_final.js"
  cat "$V/js/jquery-ui-1.12.1.min.js" >> "$TMP/initials_final.js"; echo "" >> "$TMP/initials_final.js"
  echo "$BRIDGE" >> "$TMP/initials_final.js"
  for f in \
    "$V/js/bootstrap-3.4.1.min.js" \
    "$V/js/jquery.number-2.1.6.min.js" \
    "$V/js/jquery.dataTables-1.10.22.min.js" \
    "$V/js/jquery.mask-1.14.11.js" \
    "$V/js/moment-2.24.0-with-locales.min.js" \
    "$V/js/moment-locale-es.js" \
    "$V/js/daterangepicker-3.1.min.js" \
    "$V/js/bootstrap-datetimepicker-4.17.47.min.js" \
    "$V/js/jquery.form-4.2.1.min.js" \
    "$V/js/fastclick-1.0.6.min.js" \
    "$V/js/isMobile-0.4.1.min.js" \
    "$V/js/jquery.finger-0.1.6.min.js"; do
    cat "$f" >> "$TMP/initials_final.js"; echo "" >> "$TMP/initials_final.js"
  done
  minify_js "$TMP/initials_final.js" "$ROOT/panel/scripts/initials.js"

  # tdp.js
  concat "$TMP/tdp.js" \
    "$V/js/snap-1.9.3.min.js" \
    "$V/js/xlsx-0.16.2.full.min.js" \
    "$V/js/jquery.matchHeight-0.7.2.min.js" \
    "$V/js/jquery.toast-1.3.2.min.js" \
    "$V/js/jquery.fullscreen-1.1.5.min.js" \
    "$V/js/jQuery.print-1.5.1.min.js" \
    "$V/js/Chart-2.9.4.min.js" \
    "$V/js/chartjs-plugin-annotation-0.5.7.min.js" \
    "$V/js/chartjs-chart-treemap-0.2.3.min.js" \
    "$V/js/simpleStorage-0.2.1.min.js" \
    "$V/js/select2-4.1.0.min.js" \
    "$V/js/select2-i18n-es.min.js" \
    "$V/js/mustache-4.0.1.min.js" \
    "$V/js/jquery.lazy-1.7.10.min.js" \
    "$V/js/jquery.businessHours-1.0.1.min.js" \
    "$V/js/sweetalert2-7.33.1.min.js" \
    "$V/js/offline-0.7.19.min.js" \
    "$V/js/push-1.0.8.min.js" \
    "$ROOT/panel/screens/scripts/ncm-ws.js" \
    "$ROOT/panel/scripts/written-number.min.js" \
    "$V/js/leaflet-1.7.1.js" \
    "$V/js/leaflet-routing-machine-3.2.12.js"

  minify_js "$TMP/tdp.js" "$ROOT/panel/scripts/tdp.js"

  # ncm.js
  concat "$TMP/ncm.js" \
    "$ROOT/panel/scripts/documentPrintBuilder.source.js" \
    "$ROOT/panel/scripts/rb.min.js" \
    "$ROOT/panel/scripts/common.js"

  minify_js "$TMP/ncm.js" "$ROOT/panel/scripts/ncm.js"

  # ncm.css
  concat "$TMP/ncm.css" \
    "$ROOT/panel/css/font.css" \
    "$V/css/jquery-ui-git.css" \
    "$V/css/bootstrap-3.4.1.min.css" \
    "$V/css/daterangepicker-3.1.css" \
    "$V/css/animate-3.5.2.min.css" \
    "$V/css/jquery.toast-1.3.2.min.css" \
    "$V/css/bootstrap-datetimepicker-4.17.45.min.css" \
    "$V/css/select2-4.0.6.min.css" \
    "$V/css/select2-bootstrap-0.1.0.min.css" \
    "$ROOT/panel/css/color-selector-2.css" \
    "$V/css/sweetalert2-7.33.1.min.css" \
    "$V/css/offline-language-spanish.min.css" \
    "$V/css/offline-theme-dark.min.css" \
    "$ROOT/panel/css/app.css" \
    "$ROOT/panel/css/style.css" \
    "$V/css/leaflet-1.7.1.css"

  minify_css "$TMP/ncm.css" "$ROOT/panel/css/ncm.css"

  rm -rf "$TMP"
  echo ""
}

# ─── MAIN ─────────────────────────────────────────────────────────────────
TARGET="${1:-all}"
START=$(date +%s)

case "$TARGET" in
  app)   build_app ;;
  panel) build_panel ;;
  all)   build_app; build_panel ;;
  *)     echo "Usage: $0 [app|panel|all]"; exit 1 ;;
esac

END=$(date +%s)
echo "Build complete in $((END - START))s"
