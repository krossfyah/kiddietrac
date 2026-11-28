---
title: Estado de resúmenes de IA — revisar el resumen diario de cada niño
---
# Estado de resúmenes de IA — revisar el resumen diario de cada niño

Cada tarde KiddieTrac escribe un breve **resumen diario** de cada niño con lo que el personal registró ese día (comidas, siestas, estado de ánimo, actividades, observaciones). Los padres lo ven en su pantalla de inicio como **✨ YOUR DAILY DIGEST**. **Operaciones → Estado de resúmenes de IA** muestra, niño por niño, si el resumen de hoy ya está escrito, y le permite escribir uno que falte o volver a escribirlo después de corregir los registros.

[[open: #digest-status | Abrir Estado de resúmenes de IA]]

## Revisar los resúmenes de hoy

1. Abra **Estado de resúmenes de IA**. La página se abre con la fecha de hoy en la zona horaria de su agencia, esté donde esté su dispositivo.
2. Lea los cuatro recuadros de arriba: **Niños** (los niños de sus centros), **Generado** (con el porcentaje de cobertura), **Pendiente** (hay registros, el resumen aún no está escrito) y **Sin eventos** (no hay nada registrado, nada que resumir).
3. La línea bajo el título dice **IA activada** o **AI disabled (templated fallback)**. Con la IA desactivada, las familias reciben un resumen más sencillo basado en una plantilla.
4. Recorra la tabla. Cada fila muestra el **Child**, el número de **Events** registrados, la etiqueta de **Status** (**GENERATED**, **PENDING** o **NO EVENTS**), la hora **Generated at** (en la hora de su agencia) y una **Preview** del texto.

> ℹ️ Los resúmenes se escriben una vez, por la tarde. Durante el día la mayoría de los niños muestran **PENDING**: es normal.

## Ver otro día

1. Haga clic en el cuadro de fecha en la esquina superior derecha de la página.
2. Elija el día. La página se recarga con los resúmenes de ese día.
3. Pulse **Actualizar** para volver a leer la lista, por ejemplo después de que una educadora agregó registros que faltaban.

## Escribir un resumen ahora, o de nuevo

1. Busque al niño en la tabla.
2. Pulse **Generar** (aún no hay resumen) o **Regenerar** (ya existe uno). El botón muestra **Working...** mientras la IA escribe.
3. Se abre una ventana titulada **Digest for** y el nombre del niño, con el texto nuevo. Léalo. Los administradores de la plataforma también ven el modelo de IA y la cantidad de tokens sobre el texto; el personal de la agencia solo ve el resumen.
4. Pulse **Cerrar**. La tabla se actualiza y la fila muestra **GENERATED**.

> ⚠️ **Regenerar** reemplaza el resumen que ve la familia. Si un padre ya leyó el anterior, verá el texto nuevo la próxima vez que abra la aplicación.

[[show: #digest-status @ Refresh | Muéstreme el botón Actualizar]]

## Quién puede hacer qué

| Rol | Qué ve |
|---|---|
| Director de centro | Los niños de sus propios centros |
| Administrador de agencia | Los niños de todos los centros abiertos de la agencia |
| Administrador de plataforma | Lo mismo, para la agencia en la que trabaja |
| Educadoras y padres | Sin acceso. Los padres leen el resumen en su pantalla de inicio |

No aparecen los centros cerrados ni los niños que ya se fueron.

## Preguntas frecuentes y problemas

??? El botón Generar está gris. | El niño no tiene eventos registrados ese día (**No events to summarize**). Agregue primero los registros del día y luego pulse **Generar**.
??? Un niño muestra PENDING tarde por la noche. | Puede que el proceso de la tarde aún no llegara a él, o que la IA no respondiera. Pulse **Generar** para ese niño.
??? Veo "AI digests are not available right now. Families still get the standard daily summary." | El servicio de IA no está configurado o activado para su agencia en este momento. No se pierde nada: las familias igual reciben el resumen diario estándar. Abra un ticket de soporte si esperaba resúmenes de IA.
??? Veo "The digest could not be written this time. If there were no updates logged for this child that day, there is nothing to summarise; otherwise please try again later." | Si no se registró nada para el niño ese día, agregue primero los registros. Si no, el servicio de IA estaba ocupado: espere unos minutos y pulse **Generar** otra vez. Si sigue fallando, abra un ticket de soporte; las familias igual reciben el resumen de plantilla.
??? "No children in scope". | No se encontraron niños inscritos para esa fecha en sus centros. Elija otra fecha o revise las inscripciones.
??? "Could not load digest status" con "Please refresh and try again." | Recargue la página. Si sigue pasando, abra un ticket de soporte.
??? ¿Regenerar envía una nueva notificación al padre? | No. El resumen se actualiza en su lugar; el padre lo ve la próxima vez que abre su pantalla de inicio.

## Pantallas relacionadas

[[open: #care-log | Registro diario]]

Vea también: [Herramientas de IA](ai-tools), [Notificaciones de la agencia](agency-notifications).
