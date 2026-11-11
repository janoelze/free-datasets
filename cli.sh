#!/usr/bin/env bash
# Project task runner. Usage: ./cli.sh <command> [args]
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  cat <<'USAGE'
Usage: ./cli.sh <command> [args]

  dev [port]          Start the local dev server (default port 8000)
  dev:prefix [port]   Same, but served under /datasets like production
  validate [args]     Validate every datasets/*.json against the schema
  build               Validate, then write var/catalog.json
  verify [slug…]      Execute each record's example request (--write to update records)
  test [filter]       Run the test suite
  lint                php -l on every PHP file
  check               lint + validate + test (what CI runs)
  deploy              Deploy to the server (bin/deploy.sh)
USAGE
}

serve() {
  local port="${1:-8000}"
  echo "http://localhost:${port}${APP_BASE_PATH:-}/"
  exec php -d display_errors=1 -d error_reporting=E_ALL -S "localhost:${port}" -t public public/index.php
}

lint() {
  local failed=0 f
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || failed=1
  done < <(find . -name '*.php' -not -path './var/*' -not -path './.git/*' -print0)
  [ "$failed" -eq 0 ] && echo "Lint OK."
  return "$failed"
}

cmd="${1:-help}"; shift || true
case "$cmd" in
  dev)        serve "$@" ;;
  dev:prefix) APP_BASE_PATH=/datasets serve "$@" ;;
  validate)   php bin/validate.php "$@" ;;
  build)      php bin/build.php "$@" ;;
  verify)     php bin/verify.php "$@" ;;
  test)       php tests/run.php "$@" ;;
  lint)       lint ;;
  check)      lint; php bin/validate.php --quiet && php tests/run.php ;;
  deploy)     exec bin/deploy.sh "$@" ;;
  help|-h|--help) usage ;;
  *) echo "Unknown command: $cmd" >&2; usage >&2; exit 2 ;;
esac
