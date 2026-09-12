# Bitácora — Módulo Uploads

## 2026-09-10

- **12:00** — Orden recibida ("módulo o herramienta para subir archivos al server").
  Descubrimiento: `$_FILES` capturado pero sin accessors en `Request`; sin reglas de
  archivos en `RuleRegistry`; sin helpers binarios en `Response`; sin config de uploads.
- **12:01** — Spec (7 AC), design (D1–D8) y plan (M1 core, M2 app+docs) escritos.
  Estado registrado (session/plan/tasks/graph/gates). Gates G1 y G2 en `pass`.
- **12:05→12:30** — **UPLOAD-01** (core/Uploads): 35 tests verdes. Fix en ruta:
  comparación de paths con separadores mixtos de `realpath` en Windows.
- **12:35→12:45** — **UPLOAD-02** (accessors de Request): 10 tests verdes. Fix:
  estructura exótica `files[n][name]` fuera de alcance.
- **12:50→13:00** — **UPLOAD-03** (reglas file/mimes/image + KB): 43 tests verdes
  (11 nuevos + 32 de ValidationTest).
- **13:02→13:06** — **UPLOAD-04** (Response::download/file): 6 tests verdes.
- **13:12→13:35** — **UPLOAD-05** (app apps/Uploads + registro + env + storage):
  12 feature tests verdes. Suite completa 188 tests / 455 assertions, 4 skipped
  (SQLite). `apollo test` self-check OK (4 apps, 37 rutas); `route:list` muestra
  uploads.store/storeMultiple/show/destroy con auth; `test_middleware.php` OK.
  Fix: test de roundtrip usaba .txt fuera de la whitelist → pdf.
- **13:38** — Security review (G5): sin hallazgos bloqueantes. Traversal bloqueado
  (3 tests), mimes/tamaño doble validación, auth en todas las rutas, header
  injection neutralizada. Notas: fileinfo opcional (degradación documentada),
  sin rate limiting en uploads (gap del framework). `apollo.json` actualizado
  con `Uploads` para consistencia.
- **13:42→13:50** — **UPLOAD-06** (docs/uploads.md + índice). Revisión (G6)
  aprobada para las 6 tareas; gates G3–G7 en `pass`; checkpoints M1 y M2 `pass`.
- **13:55** — Plan completo. Estado final registrado (session, tasks, graph,
  gates, checkpoints, handoffs, metrics).
- **Post-cierre (mismo día)** — **Decisión: retirar la app `apps/Uploads`** (el
  usuario: "creo que la app de Upload sobra, ya es parte del core y puede
  integrarse en cualquier lugar"). Se elimina `apps/Uploads/` + feature tests de
  la app; `config/apps.php` y `apollo.json` vuelven a ApolloAuth/Users/Products;
  `docs/uploads.md` se reescribe: sección "Endpoints REST" → receta copiable
  (rutas + controlador para tu app), "Activación/desactivación" → "Integración
  en tu proyecto". El core (provider, config, reglas, Response, helper) queda
  intacto. Suite completa re-verificada en verde.