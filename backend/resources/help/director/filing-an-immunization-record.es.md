---
title: Archivar un registro de vacunación
---
# Archivar un registro de vacunación

La pantalla **Vacunas** muestra qué niños tienen una dosis atrasada o por vencer, los
registros que entregaron las familias y el personal, y el calendario con el que se mide a
cada niño. Archivar una cartilla y registrar lo que dice es una sola acción: las dosis que
marca sacan al niño de la lista de atrasados. Ábrala desde la barra lateral:
**Operaciones** → **Vacunas**.

[[open: #immunizations | Abrir Vacunas]]

## Las cuatro pestañas

- **Debido a la edad de** — todos los niños, con un estado calculado a partir de su fecha de nacimiento según su calendario.
- **Solo atrasados** — la misma lista, solo con los niños que tienen una dosis atrasada.
- **Registros recibidos** — cada registro entregado, marcado **Padre/Madre** o **Personal**, con **Enviado por**.
- **Calendario predeterminado** — con lo que se mide a cada niño. Cambiar una fila vuelve a calcular el estado de todos los niños. Vea [Calendario de vacunas «Debido a la edad de»](immunization-due-age).

## Archivar un registro de un niño

1. Pulse **↑ Subir un registro**. Está en todas las pestañas, y también en el expediente del niño → pestaña **Vacunación**, donde el niño ya está elegido.
2. Elija al niño.
3. Agregue **el registro**: un PDF o una foto de la cartilla, de hasta 10 MB. Una foto del teléfono normalmente pesa mucho menos.
4. En **What does this record cover?**, marque cada dosis que muestra la cartilla, según el calendario de su agencia. La fecha es opcional: déjela en blanco si la cartilla no es clara y la dosis igual cuenta como registrada.
5. Si la cartilla muestra algo que no está en su calendario (una familia que se mudó de provincia, una vacuna de viaje), agréguelo debajo: escriba la vacuna y la etiqueta de la dosis.
6. Agregue **Notas** para lo que el registro no dice: una línea borrosa, una dosis que la clínica está confirmando.
7. Pulse **Archivar registro**. El estado del niño se actualiza de inmediato.

Una dosis que ya está en el expediente del niño **se omite, no se duplica**, así que volver a
archivar una foto más clara de la misma cartilla nunca duplica el historial.

## Qué pasa al archivarlo

- El documento se archiva en el expediente del niño, así que también aparece en su pestaña **Documentos**.
- Cada dosis marcada se registra en el niño, **vinculada al documento del que se leyó**.
- Si las marcas cubren todo lo que el calendario espera para su edad, el niño deja de estar atrasado.
- **Se envía un correo electrónico a la oficina**: sus otros directores y los administradores de la agencia reciben qué niño, quién lo archivó, qué dosis se registraron, cuáles se omitieron por estar ya registradas, sus notas y **lo que sigue pendiente después**. A usted no se le envía correo por lo que archiva.
- Se avisa al equipo en la aplicación, y se escribe una fila de auditoría **con el nombre de cada dosis**.

## Agregar detalles a un registro que llegó sin nada registrado

La mayoría de las cartillas vienen de los padres. Un padre puede marcar lo que cree que
muestra, pero eso solo le orienta a usted: no registra ninguna dosis. Hasta que alguien del
centro lo lea, el registro muestra **Detalles pendientes**, con lo que indicó quien lo subió
marcado *not confirmed*.

1. Búsquelo en **📄 Registros archivados** del niño, o en **Vacunas → Registros recibidos** para los de todos.
2. Pulse **➕ Añadir detalles**.
3. Elija qué es el documento: **Dosis administradas** (una cartilla de vacunación o un comprobante de la clínica) o **Formulario de exención** (una declaración de creencias o una exención médica; vea [Exenciones de vacunación](immunization-exemptions)).
4. Para las dosis, marque cada dosis que muestra, con fecha si la cartilla la tiene. Lo que marcó quien lo subió aparece ya marcado e indicado *uploader ticked this*; revise cada una.
5. Pulse **Registrar dosis** (o **Registrar exenciones**).

Cuando un registro ya tiene dosis, **Añadir detalles** deja de ofrecerse en él; corrija una
dosis en el expediente del propio niño.

[[show: #immunizations @ Records received | Mostrarme Registros recibidos]]

## La pestaña Vacunación del niño

La pestaña Vacunación de un niño tiene dos subpestañas: **💉 Lo que corresponde** (el
calendario y lo pendiente) y **📄 Registros archivados** (las cartillas, con las dosis leídas
de cada una y las notas). **Ver** abre un registro en el panel de documentos del portal, con
**Imprimir** en su encabezado; no abre una pestaña nueva. Vea
[Abrir e imprimir documentos](opening-and-printing-documents).

## Quién puede hacer qué

- Los **administradores de la agencia y los directores de centro** del centro del niño pueden archivar un registro, registrar sus dosis, agregar detalles a uno que llegó vacío y editar o eliminar una dosis.
- **Los padres pueden enviar un registro** e indicar lo que creen que muestra, y nada más. Leer una línea borrosa como «DTaP-IPV-Hib, 2.ª dosis» es una decisión que le corresponde al centro.
- Los **educadores** no gestionan las vacunas; ven «Immunizations are managed by the centre director.»

## Preguntas frecuentes y solución de problemas

??? Un padre envió una cartilla pero el niño sigue atrasado. | Solo las dosis registradas a partir de ella quitan la marca. Ábrala en Registros recibidos, pulse ➕ Añadir detalles y registre las dosis.
??? Dice «No children to file against». | No se encontraron niños inscritos en esta agencia. Revise que los niños estén inscritos y que usted esté en la agencia correcta.
??? La subida falló. | El archivo debe ser un PDF o una foto de hasta 10 MB. Pruebe con una foto más pequeña, o actualice la página e inténtelo de nuevo.
??? Dice «Vaccine name required». | Cuando agrega una vacuna que no está en su calendario, escriba su nombre antes de guardar.
??? ¿Puedo eliminar un registro? | Un padre no puede. El personal quita un registro archivado desde la pestaña Documentos del niño, lo que queda auditado. Corrija una dosis en el expediente del propio niño; el Informe de vacunación solo lee.

## Relacionado

[[open: #immunizations | Vacunas]]
[[open: #reports | Informes]]

- [Exenciones de vacunación](immunization-exemptions)
- [Recordatorios de vacunación](immunization-reminders)
- [Informe de vacunación](immunization-report)
- [Calendario de vacunas «Debido a la edad de»](immunization-due-age)
- [El expediente del niño](child-record-tabs)
- [Abrir e imprimir documentos](opening-and-printing-documents)
