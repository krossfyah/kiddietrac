---
title: Llamadas de anuncio
category: Communications
order: 41
roles: agency_admin, centre_director, platform_admin
---
# Llamadas de anuncio

Un teléfono que suena y dice en voz alta un mensaje breve. Pensado para esa clase de aviso que no se puede dar por leído: un cierre, una evacuación, un confinamiento, una enfermedad en el centro. Los padres que nunca abren la app contestan el teléfono.

**Difusión por SMS** es la misma pantalla. El interruptor **Enviar por** de la parte superior —**💬 Mensaje de texto** o **📞 Llamada de voz**— elige el canal; todo lo que hay debajo —el selector de destinatarios, el cuadro del mensaje, el registro de lo enviado— funciona igual para ambos.

[[open: #sms | Abrir la difusión por SMS]]

## Antes de que funcione

- [ ] **Telnyx** configurado y seleccionado en **Ajustes → Ajustes de operadores**.
- [ ] **Realizar llamadas de anuncio para esta agencia** marcado en la pestaña **Llamadas de voz** de esa pantalla. Empieza desactivado: administrar un sitio no es lo mismo que decidir que la agencia empiece a llamar por teléfono a la gente.
- [ ] Los **motivos** por los que quiere llamar marcados en **Motivos que pueden originar llamadas**.
- [ ] Notificaciones activadas para la agencia, en **Ajustes → Ajustes de correo**.
- [ ] Una llamada de prueba a su propio teléfono, para haber escuchado la voz.

Completar los ajustes del operador es tarea de un **administrador de la agencia**, no de un director; así que si el interruptor del canal está pero no suena nada, consúltelo con él en lugar de buscar un ajuste que no ve. Consulte [Ajustes de operadores](carrier-settings).

## Enviar una

1. Barra lateral → **Difusión por SMS** y luego ponga **Enviar por** en **📞 Llamada de voz**.
2. Elija un **motivo**: *Cierre, Evacuación, Confinamiento, Enfermedad en el centro, Otra emergencia* o *No es una emergencia*. Solo se ofrecen los motivos que su agencia permite. No es una etiqueta: decide a quién se puede llamar (más abajo).
3. Elija el público: por rol, por centro, por sala, por familia o toda la agencia. Para *por centro* y *por sala* debe elegir cuál; un público que no se puede acotar se rechaza en lugar de ampliarse discretamente a todos.
4. Escriba el mensaje. Hasta 800 caracteres: lo bastante largo para un aviso de cierre leído dos veces, lo bastante corto para que nadie cuelgue. Lo lee una voz sintética, así que escríbalo como lo diría.
5. **Realizar llamadas**. Una llamada no es silenciosa ni se puede deshacer, así que primero pide confirmación.

[[show: #sms @ Voice call | Muéstreme el interruptor de Llamada de voz]]

![Difusión por SMS con Enviar por en Llamada de voz: elija el público y el motivo, y luego Realizar llamadas](https://api.kiddietrac.com/help-img/sms-broadcast-voice.png)

La línea de resultado informa **llamando / omitidas / total**.

También puede llamar a la gente desde un **Anuncio**: marque **📞 Llamada de voz** al publicarlo y el anuncio se lee en voz alta al mismo público; consulte [Anuncios](announcements).

## Por qué motivos llama su agencia

Un administrador de la agencia lo elige en **Ajustes → Ajustes de operadores → Llamadas de voz → Motivos que pueden originar llamadas**. Las cinco emergencias vienen activadas por defecto; *No es una emergencia* está desactivado, de modo que un aviso rutinario no puede leerse por teléfono hasta que alguien decida que debe.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Muéstreme dónde se definen los motivos]]

## A quién se llama realmente

A menos gente que con un texto, a propósito. Se omite a una persona cuando:

- no tiene **número de teléfono** en su ficha;
- el motivo está **desactivado para la agencia**;
- ha **desactivado las llamadas para ese motivo** en sus propias opciones; consulte [Textos y llamadas telefónicas](texts-and-phone-calls);
- ella, o **cualquiera que use el mismo teléfono**, eligió **No llamarme nunca**. Eso abarca todos los motivos, también las emergencias; quien lo ha dicho suele tener un motivo;
- la llamada es **No es una emergencia** y nadie en ese teléfono ha aceptado ser contactado por ese motivo: el mismo consentimiento que necesita un texto;
- el mismo anuncio **ya se envió a ese teléfono** hace un momento. Las cuentas que comparten número reciben una llamada, no una cada una.

Cada omisión se lista con su motivo, así que «por qué no se llamó a ella» tiene respuesta.

## Qué pasa después de pulsar enviar

Una llamada es una conversación con el operador repartida en un minuto, no un solo evento. La lista bajo el formulario informa de cada llamada a medida que avanza:

- **en cola** — marcada.
- **contestada** — alguien descolgó, y solo entonces se dice el anuncio. No se dice nada mientras el teléfono sigue sonando.
- **completada / sin respuesta / ocupado** — cómo terminó.

Actualice la lista para ver cómo se asienta. Una llamada que muestra *sin respuesta* realmente no fue contestada.

## Pruébelo primero con usted mismo

En **Ajustes → Ajustes de operadores → Llamadas de voz**:

1. Escriba lo que debe decir la prueba en **Mensaje de prueba** y elija una voz; la prueba usa la voz elegida en ese momento, incluso antes de guardar.
2. Deje **Número al que llamar** en blanco para llamar al número de su propio perfil, o escriba otro número que controle y marque **Controlo este número**.
3. Pulse **Llamar a mi propio número** y conteste.

El **Registro de pruebas** bajo el botón sigue la llamada e indica el motivo cuando se rechaza una. Las pruebas funcionan aunque las llamadas de voz sigan desactivadas, y cuentan para el límite de cinco por hora.

[[show: #sms-settings @ Voice calls > Test log | Muéstreme el registro de pruebas]]

## Conviene saber

- Un director puede enviar una, porque un cierre o una evacuación es una decisión del sitio que toma quien está en el edificio. Los ajustes del operador que la respaldan siguen siendo de los administradores de la agencia.
- Mantenga el texto sencillo y la frase importante primero. La gente contesta a mitad de frase.
- El saldo y el uso de 30 días en la pantalla de ajustes del operador cubren tanto las llamadas como los textos.

## Preguntas

??? ¿Por qué falta «No es una emergencia» entre los motivos? | Su agencia no lo ha permitido. Un administrador de la agencia puede marcarlo en Ajustes de operadores → Llamadas de voz → Motivos que pueden originar llamadas.
??? Un padre dice que nunca quiere una llamada. | Puede elegir «No llamarme nunca» en sus propios ajustes de Textos y llamadas telefónicas. Se aplica a todo su teléfono, emergencias incluidas.
??? Dos padres comparten un teléfono. ¿Sonará dos veces? | No. Un teléfono recibe una llamada por anuncio.

Vea también: [Difusión por SMS](sms-broadcast), [Ajustes de operadores](carrier-settings),
[Textos y llamadas telefónicas](texts-and-phone-calls), [Recordatorios de cierre](closure-reminders),
[Anuncios](announcements).
