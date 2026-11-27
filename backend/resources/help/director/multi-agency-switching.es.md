---
title: Mi agencia y cambiar de agencia
---
# Mi agencia y cambiar de agencia

**Mi agencia** muestra la agencia en la que tiene la sesión iniciada: su dirección web, plan, número de centros y usuarios, y su estado de facturación. Desde aquí un administrador de la agencia gestiona los datos propios de la agencia, la dirección, el remitente de correo y los interruptores de automatización. Si usted atiende más de una agencia, el selector de agencias de la barra lateral le permite pasar de una a otra sin cerrar sesión. Ábralo desde la barra lateral: **Resumen > Mi agencia**.

[[open: #agencies | Abrir Mi agencia]]

## Leer la tarjeta de la agencia

La pantalla muestra una tarjeta para su agencia, con:

- el nombre de la agencia y una etiqueta de color con el estado de facturación (por ejemplo **ACTIVE** o **TRIAL**);
- 🌐 la dirección web donde se inicia sesión, cuando la agencia tiene su propio subdominio o dominio;
- 📦 el plan, 🏫 el número de centros y 👥 el número de usuarios;
- un botón **Gestionar** a la derecha.

## Cambiar los datos de la agencia

1. Pulse **Gestionar** en la tarjeta de la agencia. Se abre la ventana **Manage: (nombre de la agencia)** en la pestaña **Detalles**.
2. Revise o cambie **Nombre**, **Razón social** (solo si es distinta del nombre comercial), **Sitio web** y **Zona horaria**. Toda hora que la agencia registra o muestra (entradas, turnos, informes) usa esta zona horaria.
3. Abra la pestaña **Dirección** y complete **Dirección, línea 1**, **Ciudad**, **Province / State**, **Código postal** y **País**. Esta dirección se imprime en facturas y recibos y define el calendario de feriados oficiales.
4. Abra la pestaña **Correo**. **Correo de contacto** y **Teléfono de contacto** son donde KiddieTrac escribe a la propia agencia. **Nombre de remitente** y **Dirección de remitente** son como sus correos aparecen ante las familias.
5. Pulse **Guardar**. Aparece un **✓ Guardado** verde y la ventana se cierra.

> ⚠️ Cambiar la **Zona horaria** cambia cómo se muestran todas las horas de toda la agencia. Cámbiela solo si de verdad está mal, y consulte primero al soporte de KiddieTrac si tiene dudas.

## Activar o desactivar envíos y automatización

1. Pulse **Gestionar** y abra la pestaña **Plan y automatización**.
2. **Enviar notificaciones y correos electrónicos** es el interruptor general. Desactivado, no sale nada hacia el personal ni las familias: ni correo, ni mensaje de texto, ni notificación push, ni alerta en la aplicación. Todo se sigue registrando.
3. **Enviar resúmenes diarios y semanales** controla los correos de resumen para administradores, directores, padres y educadores.
4. **Rellenar automáticamente los horarios del personal** programa cada centro con 28 días de anticipación cada noche a las 04:30, según el horario de cada centro. Los días de cierre se omiten, los días ya programados nunca se sobrescriben y un turno eliminado sigue eliminado.
5. Pulse **View my licence →** para ver su plan, complementos y fecha de renovación.
6. Pulse **Guardar**.

## Cambiar a otra agencia

1. Mire la parte inferior de la barra lateral, justo encima de su nombre. Cuando puede usar más de una agencia, una pastilla muestra el nombre de la agencia actual.
2. Haga clic en ella. Se listan todas las agencias que puede gestionar, con una marca en la activa.
3. Elija otra agencia. El portal muestra "⏳ Switching…" y se recarga; cada pantalla muestra ahora solo esa agencia.

No se copia nada entre agencias: centros, familias, personal, facturas, roles, marca y ajustes se mantienen separados. Su propio inicio de sesión, la verificación en dos pasos y sus chats le pertenecen a usted y lo acompañan entre agencias.

## Quién puede hacer qué

- **Administrador de la agencia** – ve **Mi agencia**, edita datos, dirección, correo y automatización, y puede cambiar entre las agencias a las que fue invitado como administrador de la agencia.
- **Administrador de la plataforma** – no tiene **Mi agencia** en el menú; usa **Todas las agencias**, que además ofrece **+ Nueva agencia**, **Suspender** / **Reanudar** y fijar el estado de facturación.
- **Director de centro** – no ve esta pantalla.

## Preguntas frecuentes y solución de problemas

??? ¿Cómo obtengo acceso a una segunda agencia? | Un administrador de esa agencia lo invita como Administrador de la agencia desde su pantalla de Gestión de usuarios. La agencia aparece en su selector la próxima vez que se cargue la página.
??? No veo el selector de agencias. | Solo aparece cuando usted tiene más de una agencia.
??? El selector dice "Could not switch agency. You may not have access." | Su acceso a esa agencia se eliminó o no se ha configurado. Pregunte al administrador de esa agencia.
??? Pulsé Eliminar y apareció "Only platform admins can delete agencies." | Eliminar una agencia es solo para los administradores de la plataforma KiddieTrac. Contacte al soporte de KiddieTrac si una agencia debe cerrar.
??? ¿Dónde cambio el plan o los complementos? | Aquí no. Pulse **View my licence →** en la pestaña **Plan y automatización**.
??? ¿Dónde configuro el logotipo y los colores? | En **Marca**; los servidores de correo y las plantillas están en **Ajustes de correo**.

## Relacionado

[[open: #dashboard | Resumen de la agencia]] [[open: #admin-centres | Centros]] [[open: #email-settings | Ajustes de correo]]

- [[plan-and-add-ons]]
- [[white-label-branding]]
- [[email-and-digests]]
