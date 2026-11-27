---
title: Plantillas de correo — cambiar el texto
---
# Plantillas de correo — cambiar el texto

**Ajustes → Plantillas de correo** muestra cada correo que KiddieTrac envía para su agencia, agrupado por destinatario (padres, facturación, personal, cuentas, alertas para administradores). Aquí puede ver exactamente cómo se ve cada correo y reescribir su texto con su propia voz. Su logotipo, colores, pie de página y los datos que cada correo completa (nombres, fechas, importes) siguen siendo automáticos: usted solo cambia las palabras.

[[open: #email-templates | Abrir Plantillas de correo]]

## Encontrar un correo

1. Abra **Plantillas de correo**. La línea de arriba dice cuántos correos hay, cuántos se pueden reescribir y cuántos ya personalizó (**customised**).
2. Escriba en **Find an email…** para filtrar la lista por nombre, momento de envío, destinatario o asunto.
3. Lea cada fila: el nombre del correo, cuándo se envía y **To** (quién lo recibe).
4. Fíjese en las etiquetas: **● customised** (ya no lleva el texto estándar), **Preview only** (no se puede reescribir), **Edited per event** (se redacta en cada evento de Event RSVPs) y **also push / text** (editar aquí cambia solo el correo; la notificación push y el mensaje de texto conservan su texto estándar).

## Vista previa de un correo

1. Pulse **⋮** en la fila y luego **👁 Vista previa**.
2. El correo lo arma el mismo código que lo envía, con nombres y cifras de ejemplo. No se envía nada.
3. Cierre la vista previa con **×** o **Escape**.

## Reescribir un correo

1. Pulse **⋮** en la fila y luego **✏️ Editar**. El editor se abre en una ventana.
2. Cambie el texto de cada cuadro. Deje un cuadro vacío para conservar el texto automático.
3. Para añadir un dato que cambia con cada persona, ponga el cursor en un cuadro y haga clic en una etiqueta bajo **Merge tags — click to add to the field you are in**, por ejemplo `{{agency_name}}`. Conserve las llaves dobles.
4. Use la barra de herramientas para negrita, cursiva, subrayado, enlaces, imágenes, fuente y tamaño cuando el cuadro lo permita. En los cuadros sencillos, escriba `**negrita**` con dos asteriscos; un salto de línea empieza una línea nueva.
5. Pulse **👁 Preview** para verlo con sus cambios.
6. Pulse **📤 Send test…**, escriba su dirección y pulse **Enviar prueba**. Llega una copia marcada [Test] con datos de ejemplo. Deje marcado **use my unsaved changes** para probar antes de guardar.
7. Pulse **Save**. Aparece **Saved.** y la fila muestra **● customised**.

> 💡 **↺ Reset to default** junto a un cuadro restablece el texto original de KiddieTrac en ese cuadro.

> ⚠️ Al cerrar el editor con cambios sin guardar se pregunta **Discard your changes?** Elija **Seguir editando** para volver, o **Descartar cambios** para perderlos.

[[show: #email-templates @ Find an email… | Muéstreme el cuadro de búsqueda]]

## Bienvenida al personal — lo que es automático

Usted escribe el encabezado, la bienvenida, los primeros pasos y la despedida. KiddieTrac añade el resto para cada persona:

- sus **valores de cuidado**, tomados del texto **Our care** de la bienvenida del proveedor — edítelos allí y cambian ambos correos;
- un recorrido breve por las funciones del portal que usará;
- un enlace a **Help & guides**;
- a quién preguntar — el nombre y los datos de contacto de su director.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de agencia | Ver y reescribir cada correo editable, para su agencia |
| Administrador de plataforma | Lo mismo, más los correos marcados **KiddieTrac-wide**, cuyo texto se usa en todas las agencias |
| Director de centro, personal, padres | Sin acceso |

## Preguntas frecuentes y problemas

??? Guardé un cambio pero un correo sigue mostrando el texto anterior. | Los correos ya enviados conservan el texto con el que salieron. El próximo correo usa su texto nuevo.
??? Un correo no tiene ✏️ Editar. | Está marcado **Preview only** (su texto es fijo) o **Edited per event**: pulse **📅 Edit in Event RSVPs** en su lugar.
??? Una etiqueta muestra "Unknown tag — it will be sent exactly as typed". | La etiqueta está mal escrita. Haga clic en la etiqueta de la lista en lugar de escribirla.
??? ¿Cada centro puede tener su propio texto? | No. Las plantillas son por agencia.
??? ¿Dónde cambio el logotipo y los colores? | En la pantalla **🎨 Branding**. Las plantillas los usan automáticamente.
??? ¿Puedo evitar que se envíe un correo? | Aquí no. Los interruptores de los correos automáticos están en **Ajustes → Email settings** y **Ajustes → Agency notifications**.

## Pantallas relacionadas

[[open: #email-settings | Ajustes de correo]] [[open: #notification-settings | Notificaciones de la agencia]]

Vea también: [Ajustes de correo](email-settings), [Notificaciones de la agencia](agency-notifications), [Invitar a padres y personal](inviting-parents-and-staff), [Marca blanca](white-label-branding).
