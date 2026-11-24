---
title: Informes OCCMS — Registro de asistencia (ROA) de subsidios y resumen CWELCC
category: Compliance
order: 47
---
# Informes OCCMS

Los centros de Ontario con contrato de subsidio de cuotas de cuidado infantil introducen cada mes en OCCMS un **Registro de asistencia (ROA)**: un código por cada día de cada niño con subsidio, además de la capacidad autorizada, la capacidad operativa, las matrículas y las vacantes por grupo de edad. La fecha límite de la región de York es el **día 8 del mes siguiente**. OCCMS no tiene ninguna conexión a la que KiddieTrac pueda enviar datos, así que KiddieTrac prepara el ROA para que usted lo copie y conserva un registro de quién lo revisó y cuándo se introdujo. Abra **Cumplimiento → Informes OCCMS** (solo agencias de Ontario; los directores ven sus propios centros y los administradores de agencia ven todos los centros).

## Los días

Elija el centro y el mes. Cada niño con subsidio ese mes (de **Subsidios → Subsidio provincial**) tiene una fila, con un código por día, calculado a partir de:

- **P** Presente: el niño registró su entrada.
- **S** Enfermo, **V** Vacaciones, **A** Ausente: una ausencia informada por la familia o el personal (enfermedad → S, vacaciones → V, cualquier otra → A).
- **A** con contorno rojo: ausente en un día programado sin que se informara nada.
- **H** Festivo oficial: un cierre de tipo *Holiday*. **N** Día no pagado: cualquier otro cierre (jornada de formación, emergencia, reformas).
- **W** Baja: en la fecha de baja del niño.
- **O** Otro: solo después de consultarlo con su municipio.
- En blanco: sin cuidado ese día (fin de semana, día en que el niño no está programado). Un punto gris indica que no hay subsidio ese día.

Toque cualquier día para cambiar su código. Un día modificado tiene un subrayado naranja, y cada cambio (código anterior, código nuevo, quién, cuándo) aparece en **Historial**.

Al final de cada fila: días presente, ausencias de este mes, ausencias usadas en el año natural frente a la asignación y cuántas quedan. **Requiere atención** enumera a los niños que usaron el 80 % o toda su asignación, y a cualquier niño ausente **5 días seguidos sin aviso** — avise al trabajador social de la familia (CSR).

## Capacidad y vacantes

La capacidad autorizada es el total de las capacidades de sus salas para cada grupo de edad. Introduzca la **capacidad operativa** (las plazas que puede cubrir con el personal que tiene; nunca más que la autorizada) y guarde. Se arrastra al mes siguiente. Los matriculados y las vacantes se calculan para el último día del mes.

## Revisión, firma y OCCMS

1. Revise los días y la capacidad.
2. **Marcar como revisado** (se abre el día 1 del mes siguiente).
3. Una **persona distinta** pulsa **Sign off**. La cuadrícula queda entonces congelada como firmada: los cambios de asistencia posteriores no la alteran (verá cuántos días han cambiado desde entonces).
4. Introdúzcala en OCCMS y pulse **Record OCCMS submission** con la fecha (y una referencia, si la tiene). El mes queda bloqueado.

Cambiar un día después de la revisión devuelve el mes a borrador. **Reopen** (con un motivo) desbloquea un mes firmado; **Amend** (con un motivo) desbloquea uno enviado para que pueda corregirlo e introducir la corrección en OCCMS.

**Registro de asistencia en CSV** y **Registro de asistencia imprimible (PDF)** le dan la cuadrícula con el formato del ROA de OCCMS, con la tabla de capacidad y los nombres de quienes firmaron.

## Resumen CWELCC

La segunda pestaña cuenta los niños matriculados en las salas del centro por grupo de edad, divididos en elegibles y no elegibles para CWELCC, junto a la capacidad autorizada y operativa, con un CSV. Un niño es elegible con menos de 6 años; quien cumple 6 entre el 1 de enero y el 30 de junio sigue siendo elegible hasta el 30 de junio (preescolar, kindergarten o grupo de edad familiar), y en otro caso hasta el final del mes en que cumple 6; en una sala de edad escolar, hasta el final de su mes de nacimiento. También enumera a los niños que dejan CWELCC en los próximos dos meses (las familias necesitan 2 meses de aviso) y a las familias cuya matrícula CWELCC no coincide con la edad del niño.

## Ajustes y recordatorios (administradores de agencia)

En **Ajustes**, elija su municipio: **York Region** (48 días de ausencia al año, 3 menos por cada mes después de enero en que empieza el subsidio), **City of Toronto** (35 al año, como máximo 20 seguidos), **Region of Peel** (introduzca la asignación del Service Provider Handbook de Peel) u **Otro** (reglas de York hasta que defina las suyas). Puede cambiar cualquier número y el día de vencimiento.

El día 1, los directores y administradores reciben una campana y un correo electrónico indicando que el ROA del mes pasado está listo para revisar, y dos días antes del vencimiento (el 6) otro si aún no está firmado. Desactívelos en Ajustes.
