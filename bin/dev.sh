#!/bin/sh
set -e

WATCH_DIRS="/app/src /app/config /app/bin"
CMD="$@"

if [ -z "$CMD" ]; then
    echo "Usage: bin/dev.sh <command>"
    echo "Example: bin/dev.sh php bin/server-ws.php"
    exit 1
fi

echo "=== Dev watcher started ==="
echo "Watching: $WATCH_DIRS"
echo "Command:  $CMD"
echo ""

while true; do
    $CMD &
    PID=$!

    inotifywait -q -r -e modify,create,delete,move --exclude '\.(swp|swx|~)$' $WATCH_DIRS 2>/dev/null
    echo "[dev] Files changed, restarting..."

    kill $PID 2>/dev/null
    wait $PID 2>/dev/null

    sleep 0.5
done
