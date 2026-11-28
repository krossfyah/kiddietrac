---
title: Mantenimiento programado y actualizaciones de las apps
---
# Mantenimiento programado y actualizaciones de las apps

**Platform › Maintenance** desconecta el portal durante un periodo planificado, envía un aviso por correo a todos y fija qué versiones de las apps móviles deben actualizarse. Mientras el mantenimiento está activo, nadie puede iniciar sesión excepto los administradores de la plataforma. Ábralo antes de una actualización o mudanza planificada, y después de cada nueva versión de la app.

[[open: #maintenance | Abrir Maintenance]]

## Planificar un periodo de mantenimiento

1. Abra **Platform › 🛠️ Maintenance**.
2. Llene **Starts** y **Ends** con la fecha y la hora del periodo. Deje ambos vacíos para bloquear el inicio de sesión desde que guarde hasta que lo desactive.
3. Escriba el **Message shown to users** (por ejemplo cuándo espera volver). Se muestra en la página de inicio de sesión.
4. Active **Maintenance mode**.
5. Pulse **Save**. Verá **✓ Saved.**, o **✓ Saved — maintenance is ACTIVE now.** si el periodo ya empezó.

> ⚠️ Cuando **Maintenance mode** está activo **y** la hora está dentro del periodo, el inicio de sesión queda bloqueado para todos excepto los administradores de la plataforma. Mientras dura, un aviso rojo, **● Maintenance is ACTIVE right now**, aparece arriba en esta pantalla.

## Enviar el aviso por correo

1. Primero guarde el periodo: el correo usa sus fechas y su mensaje.
2. En **📧 Heads-up email**, revise la dirección en la casilla (debe ser la suya) y pulse **Send test**. Lea el correo de prueba.
3. Cuando esté bien, pulse **Email all users** y confirme **Email the maintenance notice to ALL users right now?**
4. La línea bajo los botones dice a cuántas direcciones se envió. Después, **All-user notice last sent …** muestra la fecha.

> ⚠️ **Email all users** llega a cada usuario con correo en la plataforma, en todas las agencias, sin importar la configuración de correo de cada agencia, porque un aviso de interrupción es operativo. Envíelo una sola vez.

## Terminar el mantenimiento

1. Desactive **Maintenance mode**.
2. Pulse **Save**. El inicio de sesión vuelve a funcionar de inmediato.

Si puso una hora en **Ends**, el inicio de sesión también vuelve solo al terminar el periodo.

## Pedir a las apps Android que se actualicen

La tarjeta **📱 Mobile app updates (Google Play)** muestra la versión publicada en Google Play.

1. Cuando una versión de Play esté publicada, escriba su **Latest build** y su **Version name**.
2. Solo si las apps antiguas deben dejar de funcionar, suba **Minimum build** (0 = ninguno) y confirme.
3. Agregue un **Message** opcional para el aviso.
4. Pulse **Save**. **✓ Saved - apps check this the next time they open.**

Las apps más antiguas que la última versión ven **Update available**; las más antiguas que el mínimo ven un **Update required** que bloquea. Si Google Play ya muestra una versión más nueva que la guardada, una línea ámbar le dice qué poner.

## Pedir a las apps de iPhone que se actualicen

En la parte **📱 iPhone (App Store)** de la misma tarjeta:

1. Revise la línea que muestra lo que publica la App Store (con el enlace **App Store page**).
2. Llene **Minimum version** (vacío = ninguno) y, si quiere, **Latest version** y **Message**.
3. Pulse **Save**.

A los iPhone solo se les avisa de una versión que la App Store realmente publica.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Todo aquí, y puede seguir iniciando sesión durante el mantenimiento. |
| Todos los demás | Ven el mensaje de mantenimiento en la página de inicio de sesión y no pueden entrar mientras esté activo. |

## Preguntas frecuentes y problemas

??? "Set up the maintenance window first." | Guarde un periodo (fechas y mensaje) antes de pulsar Email all users.
??? "Enter an email." | Escriba una dirección en la casilla antes de pulsar Send test.
??? "The minimum build cannot be newer than the latest build." | Baje Minimum build o suba Latest build para que el mínimo no supere la última versión.
??? La gente sigue bloqueada después de terminar el trabajo. | Desactive Maintenance mode y pulse Save, o revise la hora de Ends.
??? "Could not load maintenance settings". | La pantalla no pudo leer su configuración. Actualice; solo los administradores de la plataforma pueden abrirla.

## Pantallas relacionadas

[[open: #server-health | Estado del servidor]] [[open: #mail-settings | Configuración de correo]] [[open: #platform-overview | Resumen de la plataforma]]
