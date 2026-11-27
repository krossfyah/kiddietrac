---
title: RR. HH.: horas extra, tiempo compensatorio y hojas de horas
---
# RR. HH.: horas extra, tiempo compensatorio y hojas de horas

Dos pestañas de **Recursos humanos > Fichajes y hojas de horas**, parte del módulo de RR. HH. de KiddieTrac. **Aprobar y bloquear** compara los turnos programados de cada persona con sus fichajes, para un centro y un periodo de pago; aprobar el periodo lo bloquea para la nómina. **Horas extra** es donde se aprueban las horas adicionales, para pagarlas en el próximo recibo o guardarlas como tiempo compensatorio.

[[open: #timesheets?tab=lock | Abrir Aprobar y bloquear]]

## Aprobar y bloquear un periodo de pago

1. Abra **Recursos humanos > Fichajes y hojas de horas** y elija la pestaña **Aprobar y bloquear**.
2. Elija el centro (si tiene más de uno).
3. Escriba las fechas, o pulse **La semana pasada**, **Las últimas 2 semanas**, **El mes pasado** o **Este mes hasta ahora**.
4. Lea los contadores de arriba: **Personas**, **Programada**, **Contratado**, **Trabajadas**, **Pagado**, **Sin fichaje de salida**, **Turno sin fichaje** y **Horas sin turno**.
5. Pulse **Ver fichajes** en la fila de una persona para ver cada día. Primero agregue una salida que falte en la pestaña **Hojas de horas**.
6. Pulse **Aprobar y bloquear este periodo**, revise el resumen y pulse **Aprobar y bloquear**.

Una nota verde **Approved and locked** reemplaza el botón y el periodo se agrega a **Periodos aprobados**. Los fichajes de esos días ya no se pueden agregar, corregir ni quitar, en ninguna pantalla.

[[show: #timesheets @ Approve & lock > Approve and lock this period | Muéstrame]]

> ℹ️ El botón queda gris, con el motivo al lado, hasta que el periodo termine, todos los fichajes tengan salida y sus propias horas no estén en la hoja (entonces lo aprueba otro director o un administrador de la agencia).

## Desbloquear un periodo (administradores de la agencia)

1. Abra el periodo en **Aprobar y bloquear**.
2. Pulse **Desbloquear** en la nota verde.
3. Explique por qué y pulse **Desbloquear**.

El desbloqueo queda en el registro de auditoría, y el periodo debe aprobarse de nuevo antes de la nómina.

## Aprobar horas extra

1. Elija la pestaña **Horas extra**. **Por aprobar** lista lo pendiente; **Aprobado**, **Pagado**, **Rechazado** y **Todos** muestran el resto.
2. Pulse **Ver horas extra** en una fila para ver la fecha, las horas, para qué fueron y qué prefiere la persona.
3. Elija:
   - **Aprobar como pago**: se ofrece como línea en el próximo recibo en **Nómina**, calculada con las horas, la tasa de horas extra y la tarifa por hora de la persona.
   - **Guardar como tiempo compensatorio**: las horas por la tasa pasan de inmediato a su **Bolsa de horas**.
   - **Rechazar**: explique por qué (la persona lo verá) y pulse **Rechazar horas extra**.

Para registrar horas extra de alguien de su equipo, pulse **Registrar horas extra de alguien**, llene **Fecha trabajada**, **Horas adicionales** y **Para qué fueron las horas adicionales**, y pulse **Enviar**.

## Configurar las reglas (administradores de la agencia)

1. En la pestaña **Horas extra**, pulse **Reglas de horas extra y hojas de horas** (o **Reglas** en **Aprobar y bloquear**).
2. Indique la tasa de horas extra (1 = tarifa normal, 1,5 = tiempo y medio).
3. Marque si el personal puede registrar sus propias horas extra, y si se pueden pagar, guardar o ambas.
4. En **Hojas de horas**, elija el redondeo de los fichajes (exacto, o a 5, 6, 10 o 15 minutos) y, si quiere, contar desde el inicio del turno (entrada temprana) o hasta el final del turno (salida tarde).
5. En "Antes de la nómina", elija avisar cuando un periodo no esté aprobado, o pagar solo periodos aprobados y bloqueados.
6. En **Descanso no pagado**, marque **Restar automáticamente un descanso no pagado de los fichajes largos** e indique su duración y después de cuánto tiempo.
7. Pulse **Guardar reglas**. Aparece **Reglas guardadas.**

Todas estas reglas empiezan **apagadas**: las horas pagadas son exactamente las del reloj. Encendidas, cambian las horas pagadas en todas partes: esta pestaña, la pantalla Hojas de horas (una cifra **Pagado**), las nóminas, resúmenes, exportaciones, recibos y facturas de contratistas. El fichaje en sí nunca cambia.

## Registrar sus propias horas extra (personal)

1. Abra **Mis RR. HH.** y busque **Horas extra y tiempo compensatorio**.
2. Pulse **Registrar horas extra**.
3. Indique la fecha, las horas adicionales, para qué fueron y si prefiere pago o tiempo compensatorio.
4. Pulse **Enviar**. Verá **Enviado para aprobación.**

Puede retirarla mientras espera. Para usar las horas guardadas, pida tiempo libre con el tipo **Bolsa de horas**. Si ve "Your director logs overtime for you.", su agencia desactivó el registro por parte del personal.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de la agencia | Todo, en todos los centros; cambiar las reglas; desbloquear periodos. |
| Director de centro | Aprobar horas extra y bloquear periodos de sus centros, nunca las propias. |
| Educador, visitador domiciliario | Registrar sus horas extra (si se permite) y ver su Bolsa de horas en Mis RR. HH. |

## Preguntas frecuentes y problemas

??? Aprobar y bloquear este periodo está gris. | Lea la línea de al lado: el periodo no ha terminado, un fichaje no tiene salida ("Add the clock-out first"), o sus propias horas están en la hoja.
??? ¿Puedo rechazar horas que ya se guardaron? | No. Las horas guardadas son definitivas. Ajuste el saldo en **Tiempo libre > Saldos**.
??? Hay que corregir un turno dentro de un periodo bloqueado. | Un administrador de la agencia desbloquea el periodo, se corrige el turno y luego se aprueba de nuevo.
??? ¿Qué hace "Only pay approved, locked periods"? | La nómina rechaza horas de un periodo que no esté aprobado y bloqueado. "Warn" solo muestra una advertencia.
??? ¿Se avisa a la persona de mi decisión? | Sí. Recibe un aviso cuando sus horas se aprueban, se guardan o se rechazan, con su comentario.

## Pantallas relacionadas

[[open: #timesheets | Hojas de horas]]
[[open: #timesheets?tab=punchfix | Correcciones de fichaje]]
[[open: #time-off?tab=balances | Saldos de tiempo libre]]

- [[timesheets]]
- [[hr-rota-publish]]
- [[hr-clock]]
