---
title: Archivar un registro de vacunación
category: Compliance
order: 33
roles: agency_admin, centre_director, platform_admin
---
# Archivar un registro de vacunación

Archivar la cartilla y registrar lo que dice solían ser dos tareas en dos pantallas, y por eso había registros archivados durante semanas mientras el niño seguía figurando como atrasado. Ahora es una sola acción.

## Dos entradas, un solo asistente

- **Vacunas** (barra lateral) → **↑ Subir un registro**, en cada pestaña. Se abre con un selector de niño: «súbalo por él» es lo que uno piensa al mirar una fila atrasada.
- **El registro de un niño → pestaña Vacunación** → el mismo botón, ya fijado a ese niño.

Ambos abren el mismo cuadro de diálogo y hacen lo mismo.

## Qué pregunta el cuadro de diálogo

**El registro** — un PDF o una foto de la cartilla, de hasta 10 MB. Una foto de teléfono normalmente pesa mucho menos.

**¿Qué cubre este registro?** — marque cada dosis que muestra la cartilla, según el calendario de su agencia.

- **La fecha es opcional.** Déjela en blanco cuando la cartilla no sea clara; la dosis cuenta igualmente como registrada. Un registro archivado no es lo mismo que una dosis registrada, y solo las marcas sacan a un niño de la lista de atrasados.
- **Lo que la cartilla muestre y no esté en su calendario se puede añadir debajo**: escriba la vacuna y la etiqueta de la dosis. Una familia que se mudó de provincia, o una vacuna de viaje, no encaja en un calendario local, y negarse a registrarla haría la cartilla menos útil que el papel en el que llegó.
- Una dosis que ya está en el registro del niño se **omite, no se duplica**. Volver a archivar una foto más clara de la misma cartilla es algo normal y no debe duplicar el historial.

**Notas** — cualquier cosa que el registro no diga: una línea borrosa, una dosis que la clínica está confirmando.

## Qué ocurre al archivarlo

- El documento se archiva en el registro del niño, así que también aparece en su pestaña **Documentos**.
- Cada dosis marcada se registra para el niño, **vinculada al documento del que se leyó**, de modo que la pregunta «¿se introdujo la 2.ª dosis de DTaP a partir de esa cartilla?» tiene respuesta.
- El estado del niño se actualiza de inmediato. Si las marcas cubren todo lo que el calendario espera para su edad, deja de estar atrasado.
- **Se envía un correo electrónico a la oficina**: sus demás directores y administradores de agencia reciben el aviso de qué niño, quién lo hizo, qué dosis se registraron, cuáles se omitieron por ser ya conocidas, sus notas y **lo que sigue pendiente después**: la diferencia entre «resuelto» y «resuelto, siga insistiendo». No recibe correo por su propio archivado.
- Se avisa al equipo en la aplicación.
- Se escribe una fila de auditoría **con el nombre de cada dosis**, no un recuento.

## Un registro que llegó sin nada registrado

La mayoría de las cartillas vienen de los padres, que fotografían la cartilla y la envían. Un padre puede marcar lo que cree que muestra, pero eso es solo una indicación para usted: no registra ninguna dosis. Hasta que alguien del centro lo lea, el registro muestra **Detalles pendientes**, con lo que indicó quien lo subió marcado como *no confirmado*.

Para completar uno, sin archivar una segunda copia de la misma cartilla:

1. Búsquelo en **📄 Registros archivados** en el niño, o en **Vacunas → Registros recibidos** para todos. Esa pestaña marca cada registro como **Padre/Madre** o **Personal**.
2. Pulse **➕ Añadir detalles**.
3. Elija qué es el documento:
   - **Dosis administradas**: una cartilla de vacunación o un impreso de la clínica. Marque cada dosis que muestre, con fecha si la cartilla la tiene. Lo que marcó quien lo subió aparece premarcado y señalado como *marcado por quien lo subió*; revise cada una.
   - **Formulario de exención**: una declaración de creencias o una exención médica. Vea [Exenciones de vacunación](immunization-exemptions).
4. **Registrar dosis** (o **Registrar exenciones**).

Las dosis quedan vinculadas al registro del que se leyeron, y lo que ya estaba registrado se omite en lugar de duplicarse. Cuando un registro ya tiene dosis asociadas, deja de ofrecerse **Añadir detalles**; corrija la dosis en el registro del propio niño.

[[show: #immunizations @ Records received | Muéstreme Registros recibidos]]

## La pestaña Vacunación

La pestaña Vacunación de un niño tiene dos subpestañas:

- **💉 Qué corresponde** — el calendario y lo que está pendiente.
- **📄 Registros archivados** — las propias cartillas, con las dosis leídas de cada una y las notas. Quien acaba de archivar una cartilla busca esta.

**Ver** abre un registro en el panel de documentos del portal, con **Imprimir** en su encabezado; no abre una pestaña nueva ni saca al usuario de la aplicación. Vea [Abrir e imprimir documentos](opening-and-printing-documents).

## Quién puede hacer qué

- **Los administradores de agencia y los directores de centro** del centro del niño pueden archivar un registro, registrar las dosis que contiene y añadir detalles a uno que llegó vacío.
- **Los padres pueden enviar un registro** y decir qué creen que muestra, y nada más. Decidir que una línea borrosa significa «DTaP-IPV-Hib, 2.ª dosis» es un juicio clínico y corresponde al centro. Lo que suben llega aquí para que usted lo lea y lo marque.

## Conviene saber

- Un registro archivado por un padre sigue mostrando al niño como atrasado hasta que alguien registre las dosis que contiene. Ese es el momento en que se borra un indicador de cumplimiento, y es deliberado.
- Corrija los datos de origen en el registro del propio niño. El [Informe de vacunación](immunization-report) es solo de lectura.
- Un padre no puede eliminar un registro archivado. Elimine uno desde la pestaña Documentos del niño, lo cual queda auditado.

Vea también: [Exenciones de vacunación](immunization-exemptions),
[Recordatorios de vacunación](immunization-reminders), [Informe de vacunación](immunization-report),
[Vacunas pendientes por edad](immunization-due-age), [El registro del niño](child-record-tabs),
[Abrir e imprimir documentos](opening-and-printing-documents).
