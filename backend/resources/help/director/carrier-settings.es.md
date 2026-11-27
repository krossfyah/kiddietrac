---
title: Ajustes de operadores — Twilio y Telnyx
---
# Ajustes de operadores — Twilio y Telnyx

**Ajustes → Ajustes de operadores** guarda las cuentas con las que su agencia envía mensajes de texto y hace llamadas. Se admiten dos operadores (Twilio y Telnyx) y por ellos pasan dos canales: mensajes de texto y llamadas de voz con anuncios. Ábralo una vez al configurar los mensajes de texto, y otra vez cuando un mensaje o una llamada no llegó.

[[open: #sms-settings | Abrir Ajustes de operadores]]

Cada agencia configura aquí su propio operador. Los números, claves, saldo y opciones de una agencia nunca los ve otra, y solo un **administrador de esa agencia** puede guardarlos. La pantalla tiene dos pestañas, **Mensajes de texto** y **Llamadas de voz**, y recuerda en cuál estaba.

## Configurar los mensajes de texto, de principio a fin

1. Abra **Ajustes de operadores** en la pestaña **Mensajes de texto**.
2. En **Configurar proveedor**, elija la página **Twilio** o **Telnyx**.
3. Para Twilio, escriba el **SID de la cuenta** (empieza por AC) y el **Token de autenticación**, o mejor un **API key SID** y un **API key secret**. Para Telnyx, escriba la **Clave de API** (empieza por KEY).
4. Escriba **Enviar desde**: el número en formato internacional completo (por ejemplo +1 y diez dígitos).
5. Para Telnyx, pegue la **Clave pública del webhook** (Mission Control → Keys & Credentials → **Public Key**), para que las respuestas y los informes de entrega sean de confianza.
6. Copie el **Replies and STOP webhook** (Twilio) o el **Inbound message webhook** (Telnyx) en el portal del operador.
7. Pulse **Guardar**. Se guardan los dos operadores a la vez, sea cual sea la página abierta.
8. Pulse **Test Twilio** o **Test Telnyx**. Comprueba las credenciales con el operador, no cuesta nada y no envía nada. El resultado queda en el registro de pruebas.

> ℹ️ Las credenciales son de **solo escritura**. Una vez guardadas, la pantalla solo dice si hay un secreto guardado. Para cambiarlo, escriba el valor nuevo; para conservarlo, deje el cuadro vacío. Tome los nombres de los campos al pie de la letra: una clave de API de Telnyx no es un SID de cuenta de Twilio, y un identificador que empieza por `OQ…` tampoco lo es.

## Elegir qué operador envía

1. Busque la tarjeta **Proveedor que envía**, encima de las páginas de operadores. **Both can be set up at once. Only the one chosen here sends.**
2. Elija **Twilio** o **Telnyx**. La página del operador que envía queda marcada **sending**.
3. Marque **If that carrier refuses, try the other one.** si ambos están configurados. El otro operador solo se usa cuando el primero rechaza un mensaje, y un mensaje ya aceptado nunca se envía dos veces.
4. Active **Send text messages for this agency**.
5. Pulse **Guardar**.

[[show: #sms-settings @ Which carrier sends | Muéstreme la elección de operador]]

## Elegir los tipos de mensajes que envía la agencia

1. En la pestaña **Mensajes de texto**, busque **Tipos de mensajes que envía esta agencia**.
2. Marque los tipos que quiera:

| Tipo | Qué es |
|---|---|
| Entrada y salida | Cuando un niño entra o sale, y con quién |
| Recordatorios de entrada | Un niño no tiene registrada la entrada, o nunca se registró la salida |
| Anuncios | Los anuncios, cuando también se envían por mensaje de texto |
| Envíos masivos de texto | Mensajes a un grupo, una sala o todos desde SMS broadcast |
| Respuestas del personal | La respuesta del personal a un mensaje enviado al número de la agencia |

3. Pulse **Guardar**.

Si desmarca un tipo, la agencia no envía ningún mensaje de ese tipo, elija lo que elija cada persona. Solo reciben mensajes quienes aceptaron recibirlos, y cada persona puede apagar tipos en su perfil — vea [Mensajes de texto y llamadas](texts-and-phone-calls). Las confirmaciones de consentimiento y las respuestas a STOP siempre se envían.

![Tipos de mensajes que envía esta agencia, en la pestaña Mensajes de texto](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Enviar un mensaje o una llamada de prueba real

1. En **Enviar una prueba real**, escriba un número que usted controle.
2. Marque **I control this number, or its owner is expecting this.**
3. Pulse **💬 Send text** o **📞 Place call**.

> ⚠️ Esto envía de verdad. Lo cobra su operador, dice que es una prueba, nombra a su agencia e incluye STOP. Solo para administradores de agencia y cinco por hora por agencia.

## Configurar las llamadas de voz con anuncios

1. Abra la pestaña **Llamadas de voz**. La voz usa la clave de API de Telnyx de la pestaña Mensajes de texto.
2. Escriba el **Call Control connection id** (Mission Control → Voice → **Call Control** → su aplicación) y **Llamar desde**, el número que verán los padres. **Caller name** es opcional.
3. Elija una voz en **Elija una voz…** y ponga el **Idioma** (por ejemplo `en-US`, `en-GB`, `fr-CA`). Las voces neuronales suenan mucho más naturales y cuestan un poco más.
4. En **Motivos que pueden originar llamadas**, marque los motivos para los que se puede hacer una llamada: *Cierre, Evacuación, Confinamiento, Enfermedad en el centro, Otra emergencia* y *No es una emergencia*. Las cinco emergencias empiezan activadas; *No es una emergencia*, desactivada.
5. Active **Place announcement calls for this agency**. Está apagado hasta que usted lo active.
6. Pulse **Guardar**.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Muéstreme los motivos de llamada]]

![Motivos que pueden originar llamadas — las cinco emergencias activadas, "No es una emergencia" desactivada](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

La pantalla SMS broadcast solo ofrece los motivos marcados aquí.

## Probar una llamada

1. En la pestaña **Llamadas de voz**, escriba el **Mensaje de prueba** (hasta 600 caracteres).
2. Deje **Número al que llamar** en blanco para llamar al número de su perfil, o escriba otro número y marque **I control this number**.
3. Pulse **Llamar a mi propio número**. La prueba usa la voz elegida, guardada o no, para que pueda escuchar voces.
4. Siga el **Registro de pruebas**: muestra cada prueba mientras avanza (marcada, contestada, completada, sin respuesta) y, si se rechazó, el motivo.

La prueba funciona **aunque las llamadas de voz estén apagadas**, y cuenta para el límite de **cinco por hora** por agencia.

[[show: #sms-settings @ Voice calls > Call my own number | Muéstreme el botón de llamada de prueba]]

![La llamada de prueba: su mensaje, un número opcional y el registro de pruebas debajo](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

## Vigilar el saldo (Telnyx)

Con Telnyx configurado, la tarjeta **Telnyx balance & usage** muestra su **Balance**, los **Texts (30 days)** y **Calls (30 days)**, su costo y cuánto durará aproximadamente el saldo. Se pone roja cuando el saldo baja. Pulse **Actualizar** para ver las cifras más recientes. Recargue, o active la recarga automática en Telnyx, para que una tanda de llamadas de emergencia no se detenga a la mitad.

[[show: #sms-settings @ Balance | Muéstreme la tarjeta de saldo]]

## Dos interruptores, no uno

Nada se envía hasta que ambos estén activados, y están en lugares distintos:

- **Un operador configurado y elegido aquí.**
- **Las notificaciones activadas para la agencia**, en **Ajustes → Email settings** (**📬 Mail & notifications**). Si están apagadas, nada se envía ni suena aunque el operador esté configurado. Esta pantalla lo indica en rojo.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de agencia | Toda esta pantalla, para su agencia, incluidas las pruebas reales |
| Administrador de plataforma | Lo mismo; la pantalla se llama **Phone & carriers** y añade una pestaña **☎️ Toll-free line** para la línea de KiddieTrac |
| Director de centro, personal | Sin acceso. Los directores envían mensajes y llamadas desde **SMS broadcast** |

## Preguntas frecuentes y problemas

??? Un envío masivo dijo "enviado" pero nadie lo recibió. | Revise primero los dos interruptores: un operador elegido aquí y las notificaciones activadas en Ajustes → Email settings. Luego compruebe que el tipo de mensaje esté marcado y que las personas aceptaron recibir mensajes.
??? Un aviso rojo dice que la clave pública del webhook está vacía. | Telnyx firma cada mensaje que devuelve (respuestas, STOP, informes de entrega). Hasta que se guarde la clave, las respuestas y los STOP de las familias no se registran. Pegue la **Public Key** y pulse **Guardar**.
??? Mi llamada de prueba dice "Not placed". | Lea la línea del registro de pruebas: dice el motivo. Lo más común es un número de una cuenta suspendida o archivada, o una persona que eligió "No me llamen nunca".
??? "Tick "I control this number" first." | Marque la casilla bajo el número antes de pulsar el botón.
??? ¿Otra agencia puede ver nuestro saldo o claves? | No. Cada agencia tiene sus propios ajustes de operador y solo sus administradores pueden abrirlos o cambiarlos.
??? ¿Por qué falta un motivo en la pantalla SMS broadcast? | Está desmarcado en Motivos que pueden originar llamadas. Márquelo aquí y guarde.
??? Algo se ve mal en esta pantalla. | Lea primero la línea **Screen build** bajo el título: una copia antigua de la aplicación en caché se parece mucho a un error. Recargue la página para obtener la última versión.

## Pantallas relacionadas

[[open: #sms | Envío masivo de SMS]] [[open: #email-settings | Ajustes de correo]]

Vea también: [Envío masivo de SMS](sms-broadcast), [Llamadas con anuncios](voice-announcements), [Mensajes de texto y llamadas](texts-and-phone-calls), [Ajustes de correo](email-settings).
