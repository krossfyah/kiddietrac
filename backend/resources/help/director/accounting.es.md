---
title: Contabilidad (todas las facturas en una lista)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
---
# Contabilidad

**Finanzas → Contabilidad.** Todas las facturas de la agencia están en una sola lista, sea con quien sea y donde sea que se hayan emitido. Desde aquí puede abrir facturas, registrar pagos, reenviarlas, editarlas y anularlas.

[[open: #external-billing | Abrir Contabilidad]]

## Qué hay en la lista

Contabilidad lee los tres lugares de los que puede proceder una factura, de modo que no falta ningún importe:

- **Facturas de KiddieTrac**: emitidas dentro de KiddieTrac, por ejemplo mediante una ejecución mensual o un calendario de pagos. Llevan la etiqueta **KIDDIETRAC** bajo el número.
- **Facturas sincronizadas**: emitidas en un sistema de facturación externo y sincronizadas.
- **Facturas de beneficiarios**: dinero facturado a, o pagado a, alguien que no es una familia, como un educador o un contratista. Se emiten en **Mi pago**, y la fila dice **Emitida en Mi pago** en lugar de ofrecer acciones.

Las tarjetas **Pendiente** y **Cobrado** de la parte superior suman lo que muestre la lista, así que siempre describen el filtro que haya elegido.

> **Nota:** Una factura pendiente de un calendario de pagos aparece en la lista pero **no se cuenta como pendiente**. Todavía no se ha pedido ese dinero a nadie. Consulte [Calendarios de pago](payment-plans).

## Filtrar

**Pestañas**: **Pendiente** (todo salvo lo anulado), **Pagado**, **Vencido** y **Anulada**. Las facturas anuladas quedan fuera de la vista predeterminada porque ya no se deben, pero están a un clic.

[[show: #external-billing @ Voided | Mostrarme la pestaña Anulada]]

**Con quién está la factura**: la primera lista reduce las facturas a un tipo de contraparte:

- **Todos**
- **Padres**: todas las facturas dirigidas a familias, ya sean de KiddieTrac o sincronizadas
- **Educadores** o **Proveedores**: la palabra sigue el ajuste de su agencia sobre cómo se llaman sus instalaciones
- **Contratistas**
- **Otro**: todo lo que no tiene un tipo asignado. Se muestra en lugar de ocultarse, porque una fila sin etiqueta es justo la que conviene revisar

Elegir cualquier opción distinta de Padres borra el filtro de **familia**, porque un contratista no tiene familia.

**Familia** y **búsqueda** (número de factura, descripción o estado) funcionan sobre lo que esté seleccionado.

## Acciones de fila

**En una factura de KiddieTrac:**

- **Ver factura**: abre la hoja de la factura. El personal ve la factura sin el botón **Pagar** de la familia, para que nadie pague por error la factura de una familia con su propia tarjeta.
- **Registrar pago**: vea más abajo.
- **Reenviar factura**
- **Editar**: cambia a quién va dirigida la factura y lo que contiene.
- **Descargar factura**
- **Anular factura**

**En una factura sincronizada:** **Ver** y **Descargar** (cuando el origen proporciona un documento), **Editar**, **Reenviar factura**, **Registrar pago** y **Anular factura**.

> **Importante:** Una factura sincronizada pertenece al sistema de facturación del que procede. Editarla o anularla solo cambia la copia de KiddieTrac, y la próxima sincronización de ese sistema puede sobrescribir su cambio. Haga el mismo cambio en el origen si debe perdurar.

## Registrar un pago

**Registrar pago** abre un cuadro de diálogo que muestra el total de la factura y lo pendiente. Introduzca:

- **Importe que se liquida**. Mientras escribe, le indica si esto salda la factura por completo, deja un saldo o supera lo que se debe. Se permite un pago parcial.
- **Cómo se pagó**: transferencia electrónica / EFT, efectivo, cheque, transferencia bancaria, tarjeta (cobrada fuera de línea) u otro.
- **Fecha de recepción**, un **Número de referencia** y una **Nota** opcional.

Si su agencia cobra un recargo por tarjeta o EFT, aparece una casilla que ofrece añadir la tarifa de servicio. Consulte [Recargos por pago](payment-surcharges).

## Anular una factura

**Anular factura** cancela la factura definitivamente, tras confirmarlo e indicar un motivo:

- Conserva su número y permanece en la lista marcada como **Anulada**.
- Si la emitió un calendario de pagos, **esa cuota se retira** del calendario.
- Se envía un correo electrónico a la familia para avisar de que está cancelada y de que no debe nada, y también a la oficina. No se envía nada si el correo de su agencia está desactivado.

Una factura con dinero pagado no puede anularse. Reembolse primero el dinero y después anule. Una factura con un pago en línea todavía en curso tampoco puede anularse hasta que ese pago se liquide o falle.

## Trabajar con varias a la vez

Marque filas para actuar sobre ellas conjuntamente: **Descargar** y **Anular**. Al anular varias, primero se muestra el total que se va a cancelar, y se omite cualquier factura que tenga dinero pagado o que ya esté anulada.

## Quién puede usarlo

**Administradores de agencia**, **directores de centro** y **administradores de plataforma**.

Vea también: [Libros de cuentas](account-ledgers) · [Reembolsar un pago](refunds) · [Calendarios de pago](payment-plans) · [Qué significa el estado de una factura](invoice-statuses)
