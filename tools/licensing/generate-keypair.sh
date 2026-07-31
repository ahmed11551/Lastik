#!/usr/bin/env bash
# AUTOMETRIA ERP — генерация RSA-пары ТОЛЬКО на закрытом сервере лицензирования.
# НИКОГДА не запускайте этот скрипт в клиентском CI и не коммитьте private.pem.
set -euo pipefail

OUT_DIR="${1:-$HOME/.autometria-licensing/keys}"
BITS="${BITS:-4096}"

mkdir -p "$OUT_DIR"
chmod 700 "$OUT_DIR"

PRIVATE="$OUT_DIR/private.pem"
PUBLIC="$OUT_DIR/public.pem"

if [[ -f "$PRIVATE" ]]; then
  echo "ERROR: $PRIVATE already exists. Rotate manually if needed." >&2
  exit 1
fi

openssl genrsa -out "$PRIVATE" "$BITS"
chmod 600 "$PRIVATE"
openssl rsa -in "$PRIVATE" -pubout -out "$PUBLIC"
chmod 644 "$PUBLIC"

echo "Generated:"
echo "  PRIVATE (keep offline / licensing vault only): $PRIVATE"
echo "  PUBLIC  (copy into client dist as storage/framework/licensing/public.pem): $PUBLIC"
echo
echo "Next: copy PUBLIC into the client repository; keep PRIVATE off-repo."
