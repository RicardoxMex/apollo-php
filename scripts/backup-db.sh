#!/bin/sh
# ─────────────────────────────────────────────────────────────────────
# backup-db.sh — Backup de MySQL con retención (Sport Tournament Pro / Apollo)
#
# Uso:
#   ./scripts/backup-db.sh
#   BACKUP_DIR=/var/backups/stp BACKUP_RETENTION_DAYS=30 ./scripts/backup-db.sh
#
# Variables (las DB_* se leen de .env si existe y no están exportadas):
#   DB_HOST                (def: 127.0.0.1)   p. ej. mysql dentro de compose
#   DB_PORT                (def: 3306)
#   DB_DATABASE            (obligatoria)
#   DB_USERNAME            (obligatoria)
#   DB_PASSWORD            (def: vacío)
#   BACKUP_DIR             (def: ./backups)
#   BACKUP_RETENTION_DAYS  (def: 14; 0 = sin retención)
#
# Cron de ejemplo (diario 03:15, con log):
#   15 3 * * * cd /opt/sport-tournament && ./scripts/backup-db.sh >> /var/log/stp-backup.log 2>&1
#
# Notas:
#   · Requiere `mysqldump` y `gzip` en el host (no vienen en la imagen de la API).
#   · El dump es consistente en caliente (--single-transaction) y se comprime.
#   · MYSQL_PWD evita exponer la contraseña en la lista de procesos (ps).
#   · Si el .env tiene finales de línea CRLF, normalízalo (dos2unix) antes.
#
# ── Restauración ────────────────────────────────────────────────────
#   1) Verificar integridad:   gzip -t backups/db-<db>-<stamp>.sql.gz
#   2) (Opcional) inspección:  gunzip -c <archivo>.sql.gz | less
#   3) Restaurar (¡DESTRUCTIVO sobre la BD destino!):
#        mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p "$DB_DATABASE" \
#          < <(gunzip -c <archivo>.sql.gz)          # requiere bash
#      o, sin proceso en sustitución:
#        gunzip -c <archivo>.sql.gz | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p "$DB_DATABASE"
#      o, con MySQL en docker compose (desde el directorio del compose):
#        gunzip -c <archivo>.sql.gz | docker compose exec -T mysql \
#          mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
#   4) Tras restaurar, comprobar:  curl -fsS https://api.<dominio>/api/health
#      y los flujos críticos (login, explore, inscripción).
#   5) Si el backup es de una versión anterior del esquema, aplicar migraciones:
#        docker compose run --rm api php apollo migrate
# ─────────────────────────────────────────────────────────────────────
set -eu

# .env local como fallback (solo si no hay DB_* exportadas).
if [ -z "${DB_DATABASE:-}" ] && [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:?DB_DATABASE no definido (exporta las DB_* o crea un .env)}"
DB_USERNAME="${DB_USERNAME:?DB_USERNAME no definido}"
DB_PASSWORD="${DB_PASSWORD:-}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

command -v mysqldump >/dev/null 2>&1 || { echo "ERROR: falta mysqldump en el PATH" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "ERROR: falta gzip en el PATH" >&2; exit 1; }

mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$BACKUP_DIR/db-$DB_DATABASE-$STAMP.sql.gz"
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')]"

echo "$LOG_PREFIX Backup de '$DB_DATABASE' ($DB_HOST:$DB_PORT) → $FILE"

# Dump consistente en caliente + compresión.
# MYSQL_PWD como variable de entorno para no filtrar el secreto en `ps`.
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -9 > "$FILE"

# Verificación: gzip íntegro y archivo no vacío.
if [ ! -s "$FILE" ] || ! gzip -t "$FILE" 2>/dev/null; then
    echo "$LOG_PREFIX ERROR: backup inválido o vacío ($FILE); se elimina" >&2
    rm -f "$FILE"
    exit 1
fi

echo "$LOG_PREFIX OK ($(wc -c < "$FILE") bytes)"

# Retención: borra backups más antiguos que N días (0 = sin retención).
case "$BACKUP_RETENTION_DAYS" in
    ''|*[!0-9]*) echo "$LOG_PREFIX AVISO: BACKUP_RETENTION_DAYS='$BACKUP_RETENTION_DAYS' no numérico; sin retención" >&2 ;;
    0) : ;;
    *)
        find "$BACKUP_DIR" -type f -name 'db-*.sql.gz' -mtime "+$BACKUP_RETENTION_DAYS" -print |
        while IFS= read -r old; do
            echo "$LOG_PREFIX Retención: borrando $old"
            rm -f -- "$old"
        done
        ;;
esac

echo "$LOG_PREFIX Backups actuales en $BACKUP_DIR:"
ls -l "$BACKUP_DIR" | grep 'db-.*\.sql\.gz' || echo "  (ninguno)"
