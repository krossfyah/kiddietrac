---
title: Ajustes de correo y resúmenes
category: Settings
order: 65
roles: agency_admin, platform_admin
---
# Ajustes de correo y resúmenes

Cada agencia cliente de Kiddietrac puede usar su propio correo saliente para que los **resúmenes, las campañas de marketing, las facturas, los restablecimientos de contraseña y los correos de bienvenida lleguen desde su dominio**, y no desde la dirección de la plataforma Kiddietrac. Encontrará los ajustes en la ventana de edición de la agencia, en **Todas las agencias → Editar → Ajustes de correo**.

## Campos SMTP

| Campo | Qué poner | Notas |
|---|---|---|
| Host SMTP | `smtp.gmail.com` / `smtp.office365.com` / `mail.yourcentre.com` | El host saliente de su proveedor |
| Puerto | `587` (TLS) / `465` (SSL) / `25` (ninguno) | Debe coincidir con el cifrado elegido |
| Usuario | `noreply@yourcentre.com` | El inicio de sesión completo del buzón |
| Contraseña | contraseña del buzón o token de aplicación | La almacenamos cifrada; déjela en blanco al editar para conservar la existente |
| Cifrado | TLS / SSL / Ninguno | TLS (puerto 587) es la opción segura por defecto |
| Dirección del remitente | `noreply@yourcentre.com` | Lo que los destinatarios ven en el encabezado De |
| Nombre del remitente | «Sunshine Childcare» | Nombre amigable junto a la dirección del remitente |

**Deje en blanco cualquiera de ellos para usar el valor predeterminado de la plataforma** (`MAIL_*` en nuestro `.env`). Es el ajuste correcto si es un subinquilino que no ha comprado marca blanca.

Los usuarios de **Gmail / Workspace** deben generar una *contraseña de aplicación* (no la contraseña de su cuenta). Primero debe estar activada la verificación en dos pasos. Lo mismo para Outlook.

En los buzones de **GoDaddy / cPanel**, el host suele ser `mail.<your-domain>`, puerto 465 con SSL.

## Resumen diario

Cada mañana a las **7:00 a. m., hora de Toronto**, los directores y administradores de la agencia reciben un correo de resumen diario por cada agencia a la que pertenecen. Contenido:

- Cantidad de entradas registradas hoy frente al total de inscritos (con % de presentes)
- Saldo y número de facturas abiertas
- Medicamentos administrados hasta ahora hoy
- Observaciones registradas ayer
- Un aviso de advertencia si algún miembro del personal no se ha inscrito en la MFA

El resumen se envía por el SMTP de esa agencia si está configurado; de lo contrario, por el valor predeterminado de la plataforma. Las agencias suspendidas se omiten.

## Resumen semanal

Cada lunes a las **7:05 a. m., hora de Toronto**, los directores y administradores de la agencia reciben un resumen semanal de la **semana anterior** (lun.–dom.). Contenido:

- Cambio neto de matrícula (nuevos y bajas)
- Total facturado la semana pasada + pagado hasta ahora
- Observaciones registradas
- Incidentes registrados

## Probar su configuración

Dos formas de comprobar sus ajustes:

1. **Simulacro desde SSH** — `php artisan kiddietrac:digest-daily --dry-run` muestra qué diría el correo y a quién, sin enviar nada.
2. **Ejecución real desde SSH** — `php artisan kiddietrac:digest-daily` envía a todos los directores y administradores. Úselo con cuidado.

## Estado del cron

El programador se ejecuta desde una entrada de cron a nivel de servidor que lanza `php artisan schedule:run` cada minuto. Los comandos de resumen solo se ejecutan a sus horas declaradas (07:00 a diario, lunes 07:05). La salida se escribe en `backend/storage/logs/scheduler.log` en el servidor. Si alguna vez ve «no scheduled commands ready», es normal: casi todos los minutos no hay nada pendiente.

## Privacidad

Las contraseñas SMTP se almacenan como texto sin formato en la base de datos (cifradas en reposo por el cifrado de disco de GoDaddy). Nunca se devuelven en las respuestas de la API, solo un indicador `email_smtp_pass_set: true`, de modo que un volcado filtrado de `/platform/agencies` no las expondrá. Si sospecha que una contraseña está comprometida, cámbiela en la ventana de Ajustes de correo (o rote el propio buzón).
