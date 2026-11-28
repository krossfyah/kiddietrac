---
title: Créditos por reservas de vacaciones
category: Billing
order: 84
---
# Reservas de vacaciones

**Reservas de vacaciones** pausa la matrícula de una familia mientras está fuera. Una familia pide una reserva (o usted la agrega por ella), usted la aprueba o la rechaza, y el crédito de una reserva aprobada se descuenta de la **próxima factura** de la familia cuando termina la reserva.

[[open: #vacation-holds | Abrir Reservas de vacaciones]]

## La regla de crédito

Su agencia elige cómo se acredita una reserva. Los administradores de la agencia pulsan **⚙ Credit rule** en el encabezado:

- **No credit** (la regla inicial): las reservas se registran, pero no se descuenta nada.
- **Un porcentaje de la tarifa diaria, por cada día programado**: por ejemplo 50 %.
- **Un monto fijo ($) por cada día programado**: por ejemplo $20.

Solo cuentan los días en que cada niño debe asistir (un niño sin días registrados cuenta de lunes a viernes). Los días en que el centro está cerrado no cuentan. La tarifa diaria de un niño = su matrícula mensual × 12 ÷ 52 ÷ sus días por semana. El encabezado siempre muestra la regla vigente. Cambiarla no cambia las reservas ya aprobadas.

## Aprobar o rechazar una solicitud

Las solicitudes nuevas muestran **Requested**. La columna **Credit** muestra lo que daría su regla («≈ $…, N días programados») o **No credit**. Los directores del centro de la familia y los administradores de la agencia reciben un aviso cuando una familia lo pide.

1. Abra **Administration › Vacation holds** y busque la solicitud.
2. En el menú **⋮** de la fila, elija **Approve**. La ventana **Approve vacation hold** muestra los días programados, la tarifa diaria y el crédito de cada niño.
3. Deje el crédito calculado o escriba otro monto (0 = sin crédito).
4. Pulse **Approve**. La fila muestra **Approved** y el crédito, con «On the first invoice after» el último día.
5. Para rechazar, elija **Deny** en el menú **⋮**, agregue una nota para la familia si quiere y pulse **Deny**.

## Cómo llega el crédito a la factura

- Cuando pasa el último día, la próxima factura de la familia (el lote mensual o su calendario de facturación) lleva una línea **Vacation hold credit** con las fechas.
- Un crédito nunca va en dos facturas. Si es mayor que la factura, esta queda en $0 y el resto se descuenta de la siguiente.
- Si anula (void) la factura que llevaba el crédito, el crédito pasa a la siguiente factura.
- La columna **Credit** muestra dónde quedó, por ejemplo «On INV-1042».

## Cancelar una reserva

Elija **Cancel hold** en el menú **⋮** mientras la reserva esté solicitada o aprobada y su crédito aún no esté en una factura. El crédito se revierte y se avisa a la familia. Cuando el crédito ya está facturado, anule o ajuste esa factura en **Billing**.

## Agregar una reserva para una familia

1. Pulse **+ Add hold** en el encabezado.
2. Elija la familia, ponga **From** y **To**, y un motivo (opcional).
3. Pulse **Add hold**. La reserva aparece como **Requested** y se avisa a los tutores.
4. Apruébela con los pasos de arriba para fijar el crédito.

## Lo que ve la familia

- Aprobada: «Your hold for … is approved. A credit of $… will come off your next invoice after the hold ends.» Sin crédito: «No credit applies to this hold.»
- Rechazada: su nota, o «Unable to approve at this time.»
- Cancelada: la reserva se canceló y su crédito no se aplicará.
- La línea de crédito en su factura. Los avisos abren su pantalla **Billing**.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de la agencia | Ver todas las reservas, fijar la regla de crédito, agregar, aprobar, rechazar y cancelar |
| Director del centro | Lo mismo solo para las familias de sus propios centros |
| Administrador de la plataforma | Lo mismo que un administrador de la agencia, en la agencia que está viendo |
| Padre o madre | Recibe aviso de cada decisión y ve el crédito en su factura |

Los educadores no ven esta pantalla.

## Preguntas frecuentes y problemas

??? Approve o Deny no aparecen en el menú ⋮. | Solo aparecen mientras la reserva está **Requested**. Una reserva decidida no se decide otra vez; cancélela (si su crédito no está facturado) y agregue una nueva si cambian las fechas.
??? «This hold is already approved.» | Otra persona la decidió primero. Actualice la pantalla.
??? No aparece Cancel hold. | El crédito ya está en una factura, o la reserva fue rechazada o cancelada. Anule o ajuste la factura en **Billing**.
??? El crédito dice «Recorded only». | La reserva se decidió antes de que los créditos llegaran a las facturas. Agregue ese crédito a mano si aún se debe. Vea [[family-ledger]].
??? El crédito es $0 pero sí damos créditos. | La regla es **No credit**, o los niños no tienen días programados ni matrícula en esas fechas. Un administrador de la agencia la fija con **⚙ Credit rule**.
??? «Family and both dates are required.» | Elija una familia y complete **From** y **To**, luego pulse **Add hold** otra vez.

## Pantallas relacionadas

[[open: #admin-families | Familias]]
[[open: #closures | Cierres]]
[[open: #tuition-increases | Aumentos de matrícula]]

Vea también [[family-ledger]] y [[generating-monthly-invoices]].
