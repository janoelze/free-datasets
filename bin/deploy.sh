#!/usr/bin/env bash
# Deploy the app to the Uberspace host and smoke-test the public URL.
# Used both by hand (from a checkout) and by .github/workflows/ci.yml.
#
#   bin/deploy.sh
#
# Steps: validate datasets locally (fail fast) -> rsync the app tree -> on the server
# build var/catalog.json, create the docroot symlink if missing and (re)install the redirect
# directory at the app's former path -> smoke test.
# Rollback = check out an older commit and run this script again.
#
# Overridable via environment (defaults in brackets):
#   DEPLOY_HOST        [aquila.uberspace.de]
#   DEPLOY_USER        [janoelze]
#   DEPLOY_APP_DIR     [/var/www/virtual/janoelze/free-datasets]    rsync target (app root; must be
#                      reachable by Apache -- not under the 0700 home directory)
#   DEPLOY_DOCROOT     [/var/www/virtual/janoelze/endtime-instruments.org]
#   DEPLOY_MOUNT       [free-datasets]                         docroot entry -> $DEPLOY_APP_DIR/public
#   DEPLOY_PUBLIC_URL  [https://endtime-instruments.org/free-datasets]
#   DEPLOY_LEGACY_MOUNT [datasets]                             former docroot entry; becomes a real
#                      directory holding only deploy/legacy-redirect/.htaccess (301/308 to the new
#                      path). Empty = leave it alone.
#   DEPLOY_SSH_OPTS    []                                      extra ssh options
#   DEPLOY_SKIP_SMOKE  [0]                                     1 = skip the smoke test
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-aquila.uberspace.de}"
DEPLOY_USER="${DEPLOY_USER:-janoelze}"
DEPLOY_APP_DIR="${DEPLOY_APP_DIR:-/var/www/virtual/janoelze/free-datasets}"
DEPLOY_DOCROOT="${DEPLOY_DOCROOT:-/var/www/virtual/janoelze/endtime-instruments.org}"
DEPLOY_MOUNT="${DEPLOY_MOUNT:-free-datasets}"
DEPLOY_PUBLIC_URL="${DEPLOY_PUBLIC_URL:-https://endtime-instruments.org/free-datasets}"
DEPLOY_LEGACY_MOUNT="${DEPLOY_LEGACY_MOUNT-datasets}"
DEPLOY_SSH_OPTS="${DEPLOY_SSH_OPTS:-}"
DEPLOY_SKIP_SMOKE="${DEPLOY_SKIP_SMOKE:-0}"

DEPLOY_PUBLIC_URL="${DEPLOY_PUBLIC_URL%/}"
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck disable=SC2206 # intentional word splitting of extra options
SSH_CMD=(ssh -o BatchMode=yes -o ConnectTimeout=20 ${DEPLOY_SSH_OPTS})

log() { printf '\n==> %s\n' "$*"; }

log "Validating datasets"
php bin/validate.php --quiet
echo "ok"

log "Syncing app to ${TARGET}:${DEPLOY_APP_DIR}/"
"${SSH_CMD[@]}" "$TARGET" "mkdir -p '${DEPLOY_APP_DIR}/var'"
# --delete only acts inside the listed directories; var/ (server-built cache, explorer rate-limit
# counters in var/ratelimit and response cache in var/explore-cache, created 0700 on demand by
# the app) is never sent and never deleted.
rsync -rlptz --delete --omit-dir-times \
  -e "${SSH_CMD[*]}" \
  --exclude='.git' --exclude='.github' --exclude='.claude' --exclude='tests' \
  --exclude='var' --exclude='.DS_Store' --exclude='node_modules' \
  public src templates datasets schema bin docs deploy \
  "${TARGET}:${DEPLOY_APP_DIR}/"

log "Building catalogue and linking docroot on the server"
"${SSH_CMD[@]}" "$TARGET" bash -s -- "$DEPLOY_APP_DIR" "$DEPLOY_DOCROOT" "$DEPLOY_MOUNT" "$DEPLOY_LEGACY_MOUNT" <<'REMOTE'
set -euo pipefail
export PATH="$HOME/bin:/usr/local/bin:/usr/bin:/bin"
app="$1"; docroot="$2"; mount="$3"; legacy="${4:-}"
link="$docroot/$mount"
target="$app/public"

cd "$app"
php bin/build.php

# The link target must exist before the link does: a dangling symlink in the docroot can
# break the shared web config for the whole host.
if [ ! -f "$target/index.php" ] || [ ! -f "$target/.htaccess" ]; then
  echo "error: $target is incomplete, not linking" >&2
  exit 1
fi
# Apache must be able to traverse every directory up to the target (a 0700 $HOME is not),
# otherwise the link 403s with AH00037.
dir="$target"
while [ "$dir" != "/" ]; do
  perms="$(stat -c '%A %G' "$dir")"
  mode="${perms%% *}"; group="${perms#* }"
  if [ "${mode:9:1}" != "x" ] && [ "${mode:9:1}" != "t" ] && ! { [ "$group" = apache ] && [ "${mode:6:1}" = "x" ]; }; then
    echo "error: Apache cannot traverse $dir ($perms); not linking" >&2
    exit 1
  fi
  dir="$(dirname "$dir")"
done
if [ -L "$link" ]; then
  current="$(readlink "$link")"
  if [ "$current" = "$target" ]; then
    echo "symlink ok: $link -> $target"
  else
    echo "error: $link points to $current, expected $target; refusing to touch it" >&2
    exit 1
  fi
elif [ -e "$link" ]; then
  echo "error: $link exists and is not a symlink; refusing to touch it" >&2
  exit 1
else
  ln -sfn "$target" "$link"
  echo "symlink created: $link -> $target"
fi

# Former path: a real directory whose only file is our redirect .htaccess. Installed only once
# the new path is linked. Never overwrites anything that is not its own file; the one other state
# it accepts is the app's old symlink (<docroot>/$legacy -> .../$legacy/public), which it replaces.
[ -n "$legacy" ] || exit 0
[ "$legacy" != "$mount" ] || { echo "error: legacy mount equals mount ($mount)" >&2; exit 1; }
src="$app/deploy/legacy-redirect/.htaccess"
dest="$docroot/$legacy"
marker="$(head -n 1 "$src")"
case "$marker" in "# free-datasets legacy redirect"*) ;; *) echo "error: $src lacks its marker line" >&2; exit 1 ;; esac
owned_dir() { # $1: directory -> true if it holds exactly our .htaccess and nothing else
  [ -d "$1" ] && [ ! -L "$1" ] && [ "$(ls -A "$1")" = .htaccess ] && [ -f "$1/.htaccess" ] && [ ! -L "$1/.htaccess" ] \
    && [ "$(head -n 1 "$1/.htaccess")" = "$marker" ]
}
build_tmp() { # -> prints a fresh directory next to $dest holding the redirect
  local tmp="$docroot/.$legacy.redirect-tmp.$$"
  rm -rf -- "$tmp"
  mkdir -m 0755 "$tmp"
  cp "$src" "$tmp/.htaccess"
  chmod 0644 "$tmp/.htaccess"
  echo "$tmp"
}
if owned_dir "$dest"; then
  if cmp -s "$src" "$dest/.htaccess"; then
    echo "legacy redirect ok: $dest"
  else
    cp "$src" "$dest/.htaccess.new.$$" && chmod 0644 "$dest/.htaccess.new.$$" && mv -f "$dest/.htaccess.new.$$" "$dest/.htaccess"
    echo "legacy redirect updated: $dest"
  fi
elif [ -L "$dest" ]; then
  current="$(readlink "$dest")"
  case "$current" in
    */"$legacy"/public)
      tmp="$(build_tmp)"
      # A directory cannot be renamed over a symlink, so this is unlink + rename back to back:
      # the path is absent for microseconds (a plain 404), never dangling, never the wrong app.
      rm -f -- "$dest" && mv -T -- "$tmp" "$dest"
      echo "legacy redirect installed: $dest (replaced symlink -> $current)"
      ;;
    *) echo "error: $dest -> $current is not the app's old symlink; refusing to touch it" >&2; exit 1 ;;
  esac
elif [ -e "$dest" ]; then
  echo "error: $dest exists and is not our redirect directory; refusing to touch it" >&2
  exit 1
else
  tmp="$(build_tmp)"
  mv -T -- "$tmp" "$dest"
  echo "legacy redirect installed: $dest"
fi
REMOTE

if [ "$DEPLOY_SKIP_SMOKE" = "1" ]; then
  log "Smoke test skipped"
  exit 0
fi

log "Smoke testing ${DEPLOY_PUBLIC_URL}/"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

# fetch PATH -> sets $status, $ctype; body in $tmp/body
fetch() {
  local out
  out="$(curl -sS -o "$tmp/body" -w '%{http_code} %{content_type}' --max-time 20 \
    -H 'Cache-Control: no-cache' "${DEPLOY_PUBLIC_URL}$1" 2>"$tmp/err")" || out="000 -"
  status="${out%% *}"
  ctype="${out#* }"
}

pass() { printf '  ok    %-44s %s\n' "$1" "$2"; }
fail() { printf '  FAIL  %-44s %s\n' "$1" "$2"; failures=$((failures + 1)); }

fetch "/"
if [ "$status" = 200 ]; then r=pass; else r=fail; fi
$r "GET /" "$status $ctype"

fetch "/api/v1"
# shellcheck disable=SC2016 # PHP code, not shell
count="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? (int) ($d["counts"]["datasets"] ?? 0) : -1;' "$tmp/body" 2>/dev/null || echo -1)"
if [ "$status" = 200 ] && [[ "$ctype" == application/json* ]] && [ "$count" -gt 0 ]; then
  pass "GET /api/v1" "$status $ctype, datasets=$count"
else
  fail "GET /api/v1" "$status $ctype, datasets=$count"
fi

fetch "/api/v1/datasets/open-meteo-forecast"
if [ "$status" = 200 ]; then r=pass; else r=fail; fi
$r "GET /api/v1/datasets/open-meteo-forecast" "$status $ctype"

fetch "/api/v1/openapi.json"
if [ "$status" = 200 ] && grep -q '"3\.1\.0"' "$tmp/body"; then r=pass; else r=fail; fi
$r "GET /api/v1/openapi.json (openapi 3.1.0)" "$status $ctype"

fetch "/llms.txt"
if [ "$status" = 200 ]; then r=pass; else r=fail; fi
$r "GET /llms.txt" "$status $ctype"

fetch "/sitemap.xml"
if [ "$status" = 200 ] && [[ "$ctype" == application/xml* ]] && grep -qF "<loc>${DEPLOY_PUBLIC_URL}/d/open-meteo-forecast</loc>" "$tmp/body"; then r=pass; else r=fail; fi
$r "GET /sitemap.xml (absolute public URLs)" "$status $ctype"

fetch "/api/v1/no-such-resource-smoke-test"
if [ "$status" = 404 ] && [[ "$ctype" == application/problem+json* ]]; then r=pass; else r=fail; fi
$r "GET /api/v1/<bogus> (404 problem+json)" "$status $ctype"

# Explorer proxy: the open-meteo example comes back 200 through it; a foreign host is refused.
explore() {
  local out
  out="$(curl -sS -o "$tmp/body" -w '%{http_code} %{content_type}' --max-time 30 \
    -X POST -H 'Content-Type: application/json' --data "$1" "${DEPLOY_PUBLIC_URL}/explore" 2>"$tmp/err")" || out="000 -"
  status="${out%% *}"
  ctype="${out#* }"
}
explore '{"slug":"open-meteo-forecast","method":"GET","url":"https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41&current=temperature_2m,wind_speed_10m"}'
# shellcheck disable=SC2016 # PHP code, not shell
upstream="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? (int) ($d["status"] ?? 0) : -1;' "$tmp/body" 2>/dev/null || echo -1)"
if [ "$status" = 200 ] && [[ "$ctype" == application/json* ]] && [ "$upstream" = 200 ]; then
  pass "POST /explore (open-meteo example)" "$status, upstream $upstream"
else
  fail "POST /explore (open-meteo example)" "$status $ctype, upstream $upstream"
fi
explore '{"slug":"open-meteo-forecast","method":"GET","url":"https://example.com/"}'
if [[ "$status" == 4* ]] && [[ "$ctype" == application/problem+json* ]]; then r=pass; else r=fail; fi
$r "POST /explore (foreign host refused)" "$status $ctype"

# Former path: permanent redirect to the same path + query, with CORS for cross-origin fetch().
if [ -n "$DEPLOY_LEGACY_MOUNT" ]; then
  origin="$(printf '%s' "$DEPLOY_PUBLIC_URL" | sed -E 's#^(https?://[^/]+).*#\1#')"
  legacy_url="${origin}/${DEPLOY_LEGACY_MOUNT}"
  redirect() { # METHOD PATH -> sets $status, $location, $acao
    local hdr
    hdr="$(curl -sS -o /dev/null -D - --max-time 20 -X "$1" "${legacy_url}$2" 2>"$tmp/err" | tr -d '\r')" || hdr=""
    status="$(printf '%s\n' "$hdr" | awk 'NR==1{print $2}')"
    location="$(printf '%s\n' "$hdr" | awk 'tolower($1)=="location:"{print $2}')"
    acao="$(printf '%s\n' "$hdr" | awk 'tolower($1)=="access-control-allow-origin:"{print $2}')"
  }
  redirect GET "/api/v1/datasets?keyless=true&limit=1"
  if [ "$status" = 301 ] && [ "$location" = "${DEPLOY_PUBLIC_URL}/api/v1/datasets?keyless=true&limit=1" ] && [ "$acao" = "*" ]; then r=pass; else r=fail; fi
  $r "GET /${DEPLOY_LEGACY_MOUNT}/api/v1/datasets?… (301 + CORS)" "$status $location acao=$acao"
  redirect GET ""
  if [ "$status" = 301 ] && [ "$location" = "${DEPLOY_PUBLIC_URL}/" ]; then r=pass; else r=fail; fi
  $r "GET /${DEPLOY_LEGACY_MOUNT} (301)" "$status $location"
  redirect POST "/explore"
  if [ "$status" = 308 ] && [ "$location" = "${DEPLOY_PUBLIC_URL}/explore" ]; then r=pass; else r=fail; fi
  $r "POST /${DEPLOY_LEGACY_MOUNT}/explore (308)" "$status $location"
fi

if [ "$failures" -gt 0 ]; then
  echo
  echo "Smoke test FAILED: $failures check(s)" >&2
  exit 1
fi
echo
echo "Deployed and verified: ${DEPLOY_PUBLIC_URL}/"
