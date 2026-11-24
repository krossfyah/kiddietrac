---
title: Gastos (proveedores, órdenes de compra y facturas)
category: Billing
order: 85
roles: agency_admin, platform_admin
---
# Gastos

**Finanzas → Gastos.** Es el dinero que su agencia paga. Haga el seguimiento de proveedores, órdenes de compra y facturas de proveedores, desde el pedido hasta la aprobación y el pago.

[[open: #expenses | Abrir Gastos]]

La pantalla tiene cuatro pestañas.

## 📊 Resumen

- **Pendiente**: el saldo impagado de todas las facturas.
- **Vencido**: el importe que ha pasado su fecha de vencimiento y cuántas facturas son.
- **Pagado este mes**, **Proveedores** y **Órdenes de compra abiertas** (borradores y pedidos aún no recibidos).

Debajo de los mosaicos, el gasto se desglosa **Por estado** y **Gasto por categoría**.

## 🏢 Proveedores

**+ Nuevo proveedor** registra un proveedor: **Nombre del proveedor** (obligatorio), **Nombre del contacto**, **Centro**, **Correo**, **Teléfono**, **Categoría predeterminada**, **N.º de impuesto / empresa**, **Dirección** y un interruptor **Activo**. La lista muestra lo que aún debe a cada proveedor. Haga clic en un proveedor para editarlo o eliminarlo.

> **Consejo:** Desmarque **Activo** en un proveedor que ya no utilice. Permanece registrado con su historial en lugar de eliminarse.

## 📦 Órdenes de compra

[[show: #expenses @ Purchase orders > + New purchase order | Mostrarme Nueva orden de compra]]

**+ Nueva orden de compra**: elija el **Proveedor** y, opcionalmente, un **Centro**, indique la **Fecha del pedido** y la **Categoría**, y luego añada las **Líneas** y los impuestos. **Crear OC** la guarda como pedida.

Abra una orden de compra para avanzarla:

- **Marcar como recibido** cuando llegue la mercancía.
- **→ Convertir en factura** cuando llegue la factura del proveedor. Esto crea la factura a partir del pedido, así no se escribe nada dos veces. Se ofrece hasta que el pedido se marca como recibido.
- **Editar** para modificarla.

## 🧾 Facturas

Las facturas son las facturas de proveedores. **+ Nueva factura** registra una con su **Proveedor**, **Centro**, **N.º de factura del proveedor**, **Categoría**, **Fecha de vencimiento** y **Líneas**. Puede filtrar la lista por estado: **Pendiente de aprobación**, **Aprobada**, **Pagada parcialmente**, **Vencida**, **Pagada** o **Borrador**.

Abra una factura para actuar sobre ella:

- [ ] **✓ Aprobar**: para una factura en borrador o pendiente de aprobación.
- [ ] **💵 Registrar pago**: introduzca el **Importe**, el **Método** (transferencia bancaria, cheque, transferencia electrónica, tarjeta de crédito, efectivo u otro) y una **Referencia** opcional. Un pago parcial deja el resto como pendiente.
- [ ] **Anular**: cancela una factura que no debe pagar. Se le pide confirmación.

> **Nota:** Registrar un pago aquí deja constancia de que usted pagó. No envía dinero al proveedor.

## Quién puede usarlo

Está en el menú **Finanzas** para los **administradores de la agencia** y los **administradores de la plataforma**.

Vea también: [Contabilidad](accounting) · [Ciclos de nómina, horas y recibos de nómina](payroll-documents)
