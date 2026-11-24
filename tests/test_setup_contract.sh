#!/usr/bin/env bash
# Static regression checks for setup-cyrad.sh invariants.
set -euo pipefail

script="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/setup-cyrad.sh}"
source_text="$(<"$script")"

require() {
  local description=$1 pattern=$2
  if [[ "$source_text" != *"$pattern"* ]]; then
    printf 'FAIL: %s\n' "$description" >&2
    exit 1
  fi
}

require "installer installs rsync before using it" "mariadb-server unzip git curl rsync freeradius freeradius-mysql"
require "installer uses numeric fail-fast migration runner" 'php "$SRC/admin/apply_migrations.php"'
require "installer installs mail worker and persistent key" 'bash "$SRC/setup-mail.sh" --skip-packages'
require "data-limit cron uses the configured deploy directory" 'root /usr/bin/php $DEPLOY_DIR/admin/disable_user_if_limit_exceeded.php'
require "installer uses distro-provided generic PHP packages" 'nginx php-fpm php-mysql php-cli php-mbstring php-xml php-curl'
require "domain-free installs skip DoT by default" 'SKIP_DOT=1'
require "installer generates an admin password when none is supplied" 'ADMIN_PASS="${CYRAD_ADMIN_PASS:-$(openssl rand -base64 24'
require "installer writes deployment credentials" 'CYRAD_CREDENTIALS.txt'
require "installer writes the actual panel URL to credentials" 'Panel URL: http://${SERVER_IP}:8080/admin/login.php'
require "installer fixes the FreeRADIUS modconfdir reference" 's/${mods-config}/${modconfdir}/g'
require "installer prints FreeRADIUS validation failures" 'if ! freeradius -XC; then'
require "installer validates before starting FreeRADIUS" 'systemctl enable freeradius'

legacy_script="$(cd "$(dirname "$script")/admin" && pwd)/setup.sh"
legacy_text="$(<"$legacy_script")"
if [[ "$legacy_text" != *'exec "$SCRIPT_DIR/setup-cyrad.sh" "$@"'* ]]; then
  printf 'FAIL: legacy admin/setup.sh delegates to the canonical installer\n' >&2
  exit 1
fi

printf 'PASS: setup installer contract checks\n'
