---
title: Creador de formularios personalizados
---
# Creador de formularios personalizados

**Administración → Formularios personalizados** le permite crear sus propios formularios en
línea, pregunta por pregunta: inscripción, consentimiento, encuestas, comentarios e incluso
un formulario que cobra un pago. Las familias los completan desde su portal de padres y cada
respuesta vuelve aquí, lista para leer o descargar.

[[open: #admin-forms | Abrir Formularios personalizados]]

> 💡 Los formularios personalizados son formularios que usted **crea** en pantalla. Para que firmen un PDF ya hecho, use el **Gestor de formularios**.

## Crear un formulario nuevo

1. Abra **Formularios personalizados** y pulse **+ Nuevo formulario**.
2. Escriba el **Título del formulario**, por ejemplo «Permiso para la excursión».
3. Escriba la **Description (shown to parents above the form)**: para qué es el formulario y cuándo hay que entregarlo.
4. Elija la **Audiencia**: **Todas las familias**, **Actualmente inscritos**, **Lista de espera**, **Prospectos** o **Solo personal**.
5. Deje el **Estado** en **Draft (only you see it)** mientras lo prepara.
6. En **Campos** ya hay una primera pregunta. Escriba su texto en **Field label (visible to respondent)** y elija su tipo.
7. Agregue más preguntas con los botones bajo la lista: **+ Short text**, **+ Long text**, **+ Email**, **+ Number**, **+ Date**, **+ Dropdown**, **+ Checkbox**, **+ Multiple choice**, **+ ✍️ Signature** y **+ 💳 Payment**.
8. Pulse **Crear formulario**. El formulario aparece en la lista con la etiqueta **DRAFT**.

### Configurar cada pregunta

- **Obligatorio**: márquelo cuando la pregunta deba responderse.
- **Placeholder (optional)** y **Help text (optional)**: una pista dentro y debajo de la casilla.
- **Options (one per line)**: para **Lista desplegable** y **Opción múltiple**. Hacen falta al menos dos opciones; el formulario no se guarda con menos.
- **Amount to charge ($)**: para **💳 Payment**. Cuando la familia envía el formulario, el cargo se registra en su cuenta.
- **✍️ Signature** le da a la persona un recuadro para firmar con el dedo o el ratón. La firma se guarda con sus respuestas.
- Las flechas pequeñas suben o bajan una pregunta; la **×** roja la quita.

### Mostrar una pregunta solo cuando hace falta

A partir de la segunda pregunta, cada una tiene **🔀 Show only when another field has a specific value**.

1. Márquelo.
2. En **— pick a field —**, elija la pregunta anterior de la que depende.
3. Escriba la respuesta que debe mostrarla, o deje **equals (leave blank = any non-empty)** vacío para mostrarla siempre que la pregunta anterior tenga respuesta.

Por ejemplo, pregunte «¿Cuál es el motivo?» solo cuando «¿Asistirá su hijo?» tenga la respuesta «No».

## Publicar un formulario y avisar a las familias

1. Haga clic en la fila del formulario (o **⋮ > Edit form**).
2. Cambie el **Estado** a **Published (live for audience)**.
3. Pulse **Guardar cambios**. La fila muestra **LIVE** y las familias de la audiencia ven el formulario en su portal.
4. Para enviarles el enlace por correo, pulse **⋮ > Email to parents** y confirme. Un mensaje verde indica a cuántos padres se envió.

> ⚠️ Solo un formulario publicado se puede enviar por correo. Con un borrador aparece: «Publish this form first — only published forms can be emailed to parents.»

Pulse **⋮ > Vista previa** cuando quiera, incluso en un borrador, para ver el formulario tal
como lo verán las familias, con su logotipo y colores si tiene marca blanca.

## Leer las respuestas

1. Pulse la etiqueta de respuestas en la fila del formulario (por ejemplo **3 responses**) o **⋮ > View responses**.
2. Cada respuesta muestra quién la envió y cuándo, y luego cada respuesta junto a su pregunta. Las firmas se ven como imagen; un pago se ve como un importe **authorised**.
3. Pulse **⤓ Download CSV** para guardar todas las respuestas en una hoja de cálculo, una columna por pregunta.

Use el cuadro **🔍 Search forms…** sobre la lista para buscar un formulario por su título.
Las listas largas se dividen en páginas de ocho.

## Retirar o eliminar un formulario

- Para dejar de recibir respuestas pero conservar el formulario: edítelo, ponga el **Estado** en **Archived (hidden, kept for records)** y pulse **Guardar cambios**.
- Para quitarlo: **⋮ > Delete form** y confirme. Las respuestas ya recibidas se conservan.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de la agencia | Crear, publicar, enviar por correo, previsualizar y eliminar formularios; leer y descargar todas las respuestas. |
| Auditor | Tiene una entrada **Formularios** de solo lectura en su menú para los mismos formularios. Vea [Formularios personalizados para auditores](forms-auditor-view). |
| Padre o madre | Completa en su portal los formularios publicados para su familia. |
| Director de centro, educador | Sin acceso al creador. |

## Preguntas frecuentes y cuando algo falla

??? Una familia no ve mi formulario. | Compruebe que diga LIVE y que la audiencia corresponda: una familia en lista de espera no ve un formulario para Actualmente inscritos.
??? «Field ... needs at least 2 options.» | Una pregunta de Lista desplegable u Opción múltiple tiene menos de dos opciones. Agregue otra línea en Options (one per line).
??? «Title is required.» | Escriba un título antes de pulsar Crear formulario.
??? ¿Puedo cambiar un formulario después de que lo hayan respondido? | Sí. Las respuestas dadas se conservan con los textos que tenían. Evite quitar preguntas ya respondidas si las necesita en el CSV.
??? ¿Dónde veo un pago? | El importe se registra en la cuenta de la familia al enviar. Revíselo en la facturación de la familia.
??? ¿Publicar envía un correo? | No. Publicar hace visible el formulario en el portal. Solo ⋮ > Email to parents envía un correo.

## Pantallas relacionadas

[[open: #forms-manager | Gestor de formularios]] [[open: #edocuments | Documentos electrónicos]]

- [Gestor de formularios y paquetes de formularios](forms-manager)
- [Documentos electrónicos — los PDF que firma cada familia](edocuments)
