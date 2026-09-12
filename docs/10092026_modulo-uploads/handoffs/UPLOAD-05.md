# Handoff — UPLOAD-05

## Output Contract

| Campo | Contenido |
|---|---|
| Status | COMPLETED |
| Summary | App REST `apps/Uploads` registrada por defecto en `config/apps.php` (prefix `api/uploads`, grupo tras `auth`): POST `/` (single), POST `/multiple`, GET `/{path:.+}` (inline o `?download=1`), DELETE `/{path:.+}`. `UPLOADS_*` en `.env.example`, `storage/uploads` gitignored, `apollo.json` consistente. 12 feature tests verdes con auth de test sin DB. |
| Files Changed | `apps/Uploads/` (app.json, config, Routes, Controllers), `config/apps.php`, `.env.example`, `storage/uploads/.gitignore`, `.gitignore`, `apollo.json`, `tests/Feature/Uploads/UploadsApiTest.php` |
| Decisions | D6 validada: whitelist de app `jpg,jpeg,png,gif,webp,pdf,zip`; errores de validación en 422 (REST) |
| Problems | Ninguno |
| Risks | Sin rate limiting en uploads (gap del framework, no del módulo); sin fileinfo el MIME real se degrada (documentado) |
| Validation | `phpunit tests/Feature/Uploads` (12 OK) · suite completa 188 OK · `apollo test` self-check OK (37 rutas) · `route:list` muestra las 4 rutas |
| Next Steps | Consumido por UPLOAD-06 (docs) |

## Important Context

- Los feature tests rebinden `auth` a `TestAuthMiddleware` (401 sin token, pasa con cualquier Bearer) porque el AuthMiddleware real exige DB.
- El manager de uploads de tests apunta a un root temporal (no toca `storage/` real).