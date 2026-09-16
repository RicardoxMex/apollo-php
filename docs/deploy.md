# Deploy — API y frontend (runbook)

> **Estado: preparado, NO ejecutado.** Este runbook es reproducible pero permanece
> a la espera de la decisión del usuario (`OQ-DEPLOY`): target/proveedor, dominio,
> credenciales y presupuesto. El deploy real es una operación con gate humano
> (producción + credenciales) y una tarea aparte (`OPS-03`).
>
> Relacionados: `docs/operations.md` (monitoreo/backups) · spec Fase 1 (REQ-10/REQ-11,
> D-F1-6) · `docker-compose.yml` · `.env.production.example`.

## 0. Decisiones pendientes (bloquean OPS-03)

| Tema | Decisión necesaria |
|---|---|
| VPS | proveedor, región, tamaño (mínimo sugerido 2 vCPU / 2-4 GB / 40 GB SSD) |
| Dominio | dominio raíz y subdominios (`api.<dominio>`, `www.<dominio>`) + DNS |
| Frontend | Vercel (recomendado) **o** Docker en el mismo VPS |
| Email | dominio verificado en Resend (SPF/DKIM) + API key |
| Monitoreo | proveedor de uptime externo (UptimeRobot, BetterStack, …) |
| Backups | retención y copia off-site (S3/Backblaze/rclone) |

Sin estos datos no se ejecuta nada de este runbook (no se inventan valores).

## 1. Arquitectura objetivo (candidata, D-F1-6)

```text
Usuarios ── HTTPS ──► Frontend (Vercel)  ──►  https://api.<dominio>
                        │                        │  reverse proxy TLS (Caddy/Nginx)
                        └── wss (si realtime)    ▼
                                          docker compose: api (PHP 8.3) + mysql:8
                                          volúmenes: db_data / uploads / runtime
                                          cron host: backup-db.sh + monitor uptime /api/health
```

- La API se sirve con el **servidor embebido de PHP** (`php -S`, ver `Dockerfile`):
  suficiente para el MVP (mono-instancia, tráfico bajo). Para más carga, migrar a
  `php-fpm + nginx` sin cambiar la app (mismo `public/index.php`).
- El servicio **realtime (Workerman)** es opcional; si se activa, se despliega como
  un servicio adicional (ver `docs/websockets.md`) y se define `WEBSOCKET_APP_SECRET`.

## 2. Requisitos previos

- VPS con Docker Engine + plugin Compose (`docker compose version`).
- Dominio con registro DNS `A` apuntando al VPS (p. ej. `api.tudominio.com`).
- Cuenta Resend con dominio verificado y API key.
- Repo clonado en el VPS (rama `sport-tournament` o la de release) y acceso a `.env`.
- Puertos 80/443 abiertos; 8000 y 3306 cerrados al exterior (solo reverse proxy/red Docker).

## 3. Secretos: checklist y creación del `.env`

```bash
cd sport-tournament-backend
cp .env.production.example .env
chmod 600 .env
# editar y completar TODOS los placeholders antes de continuar
docker compose config --quiet   # valida sintaxis e interpolación (no imprime valores)
```

| Variable | Qué es | Cómo se obtiene / rota |
|---|---|---|
| `APP_KEY` | clave de la app | `php -r "echo bin2hex(random_bytes(32));"` |
| `JWT_SECRET_KEY` | firma de JWT (obligatoria) | idem; **rotarla invalida sesiones** (aceptable en incidente) |
| `DB_PASSWORD` | contraseña del usuario de la app | gestor de secretos; coincide con `MYSQL_PASSWORD` |
| `DB_ROOT_PASSWORD` | root de MySQL (solo compose) | gestor de secretos; nunca se usa desde la app |
| `MAIL_PASSWORD` | API key de Resend | panel de Resend; rotar si se filtra |
| `WEBSOCKET_APP_SECRET` | canales privados (si realtime) | idem `php -r …` |

Reglas: el `.env` real nunca se commitea (ya está gitignored), nunca se copia al
repositorio ni a la imagen (`.dockerignore`), y los backups del `.env` viven cifrados
fuera del VPS si se guardan.

## 4. Backend: build + arranque

```bash
docker compose build                 # imagen api (PHP 8.3 + extensiones, ver Dockerfile)
docker compose up -d                 # api + mysql:8 (healthchecks + volúmenes nombrados)
docker compose ps                    # esperar: api "healthy", mysql "healthy"
curl -fsS http://127.0.0.1:8000/api/health
# → {"status":"ok","db":true,"time":"..."}   (503 "degraded" = app ok, BD caída)
```

Notas:
- `docker compose` fuerza `DB_HOST=mysql` dentro de la red; en el `.env` del VPS
  puede quedar `mysql` (también válido fuera de compose si se cambia).
- El healthcheck del contenedor `api` usa `/api/health`; si queda `unhealthy`,
  `docker compose logs api` muestra la causa (BD, `.env` incompleto, puerto ocupado).

## 5. Migraciones y seed (primera puesta en marcha)

```bash
# BD vacía (primer deploy): recrea el esquema completo + seed opcional
docker compose run --rm api php setup_database.php
docker compose run --rm api php run_seeders.php     # opcional: datos demo

# Deploys posteriores (sin borrar datos): solo pendientes
docker compose run --rm api php apollo migrate
docker compose run --rm api php apollo migrate:status
```

> **Nunca** ejecutar `setup_database.php` sobre una base con datos reales: hace
> `DROP TABLE` de todas las tablas antes de recrearlas. Para actualizaciones usar
> `php apollo migrate` (no destructivo) y `migrate:rollback` para revertir.

## 6. Frontend

### Opción A — Vercel (recomendada)

1. Importar el repo `sport-tournament-front` en Vercel (framework Next.js detectado).
2. Variables de entorno del proyecto (Production):
   - `NEXT_PUBLIC_API_URL=https://api.tudominio.com`
   - `NEXT_PUBLIC_WS_URL=wss://api.tudominio.com/ws` (solo si realtime activo)
3. Deploy. Vercel construye y publica; `output: "standalone"` del `next.config.ts`
   no afecta al builder de Vercel (se ignora).
4. Asignar el dominio del front en el panel (TLS automático).

### Opción B — Docker en el VPS

```bash
cd sport-tournament-front
docker build \
  --build-arg NEXT_PUBLIC_API_URL=https://api.tudominio.com \
  --build-arg NEXT_PUBLIC_WS_URL=wss://api.tudominio.com/ws \
  -t sport-tournament/front:local .
docker run -d --name stp-front --restart unless-stopped -p 3000:3000 sport-tournament/front:local
```

> Las `NEXT_PUBLIC_*` se **hornean en build time**: cualquier cambio de URL exige
> reconstruir la imagen (o redeploy en Vercel). El contenedor usa la salida
> `standalone` (`node server.js`), no `next start`.

## 7. Reverse proxy + TLS (recomendado)

Con el puerto 8000 solo accesible en localhost, un proxy termina TLS. Ejemplo con
Caddy 2 (`/etc/caddy/Caddyfile`):

```caddyfile
api.tudominio.com {
    reverse_proxy 127.0.0.1:8000
}
```

Si el front también va en Docker, añadir su propio bloque `tudominio.com { reverse_proxy 127.0.0.1:3000 }`.
Firewall: `ufw allow 80,443/tcp`; denegar 8000 y 3306 desde fuera.

## 8. Verificación post-deploy (AC-06)

- [ ] `curl -fsS https://api.tudominio.com/api/health` → 200 `status: ok`.
- [ ] Registro de usuario + verificación por email (llega a bandeja real, no spam).
- [ ] Login, explorar torneos, inscribirse; subir imagen de equipo (uploads).
- [ ] `docker compose ps` → api/mysql healthy; `docker compose logs api` sin errores.
- [ ] Cron de backup instalado (`crontab -l`) y primer backup generado + verificado.
- [ ] Monitor de uptime externo apuntando a `/api/health` con alerta por email.
- [ ] Rollback probado mentalmente: tag/imagen anterior + procedimiento de restauración.

## 9. Rollback

| Capa | Acción |
|---|---|
| API | `docker compose down` → checkout de la versión anterior (tag/commit) → `docker compose up -d --build` |
| BD | restaurar el último backup verificado (`docs/operations.md` §2); si el esquema es anterior, `php apollo migrate` |
| Front (Vercel) | "Promote" del deployment anterior en el panel |
| Front (Docker) | `docker run` con la imagen anterior (`sport-tournament/front:<tag>`); mantener tags por release |

Criterio de rollback: 5xx sostenidos, datos corruptos o error de migración
irreversible → **rollback primero, investigar después**. Registrar el incidente en
`docs/operations.md` §3.

## 10. Lo que queda fuera de este runbook

- Ejecución real del deploy (OPS-03): requiere los datos de la sección 0.
- Provisioning del VPS/DNS/certificados (depende del proveedor elegido).
- Copia off-site de backups y rotación de secretos automatizada (decidir política).
