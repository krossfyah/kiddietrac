---
title: Ajustes de operadores — Twilio y Telnyx
category: Settings
order: 41
roles: agency_admin, platform_admin
---
# Ajustes de operadores — Twilio y Telnyx

**Ajustes → Ajustes de operadores.** Las cuentas a través de las cuales su agencia envía mensajes de texto y hace llamadas telefónicas. Se admiten dos operadores y dos canales funcionan sobre ellos (SMS y llamadas de anuncio por voz), por lo que la pantalla ya no lleva el nombre de ninguno de los dos.

[[open: #sms-settings | Abrir ajustes de operadores]]

Cada agencia configura aquí su propio operador. Los números, claves, saldo y opciones de una agencia nunca son visibles para otra, y solo un **administrador de agencia de esa agencia** puede guardarlos.

## Configuración, de principio a fin

- [ ] Introduzca las credenciales del operador y el número desde el que envía, y luego **Guardar**.
- [ ] Pulse **Probar conexión**: verifica las credenciales y no envía nada.
- [ ] Elija el operador en **Qué operador envía**.
- [ ] Marque los **tipos de mensajes que envía esta agencia** (abajo).
- [ ] Para Telnyx, pegue la **clave pública del webhook** para que se confíe en las respuestas y los informes de entrega.
- [ ] Envíese un mensaje de texto de prueba real y luego haga una llamada de prueba en la pestaña **Llamadas de voz**.
- [ ] Revise **Ajustes → Ajustes de correo**: las notificaciones deben estar activadas para la agencia.

## Dos interruptores, no uno

No se envía nada hasta que ambos estén activados, y están en lugares distintos. Un administrador que no lo sabe pasa mucho tiempo preguntándose por qué una difusión indicó «enviado» y ningún teléfono se movió.

1. **Hay un operador configurado y seleccionado aquí.**
2. **Las notificaciones están activadas para la agencia**, en **Ajustes → Ajustes de correo**. Es un interruptor maestro de todos los canales: si está apagado, nada se envía ni suena aunque haya un operador totalmente configurado. La pantalla lo indica en rojo cuando está apagado.

## Elegir qué operador envía

La tarjeta **Qué operador envía** está encima de las pestañas de operadores, porque es una decisión sobre el par y no pertenece a ninguno de los dos. Elija Twilio o Telnyx; el que está enviando actualmente aparece marcado en su propia pestaña.

[[show: #sms-settings @ Which carrier sends | Muéstreme la elección del operador]]

Ambas páginas se guardan juntas. Completar Telnyx no puede descartar en silencio un número escrito en la página de Twilio, pero sí puede configurar Telnyx con cuidado y olvidar cambiar a él, así que revise la marca de la pestaña antes de suponer.

## Pestañas Mensajes de texto y Llamadas de voz

La pantalla tiene dos pestañas, **Mensajes de texto** y **Llamadas de voz**. Recuerda en cuál estaba, de modo que guardar o probar en la pestaña de voz ya no lo devuelve a los mensajes.

[[show: #sms-settings @ Voice calls | Muéstreme la pestaña Llamadas de voz]]

## Tipos de mensajes que envía esta agencia

En la pestaña **Mensajes de texto**, **Tipos de mensajes que envía esta agencia** lista cada motivo por el que KiddieTrac podría enviar un mensaje de texto a alguien:

| Tipo | Qué es |
|---|---|
| Entradas y salidas | Cuando se registra la entrada o salida de un niño, y quién lo hizo |
| Recordatorios de entrada | Un niño no fue registrado a su llegada, o nunca se registró su salida |
| Anuncios | Anuncios, cuando también se envían por mensaje de texto |
| Difusiones de texto | Mensajes enviados a un grupo, una sala o todos desde la difusión por SMS |
| Respuestas del personal | Una respuesta del personal a un mensaje enviado al número de la agencia |

Desmarque un tipo y esta agencia no enviará por texto nada de ese tipo, sin importar lo que cada persona haya elegido. Luego cada persona puede desactivar los tipos que no desea; vea [Mensajes de texto y llamadas telefónicas](texts-and-phone-calls). El ajuste de la agencia siempre prevalece sobre el personal: una persona no puede reactivar lo que la agencia ha desactivado.

![Tipos de mensajes que envía esta agencia, en la pestaña Mensajes de texto](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Motivos que pueden originar llamadas

En la pestaña **Llamadas de voz**, **Motivos que pueden originar llamadas** decide para qué motivos se puede enviar una llamada de anuncio: *Cierre, Evacuación, Encierro, Enfermedad en el centro, Otra emergencia* y *No es una emergencia*. Las cinco emergencias están activadas por defecto; *No es una emergencia* está desactivado, de modo que un aviso rutinario nunca se lee por teléfono a menos que usted decida que así sea.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Muéstreme los motivos de llamada]]

![Motivos que pueden originar llamadas — las cinco emergencias están activadas, «No es una emergencia» está desactivado](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

La pantalla de difusión por SMS solo ofrece los motivos marcados aquí.

## Elegir una voz

Elija una voz en **Elija una voz…** y defina el **Idioma** (por ejemplo `en-US`, `en-GB`, `fr-CA`). Las voces neuronales suenan mucho más naturales y se facturan a una tarifa algo mayor. La llamada de prueba siguiente reproduce la voz elegida en ese momento, **guardada o no**, de modo que puede escuchar voces antes de decidirse por una.

## Probar una llamada

- **Mensaje de prueba**: lo que lee la llamada de prueba, hasta 600 caracteres.
- **Número al que llamar**: déjelo en blanco para llamar al número de su propio perfil. Si escribe otro número, se le pide marcar **Controlo este número**; la llamada anuncia qué agencia está llamando.
- **Llamar a mi propio número** realiza la llamada.

La prueba funciona **con las llamadas de voz desactivadas**, de modo que puede escucharla antes de activar el canal. Las pruebas cuentan hacia **cinco por hora** por agencia.

[[show: #sms-settings @ Voice calls > Call my own number | Muéstreme el botón de llamada de prueba]]

![La llamada de prueba: su propio mensaje, un número opcional y el registro de pruebas debajo](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

Debajo del botón, el **Registro de pruebas** muestra cada prueba a medida que avanza (marcada, contestada, completada, sin respuesta) y, cuando una prueba fue rechazada, **por qué** (por ejemplo, el número pertenece a una cuenta suprimida, o dijo *no me llamen*).

## Saldo y uso (Telnyx)

Con Telnyx seleccionado, una tarjeta de uso muestra su **saldo**, los mensajes y llamadas de los últimos 30 días, lo que costaron, y aproximadamente cuánto durará el saldo a ese ritmo. Se pone en rojo cuando el saldo es bajo. Los conteos provienen del propio registro de KiddieTrac de lo enviado, valorado a las tarifas actuales de Telnyx, así que la tarjeta es correcta aun cuando los informes de Telnyx van con unas horas de retraso.

[[show: #sms-settings @ Balance | Muéstreme la tarjeta de saldo]]

## La advertencia del webhook

Telnyx firma cada mensaje que nos devuelve: respuestas, STOP, informes de entrega, progreso de llamadas. Si la **clave pública del webhook** está en blanco, o la última llamada de Telnyx fue rechazada porque su firma no coincidía, aparece una advertencia roja en la parte superior de la página. Mientras no se corrija, las respuestas y los STOP de las familias no se registran. Copie la clave pública desde el portal de Telnyx (**Account settings → Keys & Credentials → Public key**) y guárdela aquí.

## Secretos

Las credenciales son de **solo escritura**. Una vez guardado, la pantalla le indica si hay un secreto almacenado, pero nunca lo muestra. Para cambiar uno, escriba el valor nuevo; para dejarlo como está, deje el campo vacío.

Tome los nombres de los campos al pie de la letra. Una clave de API de Telnyx no es un Account SID de Twilio, y un identificador que empieza por `OQ…` tampoco es un Account SID.

## Probar un mensaje de texto

- **Probar conexión** verifica las credenciales con el operador. No cuesta nada y no envía nada. El resultado se conserva en pantalla, de modo que «¿esto funcionó alguna vez?» se puede responder mañana.
- **Enviar una prueba real** envía un mensaje de texto **real**, o hace una llamada **real**, a un número que usted escribe. Se factura, nombra a su agencia y lleva STOP, y está limitado a los administradores de agencia y a cinco por hora por agencia. No se contacta a nadie más.

## Sello de versión

Debajo del título de la página se imprime una fecha de compilación. De otro modo, una copia desactualizada en caché de la aplicación es indistinguible de un error, así que cuando algo parezca estar mal, lea primero esa línea.

## Preguntas

??? Una difusión dijo «enviado» pero nadie la recibió. | Revise primero los dos interruptores: un operador seleccionado aquí, y las notificaciones activadas en Ajustes → Ajustes de correo. Luego verifique que el tipo de mensaje esté marcado y que las personas hayan aceptado recibir mensajes de texto.
??? Mi llamada de prueba dice «no realizada». | Lea la línea del Registro de pruebas bajo el botón: indica el motivo. Las razones más comunes son un número que pertenece a una cuenta suprimida o archivada, o una persona que eligió «No llamarme en absoluto».
??? ¿Puede otra agencia ver nuestro saldo o nuestras claves? | No. Cada agencia tiene sus propios ajustes de operador, y solo los administradores de esa agencia pueden abrirlos o cambiarlos.
??? ¿Por qué falta un motivo en la pantalla de difusión por SMS? | Está desmarcado en Motivos que pueden originar llamadas. Márquelo aquí y guarde.

Vea también: [Difusión por SMS](sms-broadcast), [Llamadas de anuncio](voice-announcements),
[Mensajes de texto y llamadas telefónicas](texts-and-phone-calls), [Correo y resúmenes](email-and-digests).
