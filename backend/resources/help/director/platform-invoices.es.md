---
title: Contabilidad AR/AP (facturar a las agencias por KiddieTrac)
---
# Contabilidad AR/AP (facturar a las agencias por KiddieTrac)

**Platform › 🏦 Accounting AR/AP** es la contabilidad propia de KiddieTrac: las facturas que las agencias deben por la plataforma, los pagos que hicieron, los cargos y créditos extra, el plan de facturación de cada agencia y lo que gasta KiddieTrac. Es aparte de las facturas que las agencias envían a las familias. Solo los superadministradores de la plataforma pueden abrirla.

[[open: #sales-invoices | Abrir contabilidad AR/AP]]

Las pestañas de arriba son **🧾 Invoices**, **💳 Payments**, **➕ Extra line items**, **🤝 Referral credits**, **🏷️ Billing plans**, **⚙️ Settings** y **📤 Expenses (AP)**.

## Generar las facturas que vencen

1. Abra **🧾 Invoices**.
2. Presione **Raise invoices due now**.
3. Lea la vista previa: cada agencia que se facturará y el monto, y en **Not due** las agencias omitidas y por qué.
4. Presione **Raise invoices** para crearlas, o **Cancel** para detenerse. No se crea nada hasta que confirme.
5. Las facturas nuevas aparecen en **Open** como borradores. La próxima fecha de factura de cada agencia avanza según su intervalo.

> ℹ️ Una agencia sin próxima fecha de factura no está en facturación recurrente y nunca se factura sola. Las renovaciones de licencia también se facturan aquí.

## Hacer una factura única

1. Presione **＋ New invoice**.
2. Elija la **Agency** y defina **Due date**, **Tax %** y **Tax name** (una tasa mayor que 0 necesita un nombre, como HST).
3. Agregue líneas con **＋ Add a line**: **Description**, **Qty**, **Price** y **Taxed**. El subtotal, el impuesto y el total se actualizan al escribir.
4. Agregue una **Note on the invoice (optional)** y presione **Save draft**. Un aviso dice **Draft … created**.

## Trabajar con una factura

Las pestañas **Open**, **Paid** y **Voided** muestran su cantidad, con mosaicos de facturado, cobrado, pendiente, impuestos y vencido por moneda. Un ⚠ junto a **Due** significa vencida. Cada fila solo ofrece lo que aplica:

1. **View invoice** abre el PDF (en cada fila, incluso las anuladas).
2. **Emitir** (solo borradores) registra la fecha de emisión. No envía ningún correo.
3. **Enviar factura por correo** pide la dirección cada vez; si no da ninguna, no se envía nada. Funciona en cualquier factura no anulada, así que puede reenviar una copia.
4. **Registrar pago** registra el dinero recibido.
5. **Lines & payments** agrega una línea, quita un extra, usa **Waive late fees** o marca un pago como **Returned (NSF)…**.
6. **Edit invoice** cambia las líneas, la fecha de vencimiento y el impuesto, con un **Reason for the change** que queda en el registro de auditoría.
7. **Reissue** anula la factura y genera una copia corregida según lo que tiene licencia hoy; **Void** la cancela pero la conserva, atenuada, sin contar como deuda.
8. **Refund…** aparece cuando hay un pago registrado, para devolver dinero. **Delete draft** solo aparece en un borrador que nunca se envió por correo.

## Confirmar pagos que informan las agencias

1. Abra **💳 Payments**. **Payments to confirm** muestra lo que los administradores de agencia informaron desde su página Licence.
2. Presione **Confirm received** para registrarlo y enviar un recibo por correo, o **Not received** y escriba un motivo; se avisa al administrador de agencia que lo informó.
3. Abajo, el **Payment ledger** lista cada pago, reembolso y pago devuelto con **Date**, **Agency**, **Invoice**, **Method**, **Amount**, **Receipt** y **Status**.

## Planes de facturación, extras y ajustes

1. **🏷️ Billing plans**: una fila por agencia con **Before discounts**, **Discounts / complimentary**, **Every**, **Tax**, **Total**, **Next invoice** y **Bill to**. Presione **View** para definir **Price**, **Currency**, **Every**, **Tax %**, **Tax name**, **Next invoice** y los **Business details on the invoice**, y luego **Save**. Las agencias marcadas **billed from licences** toman su precio de **Platform › 🔑 Licences**.
2. **➕ Extra line items**: agregue un cargo a la próxima factura de una agencia o a todas (**Every invoice** / **Next invoice only**), y luego **Pause**, **Resume** o **Delete**.
3. **🤝 Referral credits**: un crédito único en la factura de un mes elegido.
4. **⚙️ Settings**: **🔁 Recurring invoices** (**On**, **Raise invoices at**, **Which agencies**, **Invoices are due**, **BCC on invoices**, **Email them automatically**, y luego **Save recurring invoices**), además de recordatorios y recibos, recargos por mora, cargos por pago devuelto y recargos por método de pago (**Save settings**).

> ⚠️ Activar **Email them automatically** pide confirmación: las facturas se envían a las agencias en cuanto se generan. En Canadá, los recargos con tarjeta de crédito tienen un tope de 2.4 % y no se permiten con débito.

## Quién puede hacer qué

Solo **superadministradores de la plataforma**. Ningún rol de agencia, representante de ventas ni revendedor ve las cuentas por cobrar de KiddieTrac. Los administradores de agencia ven sus propias facturas e informan pagos desde su página Licence.

## Preguntas frecuentes y problemas

??? «No invoices yet» | Defina el precio de una agencia en **🏷️ Billing plans** (o dele una licencia) y luego use **Raise invoices due now**.
??? «Every line needs a description and a price» | Llene ambos en cada línea, o quite la línea vacía con ✕.
??? «A tax rate needs a name (HST, GST)» | Escriba el nombre del impuesto; un impuesto sin nombre se imprimiría como una simple línea «Tax».
??? ¿Por qué no puedo eliminar una factura? | Solo se puede eliminar un borrador que nunca se envió. Cuando la agencia ya tiene una copia, use **Void** o **Reissue**.
??? La agencia dice «no address on file». | Abra **🏷️ Billing plans**, presione **View** y llene los datos de la empresa. Lo que quede vacío no aparece en la factura.
??? ¿Emitir envía la factura? | No. Use **Enviar factura por correo**, o active el envío automático en **⚙️ Settings**.

## Pantallas relacionadas

[[open: #licences | Licencias]] · [[open: #platform-sales | Informes de ventas]] · [[open: #admin-mrr | Panel de MRR]] · [[open: #sales-expenses | Gastos]]

Vea también: [[platform-licences]] · [[platform-sales-reports]] · [[billing-setup]] · [[platform-admin-overview]]
