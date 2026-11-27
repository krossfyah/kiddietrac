---
title: Ajustes de envío — cómo KiddieTrac envía el correo
---
# Ajustes de envío — cómo KiddieTrac envía el correo

**Plataforma → Mail settings** es donde un administrador de la plataforma KiddieTrac elige cómo toda la plataforma envía el correo saliente, y configura las aplicaciones de inicio de sesión de Microsoft y Google que las agencias usan para conectar buzones. Las agencias nunca ven esta pantalla: su nombre y dirección de remitente están en **Ajustes → Email settings**.

[[open: #mail-settings | Abrir Mail settings]]

## Elegir el método de envío y el remitente

1. Abra **Mail settings**. La primera tarjeta es el correo saliente de la plataforma.
2. En **Método de envío**, elija **Microsoft Graph (recommended — with sendmail fallback)**. **Local sendmail only** es para emergencias: el correo sale, pero llega a correo no deseado con más frecuencia.
3. Escriba la **Dirección de remitente** y el **Nombre de remitente** que se usan cuando una agencia no definió los suyos.
4. En **🔐 Microsoft Graph credentials**, complete **Directory (tenant) ID**, **Application (client) ID** y **Buzón remitente de Graph**.
5. Pegue el **Secreto del cliente** (déjelo en blanco para conservar el guardado) y ponga en **El secreto vence el** la fecha que muestra Azure, para recibir un aviso antes de que venza.
6. Pulse **Guardar ajustes**. Aparece **✓ Saved.** junto al botón.

> ⚠️ El servidor bloquea el SMTP normal, así que el correo sale por Microsoft Graph mediante HTTPS. Cuando vence el secreto, el envío pasa a sendmail y la entrega empeora. Renueve el secreto en Azure antes de la fecha indicada.

## Probar la entrega

1. Vaya a **📤 Send a test**, al final.
2. Escriba una dirección que pueda revisar y pulse **Enviar prueba →**.
3. Lea la línea de resultado: **✓ Sent via** indica el servicio usado y **Graph credentials** dice si el acceso a Graph funcionó. La línea **Last test** guarda el resultado.

## Inicio de sesión de Microsoft 365 para buzones

La tarjeta **🟦 Microsoft 365 sign-in (mailboxes and "Sign in with Microsoft")** es la aplicación con la que las personas inician sesión al conectar un buzón en **Email** o **Sales email**, y para **Iniciar sesión con Microsoft** en la página de acceso.

1. En Microsoft Entra, abra **App registrations** y su aplicación. Necesita los permisos delegados Mail.ReadWrite, Mail.ReadWrite.Shared, offline_access y User.Read.
2. Copie el **Application (client) ID** de la página Overview de la aplicación.
3. Cree un secreto en Certificates & secrets y pegue su valor (Value) en **Client secret value**.
4. Pulse **Copiar** junto a **Redirect URI** y agréguela en Authentication → Web de la aplicación.
5. Pulse **Guardar configuración de Microsoft**. KiddieTrac guarda y comprueba con Microsoft; el resultado aparece junto al botón. **Comprobar con Microsoft** repite la comprobación más tarde.

## Inicio de sesión de Gmail para remitentes de RSVP

La tarjeta **📧 Gmail sign-in for RSVP senders** guarda el cliente de Google que permite a una agencia conectar una cuenta de Gmail en **Event RSVPs → Sender accounts**.

1. En Google Cloud Console, abra Google Auth Platform → Clients y cree un cliente web.
2. Pulse **Copiar** junto a **Authorized redirect URI (paste this into the Google client)** y péguela en el cliente.
3. Escriba aquí el **ID de cliente** y el **Secreto del cliente**.
4. Pulse **Guardar configuración de Gmail** y luego **Comprobar con Google** cuando quiera confirmar que sigue funcionando.

## Quién puede hacer qué

Solo los administradores de plataforma abren esta pantalla. Los administradores de agencia definen su nombre y dirección de remitente en **Ajustes → Email settings**; los directores de centro y los demás roles no tienen ajustes de envío.

## Preguntas frecuentes y problemas

??? La tarjeta de Microsoft dice "Using the server file (.env)". | La aplicación todavía se lee del archivo del servidor, que no se puede comprobar desde aquí. Escriba el ID de cliente y el secreto y pulse **Guardar configuración de Microsoft** para gestionarla desde esta pantalla.
??? ¿Qué pasa si pulso Quitar? | Se le pide confirmar. Para Microsoft, el inicio de sesión vuelve al archivo del servidor y los buzones ya conectados siguen funcionando hasta que deban renovar su acceso. Para Gmail, las agencias no pueden conectar remitentes de Gmail y los ya conectados dejan de enviar.
??? La prueba dice "✗ Send failed". | Lea el motivo en rojo que sigue. Lo más común es que el secreto haya vencido o que el buzón remitente sea incorrecto. Corríjalo, pulse **Guardar ajustes** y envíe otra prueba.
??? "Enter an address to test." | Escriba la dirección en el cuadro antes de pulsar **Enviar prueba →**.
??? ¿Las agencias ven estos secretos? | No. Los secretos son de solo escritura: una vez guardados, la pantalla solo dice que hay uno guardado.

## Pantallas relacionadas

[[open: #email-settings | Ajustes de correo]] [[open: #email | Cliente de correo]]

Vea también: [Ajustes de correo](email-settings), [Cliente de correo](email-client).
