#!/bin/sh
# NFR — dump MySQL harian, retensi 30 hari.
# Pasang di crontab host:  0 2 * * *  sh /path/ke/docker/backup.sh
set -eu

DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE="${DIR}/../docker-compose.yml"
STAMP="$(date +%Y-%m-%d)"

docker compose -f "${COMPOSE}" exec -T mysql sh -c \
    "mysqldump --single-transaction --routines --quick -u root -p\"\${MYSQL_ROOT_PASSWORD}\" \"\${MYSQL_DATABASE}\" | gzip > /backup/lemburku-${STAMP}.sql.gz"

# Buang dump yang lebih tua dari 30 hari.
docker compose -f "${COMPOSE}" exec -T mysql \
    find /backup -name 'lemburku-*.sql.gz' -mtime +30 -delete

echo "Backup selesai: lemburku-${STAMP}.sql.gz"
