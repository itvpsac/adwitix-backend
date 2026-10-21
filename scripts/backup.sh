#!/bin/bash
#
# backup.sh -- D-104: real database (and media) backup for a production
# deployment. Nothing in this repo ran this automatically before -- no
# backup strategy existed anywhere in the docs. This is meant to be
# dropped onto the real i2k2 server and run from cron; it does nothing
# useful run from this sandbox, since it needs the real production
# .env and a persistent BACKUP_DIR outside the app's own directory.
#
# What it does:
#   1. Reads DB connection info out of the app's own .env (one source
#      of truth for credentials -- never hardcode them here).
#   2. Runs `mysqldump`, gzip-compressed, timestamped.
#   3. Optionally tars up the user-file storage (storage.root in .env:
#      listing photos/videos/documents, tenant logos and the KYC vault;
#      public/uploads/ when storage.root is unset).
#   4. Deletes backups older than RETENTION_DAYS (default 14).
#   5. Exits non-zero on any real failure, so cron's own mail-on-error
#      (or a monitoring wrapper) actually catches a broken backup
#      instead of silently producing an empty/missing file.
#
# Usage (on the Hostinger server this runs nightly and before every
# deploy's migrations -- see .github/workflows/backup.yml and deploy.yml):
#   BACKUP_DIR=/path/outside/webroot ./scripts/backup.sh
#   SKIP_MEDIA=1 BACKUP_LABEL=predeploy ...   database only
#
# Restore (on the server, from BASE/adwitix-backups):
#   files:    tar -xzf adwitix-files-<label>-<time>.tar.gz -C <dir>, then
#             copy what is needed into storage (storage.root in .env)
#   database: gunzip -c adwitix-db-<label>-<time>.sql.gz | mysql -u <user> -p <db>
#
# Required: mysqldump on PATH (same major version family as the
# server, or newer -- installed alongside the `mysql-server` package
# in README.md Step 4, or via `mysql-client`/`default-mysql-client` if
# the app server and DB server are split). Does NOT require sudo/root
# -- runs as the app's own `ebidhub_app` user, same credentials the
# app itself uses, which already has SELECT on everything it needs.

set -euo pipefail
# Backups hold the whole database and KYC documents: owner-only.
umask 077

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$APP_ROOT/.env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/ebidhub}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SKIP_MEDIA="${SKIP_MEDIA:-0}"
BACKUP_LABEL="${BACKUP_LABEL:-nightly}"

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }
die() { log "ERROR: $*"; exit 1; }

[[ -f "$ENV_FILE" ]] || die "No .env at $ENV_FILE -- can't read DB credentials. Set ENV_FILE if it's elsewhere."

# Pull the four DB settings straight out of .env rather than duplicating
# them here -- if the real credentials ever rotate, this script picks
# them up automatically with zero edits.
env_get() {
    local key="$1"
    # `|| true`: a missing optional key (port, storage.root) is empty,
    # not a silent exit under `set -e`.
    { grep -E "^${key}[[:space:]]*=" "$ENV_FILE" || true; } | tail -1 | sed -E "s/^${key}[[:space:]]*=[[:space:]]*//; s/^'(.*)'$/\1/; s/^\"(.*)\"$/\1/"
}

DB_HOST="$(env_get 'database\.default\.hostname')"
DB_NAME="$(env_get 'database\.default\.database')"
DB_USER="$(env_get 'database\.default\.username')"
DB_PASS="$(env_get 'database\.default\.password')"
DB_PORT="$(env_get 'database\.default\.port')"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"

[[ -n "$DB_NAME" && -n "$DB_USER" ]] || die "Could not read database.default.database/username from $ENV_FILE"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

DB_DUMP_FILE="$BACKUP_DIR/adwitix-db-${BACKUP_LABEL}-${TIMESTAMP}.sql.gz"
log "Backing up database '$DB_NAME' on $DB_HOST:$DB_PORT to $DB_DUMP_FILE"

# Password via the environment, not the command line, where any other
# process on a shared host could read it.
MYSQL_PWD="$DB_PASS" mysqldump \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    --single-transaction --routines --triggers --no-tablespaces "$DB_NAME" \
    | gzip > "$DB_DUMP_FILE"

[[ -s "$DB_DUMP_FILE" ]] || die "Dump produced an empty file -- treating as a failed backup, not a silent no-op."
log "Database backup OK ($(du -h "$DB_DUMP_FILE" | cut -f1))"

STORAGE_ROOT="$(env_get 'storage\.root')"
if [[ -n "$STORAGE_ROOT" ]]; then
    MEDIA_PARENT="$(dirname "$STORAGE_ROOT")"; MEDIA_NAME="$(basename "$STORAGE_ROOT")"
else
    MEDIA_PARENT="$APP_ROOT/public"; MEDIA_NAME="uploads"
fi
if [[ "$SKIP_MEDIA" != "1" && -d "$MEDIA_PARENT/$MEDIA_NAME" ]]; then
    MEDIA_ARCHIVE="$BACKUP_DIR/adwitix-files-${BACKUP_LABEL}-${TIMESTAMP}.tar.gz"
    log "Archiving $MEDIA_PARENT/$MEDIA_NAME (photos, logos, KYC vault) to $MEDIA_ARCHIVE"
    tar -czf "$MEDIA_ARCHIVE" -C "$MEDIA_PARENT" "$MEDIA_NAME"
    log "File backup OK ($(du -h "$MEDIA_ARCHIVE" | cut -f1), $(tar -tzf "$MEDIA_ARCHIVE" | grep -vc '/$') files)"
fi

log "Pruning backups older than $RETENTION_DAYS day(s) in $BACKUP_DIR"
find "$BACKUP_DIR" -maxdepth 1 -name 'adwitix-*.gz' -mtime "+$RETENTION_DAYS" -print -delete

log "Backup complete."
