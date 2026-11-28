---
title: Gastos (proveedores, órdenes de compra y facturas de proveedores)
category: Billing
order: 85
roles: agency_admin, centre_director, platform_admin
---
# Gastos (proveedores, órdenes de compra y facturas de proveedores)

**Finanzas → Gastos** es el dinero que su agencia o centro paga. Lleve la lista de proveedores, emita órdenes de compra, registre la factura del proveedor cuando llegue, apruébela y registre cada pago. Ábralo cuando reciba la factura de un proveedor o cuando quiera ver lo que aún se debe.

[[open: #expenses | Abrir Gastos]]

La pantalla tiene cuatro pestañas: **📊 Resumen**, **🧾 Facturas de proveedores**, **📦 Órdenes de compra** y **🏢 Proveedores**.

## Leer el resumen

1. Abra **Finanzas → Gastos**. Primero se abre la pestaña **📊 Resumen**.
2. Lea los recuadros: **Pendiente** (el saldo sin pagar de las facturas), **Vencido** (el monto vencido y cuántas facturas), **Pagado este mes**, **Proveedores** y **Órdenes de compra abiertas** (órdenes en borrador o pedidas).
3. Desplácese hasta **Por estado** y **Gasto por categoría** para ver a dónde va el dinero.

## Agregar un proveedor

1. Abra la pestaña **🏢 Proveedores**.
2. Pulse **+ Nuevo proveedor**.
3. Escriba el **Nombre del proveedor** (el único campo obligatorio).
4. Agregue el **Nombre del contacto**, **Correo**, **Teléfono**, **Dirección** y **Tax / business #** (número fiscal o de empresa) si los tiene.
5. Elija el **Centro** al que atiende el proveedor, o deje **All centres**, y una **Categoría predeterminada** para que las facturas nuevas la tomen.
6. Pulse **Crear proveedor**. Aparece el aviso **Supplier saved** y el proveedor se suma a la lista, con lo que aún le debe.

> 💡 Un proveedor que ya no usa: abra el menú de su fila y elija **🗄 Archivar**. Se mantiene en las facturas anteriores y puede volver con **♻️ Restaurar**. Elimine solo un proveedor registrado por error.

## Emitir una orden de compra

[[show: #expenses @ Purchase orders > + New purchase order | Muéstreme Nueva orden de compra]]

1. Abra la pestaña **📦 Órdenes de compra**.
2. Pulse **+ Nueva orden de compra**.
3. Elija el **Proveedor**, y el **Centro** si la orden es para un solo centro.
4. Indique la **Fecha del pedido** y la **Categoría**.
5. En **Conceptos**, escriba para cada artículo una **Descripción**, la **Qty** y el **Unit $**, y luego el impuesto en **Tax ($)**. El subtotal y el total se actualizan mientras escribe.
6. Pulse **Crear orden de compra**. Aparece el aviso **PO created** y la orden se suma a la lista.

## Convertir una orden en factura

1. En la pestaña **📦 Órdenes de compra**, abra la orden.
2. Cuando llegue la factura del proveedor, pulse **→ Convertir en factura de proveedor**. La factura se crea con las líneas de la orden, sin escribir nada dos veces, y la pantalla pasa a **🧾 Facturas de proveedores**.
3. Si la orden no seguirá adelante, elija en su lugar **🚫 Cancelar el pedido** en el menú de su fila.

> 💡 **→ Convertir en factura de proveedor** sigue disponible en la orden, recibida o no, hasta que se crea una factura con ella (solo una orden cancelada lo pierde). Una vez convertida, la orden muestra **Billed:** y la referencia de la factura, para que nunca se facture dos veces.

## Registrar una factura de proveedor

1. Abra la pestaña **🧾 Facturas de proveedores**.
2. Pulse **+ Nueva factura de proveedor**.
3. Elija el **Proveedor**. Su categoría predeterminada se completa.
4. Elija el **Centro**, escriba el **Supplier invoice #** (número de factura del proveedor) y la **Fecha de vencimiento**.
5. Escriba los **Conceptos** y el impuesto en **Tax ($)**.
6. Pulse **Crear factura de proveedor**. Aparece el aviso **Bill created**.

Use el filtro de estado sobre la lista para ver solo facturas en **Borrador**, **Pendiente de aprobación**, **Aprobado**, **Pagado parcialmente**, **Vencido** o **Pagado**.

## Aprobar y pagar una factura

1. En la pestaña **🧾 Facturas de proveedores**, abra la factura (o elija **👁 Ver** en el menú de su fila).
2. Pulse **✓ Aprobar**. Aparece el aviso **Factura de proveedor aprobada**.
3. Al pagar, pulse **💵 Registrar pago**.
4. Escriba el **Monto**, elija el **Método** (**Bank transfer**, **Cheque**, **e-Transfer**, **Credit card**, **Cash** u **Other**) y agregue una **Reference (optional)**, como el número de cheque.
5. Pulse **Registrar pago**. Aparece el aviso **Pago registrado**. Un pago parcial deja el resto como saldo y la factura queda en **Pagado parcialmente**.

> ℹ️ Registrar un pago solo deja constancia de que usted pagó. KiddieTrac no envía dinero al proveedor.

Para cancelar una factura que no pagará, elija **🚫 Anular** en el menú de su fila y confirme. Queda registrada como **Void**.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de agencia | Todo, para cada centro y para los registros de toda la agencia. |
| Director de centro | Agrega y cambia proveedores, órdenes y facturas de sus propios centros. Ve los proveedores y registros de toda la agencia, pero no puede cambiarlos. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia donde trabaja. |

Los educadores y los padres no ven esta pantalla. Gastos forma parte de su plan de KiddieTrac; si el plan de su agencia no lo incluye, la pantalla no aparece en el menú.

## Preguntas frecuentes y problemas

??? No encuentro Gastos en el menú. | Está en **Finanzas** para administradores de agencia y directores de centro. Si no aparece, el plan de su agencia no incluye Gastos: consulte a su administrador de agencia.
??? Al guardar aparece "Choose one of your centres." | Como director de centro solo puede guardar registros de sus propios centros. Elija uno en **Centro**.
??? "Only a record of one of your centres can be changed." | La factura o la orden pertenece a otro centro o a toda la agencia. Pida a su administrador de agencia que la cambie.
??? "Elija un proveedor" o "Agregue al menos una línea" | Una factura u orden necesita un proveedor y al menos una línea con monto. Agréguelos y guarde de nuevo.
??? La lista dice "No bills yet." | No se ha registrado nada para sus centros. Pulse **+ Nueva factura de proveedor**, o convierta una orden de compra.
??? ¿Alguien recibe un correo cuando apruebo o pago una factura? | No. Gastos no envía correos ni notificaciones. Cada cambio queda en el registro de auditoría.
??? ¿Puedo vincular una factura a una subvención? | Sí. En **Finanzas → Subvenciones**, registre un gasto y elija la factura en **Linked expense bill (optional)**.

## Pantallas relacionadas

[[open: #grants | Subvenciones]] [[open: #external-billing | Contabilidad]]

Vea también: [Subvenciones](grants) · [Contabilidad](accounting) · [Nóminas, horas y recibos de pago](payroll-documents)
