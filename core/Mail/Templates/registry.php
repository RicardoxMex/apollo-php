<?php

/**
 * Registro central de plantillas de email (D1).
 *
 * Cada plantilla: ['subject' => …, 'html' => …, 'text' => …] con placeholders
 * {{clave}} rellenados por Apollo\Core\Mail\Template::render().
 * Las variables disponibles las documenta cada flujo (verificación, reset,
 * inscripción…).
 */
return [
'verification' => [
        'subject' => 'Verifica tu email — TorneoMaster',
        'html' => '<p>Hola {{name}}:</p><p>Para activar tu cuenta en TorneoMaster, confirma tu email en el siguiente enlace:</p><p><a href="{{link}}">{{link}}</a></p><p>El enlace caduca en 24 horas.</p><p>Si no creaste una cuenta, ignora este correo.</p>',
        'text' => "Hola {{name}}:\n\nPara activar tu cuenta en TorneoMaster, confirma tu email en:\n{{link}}\n\nEl enlace caduca en 24 horas.\nSi no creaste una cuenta, ignora este correo.",
    ],
    'email_changed' => [
        'subject' => 'Tu correo cambió - TorneoMaster',
        'html' => '<p>Hola {{name}}:</p><p>El correo de tu cuenta en TorneoMaster se actualizó a <strong>{{new_email}}</strong>.</p><p>Si no fuiste tú, recupera tu cuenta restableciendo tu contraseña de inmediato.</p>',
        'text' => "Hola {{name}}:\n\nEl correo de tu cuenta en TorneoMaster se actualizó a {{new_email}}.\n\nSi no fuiste tú, recupera tu cuenta restableciendo tu contraseña de inmediato.",
    ],
    'reset_password' => [
        'subject' => 'Recupera tu contraseña — TorneoMaster',
        'html' => '<p>Hola {{name}}:</p><p>Recibimos una solicitud para restablecer tu contraseña. Usa el siguiente enlace (caduca en 30 minutos):</p><p><a href="{{link}}">{{link}}</a></p><p>Si no la solicitaste, ignora este correo y tu contraseña seguirá igual.</p>',
        'text' => "Hola {{name}}:\n\nRecibimos una solicitud para restablecer tu contraseña. Usa el siguiente enlace (caduca en 30 minutos):\n{{link}}\n\nSi no la solicitaste, ignora este correo y tu contraseña seguirá igual.",
    ],
    'registration_request' => [
        'subject' => 'Nueva solicitud de inscripción — {{tournament}}',
        'html' => '<p>Hola {{name}}:</p><p><strong>{{team}}</strong> quiere inscribirse en tu torneo <strong>«{{tournament}}»</strong>.</p><p>Entra a tu panel para aceptar o rechazar la solicitud:</p><p><a href="{{link}}">{{link}}</a></p>',
        'text' => "Hola {{name}}:\n\n{{team}} quiere inscribirse en tu torneo «{{tournament}}».\n\nEntra a tu panel para aceptar o rechazar la solicitud:\n{{link}}",
    ],
    'registration_decision' => [
        'subject' => '{{status}}: {{tournament}} — TorneoMaster',
        'html' => '<p>Hola {{name}}:</p><p>Tu inscripción en el torneo <strong>«{{tournament}}»</strong> fue <strong>{{status}}</strong>{{reason}}.</p><p><a href="{{link}}">{{link}}</a></p>',
        'text' => "Hola {{name}}:\n\nTu inscripción en el torneo «{{tournament}}» fue {{status}}{{reason}}.\n\n{{link}}",
    ],
    'announcement' => [
        'subject' => '{{title}} — {{tournament}}',
        'html' => '<p>Hola {{name}}:</p><p><strong>{{title}}</strong></p><p>{{body}}</p><p><a href="{{link}}">Ver el torneo</a></p>',
        'text' => "Hola {{name}}:\n\n{{title}}\n\n{{body}}\n\n{{link}}",
    ],
];