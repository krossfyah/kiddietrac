---
title: Alertas de seguridad y monitoreo
---

# Alertas de seguridad y monitoreo

**🛡️ Security alerts** muestra la actividad de inicio de sesión sospechosa en toda la plataforma. Cada 15 minutos KiddieTrac revisa el registro de auditoría y anota todo lo que parezca un ataque contra cuentas, para que un administrador de la plataforma lo revise, decida si es importante y mantenga un registro limpio para las auditorías de seguridad. Ábralo desde **Platform › 🚨 Security alerts**.

[[open: #security-alerts | Abrir alertas de seguridad]]

## Qué se detecta

Cada revisión mira los últimos 20 minutos de inicios de sesión:

| Tipo | Se genera cuando | La columna Subject muestra |
|---|---|---|
| **🌐 Brute force (IP)** | 8 o más inicios de sesión fallidos desde una misma dirección de internet | la dirección IP |
| **🔑 MFA hammering** | 5 o más códigos de verificación en dos pasos fallidos en una cuenta | la cuenta |
| **🎯 Credential stuffing** | 10 o más inicios de sesión fallidos dirigidos a un mismo correo o usuario | el usuario que se intentó |

El mismo tipo y sujeto se registra solo una vez en ese periodo, así un ataque largo no llena la lista de copias. Todas las alertas tienen gravedad **high**.

## Revisar una alerta

1. Abra **Platform › 🚨 Security alerts**.
2. Mire los dos contadores de arriba: **Open alerts** (en rojo cuando hay algo que revisar) y **Total recorded**.
3. Lea la fila: **Type**, **Severity**, **Subject**, **Detail** (por ejemplo «35 failed logins from IP … in the last 20 min») y **When**.
4. Abra **Administration › Audit log** en otra pestaña y busque la dirección o cuenta de **Subject** para ver exactamente qué se intentó y cuándo.
5. Decida: un empleado que olvidó su contraseña se parece mucho a un ataque. Si no lo reconoce, bloquee el origen y haga que la cuenta afectada cambie su contraseña.
6. Presione **Marcar como resuelto** en la fila. Se atenúa y muestra **✓ Resolved**.

> 💡 Si hay muchas alertas abiertas y ya las revisó todas, presione **Acknowledge all (n)** y confirme con **Confirmar todas**. Siguen en el registro y se pueden leer; solo quedan marcadas como vistas.

## Vaciar el registro

Las alertas son evidencia para las revisiones de seguridad, por eso vaciar se hace en dos pasos.

1. Primero resuelva o confirme las alertas (vea arriba).
2. Presione **Clear log**.
3. Lea la confirmación **Clear n acknowledged alerts?** y presione **Clear log**. Un aviso **Log cleared** dice cuántas se eliminaron.

> ⚠️ Solo se eliminan las alertas confirmadas, y se eliminan para siempre. Las alertas abiertas nunca se borran. El vaciado queda anotado en el registro de auditoría con los tipos y la cantidad.

Si todavía no hay nada confirmado, **Clear log** muestra **Nothing to clear** y le recuerda confirmar primero las alertas abiertas.

## A quién se avisa

Cuando se registra una alerta nueva, cada administrador de la plataforma activo con correo recibe un correo de **monitoreo de seguridad** con lo encontrado y qué hacer después. El personal de las agencias y las familias nunca reciben correos sobre estas alertas, y nada aparece en su campana.

## Quién puede hacer qué

- **Administradores de la plataforma**: ven todas las alertas, las resuelven, las confirman y vacían el registro.
- **Administradores de agencia, directores de centro y todos los demás roles**: no pueden abrir esta pantalla. Un administrador de agencia que llega a ella ve **This page is available to platform administrators only.** El historial de inicios de sesión de su agencia está en **Administration › Audit log**.

## Preguntas frecuentes y problemas

??? La lista dice «No security alerts — no anomalous authentication activity detected.» ¿Funciona el monitoreo? | Sí. Ese mensaje significa que las últimas revisiones no encontraron nada por encima de los límites. Una persona que se equivoca de contraseña unas pocas veces nunca los alcanza.
??? Veo «This page is available to platform administrators only.» | Inició sesión con un rol de agencia. Solo un administrador de la plataforma puede leer estas alertas.
??? Clear log no hizo nada y mostró «Nothing to clear». | Todavía no hay alertas confirmadas. Presione **Marcar como resuelto** en cada fila revisada, o **Acknowledge all**, y luego vacíe.
??? Apareció «Could not acknowledge» o «Could not clear». | Se cortó la conexión o terminó su sesión. Actualice la página e intente de nuevo; nada quedó a medias.
??? La misma IP vuelve a aparecer después de resolverla. | El ataque siguió después del periodo de 20 minutos y se registró una alerta nueva. Bloquee el origen si continúa.
??? No recibí el correo de alerta. | Revise que su cuenta de administrador de la plataforma esté activa y tenga correo. Las alertas nunca se envían a una dirección «no-reply».
??? ¿Resolver una alerta desbloquea o bloquea a alguien? | No. Solo deja constancia de que la revisó. Los bloqueos de cuenta y los cambios de contraseña se hacen desde la cuenta de la persona.

## Pantallas relacionadas

[[open: #audit-logs | Registro de auditoría]] · [[open: #social-settings | Métodos de inicio de sesión]] · [[open: #server-health | Estado del servidor]]

Vea también: [[audit-log]] · [[single-sign-on]]
