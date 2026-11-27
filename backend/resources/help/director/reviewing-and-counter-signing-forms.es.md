---
title: Revisar, contrafirmar y devolver formularios
category: Administration
order: 68
roles: agency_admin, platform_admin
---
# Revisar, contrafirmar y devolver formularios

**Participación → Gestor de formularios → Completado.** Todos los formularios que alguien ha firmado y lo que
la agencia ha hecho con ellos. Un formulario completado tiene dos respuestas posibles: **aceptarlo** contrafirmándolo, o
**devolverlo** con una nota que indique qué hay que corregir.

[[show: #forms-manager @ Completed > Counter-signed | Muéstreme la columna Contrafirmado]]

## Leer la tabla

Junto al formulario, el firmante y la hora en que firmó (en hora de la agencia):

- **Copia enviada por correo**: si la copia completada llegó a la dirección definida en el formulario.
  **Sin configurar** significa que el formulario no tiene dirección de envío.
- **Devuelto**: **Esperando al firmante** mientras espera una corrección (con un número
  si se ha devuelto más de una vez), **Reenviado** una vez que han enviado uno nuevo.
- **Contrafirmado**: la fecha y quién firmó, o **Esperando revisión**. «Esperando revisión»
  es un aviso: es un formulario que nadie en la agencia ha revisado todavía.
- **Sincronizado**: si el formulario completado está en el registro del propio firmante. **✓ Archivado**
  nombra el registro. **Sin archivar** suele significar que la cuenta no existía cuando se firmó
  el formulario. Pulse **Sincronizar ahora** en la barra roja sobre la tabla para corregirlo. **Huérfano**
  significa que se archivó en una cuenta que ya no existe, y sincronizar no lo arreglará.

## Revisar y contrafirmar

Elija **⋮ → Revisar y contrafirmar**. Funciona en dos pasos, en este orden, para que una
contrafirma signifique siempre que alguien leyó realmente el formulario.

1. **Revisar.** El formulario completado se abre a tamaño completo. En **Añadir información** puede escribir
   una nota. Aparece en la página de contrafirma y en el correo. Para cambiar el propio documento:
   - en un formulario rellenable, **✎ Editar el formulario** lo reabre con las respuestas del firmante, para que
     pueda corregir o completar los mismos campos que usó.
   - en cualquier otro PDF, **✎ Añadir información al formulario** le permite escribir sobre la página.

   Cada campo modificado se lista por su nombre en la contrafirma, de modo que queda claro qué
   cambió la agencia. La copia original del firmante nunca se sobrescribe.
2. **Contrafirmar.** Pulse **Siguiente: contrafirmar →**, revise su nota y luego
   **✍️ Añadir su firma**. Su firma se muestra antes de enviar nada. Deje marcado
   **Enviar por correo la copia contrafirmada a ambas partes** para enviarla al firmante y a
   la dirección del formulario. Pulse **Completar contrafirma**.

Su firma va en una nueva página final. No se altera nada de lo que firmó el firmante. Después,
el menú ofrece **Ver el PDF contrafirmado**. La confirmación le dice a quién
llegó realmente el correo. Si indica que no se pudo enviar nada por correo, revise los ajustes
de entrega de su agencia.

> **Nota:** Un formulario solo se puede contrafirmar una vez.

## Devolver para corrección

Cuando falta algo o hay un error, elija **⋮ → Devolver al firmante**, o pulse
**↩️ Devolver para corrección** durante la revisión.

1. En **Qué hay que cambiar**, indique exactamente qué corregir. La nota es obligatoria y es
   lo único que se les comunica, así que sea específico.
2. Pulse **Devolverlo**.

Lo que ocurre después:

- El formulario vuelve a quedar pendiente para ellos, **con sus respuestas todavía dentro**. Su
  nota se muestra en el formulario en su lista **Formularios por firmar**, bajo «Devuelto para corrección».
- Reciben un correo y una notificación en la aplicación. Si su cuenta no tiene dirección de correo,
  el cuadro de diálogo se lo indica antes de enviar, y el resultado dice **Registrado, no enviado por correo**.
- El envío que devolvió se conserva exactamente como estaba. Su corrección llega como uno
  nuevo, y la columna **Devuelto** cambia a **Reenviado**.

Puede devolver un formulario tantas veces como requiera una corrección.

> **Importante:** Devolver y contrafirmar se contradicen, así que solo puede hacer una cosa a la vez. Un formulario contrafirmado no se puede devolver, y un formulario que espera al firmante no se puede contrafirmar hasta que lo reenvíe.

## Otras acciones sobre un formulario completado

- **Ver formulario** y **Descargar formulario completado**.
- **Enviar por correo la copia a …** envía la copia completada a la dirección definida en el formulario,
  o la envía de nuevo.
- **Eliminar esta aprobación** retira la firma. El PDF completado y la copia en
  el registro del firmante se eliminan, y el formulario vuelve a quedar pendiente para ellos. Úsela
  cuando firmó la persona equivocada o alguien firmó lo que no debía. Queda registrado en el registro
  de auditoría y no se puede deshacer.

## Preguntas frecuentes

??? ¿Dónde está el formulario corregido? | Llega como una nueva fila completada. La fila que devolvió cambia a Reenviado.
??? ¿Por qué un formulario completado no está en los Documentos de la familia? | Su columna Sincronizado dirá Sin archivar. Pulse Sincronizar ahora sobre la tabla.
??? ¿Por qué no puedo contrafirmar este formulario? | Se devolvió y está esperando al firmante. Contrafirme su reenvío en su lugar.
??? ¿Qué se imprime debajo de cada firma? | Firmado: la fecha y la hora en la zona horaria de su agencia, la dirección IP de quien firma y la ubicación aproximada obtenida de esa dirección (ciudad, provincia o estado, país). El servidor los registra en el momento de la firma (la persona nunca los escribe) y aparecen en la pantalla, en el PDF y en las copias enviadas por correo, tanto para quien firma como para su contrafirma. Los formularios firmados antes del 27 de nov. de 2026 indican que la IP y la ubicación no se registraron.

Véase también: [Gestor de formularios y paquetes de formularios](forms-manager), [Solicitar archivos](requesting-files),
[Flujos de documentos](doc-workflows).

## Formularios que no necesitan contrafirma

No todos los formularios necesitan la firma de la oficina. En el **Gestor de formularios**, al añadir o editar un formulario
(incluso en la biblioteca de formularios y en los paquetes de varios formularios), desactive **Requiere contrafirma**.
Sus copias firmadas muestran **No requerido** en Completado y nunca se incluyen en los recordatorios.

## Recordatorios diarios para contrafirmar

Pestaña **Ajustes → Notificaciones → ✍️ Contrafirma**. Los administradores y directores reciben un
correo con los formularios firmados que aún los esperan, según su programación: a diario, en días laborables,
cada semana o cada pocos días, a la hora que elija. Los directores solo ven formularios de su propio
proveedor; si un proveedor no tiene director, sus formularios van a los administradores. También puede activar una notificación
de campana o una push, usar **Vista previa** para ver el correo y **Enviar prueba…** a una dirección que escriba.
