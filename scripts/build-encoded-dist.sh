#!/usr/bin/env bash
# AUTOMETRIA ERP — клиентский dist с SourceGuardian 17 / IonCube Encoder 15.
#
# Требования на build-агенте:
#   - sgencoder (SourceGuardian) ИЛИ ioncube_encoder.sh
#   - PHP CLI той же мажорной версии, что у клиента
#
# Пример:
#   ENCODER=sourceguardian ./scripts/build-encoded-dist.sh
#   ENCODER=ioncube ./scripts/build-encoded-dist.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENCODER="${ENCODER:-sourceguardian}"
OUT_DIR="${OUT_DIR:-$ROOT/dist-encoded}"
PHP_VERSION="${PHP_VERSION:-8.4}"

echo "==> AUTOMETRIA encoded client build ($ENCODER, PHP $PHP_VERSION)"

# Guard: private key must never enter the dist
if [[ -f "$ROOT/storage/framework/licensing/private.pem" ]]; then
  echo "WARN: private.pem exists locally — it will be EXCLUDED from dist." >&2
fi

rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"

# Stage clean tree (no .git, vendor rebuild later, no private keys)
rsync -a \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'dist' \
  --exclude 'dist-encoded' \
  --exclude 'storage/framework/licensing/private.pem' \
  --exclude 'storage/framework/licensing/*.lic' \
  --exclude 'storage/logs' \
  --exclude 'storage/framework/cache' \
  --exclude 'storage/framework/views' \
  --exclude 'tests' \
  --exclude '.env' \
  "$ROOT/" "$OUT_DIR/"

# Ensure only public key ships
mkdir -p "$OUT_DIR/storage/framework/licensing"
if [[ -f "$ROOT/storage/framework/licensing/public.pem" ]]; then
  cp "$ROOT/storage/framework/licensing/public.pem" "$OUT_DIR/storage/framework/licensing/public.pem"
fi
cp "$ROOT/storage/framework/licensing/autometria.lic.example" \
  "$OUT_DIR/storage/framework/licensing/autometria.lic.example" 2>/dev/null || true
rm -f "$OUT_DIR/storage/framework/licensing/private.pem"

encode_sourceguardian() {
  local bin="${SG_ENCODER_BIN:-sgencoder}"
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "ERROR: SourceGuardian encoder not found ($bin). Install SourceGuardian 17." >&2
    exit 2
  fi
  # Encode Autometria application sources only
  "$bin" \
    --php "$PHP_VERSION" \
    --stop-on-error \
    --copy-non-php \
    --ignore-file-perms \
    -o "$OUT_DIR/app" \
    "$OUT_DIR/app"
}

encode_ioncube() {
  local bin="${IONCUBE_ENCODER_BIN:-ioncube_encoder.sh}"
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "ERROR: IonCube encoder not found ($bin). Install IonCube Encoder 15." >&2
    exit 2
  fi
  "$bin" \
    --php "$PHP_VERSION" \
    --replace-target \
    --ignore-strict-warnings \
    --into "$OUT_DIR/app" \
    "$OUT_DIR/app"
}

case "$ENCODER" in
  sourceguardian|sg) encode_sourceguardian ;;
  ioncube|ic) encode_ioncube ;;
  *)
    echo "Unknown ENCODER=$ENCODER (use sourceguardian|ioncube)" >&2
    exit 1
    ;;
esac

# Final safety scan
if find "$OUT_DIR" -iname 'private.pem' | grep -q .; then
  echo "FATAL: private.pem leaked into dist" >&2
  exit 3
fi

echo "==> Encoded dist ready: $OUT_DIR"
echo "    Next on target host: composer install --no-dev -o && place autometria.lic"
