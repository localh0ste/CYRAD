#!/usr/bin/env bash
# Compatibility entry point. Keep this path for existing instructions, but use
# the maintained installer at the repository root.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec "$SCRIPT_DIR/setup-cyrad.sh" "$@"
