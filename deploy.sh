#!/bin/bash
# ============================================
# azadi.wtf — Deploy Script
# Usage: ./deploy.sh [file1 file2 ...]
#   No args = deploy all changed HTML/CSS/JS files
#   With args = deploy specific files only
#
# Auth: the cron secret goes in an Authorization header, never in a URL.
# The legacy form field is kept for one transition release so this script also
# works against a host that still runs the old cron/push.php.
# ============================================
set -e

SECRET="${AZADI_CRON_SECRET:-}"
BASE="${AZADI_DEPLOY_URL:-https://azadi.wtf/cron/push.php}"

if [ -z "$SECRET" ]; then
  echo "ERROR: Set AZADI_CRON_SECRET environment variable"
  echo "  export AZADI_CRON_SECRET='your-cron-secret-here'"
  exit 1
fi

# Files to deploy
if [ $# -eq 0 ]; then
  FILES=$(git diff --name-only HEAD~1 2>/dev/null | grep -E '\.(html|css|js)$' || echo "")
  if [ -z "$FILES" ]; then
    # Fallback: deploy all frontend files
    FILES="index.html city.html cities.html guide.html style.css script.js"
  fi
else
  FILES="$@"
fi

echo "Deploying to azadi.wtf..."
for fn in $FILES; do
  # Only deploy allowed files
  case "$fn" in
    *.html|*.css|*.js)
      if [ -f "$fn" ]; then
        CONTENT=$(cat "$fn")
        # Header auth is the supported path. The legacy "secret" form field is
        # kept for one transition release only; remove it once the host runs the
        # hardened cron/push.php and the workflow that sends the header.
        RESULT=$(curl -s -X POST "$BASE" \
          -H "Authorization: Bearer $SECRET" \
          --data-urlencode "secret=$SECRET" \
          --data-urlencode "file=$fn" \
          --data-urlencode "content=$CONTENT")
        echo "  $fn → $RESULT"
      else
        echo "  SKIP $fn (not found locally)"
      fi
      ;;
    *)
      echo "  SKIP $fn (not a deployable file type)"
      ;;
  esac
done

echo "Done."
