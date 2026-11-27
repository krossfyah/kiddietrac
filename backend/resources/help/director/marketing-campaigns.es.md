---
title: Campañas de marketing
---
# Campañas de marketing

**Marketing › Campaigns** es donde escribe boletines, invitaciones a jornadas de puertas abiertas y promociones, y las
envía a las familias (o al personal) que elija: por correo, como publicación de Noticias en el portal, o ambas. Después
del envío puede ver quién la recibió, quién la abrió y qué correos rebotaron, y reenviarla a las personas cuya
dirección ya está corregida.

[[open: #marketing-campaigns | Abrir Campañas]]

![Haga clic en + New campaign en la pantalla Campañas](https://api.kiddietrac.com/help-img/campaigns.png)

## Escribir una campaña

1. Pulse **+ New campaign**.
2. Escriba un **Campaign title** (solo lo ve su equipo) y el **Subject (email)** que las familias verán en su bandeja.
3. Elija la **Audience**: **All families**, **Currently enrolled**, **Waitlist**, **Prospects (no active child)** o **Staff only**.
4. Elija el **Channel**: en el portal (publicación de Noticias), por correo, o ambos.
5. Si va por correo, revise **Send from**: el remitente predeterminado de la agencia, o un buzón Gmail / Google Workspace conectado si su agencia lo tiene.
6. Agregue una **Hero image (optional)**, la imagen de arriba del mensaje.
7. Escriba el cuerpo en el editor (títulos, listas, colores, enlaces, imágenes). Para saludar a cada familia por su nombre, escriba **{first_name}** o pulse **👤 First name**: «Hola {first_name},» llega como «Hola Sarah,». Si no hay un único lector o no hay nombre registrado, dice «there».
8. Pulse **Save draft**.

> 💡 El logotipo, el nombre, el correo de soporte y la dirección de su agencia aparecen arriba automáticamente si su plan incluye marca blanca. El pie muestra sus enlaces **Privacy policy** y **Terms & conditions** de los ajustes de marca de la agencia.

## Vista previa y envío

1. Pulse **👁 Preview** para ver la campaña exactamente como la recibirán las familias, con un nombre de ejemplo. Siempre revise antes de enviar.
2. Para enviarla más tarde, complete **Schedule** con fecha y hora. Déjelo vacío para enviarla usted.
3. Pulse **Send now** y confirme. Las publicaciones de Noticias aparecen enseguida; los correos salen de a pocos.

Las campañas enviadas son de solo lectura. Para revisar una sin cambiarla, abra el **⋮** de su fila y elija **View**; View nunca envía nada.

### Quién recibe una campaña

Cada padre, madre o tutor de una familia que sigue con ustedes, **incluidos los que aún no aceptaron su invitación a la
aplicación**. No llega a familias que se fueron o están suspendidas, a cuentas cerradas ni a quien no tiene correo
registrado, y cada dirección recibe una sola copia.

## Ver quién la recibió: Recipients & stats

1. Abra el **⋮** de la campaña enviada y elija **Recipients & stats**. Se abre una página completa: «Who this campaign went to, and what happened.»
2. Lea los recuadros y luego la tabla: **Family**, **Email**, **Status**, **Details**, **Actions**.
3. Filtre por estado con las fichas: **Sent** (nuestro servicio de correo lo aceptó y no volvió ningún rebote), **Opened**, **Bounced** (el servidor del destinatario lo devolvió, con el motivo en palabras sencillas), **Failed** (no se pudo entregar), **Held back** (otra regla de correo lo detuvo; por ejemplo, la persona desactivó estos correos o la cuenta está cerrada), **Waiting** (aún no enviado) y **Not known**.
4. Busque a una persona, o exporte la lista a CSV, Excel o PDF desde la barra bajo la tabla.
5. Pulse **View the email they got** en una fila para ver la copia exacta que recibió esa persona, o **Copy email address**.
6. Pulse **All campaigns** para volver.

> ℹ️ Lo que no puede decirle: los servicios de correo no envían acuse de entrega, así que «Sent» es lo más cercano a «entregado». Una apertura cuenta cuando cargan las imágenes del correo, así que quien bloquea imágenes aparece como no abierto. Los clics en enlaces no se registran. Si la campaña también se publicó en Noticias, verá cuántos abrieron la publicación y leyeron el aviso en la app.

## Reenviar a quienes les rebotó el correo

1. Primero corrija el correo de la persona en su registro (**Open their record** en la lista lo lleva allí).
2. Pulse **↻ Resend to bounced**, encima del filtro de estado o en el **⋮** de la campaña.
3. Cada persona aparece con **Bounced at**, **Current email** y un **State**: **Address corrected — ready to resend**, **Same address — fix it first**, **No email on file** o **Account deactivated**.
4. Solo se pueden marcar las personas listas; empiezan marcadas (**Tick all ready** las vuelve a marcar).
5. Pulse **↻ Resend** y confirme la cantidad. Va a su dirección corregida, una vez a cada una. No se puede deshacer.
6. Aparece el aviso **Campaign resent** y la persona figura como enviada, con la dirección rebotada en **Details**. Si eran muchas, pulse **Resend again**: envía hasta 80 por vez.

Nunca se envía dos veces a una dirección que ya la recibió, y se siguen aplicando las reglas de correo habituales. El registro de auditoría anota cada dirección.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Escribir, enviar, ver estadísticas y reenviar para todos los centros. |
| Director de centro | Lo mismo para su(s) centro(s); solo ve las campañas de sus centros. |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |

Quien puede ver una campaña pero no enviarla lee: «You can see this list, but only someone who can send this campaign can resend it.»

## Preguntas frecuentes y problemas

??? Recipients & stats no está en el menú ⋮. | Aparece cuando algo ya salió. Los borradores y las campañas programadas aún no tienen destinatarios.
??? Resend to bounced dice que nadie está listo. | «Nobody is ready yet: correct their email on their record first.» Corrija la dirección y vuelva a abrirlo.
??? Algunas personas muestran «Not known». | Las campañas enviadas antes del 27 de noviembre de 2026 se reconstruyeron desde el registro de correos; algunas personas pueden no tener registro.
??? Un padre dice que nunca la recibió. | Mire su fila: **Held back** indica la regla que lo detuvo; **Bounced**, el motivo. Si dice **Sent**, pídale que revise el correo no deseado.
??? ¿Puedo borrar una campaña? | Sí, con **⋮ > Delete** («Delete this campaign? This cannot be undone.»). Los correos ya enviados no se recuperan.

## Pantallas relacionadas

[[open: #tours | Visitas]] [[open: #audit-logs | Registro de auditoría]]

Vea también [[drip-campaigns]] y [[email-templates]].
