# Apollo Framework — Repository Guide

This is the OpenCode entry point of the **Apollo Framework** repository (`mexancode/apollo-php`): a PHP mini-framework for building modular REST APIs, inspired by Django REST Framework. The repository carries the portable **Arches AI Engineering Operating System** (`.ai/`) — a Markdown-first multi-agent orchestration system for OpenCode: role definitions, workflows, rules, specs, templates, and state conventions turn the session into a professional multi-agent engineering system. Markdown defines the system (roles, workflows, rules, specs, knowledge); a structured execution state (`.ai/state/store/*.json`) controls how work runs.

> **Markdown defines the system; the structured state executes the system.**
> The LLM proposes; Arches validates; the structured state records; the engine executes.

## Startup instruction (CRITICAL)

CRITICAL: Read `@.ai/AGENTS.md` immediately — before answering or acting on anything. It is the system entry point and defines the protocol this session must follow. Do not preemptively load other references; load them lazily on a need-to-know basis as the protocol dictates.

- `.ai/AGENTS.md` — system entry point, operating principles, orchestration protocol
- `.ai/agents/` — agent role cards
- `.ai/workflows/` — named execution procedures (including `state-engine.md`, the state authority)
- `.ai/rules/` — invariant constraints
- `.ai/skills/` — loadable knowledge packs (SKILL.md convention)
- `.ai/templates/` — artifact templates (task, handoff, decision, spec)
- `.ai/specs/` — the OS's own source of truth (OS-2.4.0; release notes and upgrade info)
- `.ai/project/` — durable project knowledge (context, conventions, architecture)
- `.ai/state/` — canonical state model + structured execution state (`.ai/state/store/*.json`)

## About this repository

- **What:** Apollo Framework — mini-framework PHP para APIs REST modulares (DRF-inspired). Modular apps (`apps/`: Users, Products, ApolloAuth) over an in-house kernel (`core/`: Application, Config, Container DI, Router, Http, Database, Auth JWT, Console). MIT.
- **Stack:** PHP >= 8.3, Composer (PSR-4: `Apollo\Core\` → `core/`, `Apps\` → `apps/`, `Tests\` → `tests/`), **MySQL o SQLite** (drivers intercambiables, `DB_DRIVER`/`DB_CONNECTION`; SQLite: `database/*.sqlite` o `:memory:`, requiere `extension=pdo_sqlite`; `setup_database.php` corre todas las migraciones `*.php` ordenadas), JWT auth, phpdotenv; dev: phpunit ^10.5 + mockery (suite ~48 sin DB + integración SQLite opcional). No frontend, no deployment config.
- **Commands:** `composer install` · `composer test` (phpunit) · `composer start` (`php -S localhost:8000 -t public`) · `php apollo help` (CLI: `route:list`, `make:controller`, `make:middleware`, `system:report`, `test`) · dev scripts `php setup_database.php`, `php run_seeders.php`, `php test_middleware.php`.
- **Language:** user docs (`docs/`) and most inline comments are Spanish; `.ai/` artifacts stay English. Match the file you touch.
- **Knowledge:** `.ai/project/context.md` is the durable project knowledge (stack, commands, gotchas) — read it before planning work. User manual index: `docs/README.md`.
- **Portability:** only this `AGENTS.md` and the knowledge under `.ai/project/`, `.ai/state/`, and `docs/` are repo-specific; the rest of `.ai/` is portable and gitignored.
