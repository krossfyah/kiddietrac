---
title: Generar las facturas mensuales
category: Billing
order: 1
---

# Generar las facturas mensuales

Al comienzo de cada mes (o cuando prefiera), genere las facturas de todas las familias inscritas con un solo clic.

## Cómo funciona la generación de facturas

Cuando hace clic en **Generar facturas**, Kiddietrac:

1. Encuentra todas las inscripciones activas de su centro
2. Las agrupa por familia
3. Crea para cada familia una factura con una línea por niño
4. Aplica el subsidio activo del niño, si hay uno registrado (por ejemplo un subsidio provincial de cuidado infantil — vea [Subsidios](subsidies)), para reducir el importe que debe la familia
5. Pone la factura en estado **Enviado** y se la envía por correo electrónico a la familia (si ha configurado el correo)

La familia ve la factura en su aplicación para padres, en **Facturación**.

## Cómo hacerlo

1. Vaya a **Familias → Facturas** (o haga clic en «Cuentas por cobrar» en su panel)
2. Haga clic en **Generar facturas de [mes]**
3. Confirme — Kiddietrac muestra cuántas facturas se crearán y el importe total
4. Haga clic en **Generar**

## ¿Y si ya generé las facturas de este mes?

El sistema no crea duplicados. Si ya lo ejecutó este mes, omitirá las familias que ya tienen una factura de ese mes y solo generará para los niños recién inscritos.

## Cómo se calculan las facturas

Para cada niño inscrito:

- **Subtotal** = cuota mensual de la inscripción
- **Subsidio** = el importe mensual del subsidio activo del niño en la fecha de la factura, tomado de **Subsidios**
- **Neto** = subtotal − subsidio

Si una familia tiene varios niños, los importes de todos se suman en una sola factura.

## Registrar pagos

Cuando una familia paga (transferencia electrónica, cheque, efectivo, etc.), regístrelo:

1. Haga clic en el número de la factura
2. Haga clic en **Registrar pago**
3. Ingrese el importe, el método, la fecha de pago y cualquier referencia
4. Guarde

El saldo de la factura se actualiza automáticamente. Si la familia paga el importe completo, el estado de la factura cambia a **Pagado**.

También puede registrar un pago desde **Finanzas → Contabilidad**, que ofrece añadir su cargo por servicio de tarjeta o transferencia si lo cobra. Vea [Contabilidad](accounting) y [Recargos por pago](payment-surcharges).

## ¿Y las tarjetas de crédito?

Las familias pueden pagar en línea una vez que su agencia haya conectado un proveedor de pago en **Ajustes → Proveedores de pago**. Hasta entonces, usted recibe los pagos fuera de la aplicación (transferencia, cheque, etc.) y los registra aquí para que la cuenta de la familia esté al día. Vea [Proveedores de pago](payment-providers).

## Números de factura y aspecto

Los números de factura siguen el formato propio de su agencia, y las facturas se dibujan con el estilo elegido en **Marca**. Vea [Estilo y numeración de facturas](invoice-numbering-and-style).

## Consejos

- **Genere las facturas el día 1 de cada mes**. Programe un recordatorio en su calendario.
- **Envíe un correo de recordatorio** a las familias con facturas vencidas hacia el día 15
- **Haga seguimiento de los métodos de pago**. En la mayoría de los centros de Ontario, la transferencia electrónica es el método más común

## Editar o cancelar una factura

No puede eliminar una factura una vez generada (esto preserva sus registros financieros). Si lo necesita:

- **Corregir una factura**: use **Editar** en la factura en **Finanzas → Contabilidad**.
- **Cancelar una factura**: use **Anular factura** en **Contabilidad**. Conserva su número, deja de adeudarse y se avisa a la familia por correo electrónico. Vea [Contabilidad](accounting).
- **Reembolsar a una familia**: use **Finanzas → Reembolsos**, que registra quién lo aprobó. Reembolse cualquier dinero pagado antes de anular la factura. Vea [Reembolsar un pago](refunds).
