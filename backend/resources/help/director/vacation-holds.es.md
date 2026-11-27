---
title: Créditos por reservas de vacaciones
category: Billing
order: 84
---
# Reservas por vacaciones

**Reservas por vacaciones** pausa la cuota de una familia mientras está fuera. Una familia pide una pausa (o usted la agrega por ella), usted la aprueba o la rechaza, y una pausa aprobada lleva un crédito para la familia. Los directores y administradores de agencia abren esta pantalla cuando una familia avisa que estará fuera, y para atender las solicitudes pendientes.

[[open: #vacation-holds | Abrir Reservas por vacaciones]]

## Aprobar o denegar una solicitud

Las solicitudes nuevas tienen el estado **solicitado**, con **Aprobar** y **Denegar** en la fila. Los directores y administradores de agencia reciben una notificación cuando una familia la pide: «Vacation hold requested by» y la familia, con las fechas.

1. Abra **Administración › Reservas por vacaciones**.
2. Busque la fila con el estado **solicitado**. Revise **Familia**, **Fechas** y **Motivo**.
3. Presione **Aprobar**. Se abre el cuadro **Aprobar pausa por vacaciones**.
4. Escriba un monto de crédito para la familia, o deje el cuadro vacío para calcularlo automáticamente.
5. Presione **Aprobar**. La fila cambia a **approved** y la columna **Crédito** muestra el monto.
6. Para rechazarla, presione **Denegar** en la fila. Cambia a **denied** de inmediato; no hay nada que llenar.

Presionar **Cancelar** en el cuadro de aprobación deja la solicitud como estaba: no se aprueba nada.

> ⚠️ Un monto vacío es un cálculo aproximado de **30 $ por día** por cada día de la pausa, incluidos el primero y el último (una pausa de 10 días = 300 $). Si su tarifa diaria es otra, escriba el monto real.

## Agregar una pausa para una familia

Use esto cuando una familia le avise por teléfono, por correo o en persona.

1. Presione **+ Añadir pausa** en el encabezado.
2. En **🏖 Agregar pausa por vacaciones**, elija la familia en **Familia** (**Seleccione una familia…**).
3. Indique **De** y **Para**. La fecha final no puede ser anterior a la inicial.
4. Agregue un **Reason (optional)**, como «Vacaciones familiares».
5. Presione **Añadir pausa**. La pausa se suma a la lista como **solicitado**, y a los tutores de la familia se les avisa: «A vacation hold was added to your account».
6. Apruébela con los pasos de arriba para fijar el crédito.

## Lo que ve la familia

- Cuando aprueba, cada tutor recibe una notificación: «Vacation hold approved — Credit of $… will be applied.»
- Cuando deniega, recibe «Vacation hold denied — Unable to approve at this time.»
- La notificación abre su pantalla de **Facturación**.

> ℹ️ El crédito queda registrado en la pausa. Asegúrese de que llegue a la cuenta de la familia: revise su próxima factura en **Facturación** y agregue el crédito si no aparece. Vea [[family-ledger]].

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Ver todas las pausas de la agencia, agregarlas, aprobarlas o denegarlas |
| Director de centro | Agregar, aprobar y denegar pausas para las familias de sus centros |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia que esté viendo |
| Padre o madre | Pide una pausa para su propia familia y recibe la decisión |

Los educadores no ven esta pantalla.

## Preguntas frecuentes y problemas

??? No hay botón Aprobar ni Denegar en una fila. | Los botones solo aparecen mientras la pausa está **solicitado**. Una pausa aprobada o denegada es definitiva; agregue una nueva si cambian las fechas.
??? «Family and both dates are required.» | Elija una familia y llene **De** y **Para**, luego presione **Añadir pausa** otra vez.
??? «No families loaded — select an agency first.» | La lista de familias está vacía porque no hay agencia elegida. Elija su agencia en el selector de agencias y vuelva a abrir la pantalla.
??? Presioné Cancelar por error. | No cambió nada: la pausa sigue **solicitado**. Presione **Aprobar** otra vez.
??? El crédito quedó mal. | Una pausa ya decidida no se puede editar aquí. Ajuste la próxima factura de la familia en **Facturación**, o pregunte a su administrador de agencia.
??? La lista dice «No vacation holds.» | Ninguna familia ha pedido una pausa y no se ha agregado ninguna. Use **+ Añadir pausa** para agregar una.
??? ¿Hay un límite de duración? | No en KiddieTrac. Muchos centros permiten hasta dos semanas al año: aplique su propia política al aprobar.

## Pantallas relacionadas

[[open: #admin-families | Familias]]
[[open: #closures | Cierres]]
[[open: #tuition-increases | Aumentos de matrícula]]

Vea también [[family-ledger]] y [[generating-monthly-invoices]].
