---
title: Contabilidad (todas las facturas en una lista)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
---
# Contabilidad

**Finanzas → Contabilidad** reúne todas las facturas de su agencia en una sola lista, sin importar con quién sean ni dónde se crearon. Desde aquí abre facturas, registra pagos, confirma transferencias Interac, reenvía, edita y anula. Ábrala cuando llega dinero, cuando una familia vuelve a pedir su factura o cuando hay que cancelar una factura.

[[open: #external-billing | Abrir Contabilidad]]

## Qué hay en la lista

Contabilidad lee los tres lugares de donde puede venir una factura, así que no falta dinero:

- **Facturas de KiddieTrac**: creadas dentro de KiddieTrac, por ejemplo con la Facturación masiva o un plan de pago. Llevan la etiqueta **KIDDIETRAC** bajo el número.
- **Facturas sincronizadas**: creadas en un sistema de facturación externo y sincronizadas.
- **Facturas de beneficiarios**: dinero facturado o pagado a alguien que no es una familia, como un educador o un contratista. La fila dice **Generada en Mi pago** en lugar de ofrecer acciones.

Las tarjetas **Pendiente** y **Collected** de arriba suman lo que muestra la lista, así que siempre describen el filtro elegido.

> ℹ️ Una factura pendiente de un plan de pago aparece en la lista, pero no se cuenta como pendiente de cobro. Todavía no se le ha pedido ese dinero a nadie. Vea [Planes de pago](payment-plans).

## Encontrar una factura

1. Elija una pestaña: **Pendiente** (todo menos lo anulado), **Pagado**, **Vencido**, **Anulada** o **e-Transfers to confirm**.
2. En la primera lista, elija con quién es la factura: **Todos**, **Padres**, **Educadores** o **Proveedores** (la palabra sigue el ajuste de su agencia), **Contratistas** u **Otro** (lo que aún no tiene tipo, listado para revisarlo).
3. Opcional: elija una familia en la lista de familias. Elegir algo distinto de Padres borra el filtro de familia, porque un contratista no tiene familia.
4. Opcional: busque por número de factura, descripción o estado.

[[show: #external-billing @ Voided | Mostrarme la pestaña Anulada]]

## Registrar un pago recibido

1. Busque la factura y abra su menú **⋮**.
2. Elija **Registrar pago**. La ventana muestra el **Invoice total** y lo que está **Pendiente**.
3. Revise el **Importe que se salda**. Empieza en el saldo completo; se permite un pago parcial.
4. Elija **Cómo se pagó**: transferencia Interac / EFT, efectivo, cheque, transferencia bancaria, tarjeta (cobrada fuera de línea) u otro.
5. Fije la **Fecha de recepción** y escriba el **Número de referencia** (número de transferencia o de cheque). Agregue una **Note (optional)** si sirve.
6. Si su agencia cobra un recargo por ese método, una casilla ofrece agregar el cargo por servicio. Está desmarcada salvo que usted la marque. Vea [Recargos por pago](payment-surcharges).
7. Presione **Registrar pago**. El saldo y el estado de la fila se actualizan.

## Confirmar una transferencia Interac que informó una familia

Cuando una familia indica en su pantalla de facturación que envió una transferencia, esta lo espera en la pestaña **e-Transfers to confirm** (la pestaña muestra cuántas hay).

1. Abra la pestaña **e-Transfers to confirm**, o use **⋮ > Confirm e-Transfer** en la factura.
2. La ventana **Confirm Interac e-Transfer** muestra de quién viene, el **Amount sent**, la **Reference** y las facturas que cubre.
3. Revise en su banco que la transferencia llegó antes de aprobarla.
4. Si su banco muestra otro monto, cambie **Amount received**.
5. Presione **✅ Approve & record payment**. El pago queda registrado en la factura o facturas.
6. Si no llegó, presione **Rechazar**, escriba el motivo en **Why? The family is sent this** y presione **Reject and tell the family**.

## Pedir una transferencia Interac a una familia

KiddieTrac nunca envía la solicitud de dinero; la envía su centro, desde la opción Solicitar dinero de su propio banco.

1. En una factura de KiddieTrac o sincronizada sin pagar, abra **⋮ > Enviar solicitud de transferencia electrónica**.
2. La ventana muestra lo que pide el formulario de su banco, cada dato con un botón **Copy**, y los contactos del tutor que paga en **Request it from**. Solo se ofrecen contactos que ya están en el perfil de un tutor.
3. En su banco, abra Transferencia Interac → Solicitar dinero y pegue los datos.
4. De vuelta en KiddieTrac, presione **Marcar como solicitado**. La factura registra la solicitud, y a la familia que la pidió se le indica que revise la aplicación de su banco.

## Otras acciones de una fila

**En una factura de KiddieTrac:** **Ver factura** (el personal la ve sin el botón Pagar de la familia), **Registrar pago**, **Reenviar factura**, **Editar** (para quién es y qué contiene), **Copias para los tutores** (la copia de cada tutor, cuando la factura se divide), **Descargar factura** y **Anular factura**.

**En una factura sincronizada:** **Ver** y **Descargar** cuando la fuente da un documento, **Editar**, **Reenviar factura**, **Registrar pago** y **Anular factura**.

> ⚠️ Una factura sincronizada pertenece al sistema de facturación del que vino. Editarla o anularla solo cambia la copia de KiddieTrac, y la próxima sincronización de ese sistema puede sobrescribir su cambio. Haga el mismo cambio en el origen si debe durar.

## Anular una factura

1. Abra el menú **⋮** de la factura y elija **Anular factura**.
2. Indique el motivo cuando se le pida y confirme.
3. La factura conserva su número y queda en la lista marcada **Void**, en la pestaña **Anulada**.

Qué más pasa: si la creó un plan de pago, esa cuota se retira del plan. La familia recibe un correo que dice que está cancelada y que no debe nada, y la oficina también recibe un correo. No se envía nada si el correo de su agencia está apagado.

Para anular varias, marque sus filas y use la barra de acciones masivas (**Descargar** o anular). Al anular varias se muestra primero el total que se cancela, y se omite toda factura con dinero recibido o ya anulada.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la agencia | Todo en esta pantalla, para cada centro |
| Director de centro | Todo en esta pantalla, para sus propios centros |
| Administrador de la plataforma | Todo, para la agencia elegida en el selector de la barra superior |
| Educador, padre | Sin acceso. Los padres ven sus facturas en su pestaña **Facturación** |

## Preguntas frecuentes

??? Anular factura no aparece o se rechaza. | Una factura con dinero pagado no puede anularse. Primero reembolse (Finanzas → Reembolsos) y después anule. Una factura con un pago en línea en curso también espera a que ese pago se acredite o falle.
??? No aparece Enviar solicitud de transferencia electrónica. | Solo aparece en una factura con saldo que no esté anulada, pagada, en borrador ni reembolsada.
??? La ventana de solicitud dice "No email or mobile on their profile." | Agregue un correo o un celular al perfil del tutor que paga y vuelva a intentarlo.
??? La lista está vacía. | Revise la pestaña y el filtro "con quién". Las facturas anuladas solo aparecen en la pestaña Anulada; un filtro de familia oculta a todas las demás.
??? Una cuota pendiente aparece en la lista pero no en Pendiente. | Todavía no se emitió, así que nadie la debe. Cuenta en cuanto empieza su periodo de aviso.
??? ¿Registrar pago envía un correo a la familia? | En una factura de KiddieTrac, sí: el pagador recibe un recibo por correo y, cuando la factura queda pagada por completo, se les avisa a la familia y a usted. No se envía nada si el correo de su agencia está apagado.
??? La ventana de transferencia dice "That e-Transfer no longer exists." | Otra persona ya la atendió. Actualice la pestaña.

## Pantallas relacionadas

[[open: #payment-plans | Planes de pago]] [[open: #refunds | Reembolsos]] [[open: #billing-schedule | Calendario de facturación]]

Vea también: [Libros de cuentas](account-ledgers) · [Reembolsar un pago](refunds) · [Planes de pago](payment-plans) · [Qué significa cada estado de factura](invoice-statuses)
