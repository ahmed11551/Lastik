#!/usr/bin/env bash
# CI/local guard: private.pem and signed licenses must not be tracked.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

leaks="$(git ls-files | grep -E '(^|/)private\.pem$|\.lic$' || true)"
if [[ -n "$leaks" ]]; then
  echo "FATAL: secret licensing artifacts are tracked by git:" >&2
  echo "$leaks" >&2
  exit 1
fi

if [[ -f dist-encoded/storage/framework/licensing/private.pem ]]; then
  echo "FATAL: private.pem present in dist-encoded" >&2
  exit 1
fi

echo "OK: no private.pem / *.lic tracked"
