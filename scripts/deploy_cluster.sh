#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Deploy local/jobportal to a Moodle cluster.

Usage:
  ./scripts/deploy_cluster.sh --config ./scripts/deploy_cluster.env

Options:
  --config <file>        Environment file with deploy settings (required)
  --dry-run              Print actions without changing remote servers
  --with-maintenance     Toggle Moodle maintenance mode during deploy
  --skip-maintenance     Explicitly skip maintenance mode (default behavior)
  --skip-upgrade         Do not run Moodle CLI upgrade
  --seed                 Run local/jobportal seed script on primary node after deploy
  --seed-args "<args>"   Arguments to pass to seed script (example: --seed-args "--companies=20 --jobspercompany=5")
  --skip-purge           Do not purge caches after deploy
  --help                 Show this message

Required config values:
  SERVERS                Space-separated hostnames (example: "web1 web2")
  PRIMARY_SERVER         Host to run maintenance/upgrade from (must be in SERVERS)
  DEPLOY_USER            SSH user on remote hosts
  REMOTE_MOODLE_DIR      Moodle root on remote host (example: /var/www/moodle)

Optional config values:
  PHP_BIN                PHP binary path on remote host (default: php)
  PLUGIN_NAME            Plugin directory name under local/ (default: jobportal)
  PLUGIN_DIR             Local plugin source directory (default: parent of this script)
  SSH_OPTS               Extra ssh options (default: "-o BatchMode=yes")
  BACKUP_DIR             Remote backup directory (default: $REMOTE_MOODLE_DIR/local/.deploy-backups)
  SEED_SCRIPT            Remote seed script path (default: $REMOTE_MOODLE_DIR/local/$PLUGIN_NAME/cli/seed_test_data.php)
  SEED_ARGS              Default arguments for seed script when --seed is used
EOF
}

CONFIG_FILE=""
DRY_RUN=0
SKIP_MAINTENANCE=1
SKIP_UPGRADE=0
SKIP_PURGE=0
RUN_SEED=0
SEED_ARGS_OVERRIDE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --config)
            CONFIG_FILE="${2:-}"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --with-maintenance)
            SKIP_MAINTENANCE=0
            shift
            ;;
        --skip-maintenance)
            SKIP_MAINTENANCE=1
            shift
            ;;
        --skip-upgrade)
            SKIP_UPGRADE=1
            shift
            ;;
        --seed)
            RUN_SEED=1
            shift
            ;;
        --seed-args)
            SEED_ARGS_OVERRIDE="${2:-}"
            shift 2
            ;;
        --skip-purge)
            SKIP_PURGE=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage
            exit 1
            ;;
    esac
done

if [[ -z "$CONFIG_FILE" ]]; then
    echo "--config is required." >&2
    usage
    exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Config file not found: $CONFIG_FILE" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$CONFIG_FILE"
set +a

: "${SERVERS:?SERVERS is required in config}"
: "${PRIMARY_SERVER:?PRIMARY_SERVER is required in config}"
: "${DEPLOY_USER:?DEPLOY_USER is required in config}"
: "${REMOTE_MOODLE_DIR:?REMOTE_MOODLE_DIR is required in config}"

PHP_BIN="${PHP_BIN:-php}"
PLUGIN_NAME="${PLUGIN_NAME:-jobportal}"
SSH_OPTS="${SSH_OPTS:--o BatchMode=yes}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="${PLUGIN_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-$REMOTE_MOODLE_DIR/local/.deploy-backups}"

REMOTE_PLUGIN_DIR="$REMOTE_MOODLE_DIR/local/$PLUGIN_NAME"
SEED_SCRIPT="${SEED_SCRIPT:-$REMOTE_PLUGIN_DIR/cli/seed_test_data.php}"
SEED_ARGS="${SEED_ARGS:-}"
if [[ -n "$SEED_ARGS_OVERRIDE" ]]; then
    SEED_ARGS="$SEED_ARGS_OVERRIDE"
fi
TIMESTAMP="$(date -u +%Y%m%d%H%M%S)"

command -v ssh >/dev/null 2>&1 || { echo "ssh is required."; exit 1; }
command -v rsync >/dev/null 2>&1 || { echo "rsync is required."; exit 1; }

if [[ ! -d "$PLUGIN_DIR" ]]; then
    echo "Local PLUGIN_DIR does not exist: $PLUGIN_DIR" >&2
    exit 1
fi

PRIMARY_FOUND=0
for host in $SERVERS; do
    if [[ "$host" == "$PRIMARY_SERVER" ]]; then
        PRIMARY_FOUND=1
        break
    fi
done

if [[ "$PRIMARY_FOUND" -ne 1 ]]; then
    echo "PRIMARY_SERVER ($PRIMARY_SERVER) must be listed in SERVERS." >&2
    exit 1
fi

run_remote() {
    local host="$1"
    local command="$2"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "[dry-run] ssh $SSH_OPTS ${DEPLOY_USER}@${host} \"$command\""
        return 0
    fi
    ssh $SSH_OPTS "${DEPLOY_USER}@${host}" "$command"
}

run_rsync() {
    local host="$1"
    local source_dir="$2"
    local destination_dir="$3"
    local rsync_opts=(-az --delete --exclude='.git/' --exclude='.DS_Store' --exclude='*.swp')
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "[dry-run] rsync ${rsync_opts[*]} -e \"ssh $SSH_OPTS\" \"${source_dir}/\" \"${DEPLOY_USER}@${host}:${destination_dir}/\""
        return 0
    fi
    rsync "${rsync_opts[@]}" -e "ssh $SSH_OPTS" "${source_dir}/" "${DEPLOY_USER}@${host}:${destination_dir}/"
}

MAINTENANCE_ENABLED=0
cleanup() {
    if [[ "$MAINTENANCE_ENABLED" -eq 1 && "$SKIP_MAINTENANCE" -eq 0 ]]; then
        echo "Disabling maintenance mode on ${PRIMARY_SERVER}..."
        run_remote "$PRIMARY_SERVER" \
            "$PHP_BIN '$REMOTE_MOODLE_DIR/admin/cli/maintenance.php' --disable" || true
    fi
}
trap cleanup EXIT

echo "Deploying $PLUGIN_DIR to: $SERVERS"
echo "Remote plugin dir: $REMOTE_PLUGIN_DIR"

if [[ "$SKIP_MAINTENANCE" -eq 0 ]]; then
    echo "Enabling maintenance mode on ${PRIMARY_SERVER}..."
    run_remote "$PRIMARY_SERVER" \
        "$PHP_BIN '$REMOTE_MOODLE_DIR/admin/cli/maintenance.php' --enable"
    MAINTENANCE_ENABLED=1
fi

for host in $SERVERS; do
    echo "Backing up current plugin on ${host}..."
    run_remote "$host" \
        "mkdir -p '$BACKUP_DIR' && if [ -d '$REMOTE_PLUGIN_DIR' ]; then tar -czf '$BACKUP_DIR/${PLUGIN_NAME}-${TIMESTAMP}.tgz' -C '$REMOTE_MOODLE_DIR/local' '$PLUGIN_NAME'; fi"

    echo "Syncing plugin to ${host}..."
    run_rsync "$host" "$PLUGIN_DIR" "$REMOTE_PLUGIN_DIR"
done

if [[ "$SKIP_UPGRADE" -eq 0 ]]; then
    echo "Running Moodle upgrade on ${PRIMARY_SERVER}..."
    run_remote "$PRIMARY_SERVER" \
        "$PHP_BIN '$REMOTE_MOODLE_DIR/admin/cli/upgrade.php' --non-interactive"
fi

if [[ "$RUN_SEED" -eq 1 ]]; then
    echo "Running seed script on ${PRIMARY_SERVER}..."
    if [[ -n "$SEED_ARGS" ]]; then
        run_remote "$PRIMARY_SERVER" \
            "$PHP_BIN '$SEED_SCRIPT' $SEED_ARGS"
    else
        run_remote "$PRIMARY_SERVER" \
            "$PHP_BIN '$SEED_SCRIPT'"
    fi
fi

if [[ "$SKIP_PURGE" -eq 0 ]]; then
    for host in $SERVERS; do
        echo "Purging caches on ${host}..."
        run_remote "$host" \
            "$PHP_BIN '$REMOTE_MOODLE_DIR/admin/cli/purge_caches.php'"
    done
fi

if [[ "$SKIP_MAINTENANCE" -eq 0 ]]; then
    echo "Disabling maintenance mode on ${PRIMARY_SERVER}..."
    run_remote "$PRIMARY_SERVER" \
        "$PHP_BIN '$REMOTE_MOODLE_DIR/admin/cli/maintenance.php' --disable"
    MAINTENANCE_ENABLED=0
fi

echo "Deployment completed successfully."
