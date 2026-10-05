#!/bin/bash
# Deploys the checked-out repository into the web root. Called by .cpanel.yml
# (cPanel runs it from the repository directory). Safe to run by hand too:
#   bash deploy/cpanel-deploy.sh [/path/to/webroot]
#
# Never touches: .env, app/config.local.php, uploads/logos/*, uploads/photos/*
# Log: ~/c2c-deploy.log

DEPLOYPATH="${1:-${DEPLOYPATH:-/home/shooting/public_html/c2c.kdiscmis.org.in}}"
DEPLOYPATH="${DEPLOYPATH%/}"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
LOG="${HOME:-/tmp}/c2c-deploy.log"

exec > >(tee -a "$LOG") 2>&1
echo "=== $(date '+%Y-%m-%d %H:%M:%S') deploy $(git -C "$SRC" rev-parse --short HEAD 2>/dev/null) from $SRC to $DEPLOYPATH"

if [ -z "$DEPLOYPATH" ] || [ "$DEPLOYPATH" = "/" ] || [ "$DEPLOYPATH" = "$SRC" ]; then
    echo "ERROR: invalid deploy path '$DEPLOYPATH'"; exit 1
fi
mkdir -p "$DEPLOYPATH" || { echo "ERROR: cannot create $DEPLOYPATH"; exit 1; }

EXCLUDES=(.git .gitignore .cpanel.yml .env .env.example deploy node_modules package.json package-lock.json
          router.php app/config.local.php 'uploads/logos/*' 'uploads/photos/*' error_log .well-known cgi-bin .user.ini php.ini)

RSYNC="$(command -v rsync || ls /usr/bin/rsync /bin/rsync 2>/dev/null | head -1)"
if [ -n "$RSYNC" ]; then
    ARGS=()
    for x in "${EXCLUDES[@]}"; do ARGS+=(--exclude="$x"); done
    # -rlt: recurse, links, times (no owner/group/perms - those fail on shared hosting)
    "$RSYNC" -rlt --delete --omit-dir-times --itemize-changes "${ARGS[@]}" "$SRC/" "$DEPLOYPATH/"
    rc=$?
    # 23/24 = some attributes/files could not be transferred (e.g. times on the domain folder) - not fatal
    if [ $rc -ne 0 ] && [ $rc -ne 23 ] && [ $rc -ne 24 ]; then
        echo "ERROR: rsync failed with exit code $rc"; exit $rc
    fi
else
    echo "rsync not found - falling back to cp (removed files are not deleted from the web root)"
    cd "$SRC" || exit 1
    git ls-files | grep -vE '^(\.gitignore|\.cpanel\.yml|\.env\.example|deploy/|package(-lock)?\.json|router\.php)' \
        | while IFS= read -r f; do mkdir -p "$DEPLOYPATH/$(dirname "$f")" && cp -f "$f" "$DEPLOYPATH/$f"; done
fi

mkdir -p "$DEPLOYPATH/uploads/logos" "$DEPLOYPATH/uploads/photos"
find "$DEPLOYPATH" -path "$DEPLOYPATH/uploads" -prune -o -type d -exec chmod 755 {} + 2>/dev/null
find "$DEPLOYPATH" -path "$DEPLOYPATH/uploads" -prune -o -type f -name '*.php' -exec chmod 644 {} + 2>/dev/null
chmod 755 "$DEPLOYPATH/uploads" "$DEPLOYPATH/uploads/logos" "$DEPLOYPATH/uploads/photos" 2>/dev/null

if [ -f "$DEPLOYPATH/.env" ]; then
    chmod 640 "$DEPLOYPATH/.env" 2>/dev/null
else
    echo "WARNING: $DEPLOYPATH/.env is missing - copy .env.example there and fill in the database settings"
fi

echo "=== deploy finished OK"
exit 0
