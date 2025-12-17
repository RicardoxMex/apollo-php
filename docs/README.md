# Apollo Framework - Documentación

Bienvenido a la documentación oficial de Apollo Framework, un mini-framework PHP inspirado en Django REST Framework.

## Índice de Documentación

### CLI (Command Line Interface)
- [**Comandos CLI Personalizados**](cli-commands.md) - Tutorial completo para crear comandos CLI personalizados

### Próximamente
- Guía de Instalación
- Arquitectura del Framework
- Creación de APIs REST
- Sistema de Middleware
- Manejo de Rutas
- Contenedor de Dependencias
- Testing

## Comandos CLI Disponibles

Apollo Framework incluye un potente sistema CLI similar a Laravel Artisan:

```bash
# Listar todas las rutas
php apollo route:list

# Crear un nuevo controlador
php apollo make:controller ProductController --app=products

# Crear un nuevo middleware
php apollo make:middleware ValidationMiddleware --app=users

# Crear un nuevo modelo
php apollo make:model Product --app=products

# Generar reporte del sistema
php apollo system:report

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