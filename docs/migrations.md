# Migraciones — Crear, aplicar y deshacer

Las migraciones son archivos PHP en `database/migrations/*.php` que definen
`up()` (crear/alterar esquema) y `down()` (revertir). Cada archivo devuelve
una clase anónima que extiende `Apollo\Core\Database\Migration`:

```php
<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

## Comandos

| Comando | Qué hace | Destructivo |
|---|---|---|
| `php apollo migrate` | Aplica **solo las pendientes** en un batch nuevo | No |
| `php apollo migrate:rollback` | Deshace el **último batch** (`down()` + limpieza del tracking) | Sí (solo ese batch) |
| `php apollo migrate:reset` | Deshace **todos** los batches en orden inverso | Sí (todo el esquema) |
| `php apollo migrate:status` | Tabla con aplicadas vs pendientes | No |
| `php apollo db:setup` | Drop de todas las tablas + correr todas (migrate:fresh) | Sí (todo) |
| `php apollo db:refresh` | `db:setup` + `db:seed` (migrate:fresh --seed) | Sí (todo) |

## Tracking por batch

`migrate` registra cada migración ejecutada en la tabla `migrations`
(`migration`, `batch`, `executed_at`), creada bajo demanda. Las ejecuciones de
`migrate` agrupan sus migraciones en un **batch** (el máximo existente + 1), y
`migrate:rollback` deshace el último batch completo:

```bash
php apollo migrate            # aplica 001..009 en batch 1
# ...creas 010_create_x_table.php...
php apollo migrate            # aplica SOLO 010 en batch 2
php apollo migrate:rollback   # deshace batch 2 (010); batch 1 intacto
php apollo migrate:reset      # deshace batch 1 (009..001)
```

Si un `down()` falla, el comando aborta y **no** borra el registro: la
migración queda marcada como aplicada hasta que corrijas y reintentes.

> `db:setup` y el script dev `setup_database.php` también registran sus
> migraciones (en batch 1), así que `migrate:status` refleja siempre el
> estado real de la base de datos activa.

## Generar una migración

```bash
php apollo make:migration create_products_table
```

Crea el archivo numerado en `database/migrations/` (sigue el patrón
`<NNN>_<nombre>.php`; el orden de los archivos es el orden de aplicación).