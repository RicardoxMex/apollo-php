# Módulo Mail — Envío de correos

Módulo del core (`core/Mail/`) para enviar correos desde cualquier app: una
fachada `Mailer` con política de robustez, transporte SMTP mínimo sin
dependencias externas, transporte `log` para desarrollo/tests, plantillas con
placeholders `{{clave}}` y la tabla `email_verifications` para flujos de
verificación. **No hay app REST asociada**: cada flujo (verificación, reset,
notificaciones...) integra `mailer()` donde lo necesite.

## Características

- `mailer()` / `app(Mailer::class)` — fachada de envío (singleton del container).
- `MailMessage` — mensaje inmutable por construcción: `to`, `subject`, `html`, `text`, `from`.
- `Template` — render de `{{placeholders}}` (anidados `{{a.b}}`) sin motor externo; registro central en `core/Mail/Templates/registry.php`.
- Transports: `LogTransport` (dev/tests: escribe el email renderizado en disco) y `SmtpTransport` (SMTP estándar: STARTTLS + AUTH LOGIN, compatible con Resend, Mailgun, SendGrid, SMTP propio).
- **Robustez**: el envío nunca rompe la petición — ante fallo reintenta (`MAIL_RETRY`) y loguea vía `error_log`, devolviendo `false`.
- Migración `029_create_email_verifications_table` — token con hash (nunca en claro), expiración, `used`/`used_at`, IP, índices.

## Configuración (config/mail.php, env)

| Clave | Default | Env | Descripción |
|---|---|---|---|
| `driver` | `log` | `MAIL_DRIVER` | `log` (dev/test) o `smtp` (producción) |
| `from.address` | `no-reply@torneomaster.app` | `MAIL_FROM_ADDRESS` | Remitente por defecto |
| `from.name` | `TorneoMaster` | `MAIL_FROM_NAME` | Nombre del remitente |
| `smtp.host` | `localhost` | `MAIL_HOST` | p. ej. `smtp.resend.com`, `smtp.gmail.com` |
| `smtp.port` | `587` | `MAIL_PORT` | `465` con `ssl`, `587` con `tls` |
| `smtp.username` | `''` | `MAIL_USERNAME` | Usuario SMTP (vacío = sin AUTH) |
| `smtp.password` | `''` | `MAIL_PASSWORD` | Contraseña SMTP |
| `smtp.encryption` | `tls` | `MAIL_ENCRYPTION` | `tls` \| `ssl` \| `none` |
| `smtp.verify_peer` | `true` | `MAIL_VERIFY_PEER` | Verificar certificado TLS |
| `smtp.timeout` | `15` | `MAIL_TIMEOUT` | Timeout de conexión/lectura (segundos) |
| `log_path` | `runtime/logs/mail` | `MAIL_LOG_PATH` | Directorio del driver `log` |
| `retry` | `1` | `MAIL_RETRY` | Reintentos adicionales al primer intento |
| `frontend_url` | `http://localhost:3000` | `FRONTEND_URL` | URL pública de la app (enlaces de verificación/reset) |

Ejemplo para producción (Resend):

```dotenv
MAIL_DRIVER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_xxxxxxxx
MAIL_ENCRYPTION=tls
```

> El driver `log` escribe cada correo como un archivo HTML autocontenido
> (`<fecha>-<asunto>.html`) en `MAIL_LOG_PATH`; útil para inspeccionar el
> render exacto en desarrollo sin enviar nada.

## Uso básico (backend PHP)

```php
use Apollo\Core\Mail\MailMessage;

// En un controlador o servicio
$enviado = mailer()->send(
    MailMessage::to('usuario@example.com', 'Ana')
        ->subject('Bienvenida')
        ->html('<p>Hola <strong>Ana</strong>:</p>')
        ->text("Hola Ana:")
);
// $enviado: true si se envió (o se escribió en log); false si falló tras reintentos
```

Con plantilla del registro central:

```php
$enviado = mailer()->sendTemplate('verification', 'ana@example.com', [
    'name' => 'Ana',
    'link' => config('mail.frontend_url') . '/verify?token=' . $token,
]);
```

Remitente explícito (por defecto se usa `config('mail.from')`):

```php
MailMessage::to('x@example.com')
    ->from('contacto@misitio.com', 'Soporte')
    ->subject('...');
```

### Plantillas

El registro vive en `core/Mail/Templates/registry.php`: cada plantilla define
`subject`, `html` y `text` con placeholders `{{clave}}` (rutas anidadas
`{{a.b}}` también funcionan). Claves desconocidas o no escalares se
reemplazan por cadena vacía. Las plantillas actuales:

| Nombre | Placeholders | Uso |
|---|---|---|
| `verification` | `name`, `link` | Confirmar email (enlace caduca en 24 h) |
| `reset_password` | `name`, `link` | Recuperar contraseña (caduca en 30 min) |
| `registration_request` | `name`, `team`, `tournament`, `link` | Aviso al organizador de una solicitud de inscripción |
| `registration_decision` | `name`, `tournament`, `status`, `reason`, `link` | Resultado de una inscripción |

### Flujo de verificación de email

La migración `029` crea `email_verifications` para token + expiración. El
token se guarda **haseado** (columna `token`, 64 chars, unique), nunca en
claro; `ip_address` registra quién lo solicitó y `used`/`used_at` lo marcan
consumido. Los índices cubren `user_id` y `expires_at`.

## Robustez y límites

- `Mailer::send()` captura cualquier `Throwable` del transporte, reintenta
  hasta `1 + MAIL_RETRY` veces y devuelve `false` al agotar; **no lanza** al
  caller: el flujo principal (registro, login...) no se rompe por correo.
- `MailMessage` es un builder fluente: los métodos (`subject()`, `html()`,
  `from()`...) mutan y devuelven `$this`, sin estado compartido entre mensajes.
- El transporte SMTP es mínimo y deliberado: sin dependencias externas,
  normalización CRLF y escape de líneas `.` (dot-stuffing), verificación TLS
  configurable.