---
title: Reembolsar un pago
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
---
# Reembolsar un pago

**Finanzas → Reembolsos** devuelve dinero a una familia, total o parcialmente, sobre un pago que hizo. Un reembolso es el único movimiento de dinero que nadie del otro lado confirma, así que cada reembolso lo firma la persona que lo aprueba y queda registrado con la fecha, la hora y desde dónde se hizo.

[[open: #refunds | Abrir Reembolsos]]

![La pantalla Reembolsos: elija el pago o ingrese el número de factura](https://api.kiddietrac.com/help-img/refunds.png)

## Encontrar el pago a reembolsar

Puede encontrar el dinero de dos formas: desde la lista o por número de factura.

1. Abra **Finanzas → Reembolsos**.
2. Abra la lista **Elija el pago a reembolsar**. Muestra todo lo que en la agencia todavía tiene dinero reembolsable: la familia, el monto reembolsable, el número de factura o de pago, la fecha y lo que ya se reembolsó.
3. Elija el pago. Su detalle se abre debajo.

O, cuando trabaja con un número y no con un nombre:

1. Escriba el número en **Or enter the invoice number to refund:**, es decir, el número impreso en la factura que se envió a la familia. Sirve una parte del número.
2. Pulse **Buscar factura** (o Enter).
3. Si coincide una sola factura, se abre de inmediato. Si coinciden varias, cada una aparece con el nombre de la familia, lo facturado y lo recibido; pulse **Reembolso** junto a la correcta.

> ⚠️ Un mismo número puede pertenecer a varias facturas (por ejemplo, los números formados con una fecha pueden compartirlos muchas familias). Todas las coincidencias se muestran y usted elige. No se elige nada por usted.

Los administradores de agencia también pueden empezar desde la cuenta de una familia: ábrala en **Finanzas → Libros de cuentas** y pulse **↩ Refund**. Así solo ve los pagos de esa familia, que es la vía más segura cuando ya sabe de quién es el dinero.

## Emitir el reembolso

Arriba verá tres cifras: **Importe del pago**, **Already refunded** y **Reembolsable**. Debajo, **Historial de reembolsos** lista los reembolsos anteriores de este pago con su estado.

1. En **Emitir nuevo reembolso**, escriba el **Importe**. Sirve cualquier monto hasta la cifra reembolsable, así que un reembolso parcial es simplemente un número menor.
2. Elija un **Motivo**: **Solicitado por el cliente**, **Cargo duplicado**, **Pago en exceso**, **Cortesía** o **Crédito por vacaciones**.
3. Agregue **Notas** si hace falta (la familia ve el motivo y las notas en su aviso).
4. Escriba su nombre completo en **Su nombre**.
5. Firme en el recuadro **Firma** («Firme aquí para aprobar»). Pulse **Borrar firma** para empezar de nuevo.
6. Pulse **Emitir reembolso**.

Un recuadro verde confirma el reembolso y su estado. Las cifras se actualizan unos segundos después.

El dinero ya reembolsado se descuenta: un pago de $1,680 del que se devolvieron $420 muestra **$1,260** reembolsables. Reembolsar más de lo que queda se rechaza, indicando el monto exacto restante.

## Qué pasa con el dinero

Depende de cómo pagó la familia.

- **Tarjeta pagada por Helcim o Stripe**: el dinero vuelve automáticamente a la tarjeta. Con Helcim, un reembolso total el mismo día se intenta primero como **anulación**, para que desaparezca del estado de cuenta en lugar de aparecer como un cargo y un abono. A la familia se le avisa «Refund sent» y que debería llegar a su cuenta en unos días.
- **Cualquier otro pago** (e-Transfer, efectivo, cheque, banco): el reembolso se registra y el saldo se reabre en la cuenta, pero el dinero no se mueve solo. A la familia se le avisa que su centro coordinará el pago. Un recuadro dice **Aún no se ha enviado dinero**; si el envío por Interac está configurado, pulse **Send $… by Interac now**, o pulse **Lo gestionaré yo mismo**.

En una factura que vino de un sistema de facturación externo, el saldo se reabre en el libro de la familia en lugar de escribirse en la factura, porque ese sistema sobrescribe sus facturas en cada sincronización.

## Reembolsar dinero sin registro de pago

El dinero que llegó por una integración puede estar en una factura sin recibo en KiddieTrac. Una nota azul lo indica. Igual puede reembolsarlo: la pantalla muestra **Recibido**, **Reembolsable** y la **Factura**, y el recibo se crea en el momento en que usted aprueba, para que el reembolso tenga algo que revertir. Complete los mismos campos y pulse **Emitir reembolso**; luego el botón dice **Reembolso registrado**.

## Primero reembolsar, luego anular

Una factura con dinero pagado no se puede anular. Para cancelarla, primero reembolse el dinero aquí y luego anule la factura en **Finanzas → Contabilidad**.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Reembolsar cualquier pago de la agencia, desde esta pantalla o desde Libros de cuentas. |
| Director de centro | Reembolsar pagos de las familias que puede ver, desde esta pantalla. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, para la agencia que está viendo. |
| Educador | No puede abrir Reembolsos. El servidor responde «Only an agency admin or a centre director can approve a refund.» |

## Preguntas frecuentes y cuando algo sale mal

??? La lista dice «Nada en esta agencia tiene fondos que puedan reembolsarse». | Ningún pago tiene dinero pendiente de reembolso: todavía no se ha pagado nada, o ya se reembolsó todo. Revise el número de factura con Buscar factura para estar seguro.
??? «No invoice found with the number …» | Revise el número en la factura que recibió la familia, o elija a la familia en la lista de arriba.
??? «Cannot refund $…; only $… of this payment is left to refund.» | Parte del pago ya se reembolsó. Ingrese el monto indicado o menos.
??? «Type your full name to approve this refund.» o «Please sign in the box to approve this refund.» | Se requieren su nombre y su firma. Complete el que falte y vuelva a pulsar Emitir reembolso.
??? «This payment was taken through Helcim (or Stripe), but … is no longer set up for this agency» | El dinero no puede volver a la tarjeta automáticamente, así que no se registró nada. Un administrador de agencia puede volver a conectar el proveedor en Ajustes → Proveedores de pago, o coordinar el reembolso de otra forma.
??? El proveedor de tarjetas rechazó el reembolso. | Su motivo aparece en rojo y no se registra nada. Lea el motivo (por ejemplo, la tarjeta se cerró) e intente de nuevo o coordine el pago de otra forma.
??? ¿Se avisa a la familia? | Sí. Cada tutor recibe un aviso en la app y un correo: «Refund sent» cuando el dinero volvió a la tarjeta, «Refund approved» cuando el centro coordinará el pago.
??? ¿Dónde veo los reembolsos más tarde? | Elija de nuevo el mismo pago: Historial de reembolsos lista cada reembolso con su fecha, monto, motivo y estado. Los administradores de agencia también lo ven en la cuenta de la familia en Libros de cuentas.

## Pantallas relacionadas

[[open: #external-billing | Contabilidad]] [[open: #billing-schedule | Calendario de facturación]]

Vea también: [Contabilidad](accounting) · [Libros de cuentas](account-ledgers) · [Proveedores de pago](payment-providers)
