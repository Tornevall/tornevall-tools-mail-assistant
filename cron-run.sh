#!/bin/sh
set -eu

DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

LOCK_DIR=${MAIL_ASSISTANT_CRON_LOCK_DIR:-"$DIR/storage/state/cron-run.lock.d"}
LOCK_INFO_FILE="$LOCK_DIR/holder"

mkdir -p "$(dirname -- "$LOCK_DIR")"

current_utc_timestamp() {
	date -u +"%Y-%m-%dT%H:%M:%SZ"
}

read_lock_pid() {
	if [ ! -f "$LOCK_INFO_FILE" ]; then
		return 1
	fi

	sed -n 's/^pid=//p' "$LOCK_INFO_FILE" | head -n 1
}

write_lock_info() {
	{
		printf 'pid=%s\n' "$$"
		printf 'owner=%s\n' "cron-run.sh"
		printf 'started_at=%s\n' "$(current_utc_timestamp)"
		printf 'host=%s\n' "$(hostname 2>/dev/null || printf unknown)"
		printf 'dir=%s\n' "$DIR"
	} > "$LOCK_INFO_FILE"
}

cleanup_lock() {
	rm -rf -- "$LOCK_DIR"
}

acquire_lock() {
	if mkdir "$LOCK_DIR" 2>/dev/null; then
		write_lock_info
		return 0
	fi

	holder_pid=$(read_lock_pid || true)
	if [ -n "${holder_pid:-}" ] && kill -0 "$holder_pid" 2>/dev/null; then
		printf 'Mail Support Assistant cron skipped: another process is already running (pid %s).\n' "$holder_pid" >&2
		return 1
	fi

	printf 'Mail Support Assistant cron found a stale lock and will remove it before retrying.\n' >&2
	rm -rf -- "$LOCK_DIR"

	if mkdir "$LOCK_DIR" 2>/dev/null; then
		write_lock_info
		return 0
	fi

	printf 'Mail Support Assistant cron could not acquire the lock directory: %s\n' "$LOCK_DIR" >&2
	exit 1
}

if ! acquire_lock; then
	exit 0
fi

trap cleanup_lock EXIT INT TERM HUP

php "$DIR/run" "$@"

