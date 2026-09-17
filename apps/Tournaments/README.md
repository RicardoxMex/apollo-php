# Tournaments

App modular del dominio de torneos para **Apollo Framework** (`Apps\Tournaments`).

## Endpoints (prefix `api`)

### Públicos (explore + detalle)
| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/tournaments` | Listado con filtros: `q`, `sport`, `status`, `visibility`, `organizer_id` + `page`/`perPage` |
| GET | `/api/tournaments/{id}` | Detalle con `prizes`, `stats`, `aceptados`, `tiene_draw` |
| GET | `/api/tournaments/{id}/participants` | Participantes resueltos (equipo/jugador + `seed`) |
| GET | `/api/tournaments/{id}/draws` | Sorteo activo: `rounds[].matches[]` (bracket) o `groups[]` |
| GET | `/api/tournaments/{id}/matches` | Partidos oficiales con `scores` y nombres resueltos |
| GET | `/api/uploads/{path}` | Servir archivos subidos (imágenes de equipos; path seguro, 404 si no existe) |

### Con auth (JWT)
| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/tournaments` | Crear (borrador) con `prizes[]` y `stats[]` |
| PUT | `/api/tournaments/{id}` | Editar aplicando reglas por estado (open bloquea sport/formato/cupo; live/finished nada) |
| DELETE | `/api/tournaments/{id}` | Eliminar (soft delete, solo organizador) |
| POST | `/api/tournaments/{id}/publish` | draft→open (público sin mínimo; privado ≥2 aceptados) |
| POST | `/api/tournaments/{id}/start` | open→live (requiere sorteo) |
| POST | `/api/tournaments/{id}/finish` | live→finished (requiere final con ganador) |
| POST | `/api/tournaments/{id}/pause` / `resume` | open↔paused (pausar/reanudar inscripciones) |
| POST | `/api/tournaments/{id}/unpublish` | open→draft (volver a borrador para reeditar) |
| POST | `/api/tournaments/{id}/draw` | Generar sorteo: `{"type":"bracket"}` (o `groups` con `num_groups`); crea partidos oficiales 1:1 y avances |
| POST | `/api/tournaments/{id}/draw/participants` | Añadir participantes a un sorteo existente sin regenerarlo: brackets rellenan byes; grupos suman al grupo más pequeño y crean solo sus partidos (`{"participant_ids":[N]}`) |
| GET | `/api/tournaments/{id}/registrations` | Solicitudes (solo organizador, filtro `status`) |
| POST | `/api/tournaments/{id}/registrations` | Aplicar: `{"team_id":N}` o `{"player_id":N}` (organizador puede inscribir directo en draft) |
| POST | `/api/tournaments/{id}/registrations/{rid}/decide` | Moderación: `{"action":"accepted"}` (crea participante con seed) o `"rejected"` |
| POST | `/api/tournaments/{id}/registrations/{rid}/cancel` | Cancelar la propia solicitud pendiente |
| PUT | `/api/tournaments/{id}/matches/{mid}` | Resultado: `status`, `scores[]`, `player_stats[]`, `winner_participant_id`; propaga al bracket y avanza al ganador |
| GET/POST | `/api/teams`, `/api/teams/{id}`… | CRUD de equipos (players[] con dorsal; capitanes; soft delete) |
| GET/POST | `/api/players`, `/api/players/{id}`… | CRUD de jugadores |
| GET/POST | `/api/seasons`, `/api/seasons/{id}`… | CRUD de temporadas |
| GET | `/api/audit-logs` | Auditoría (filtros `entity_type`/`entity_id`) |
| POST | `/api/uploads` | Subir imagen con el servicio nativo de Uploads (`multipart/form-data`, campo `file`); devuelve `{ url, path, name, size, mime }` (límites de `config/uploads.php`) |

## Convenciones

- **ENUMs de la API ↔ BD**: la API habla los valores del frontend
  (`eliminacion-directa`, `grupos`, `publico`, `privado`); el `Mapeos` traduce a
  `single_elimination`, `groups`, `public`, `private`.
- **Fuente única de resultados**: `matches` es la oficial; `draw_matches` (bracket)
  recibe propagación de estado/ganador y avances al completar.
- **Errores**: `400` validación, `403` no organizador, `404` no encontrado,
  `409` regla de negocio (estado/cupo/duplicado).
- **Auditoría**: toda escritura registra `audit_logs` (actor, entidad, acción, antes/después, IP/UA).

## Estructura

```
Controllers/   → HTTP (try/catch → Response::json)
Services/      → lógica de negocio + reglas puras (ReglasTorneo, BracketGenerator, Mapeos)
Repositories/  → acceso a datos (QueryBuilder)
Models/        → modelos por entidad
```

## Tests

- `tests/Unit/Tournaments/*` — reglas puras (ciclo, campos editables, solicitudes,
  brackets con byes, mapeos).
- `tests/Feature/TournamentsSqliteFlowTest.php` — flujo completo sobre SQLite `:memory:`
  (inscripciones → sorteo → partidos con avances → finish).