---
title: Notificaciones por correo de mensajes perdidos
category: Settings
order: 80
---
# Notificaciones por correo de mensajes perdidos

Si un mensaje del chat permanece sin leer durante más de **30 minutos**, KiddieTrac envía al destinatario un correo electrónico recordatorio para que no se le pase algo importante. El correo llega con la marca de su agencia — colores, logotipo, dirección de soporte — y un enlace de un solo toque para volver a la conversación.

## Cómo funciona

Cada 15 minutos, una tarea programada busca los mensajes sin leer más antiguos que el retraso configurado. Para cada uno, revisa quién forma parte de la conversación:

- **Padres → personal**: cuando un tutor envía un mensaje, todas las personas con acceso a la conversación reciben el aviso — directores de centro, administradores de agencia, educadores del centro.
- **Personal → padres**: cuando un director o educador responde, los tutores de la familia reciben el aviso.

Varios mensajes sin leer del mismo hilo se agrupan en un solo correo. Así, si llegaron tres mensajes en una hora, el destinatario recibe un único resumen ordenado, no tres correos separados.

## Qué muestra el correo

Cada mensaje sin leer aparece como una tarjeta con:
- Nombre del remitente
- Marca de tiempo (relativa — «hace 12 minutos»)
- Cuerpo truncado (primeros 320 caracteres)
- Número de archivos adjuntos, si los hay

Un botón destacado con la etiqueta **«Abrir conversación»** al final enlaza directamente a `#chat` en el portal.

## Por qué deja de recibir recordatorios

Una vez enviado el correo de un mensaje, ese mensaje se **marca como notificado** en la base de datos (marca de tiempo `email_notified_at`). El programador no volverá a enviar un correo del mismo mensaje aunque siga sin leer. Por lo tanto:

- Leer el mensaje en el portal → no habrá más correos sobre él (se registra read_at).
- Responder → mismo efecto (read_at se registra del lado del destinatario cuando abre el hilo).
- Ignorarlo → se envía un correo y nada más. El hilo sigue en su lista de no leídos, pero el correo no se repetirá.

## Quién queda exento

- Se omiten los usuarios sin correo electrónico registrado.
- Se omiten los usuarios eliminados (eliminación lógica).
- Los remitentes nunca reciben un aviso de sus propios mensajes.
- Se omiten los usuarios que ya leyeron el mensaje (la marca `read_at` se registra cuando el panel de chat muestra el mensaje).

## Marca por agencia

El correo sale por el **mismo SMTP por agencia** que usan los resúmenes diarios y semanales:
- Si configuró el host, el usuario y la contraseña de SMTP en **Agency edit → Email settings**, el mensaje sale desde su dominio mediante su buzón.
- De lo contrario, se usa el valor predeterminado de la plataforma (`MAIL_*` en el `.env` del servidor).
- Los inquilinos de marca blanca reciben correos limpios con su propia marca — sin el pie «Powered by Kiddietrac».

## Pruebas

Desde SSH:

    php artisan kiddietrac:chat-emails --dry-run

Muestra lo que se enviaría sin enviar nada. Es útil antes de configurar SMTP por primera vez, para confirmar que los destinatarios se resuelven correctamente.

    php artisan kiddietrac:chat-emails --dry-run --delay=0

Igual, pero ignora el retraso de 30 minutos, de modo que se evalúa cada mensaje sin leer que haya ahora en el sistema. Ideal para comprobar que su lógica de unión devuelve los destinatarios correctos.

    php artisan kiddietrac:chat-emails

Envío real. Úselo con cuidado — se enviará un correo por cada mensaje en cola.

## Personalizar el retraso

El valor predeterminado de 30 minutos equilibra «no saturar a las personas» y «no perder nada urgente». Si desea otra cadencia:

- **De forma permanente**: pase `--delay=N` en la llamada a artisan desde una entrada de cron personalizada.
- **Por agencia**: es un candidato para v22p41+ — abra un ticket de soporte si desea un umbral distinto de 30 minutos.

## Registro de auditoría

Cada ejecución de envío escribe una entrada en `audit_logs` con `action='chat.email_notified'`, el número de correos enviados y los ids de los mensajes afectados. Puede revisarlo en la pantalla **Audit log** del menú Administración.
