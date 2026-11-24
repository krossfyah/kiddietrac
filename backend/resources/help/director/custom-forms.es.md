---
roles: agency_admin, platform_admin
title: Creador de formularios personalizados
category: Daily Operations
order: 68
---
# Formularios personalizados

Cree sin código formularios de inscripción, consentimiento, encuestas y opiniones. Los encontrará en **Administración → Formularios personalizados**.

## Crear un formulario

Haga clic en **+ Nuevo formulario** y complete:

- **Título** — lo que ve el personal en la lista
- **Descripción** — se muestra a los encuestados sobre las preguntas
- **Audiencia** — todas las familias / actualmente matriculadas / lista de espera / posibles clientes / solo personal
- **Estado** — Borrador (solo usted lo ve) / Publicado (activo para la audiencia) / Archivado

Luego añada campos. Se admiten diez tipos:

| Tipo | Úselo para |
|---|---|
| Texto corto | Nombres, direcciones, respuestas de una línea |
| Texto largo | Notas y descripciones de varias líneas |
| Correo electrónico | Formato de correo validado |
| Número | Entrada numérica |
| Fecha | Selector de fecha |
| Lista desplegable | Elegir uno de una lista |
| Casilla | Sí/no |
| Opción múltiple | Elegir uno con botones de opción |
| ✍️ Firma | Un panel de firma — los encuestados firman con el dedo o el ratón |
| 💳 Pago | Un cargo (indique el importe); se registra en la cuenta de la familia cuando se autoriza |

Cada campo tiene: etiqueta, interruptor de obligatorio, texto de ejemplo, texto de ayuda, opciones (para lista desplegable / botones de opción) y — para Pago — un **importe a cobrar**.

## Visibilidad condicional

Cualquier campo posterior al primero puede configurarse como «Mostrar solo cuando otro campo tenga un valor concreto». Elija el campo del que depende y deje el cuadro «es igual a» en blanco (coincide con cualquier valor no vacío) o escriba un valor concreto.

Ejemplo: preguntar «¿Por qué no participan?» solo cuando la opción «¿Asistirá su hijo?» está en «No».

## Respuestas

Haga clic en la etiqueta **N respuestas** en la fila de un formulario para abrir la ventana de respuestas. Cada respuesta muestra el nombre de quien la envió, la marca de fecha y hora y cada respuesta asociada a su etiqueta.

El botón verde **⤓ Descargar CSV** exporta todas las respuestas con una columna por campo, en el orden del esquema.

## Vista previa

Haga clic en **👁 Vista previa** en cualquier formulario para ver exactamente lo que verán los encuestados, incluidos su logotipo de marca blanca y su color principal en el encabezado. La vista previa funciona con formularios en borrador, así que puede revisar un formulario antes de publicarlo.

## Enviar un formulario por correo electrónico a los padres

Desde un formulario publicado, haga clic en **✉ Enviar a los padres por correo** para enviar el enlace del formulario a la audiencia correspondiente. El correo sale desde la dirección propia de su agencia (cuando el correo está activado y hay un remitente configurado en **Ajustes → Facturación → Ajustes**).

## Marca blanca

Los formularios — y la **Vista previa** del formulario — llevan su **logotipo y color principal** cuando la marca blanca está configurada. Consulte *Marca blanca y facturas*.

## Encontrar formularios y respuestas

Las listas largas se pueden buscar y paginar: use el **cuadro de búsqueda** sobre la lista de formularios para filtrar por título, y el **paginador** al pie de la ventana de respuestas para recorrer conjuntos grandes de respuestas.

## Lado de los padres

Los tutores ven los formularios publicados cuya audiencia coincide con el estado de su familia en la entrada **Su hijo → Formularios** de la barra lateral. Los formularios ya enviados muestran una insignia verde ✅. Los formularios con un campo de **firma** o de **pago** los solicitan antes de poder enviarse.
