---
title: Reembolsar un pago
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
---
# Reembolsar un pago

Un reembolso es el único movimiento de dinero que nadie al otro lado confirma. Una factura se acuerda con la familia; un pago llega porque alguien decidió enviarlo. Un reembolso lo autoriza una sola persona dentro de la agencia y simplemente ocurre; por eso se firma, y cada uno queda registrado.

![La pantalla Reembolsos: elija el pago o ingrese el número de factura](https://api.kiddietrac.com/help-img/refunds.png)

## Quién puede hacerlo

**Solo los administradores de agencia y los directores de centro.** Un educador que abre la pantalla es rechazado, con un mensaje que explica el motivo.

## Dos formas de llegar

**Desde el libro mayor de la cuenta**: abra el libro mayor de la persona y pulse **↩ Reembolso**. Solo verá sus facturas, que es la vía más segura cuando ya sabe de quién es el dinero.

**Desde la pantalla Reembolsos**: para cuando trabaja a partir de un número en lugar de un nombre.

- La **lista desplegable** muestra todo lo de la agencia que tiene dinero aún reembolsable, con la familia, el importe reembolsable y el número de factura. Elija a la persona.
- O escriba el **número de factura**: el impreso en la factura que se envió a la familia, como `iL-INV-1779383993378`. Los números parciales funcionan.

> **Un mismo número puede pertenecer a varias facturas.** `PINVO-05082026` lo comparten once familias distintas. Cuando ocurre, se listan todas las coincidencias con el nombre de la familia y usted elige. No se elige nada por usted.

## Parcial o total

El importe por defecto es todo lo que aún se puede reembolsar, y el tope se indica en pantalla. Escriba cualquier importe menor para un reembolso parcial. Reembolsar más de lo que queda se rechaza, y el mensaje le dice exactamente cuánto queda:

> No se pueden reembolsar $1,512.00; solo quedan $567.00 de este pago por reembolsar.

El dinero ya reembolsado se descuenta. Una factura que recibió $1,680 y a la que se han devuelto $420 muestra **$1,260 reembolsables**.

## Aprobarlo

Elija un motivo, añada una nota si lo desea y luego **escriba su nombre y firme en el recuadro**. Ambos son obligatorios: la firma se guarda con el reembolso junto con la fecha, la hora y la dirección desde la que se hizo.

Ese registro convierte *«alguien reembolsó $735»* en *«esta persona identificada aprobó este reembolso en este minuto y puso su nombre»*.

## Qué ocurre después

Depende de cómo se pagó el dinero.

**Un pago con tarjeta realizado mediante Helcim vuelve a la tarjeta automáticamente.** No necesita dar ningún paso más:

- **El mismo día, importe completo**: KiddieTrac intenta primero **anular** el cargo, de modo que desaparezca del estado de cuenta de la tarjeta de la familia en lugar de aparecer como un cargo y un crédito.
- **En caso contrario**, o si la anulación es rechazada: el importe se **reembolsa** a través de Helcim. Solo un reembolso puede ser parcial.

Si Helcim lo rechaza, se muestra su motivo y no se registra nada. Si Helcim ya no está configurado para su agencia, el reembolso se rechaza con un mensaje que explica que el dinero no puede devolverse automáticamente. Consulte [Proveedores de pago](payment-providers).

**Con cualquier otro pago, el dinero no se mueve por sí solo.** El reembolso se registra, el saldo se vuelve a abrir en la cuenta y se avisa a la familia de que su centro gestionará el pago. Donde Interac está disponible, se le ofrece la transferencia como un paso aparte y deliberado.

En una factura de KiddieTrac, el saldo se escribe de vuelta en la propia factura. En una factura que viene de un sistema de facturación externo no se hace: ese sistema sobrescribe esos campos en cada sincronización, así que un reembolso escrito ahí desaparecería sin avisar en la siguiente consulta. En su lugar, el libro mayor vuelve a abrir el saldo, que nada sobrescribe.

## Dinero sin registro de pago

Cuando un pago llegó por una integración, KiddieTrac puede tener la factura pero no el recibo. Esas facturas siguen siendo reembolsables: el recibo se escribe en el momento en que se aprueba el reembolso, para que este tenga algo que revertir. No tiene que hacer nada; ocurre como parte de la aprobación.

## Primero reembolsar, después anular

Una factura con dinero pagado no se puede anular. Para cancelarla, primero reembolse el dinero aquí y luego anule la factura en **Contabilidad**. Consulte [Contabilidad](accounting).

Véase también: [Contabilidad](accounting) · [Libros mayores de cuenta](account-ledgers) · [Proveedores de pago](payment-providers)
