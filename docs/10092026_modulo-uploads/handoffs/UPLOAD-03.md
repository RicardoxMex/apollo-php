# Handoff — UPLOAD-03

## Output Contract

| Campo | Contenido |
|---|---|
| Status | COMPLETED |
| Summary | Reglas `file`, `mimes`, `image` en `RuleRegistry` + semántica de tamaño en KB para `min/max/between/size` cuando el valor es un archivo (array de `$_FILES` o `UploadedFile`). Backwards compatible. 11 tests nuevos + 32 de `ValidationTest` verdes. |
| Files Changed | `core/Validation/RuleRegistry.php`, `tests/Unit/FileValidationRulesTest.php` |
| Decisions | `mimes` valida extensión del nombre original (normalizada); `image` valida MIME (`image/*`); tamaño de archivo en KB como la convención de Laravel |
| Problems | Ninguno |
| Risks | `mimes` no sniffa contenido (eso lo hace el manager con finfo cuando está disponible) |
| Validation | `phpunit tests/Unit/FileValidationRulesTest.php tests/Unit/ValidationTest.php` (43 OK) |
| Next Steps | Consumido por UPLOAD-05 |

## Important Context

- El `$span` de tamaños ahora desvía archivos a `size/1024` antes de comparar; strings/arrays/números no cambian.