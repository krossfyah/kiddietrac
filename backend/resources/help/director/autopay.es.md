---
title: Ejecución de cobros (pago automático)
category: Billing
order: 76
roles: agency_admin, platform_admin
---
# Ejecución de cobros (pago automático)

**Finanzas → Ejecución de cobros** cobra de una sola vez las facturas abiertas de todas las familias con pago automático, en su tarjeta guardada. Usted revisa la lista, desmarca a quien quiera dejar fuera y la ejecuta. El pago automático también se ejecuta solo cada noche a las 3:00, y cada ejecución (manual o nocturna) se guarda en **Ejecuciones anteriores** para que vea a quién se cobró y a quién no.

[[open: #payment-run | Abrir Ejecución de cobros]]

## Antes de empezar

- [ ] Hay un procesador de tarjetas conectado en **Ajustes → Proveedores de pago**. Sin uno, una nota amarilla ⚠️ arriba indica que todavía no se puede cobrar nada, y **Cobrar seleccionados** queda en gris.
- [ ] Las familias guardaron una tarjeta y activaron el pago automático (vea «Cómo una familia activa el pago automático» más abajo).
- [ ] Las facturas que espera cobrar están emitidas (los borradores nunca se cobran).

## Cobrar ahora a las familias con pago automático

1. Abra **Finanzas → Ejecución de cobros**.
2. Revise **Ready to charge**. Cada fila muestra la **Familia** (y el centro), el número de **Factura**, la fecha en que **Vence**, la **Tarjeta** guardada y el **Importe**. Las facturas vencidas llevan una etiqueta roja **Vencido**.
3. Desmarque a cualquier familia que quiera dejar fuera esta vez (por ejemplo, una que llamó para decir que pagará de otra forma). La casilla del encabezado (**Seleccionar todo**) marca o desmarca todas las filas.
4. Revise el total junto a **Selected:** abajo, por ejemplo «12 invoices · $4,380.00».
5. Pulse **Cobrar seleccionados**.
6. Lea la confirmación («Charge 12 invoices?») y pulse el botón **Charge $…** para continuar, o cancele.

Mientras se ejecuta, el botón dice **Charging…**. Al terminar, un aviso indica cuántos cobros salieron bien y cuántos fallaron («11 charged ($4,015.00), 1 failed»), ambas listas se actualizan y el detalle de la ejecución se abre solo.

> ⚠️ Un cobro no se puede deshacer desde esta pantalla. Para devolver dinero, use **Finanzas → Reembolsos**.

## Dar seguimiento a las familias sin pago automático

La lista **Not covered by auto-pay** muestra las familias que deben dinero pero no tienen tarjeta registrada para el pago automático, con el número de **Invoices**, el **Vencimiento más antiguo** y el monto **Adeudado**. A nadie de esta lista se le cobra ni se le da por pagado.

1. Ubique a la familia en **Not covered by auto-pay**.
2. Abra su factura en **Finanzas → Contabilidad**, o su cuenta en **Finanzas → Libros de cuentas**.
3. Envíe un recordatorio, o registre el pago en la factura cuando llegue (e-Transfer, efectivo, cheque).

## Revisar una ejecución anterior

1. Baje hasta **Ejecuciones anteriores**. Cada fila muestra **Cuándo**, el **Tipo** (**Nightly (automatic)** o **Manual** con el nombre de pila de quien la ejecutó), el **Resultado**, cuántos se cobraron (**Cobrado**) y cuántos fallaron (**Fallido**), y el monto **Cobrado** total.
2. Haga clic en una fila para abrirla.
3. Lea el **Resultado** de cada familia: **Cobrado**, **Fallido** o **No cobrado**. Una fila fallida u omitida muestra el motivo debajo (por ejemplo, tarjeta rechazada o vencida).
4. Pulse **Cerrar**.

El resultado de la ejecución es **Completado** cuando todo pasó, **Algunos fallaron** cuando falló una parte, **Fallido** cuando no pasó nada, y **Sin procesador de pagos** cuando no había procesador de tarjetas conectado en ese momento.

## Cómo una familia activa el pago automático

Las familias lo hacen ellas mismas en el portal de familias: **Pago automático → + Add card**, ingresan la tarjeta en el formulario seguro y luego **Guardar tarjeta**. El pago automático se activa en cuanto se guarda la tarjeta. Los datos de la tarjeta van directo al procesador de tarjetas y nunca llegan a KiddieTrac. Para dejarlo, la familia pulsa **Desactivar el pago automático**; la tarjeta queda registrada y puede volver a activarlo después con **Activar el pago automático**.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Abrir Ejecución de cobros, cobrar a las familias y ver todas las ejecuciones anteriores. |
| Administrador de plataforma | Lo mismo, para la agencia en la que está trabajando. |
| Director de centro | No ve esta pantalla. Cobrar tarjetas mueve el dinero de la agencia, así que queda en manos de los administradores de agencia. |
| Familias | Guardar una tarjeta y activar o desactivar el pago automático en su propio portal. Nunca ven esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? ¿Por qué Cobrar seleccionados está en gris? | O no hay filas marcadas, o no hay procesador de tarjetas conectado. La nota amarilla de arriba dice «No card processor is connected for this agency, so nothing can be charged yet.» Conecte uno en Ajustes → Proveedores de pago.
??? ¿Por qué Ready to charge está vacío? | Dice «No auto-pay family has an open balance right now.» O todas las familias con pago automático están al día, o las que deben no tienen tarjeta registrada (aparecen en Not covered by auto-pay).
??? Veo «A payment run is already in progress for this agency.» | Otra persona (o la ejecución nocturna) está cobrando en este momento. Espere un minuto, recargue la página y revise Ejecuciones anteriores antes de intentar otra vez, para que a nadie se le cobre dos veces.
??? Falló la tarjeta de una familia. ¿Qué pasa? | No se cobra nada y la factura sigue abierta. La familia recibe un aviso de que el pago falló. Cuando actualice su tarjeta, la siguiente ejecución (manual o nocturna) lo intenta de nuevo.
??? ¿La ejecución nocturna cobra a las familias que desmarqué? | Sí. Desmarcar solo deja fuera a la familia de esta ejecución manual. La ejecución nocturna cobra a todas las familias con pago automático que tengan saldo abierto. Para que no se le cobre a una familia, pídale que desactive el pago automático.
??? Cobré un monto equivocado. | Devuelva el dinero con un reembolso en Finanzas → Reembolsos. El reembolso se firma y se avisa a la familia.
??? ¿Las familias reciben un correo cuando se les cobra? | Sí, una factura pagada envía a la familia su confirmación de pago habitual. Un cobro fallido envía en su lugar un aviso de pago fallido.

## Pantallas relacionadas

[[open: #payment-providers | Proveedores de pago]] [[open: #refunds | Reembolsos]] [[open: #account-ledgers | Libros de cuentas]] [[open: #external-billing | Contabilidad]]

Vea también: [Proveedores de pago](payment-providers) · [Reembolsar un pago](refunds) · [Libros de cuentas](account-ledgers)
