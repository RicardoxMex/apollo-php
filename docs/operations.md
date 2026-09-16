# Operaciones — API (Sport Tournament Pro)

> Guía mínima de operación: monitoreo, logs, backups y respuesta a incidentes.
> Complementa `docs/deploy.md` (runbook de despliegue). El deploy real está
> pendiente de la decisión del usuario (`OQ-DEPLOY`).

## 1. Monitoreo

### Health endpoint

`GET /api/health` — público, sin auth, sin detalles sensibles:

```bash
curl -fsS https://api.tudominio.com/api/health
# 200 {"status":"ok","db":true,"time":"2026-09-15T12:00:00+00:00"}
# 503 {"status":"degraded","db":false,"time":"..."}   ← app responde, BD caída
```

- `api` en docker-compose ya tiene healthcheck contra este endpoint
  (`unhealthy` tras 5 fallos). Comprobar: `docker compose ps`.
- **Uptime externo recomendado** (decisión pendiente del proveedor): HTTP monitor
  cada 1-5 min contra `/api/health`, alerta por email al equipo. Un monitor con
  `200` obligatorio detecta tanto caídas de la app como de la BD.

### Logs

| Qué | Dónde |
|---|---|
| API (stdout/stderr del servidor embebido) | `docker compose logs -f api` (`--since 1h`, `--tail 200`) |
| Emails transaccionales (driver `log`) | `runtime/logs/mail/*.html` — host: `<repo>/runtime/logs/mail`; contenedor: `docker compose exec api ls runtime/logs/mail` |
| Workerman / WebSockets | `runtime/workerman.log` (host/contenedor) + stdout del servicio realtime; estado: `php apollo realtime:status`; ver `docs/websockets.md` |
| MySQL | `docker compose logs -f mysql` |
| Backups | `/var/log/stp-backup.log` (cron) |

Señales típicas:

- **503 persistente**: MySQL parado, credenciales/`.env` incorrectos o DB llena.
  `docker compose ps`, `docker compose logs mysql`, `docker compose exec api php -r "…"` (o restaurar).
- **500 repetidos**: `docker compose logs api`; suele ser configuración faltante
  (`JWT_SECRET_KEY`, `MAIL_*`) o migraciones pendientes (`php apollo migrate:status`).
- **Disco lleno**: dumps viejos, logs de mail o `runtime` creciendo; ajustar retención.

## 2. Backups

Script: `scripts/backup-db.sh` (POSIX sh). Hace `mysqldump --single-transaction`
(consistente en caliente) → `gzip -9` → verificaciones (`gzip -t`, tamaño) →
retención por días → lista final. La contraseña viaja en `MYSQL_PWD` (no en `ps`).

```bash
# Manual (lee DB_* del .env si no están exportadas)
chmod +x scripts/backup-db.sh   # una sola vez tras el clone (git no conserva el bit en Windows)
BACKUP_DIR=/var/backups/stp BACKUP_RETENTION_DAYS=14 ./scripts/backup-db.sh
```

Cron recomendado (diario 03:15, log dedicado):

```cron
15 3 * * * cd /opt/sport-tournament/sport-tournament-backend && ./scripts/backup-db.sh >> /var/log/stp-backup.log 2>&1
```

Requisitos: `mysqldump` y `gzip` en el host (no están en la imagen de la API).
Si MySQL corre en docker-compose sin puerto público, exportar
`DB_HOST=127.0.0.1 DB_PORT=<puerto>` con el puerto publicado en el host, o ejecutar
el backup desde dentro de la red (ver comentarios del script para la restauración
con `docker compose exec -T mysql`).

### Restauración (resumen; detalle en el script)

1. Verificar integridad: `gzip -t backups/db-<db>-<stamp>.sql.gz`.
2. Restaurar (¡destructivo sobre la BD destino!):
   `gunzip -c <archivo>.sql.gz | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_DATABASE"`
   o con compose:
   `gunzip -c <archivo>.sql.gz | docker compose exec -T mysql mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"`.
3. Aplicar migraciones pendientes si el backup es anterior: `docker compose run --rm api php apollo migrate`.
4. Verificar: `curl -fsS https://api.tudominio.com/api/health` + login/explore.

Política sugerida: retención 14 días en el VPS + **copia off-site semanal** (rclone/S3)
y una **prueba de restauración mensual** en un entorno de staging (sin esto, un
backup no cuenta como verificado).

## 3. Checklist de incidentes

1. **Detectar**: alerta de uptime o reporte de usuario.
2. **Confirmar alcance**: `curl -fsS .../api/health` (200/503), `docker compose ps`.
3. **Diagnosticar**: `docker compose logs --since 30m api` y `mysql`;
   ¿cambió algo? último deploy, migración, `.env`, disco (`df -h`).
4. **Mitigar** (primero restaurar servicio, luego investigar):
   - API caída → `docker compose restart api`.
   - MySQL caído → `docker compose start mysql` (esperar healthy).
   - Datos corruptos → restaurar backup (§2) y avisar del punto de pérdida.
   - Secreto comprometido → rotar `JWT_SECRET_KEY`/`DB_PASSWORD`/`MAIL_PASSWORD`,
     `docker compose up -d --force-recreate api`.
5. **Comunicar**: estado a usuarios/organizadores si hay impacto en torneos en curso.
6. **Cerrar**: postmortem breve (causa, impacto, mitigación, acción preventiva) y
   registrar en la bitácora del proyecto.

## 4. Referencias

- `docs/deploy.md` — build, `.env`, migraciones, vercel/docker del front, rollback.
- `docs/mail.md` — configuración SMTP/log y plantillas.
- `docs/websockets.md` — servicio realtime (Workerman).
- `docs/migrations.md` — migraciones y comandos `apollo`.
