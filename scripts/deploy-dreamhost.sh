#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${1:-$ROOT_DIR/deploy.env}"
IGNORE_FILE="$ROOT_DIR/.rsyncignore"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Missing deploy env file: $ENV_FILE"
    echo "Copy deploy.env.example to deploy.env and fill in your DreamHost SSH values."
    exit 1
fi

if [[ ! -f "$IGNORE_FILE" ]]; then
    echo "Missing rsync ignore file: $IGNORE_FILE"
    exit 1
fi

set -a
source "$ENV_FILE"
set +a

required_vars=(
    DREAMHOST_SSH_HOST
    DREAMHOST_SSH_USER
    DREAMHOST_REMOTE_PATH
)

for var_name in "${required_vars[@]}"; do
    if [[ -z "${!var_name:-}" ]]; then
        echo "Missing required setting: $var_name"
        exit 1
    fi
done

ssh_port="${DREAMHOST_SSH_PORT:-22}"
remote_target="${DREAMHOST_SSH_USER}@${DREAMHOST_SSH_HOST}"
delete_flag=()

if [[ "${RSYNC_DELETE:-0}" == "1" ]]; then
    delete_flag=(--delete)
fi

echo "Ensuring remote directory exists: ${DREAMHOST_REMOTE_PATH}"
ssh -p "$ssh_port" "$remote_target" "mkdir -p '$DREAMHOST_REMOTE_PATH'"

echo "Syncing project to ${remote_target}:${DREAMHOST_REMOTE_PATH}"
rsync \
    -az \
    --human-readable \
    --itemize-changes \
    ${delete_flag[@]+"${delete_flag[@]}"} \
    --exclude-from="$IGNORE_FILE" \
    -e "ssh -p $ssh_port" \
    "$ROOT_DIR/" \
    "${remote_target}:${DREAMHOST_REMOTE_PATH}/"

echo "Deploy complete."
