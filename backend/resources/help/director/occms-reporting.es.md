---
title: Informes OCCMS — Registro de asistencia (ROA) del subsidio y resumen de CWELCC
category: Compliance
order: 47
---
# Informes OCCMS

Cada mes, los centros de Ontario con contrato de subsidio de cuotas de cuidado infantil ingresan en OCCMS un **Registro de asistencia (ROA)**: un código por día para cada niño con subsidio, además de la capacidad autorizada, la capacidad operativa, la inscripción y las vacantes por grupo de edad. El plazo de la Región de York es el **día 8 del mes siguiente**. OCCMS no tiene una conexión a la que KiddieTrac pueda enviar datos, así que KiddieTrac prepara el ROA para que usted lo copie, y guarda constancia de quién lo revisó y cuándo se ingresó.

Abra **Cumplimiento → Informes OCCMS** (solo agencias de Ontario; los directores ven sus propios centros y los administradores de agencia ven todos). La pantalla tiene las pestañas **Registro de asistencia**, **Monthly attendance & fees** (asistencia y cuotas del mes), **Resumen de CWELCC** y, para los administradores de agencia, **Ajustes**.

[[open: #subsidy-roa | Abrir Informes OCCMS]]

## Revisar los días

1. Elija el centro y el mes en la parte superior.
2. En **Registro de asistencia**, cada niño con subsidio de cuotas ese mes (de **Cumplimiento → Subsidios → Subsidio provincial**) tiene una fila con un código por día.
3. Toque cualquier día para cambiar su código. Un día cambiado lleva un subrayado naranja, y cada cambio (código anterior, código nuevo, quién, cuándo) aparece en **Historial**.
4. Lea el final de cada fila: días presentes, ausencias del mes, ausencias usadas en el año calendario frente a la asignación, y cuántas quedan.
5. Lea **Requiere atención**: niños que usaron el 80 % o toda su asignación, y cualquier niño ausente **5 días seguidos sin aviso**. Avise al trabajador de caso de la familia (CSR).

Los códigos se calculan por usted:

- **P** Presente: el niño registró su llegada.
- **S** Enfermo, **V** Vacaciones, **A** Ausente: una ausencia informada por la familia o el personal (enfermo → S, vacaciones → V, otra → A).
- **A** con contorno rojo: ausente en un día programado sin que se informara nada.
- **H** Feriado oficial: un cierre de tipo *Holiday*. **N** Día no pagado: cualquier otro cierre (día de formación, emergencia, remodelación).
- **W** Retiro: en la fecha de retiro del niño.
- **O** Otro: solo después de confirmarlo con su municipio.
- En blanco: no hubo cuidado ese día (fin de semana, un día no programado). Un punto gris significa sin subsidio ese día.

## Indicar la capacidad

1. Desplácese hasta la tabla de capacidad en **Registro de asistencia**. La capacidad autorizada es el total de las capacidades de sus salas para cada grupo de edad.
2. Escriba la capacidad **Operating** (operativa) de cada grupo de edad: los lugares que puede cubrir con el personal que tiene, nunca más que la autorizada.
3. Pulse **Guardar capacidad operativa**. Se mantiene para el mes siguiente. La inscripción y las vacantes se calculan para el último día del mes.

## Revisar, aprobar e ingresarlo en OCCMS

1. Revise los días y la capacidad.
2. Pulse **Marcar como revisado**. Se habilita el día 1 del mes siguiente.
3. Una **persona distinta** pulsa **Aprobar**. La cuadrícula queda fija tal como se aprobó: los cambios de asistencia posteriores no la modifican (verá cuántos días cambiaron desde entonces).
4. Ingréselo en OCCMS y luego pulse **Registrar el envío a OCCMS**, indique la **Fecha de introducción** (y una referencia si la tiene) y pulse **Record**. El mes queda bloqueado.

Cambiar un día después de la revisión devuelve el mes a borrador. **Reabrir** (con un motivo) desbloquea un mes aprobado; **Corregir** (con un motivo) desbloquea uno enviado para corregirlo e ingresar la corrección en OCCMS.

**Registro de asistencia en CSV** y **Registro de asistencia imprimible (PDF)** le dan la cuadrícula con el formato del ROA de OCCMS, con la tabla de capacidad y los nombres de quienes aprobaron.

## Hoja mensual de asistencia y cuotas

1. Abra la pestaña **Monthly attendance & fees**.
2. Lea, para cada niño inscrito, las horas de cuidado de cada día (de la llegada a la salida), las horas del mes, **Parent fees**, **Gov. fees**, **Total fees** y **Space type**.
3. Pulse **Sheet as Excel** o **Printable sheet (PDF)**. La versión impresa agrega una columna para la firma del padre o la madre.

La hoja es de solo lectura: corrija llegadas, ausencias, cierres o facturas en su origen. También está en **Informes → Monthly attendance & fees**.

## Resumen de CWELCC y cuotas base

1. Abra la pestaña **Resumen de CWELCC**.
2. Lea los niños inscritos en las salas del centro por grupo de edad, divididos entre elegibles para CWELCC y no elegibles, junto a la capacidad autorizada y operativa.
3. Revise **Salidas de CWELCC en los próximos dos meses** (las familias necesitan 2 meses de aviso) y **Compruebe antes de informar** (familias cuya inscripción en CWELCC no coincide con la edad del niño).
4. En **CWELCC base fees**, escriba la cuota diaria al 31 de diciembre de 2024 de cada grupo de edad y pulse **Save December 31, 2024 fees**. La cuota base es la menor entre el tope diario y esa cuota, nunca por debajo del mínimo.
5. Revise la cuota de cada niño de CWELCC como monto diario (**Within** u **Over by**), otros cargos a familias de CWELCC y los días de cierre usados este año.
6. Pulse **Resumen de CWELCC en CSV** para descargarlo.

Un niño es elegible antes de los 6 años; uno que cumple 6 entre el 1 de enero y el 30 de junio sigue siendo elegible hasta el 30 de junio (grupo preescolar, jardín de infancia o familiar); si no, hasta el final del mes en que cumple 6; en una sala de edad escolar, hasta el final del mes de su cumpleaños.

## Ajustes y recordatorios (administradores de agencia)

1. Abra la pestaña **Ajustes**.
2. Elija su **Municipality (CMSM / DSSAB)**: Región de York (48 días de ausencia al año, 3 menos por cada mes después de enero en que empieza el subsidio), Ciudad de Toronto (35 al año, como máximo 20 seguidos), Región de Peel (escriba la asignación del Service Provider Handbook de Peel) u Otro (reglas de York hasta que fije las suyas).
3. Cambie cualquier número, como **Días de ausencia por año**, **Máximo de días de ausencia seguidos** o el **Día de vencimiento del mes**, o pulse **Usar los valores predeterminados del municipio**.
4. Marque o desmarque el recordatorio y pulse **Guardar**.

El día 1, los directores y administradores reciben un aviso en la campana y un correo de que el ROA del mes anterior está listo para revisar, y otro dos días antes del vencimiento (el 6 para York) si aún no está aprobado.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de agencia | Todos los centros; todas las pestañas, incluida **Ajustes**. |
| Director de centro | Sus propios centros; revisa, aprueba y registra envíos. Sin pestaña **Ajustes**. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia donde trabaja. |

La pantalla aparece solo en agencias de Ontario. Los educadores y los padres no la ven.

## Preguntas frecuentes y problemas

??? Marcar como revisado está desactivado. | El mes aún no termina. Se habilita el día 1 del mes siguiente.
??? Aprobar está desactivado: "A different person must sign off". | Usted revisó el mes. Una segunda persona (director o administrador) lo aprueba.
??? Falta un niño en el ROA. | Solo aparecen los niños con subsidio provincial de cuotas ese mes. Agréguelo en **Cumplimiento → Subsidios → Subsidio provincial**.
??? La asignación de ausencias dice "not set". | Un administrador de agencia la ingresa en la pestaña **Ajustes**.
??? Un día cambió después de que aprobé. | La cuadrícula aprobada se mantiene y muestra cuántos días cambiaron desde entonces. Use **Reabrir** para corregirla.

## Pantallas relacionadas

[[open: #cwelcc | Subsidios]] [[open: #admin-families | Familias]]

Vea también: [Subsidios — CWELCC y subsidio provincial de cuotas](subsidies)
