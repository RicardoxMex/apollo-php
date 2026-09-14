# Apollo Framework - Documentación

Bienvenido a la documentación oficial de Apollo Framework, un mini-framework PHP inspirado en Django REST Framework.

## Índice de Documentación

### Empezar
- [**README (raíz: qué es, instalación, comandos, arquitectura)**](../README.md) - Guía completa de inicio

### Guías
- [**Comandos CLI Personalizados**](cli-commands.md) - Tutorial completo para crear comandos CLI personalizados
- [**Estructura de Apps**](app-structure.md) - Cómo se compone una app y su `app.json`
- [**Sistema de Autenticación**](authentication-system.md) - Auth JWT, roles y permisos
- [**Validación de Datos**](validation.md) - Motor de validación del core (`core/Validation`)
- [**WebSockets (Workerman)**](websockets.md) - Notificaciones en tiempo real, autenticación JWT, SDK JS y guía de integración REST (en el **core** del framework)
- [**Módulo Realtime**](realtime.md) - Resumen del módulo de tiempo real: EventBus, canales, NotificationService (en el **core**)
- [**Módulo Uploads**](uploads.md) - Subida y descarga de archivos (opcional)
- [**Módulo Mail**](mail.md) - Envío de correos: SMTP/log, plantillas y verificación de email (opcional)
- [**Migraciones**](migrations.md) - Crear, aplicar y deshacer migraciones (`migrate`, `migrate:rollback`, `migrate:reset`, `migrate:status`)

### Próximamente
- Creación de APIs REST (paso a paso)
- Sistema de Middleware (guía propia)
- Manejo de Rutas (guía propia)
- Contenedor de Dependencias (guía propia)
- Testing (guía propia)

## Comandos CLI Disponibles

Apollo Framework incluye un potente sistema CLI similar a Laravel Artisan:

```bash
# Crear una nueva app modular (scaffold completo: app.json, controlador, rutas, provider...)
php apollo make:app Blog

# Listar todas las rutas
php apollo route:list

# Crear un nuevo controlador
php apollo make:controller ProductController --app=products

# Crear un nuevo middleware
php apollo make:middleware ValidationMiddleware --app=users

# Crear un nuevo modelo
php apollo make:model Product --app=products

# Crear una migración
php apollo make:migration create_products_table

# Crear un seeder
php apollo make:seeder ProductSeeder

# Crear servicio / repositorio en una app
php apollo make:service ProductService --app=products
php apollo make:repository ProductRepository --app=products

# Módulo realtime (opcional)
php apollo realtime:test       # health check (PHP, Workerman, Redis, BD, secret)
php apollo realtime:start      # servidor WebSocket (Workerman; sin extensión nativa)
# o: composer websocket

# Generar reporte del sistema
php apollo system:report

# Base de datos
php apollo db:setup        # drop de todas las tablas + correr migraciones (migrate:fresh)
php apollo db:seed         # correr todos los seeders de database/seeds
php apollo db:refresh      # db:setup + db:seed (migrate:fresh --seed)

# Migraciones (tracking por batch en la tabla `migrations`)
php apollo migrate              # aplicar SOLO las pendientes (no destructivo)
php apollo migrate:rollback     # deshacer el último batch (down())
php apollo migrate:reset        # deshacer todos los batches (down())
php apollo migrate:status       # lista aplicadas vs pendientes

# Ejecutar self-check del framework (sin DB)
php apollo test

# Smoke test de middlewares (sin DB)
php apollo test:middleware

# Ver ayuda
php apollo help
```

## Estructura del Proyecto

```
apollo-php/
├── apps/                    # Aplicaciones modulares
│   ├── users/              # App de usuarios
│   └── products/           # App de productos
├── core/                   # Núcleo del framework
│   ├── Console/           # Sistema CLI
│   ├── Container/         # Contenedor DI
│   ├── Http/             # HTTP components
│   └── Router/           # Sistema de rutas
├── config/                # Configuraciones
├── docs/                  # Documentación
├── public/               # Punto de entrada web
└── apollo               # CLI ejecutable
```

## Características Principales

- **Arquitectura Modular**: Apps independientes y reutilizables
- **Sistema CLI Robusto**: Comandos personalizables para automatización
- **Contenedor DI**: Inyección de dependencias automática
- **Middleware Pipeline**: Sistema de middleware flexible
- **Router Avanzado**: Rutas con parámetros y grupos
- **Generadores de Código**: Comandos make para scaffolding rápido

## Inicio Rápido

1. **Instalar dependencias**:
   ```bash
   composer install
   ```

2. **Configurar entorno**:
   ```bash
   cp .env.example .env
   ```

3. **Iniciar servidor de desarrollo**:
   ```bash
   php -S localhost:8000 -t public
   ```

4. **Probar la API**:
   ```bash
   curl http://localhost:8000/api/users
   ```

## Contribuir

Para contribuir al framework o su documentación:

1. Fork el repositorio
2. Crea una rama para tu feature
3. Implementa tus cambios
4. Agrega tests si es necesario
5. Envía un Pull Request

## Licencia

Apollo Framework está licenciado bajo la licencia MIT.