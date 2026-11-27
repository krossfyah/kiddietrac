---
title: Difusión por SMS
category: Communications
order: 40
---
# Difusión por SMS

Envíe un mensaje de texto puntual al personal o a las familias. Úselo para emergencias (cierres, evacuaciones,
clima severo) cuando un correo electrónico no es lo bastante urgente.

[[open: #sms | Abrir la difusión por SMS]]

## Texto o llamada

Un interruptor **Enviar por** — **💬 Mensaje de texto** o **📞 Llamada de voz** — aparece en la parte superior de la pantalla. Todo lo que está debajo — el
selector de destinatarios, el cuadro de mensaje, el registro de lo enviado — funciona igual en ambos casos.

Una llamada hace sonar el teléfono de la persona y lee en voz alta un mensaje breve, para el único tipo
de aviso del que no puede suponer que alguien lo haya leído. Es más limitada que un texto en cuanto a las personas a las que puede llegar.
Consulte [Llamadas de anuncio](voice-announcements).

## Antes de que se pueda enviar

- [ ] **Un operador configurado y seleccionado** en **Ajustes → Ajustes de operadores** (Twilio o Telnyx; la voz funciona con Telnyx). Las credenciales son tarea de un administrador de la agencia: si usted es director de centro y no se envía nada, consúltele. Consulte [Ajustes de operadores](carrier-settings).
- [ ] **Notificaciones activadas para la agencia**, en **Ajustes → Ajustes de correo**. Es un interruptor general de todos los canales: si está desactivado, no se envía nada, por bien configurado que esté el operador.
- [ ] **Difusiones por texto** marcadas en **Tipos de mensajes que envía esta agencia** en la pantalla de ajustes de operadores.

## Quién lo recibe

Cada persona debe:

- tener un **número de móvil** en su registro;
- haber **aceptado recibir mensajes de texto**: lo indica durante la incorporación, o más adelante con
  **Acepto recibir mensajes de texto** en su propio perfil (consulte
  [Mensajes de texto y llamadas telefónicas](texts-and-phone-calls));
- no haber desactivado las **Difusiones por texto** en sus propias opciones.

Quien no cumple los requisitos se omite. Antes de enviar, la línea bajo la audiencia dice a cuántos se puede llegar, por ejemplo "12 of 15 can be texted", y cuántos no tienen celular o no han dado su consentimiento. Pida a los demás que se suscriban desde la pestaña **Alertas por SMS**.
Una difusión que no llega a nadie lo dice con claridad en lugar de informar de un éxito.

## Envío

1. Abra **Difusión por SMS** en la pestaña **Enviar un mensaje**.
2. Elija la **Audiencia**:
   - **Por rol** — tutor / educador / director de centro / administrador de la agencia
   - **Por centro** (o proveedor, como lo llame su agencia) — todas las personas con un
     rol en el que elija
   - **Por sala** — los educadores asignados a esa sala, más los tutores de los
     niños matriculados actualmente en ella
   - **Toda la agencia** — todas las personas con cualquier rol activo
3. Para **por centro** o **por sala**, elija cuál en el selector que aparece. El
   mensaje no se enviará hasta que lo haga: un público que no se puede acotar se rechaza en lugar de
   ampliarse en silencio a todo el mundo.
4. Escriba su mensaje — hasta **300 caracteres**. Indique de parte de quién va (el nombre de su agencia)
   y ponga primero la frase importante; la gente lee la vista previa.
5. Haga clic en **Enviar difusión**.

[[show: #sms @ Send broadcast | Muéstreme el botón de enviar]]

## Números de teléfono compartidos

Algunas familias usan un solo teléfono para dos cuentas: dos padres con un mismo número, o un padre que
también es miembro del personal. KiddieTrac lo gestiona por **teléfono**, dentro de su agencia:

- **Una copia por teléfono.** Si tres cuentas del mismo público comparten un número, el
  teléfono recibe el mensaje una vez, no tres.
- **El consentimiento sigue al teléfono.** Si alguien con ese número aceptó recibir mensajes de texto, se puede
  enviar texto al teléfono; el mensaje se atribuye a la cuenta que dio su acuerdo.
- **STOP detiene el teléfono.** Un STOP desde un dispositivo detiene los mensajes a **todas** las cuentas con ese
  número en su agencia, no solo a la más reciente. START los reactiva todos.

## Cuando la gente responde

Una respuesta a una difusión no se pierde en un registro del operador. Llega al **Centro de mensajes** como un chat
de esa persona (marcado como hilo de SMS), se avisa al personal correspondiente, y una respuesta que escriba
en ese hilo sale hacia su teléfono como mensaje de texto. Las respuestas también aparecen en la pestaña **Respuestas**.

- **STOP** (también UNSUBSCRIBE, CANCEL, END, QUIT) detiene todos los mensajes de su agencia a ese teléfono.
- **START** los reactiva.
- **HELP** (o INFO) devuelve el nombre de su agencia y cómo contactarla.

## Costo

Su operador factura cada mensaje por segmento. Un mensaje sencillo de hasta 160 caracteres es
un segmento; los mensajes más largos, o con emojis o letras con acento, usan más. Con
Telnyx seleccionado, **Ajustes → Ajustes de operadores** muestra su saldo y lo que costaron los últimos 30
días.

## El registro

La pestaña **Enviados y recibidos** enumera los mensajes recientes en ambas direcciones,
con la hora en la zona horaria de su agencia, el estado de entrega y, cuando uno falló, el
motivo indicado por el operador. Cada mensaje también queda registrado en el registro de auditoría.

[[show: #sms @ Sent & received | Muéstreme el registro de mensajes]]

## Preguntas

??? ¿Por qué se omitió a un padre? | No tiene número de móvil, no ha aceptado recibir mensajes de texto, ha desactivado para sí mismo las difusiones por texto, o respondió STOP desde ese teléfono.
??? Un padre dice que recibió un solo mensaje para dos cuentas. ¿Es correcto? | Sí. Las cuentas que comparten un teléfono reciben una sola copia.
??? Un padre respondió STOP por error. | Pídale que envíe START al mismo número. Eso reactiva los mensajes para todas las cuentas de ese teléfono.

Vea también: [Llamadas de anuncio](voice-announcements), [Ajustes de operadores](carrier-settings),
[Mensajes de texto y llamadas telefónicas](texts-and-phone-calls).
