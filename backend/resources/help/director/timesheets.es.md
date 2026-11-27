---
title: Hojas de horas y corrección de horas
---
# Hojas de horas y corrección de horas

La pantalla **Hojas de horas** muestra cada turno trabajado en sus centros durante un periodo: la fecha, quién lo trabajó, la entrada, la salida, el descanso y las horas, con el total de horas y el número de empleados arriba. Los directores y administradores de la agencia la usan para revisar las horas antes de la nómina y para corregir un turno mal fichado o sin fichar. La **Nómina** lee estos mismos fichajes, así que una hora equivocada aquí se convierte en un monto equivocado en el recibo de pago.

[[open: #timesheets | Abrir Hojas de horas]]

## Elegir qué horas ver

1. Abra **Recursos humanos > Fichajes y hojas de horas**. La pestaña **Hojas de horas** se abre primero.
2. Elija un centro en la lista, o deje **Todos los centros** (todos los que usted puede ver).
3. Escriba el primer y el último día en las dos casillas de fecha. La pantalla abre con los últimos 30 días, y cada cambio se aplica de inmediato.
4. Escriba un nombre o una fecha en **Search staff or date…** para acotar la lista.
5. Pulse 🔄 (**Recargar**) para volver a leer el periodo, o ⬇️ (**Descargar CSV**) para guardarlo para su proveedor de nómina.

> 💡 ¿Llenó las fechas al revés? La pantalla las invierte por usted.

## Leer una fila

- **Un turno abierto** no tiene salida y muestra **0h**. Nadie fichó la salida. No se suma al total: haga el seguimiento y ciérrelo (vea abajo).
- **No hours logged** es una persona programada en su centro que no fichó nada en el periodo.
- **Pagado**, debajo de las horas, aparece solo si su agencia tiene reglas de hojas de horas (redondeo, contar según el turno, descanso no pagado). La línea de arriba indica entonces las horas pagadas después de las reglas: son las que usa la nómina.
- Un 🔒 en lugar de los botones significa que el turno está dentro de una hoja de horas aprobada y bloqueada para la nómina.

## Corregir las horas de un turno

1. Busque el turno y pulse ✏️ (**Editar horas**) al final de la fila. En un teléfono, los botones están al pie de la tarjeta.
2. Cambie **Fichar entrada** o **Fichar salida**. Las horas son las del reloj del centro, no las de su dispositivo; la ventana indica la zona horaria.
3. Para cerrar un turno sin salida, llene **Fichar salida**. Déjelo vacío para mantener el turno abierto.
4. Escriba el motivo en **Reason (optional)**. Queda anotado en el turno, para que la siguiente persona sepa por qué cambió.
5. Pulse **Guardar cambios**. Aparece el aviso **Horas corregidas** y la fila se actualiza.

La pantalla rechaza el cambio, y explica por qué, si la salida no es posterior a la entrada o si el turno pasa de 24 horas.

## Añadir un turno que nunca se fichó

1. En la fila de la persona (o en su fila **No hours logged**), pulse ➕ (**Añadir un turno**).
2. Elija el **Centro** donde se archiva el turno. Decide qué nómina lo paga, así que revíselo para quien trabaja en varios lugares.
3. Escriba **Fichar entrada** y **Fichar salida**.
4. Escriba el **Motivo**. Es obligatorio para un turno ingresado a mano.
5. Pulse **Añadir turno**. Aparece el aviso **Turno agregado**.

Un turno que se cruza con otro que la persona ya tiene se rechaza, porque las horas contarían dos veces.

## Quitar un turno que no ocurrió

1. Pulse 🗑️ (**Quitar turno**) en la fila: un fichaje doble, o un fichaje con el nombre equivocado.
2. Explique por qué se quita el turno.
3. Pulse **Quitar turno**. Aparece el aviso **Turno quitado**.

> ⚠️ Quitar un turno no se puede deshacer. El registro de auditoría guarda el turno completo (horas y notas) y quién lo quitó.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la agencia | Ver y corregir las hojas de horas de todos los centros. |
| Director de centro | Ver y corregir los turnos trabajados en sus propios centros (el centro del fichaje, no el centro principal de la persona). |
| Educador, visitador domiciliario | No ven esta pantalla. Con el módulo de RR. HH., piden una corrección en **Mis RR. HH. > Corregir mi fichaje**. |

Cada corrección, turno ingresado a mano y turno quitado queda en **Administración > Registro de auditoría**.

## Preguntas frecuentes y problemas

??? Hay un 🔒 donde deberían estar los botones. | El turno está dentro de un periodo aprobado y bloqueado en **Fichajes y hojas de horas > Aprobar y bloquear**. Un administrador de la agencia debe desbloquearlo primero, con un motivo.
??? La lista dice "No timesheets in this date range." | Nadie fichó en ese centro en esas fechas. Revise el centro y ambas fechas, o elija **Todos los centros**.
??? Alguien olvidó fichar la entrada. ¿Dónde lo corrijo? | Aquí: pulse ➕ **Añadir un turno** en su fila, con un motivo.
??? Aparece "The clock-out has to be after the clock-in." | La salida es anterior a la entrada. Para un turno que pasa la medianoche, ponga la fecha del día siguiente en la salida.
??? ¿El personal puede cambiar sus propias horas? | No. Su reloj solo ficha entrada y salida. Un turno que quedó abierto durante la noche lo cierra aquí un director, o la persona pide una corrección en Mis RR. HH.
??? ¿Se avisa a la persona cuando cambio sus horas? | Esta pantalla no envía avisos. El cambio se muestra en el turno y queda en el registro de auditoría. (Una corrección pedida en Mis RR. HH. sí le avisa de la decisión.)
??? ¿Por qué Pagado no coincide con las horas? | Las reglas de hojas de horas de su agencia (redondeo, contar desde el inicio o hasta el final del turno, descanso no pagado) se aplican solo a las horas pagadas. El fichaje en sí nunca cambia.

## Pantallas relacionadas

[[open: #timesheets?tab=lock | Aprobar y bloquear]]
[[open: #timesheets?tab=punchfix | Correcciones de fichaje]]
[[open: #payroll | Nómina]]

- [[hr-overtime-timesheets]]
- [[hr-rota-publish]]
- [Nómina, horas y recibos de pago](payroll-documents)
- [Exportación CSV para nómina](payroll-csv)
