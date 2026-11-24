---
title: Facturas de la plataforma (facturación a las agencias)
category: Billing
order: 90
roles: agency_admin, platform_admin
---
# Facturas de la plataforma

**Revendedor → Facturas.** Es lo que las agencias deben a KiddieTrac por la plataforma: el precio de cada agencia, las facturas emitidas para ella y si se han pagado. Es independiente de las facturas que las agencias envían a las familias.

[[open: #sales-invoices | Abrir las facturas de la plataforma]]

## Planes de facturación: lo que se cobra a cada agencia

[[show: #sales-invoices @ Billing plans | Mostrarme los Planes de facturación]]

**Planes de facturación** abre una tabla con una fila por agencia:

- **Precio**, **Moneda** (CAD o USD) y **Cada** (con qué frecuencia se factura a la agencia).
- **Impuesto**: una tasa y su nombre, p. ej. 13 % HST. Una tasa superior a 0 debe tener nombre, porque un impuesto sin nombre se imprimiría como una simple línea «Impuesto».
- **Total**: el precio más el impuesto. Se actualiza mientras escribe, así que ve el impuesto antes de guardar.
- **Próxima factura**: la fecha en que se factura de nuevo a la agencia. **Una agencia sin fecha de próxima factura no está en facturación recurrente** y nunca se le factura automáticamente. Úselo para pausar a una agencia sin perder su tarifa.
- **Negocio…**: los datos de la agencia para el bloque **Facturar a**, como razón social, dirección, número de registro fiscal y correo electrónico de facturación. Lo que se deje en blanco se omite de la factura.

Haga clic en **Guardar** en la fila. El precio y los datos del negocio se guardan juntos.

## Emitir facturas

**Emitir las facturas que vencen ahora** factura a cada agencia cuya fecha de próxima factura ha llegado, y adelanta cada fecha según el intervalo de facturación de esa agencia. Primero muestra una vista previa, nombrando cada agencia e importe y listando las que se omitieron con el motivo. No se crea nada hasta que confirma.

Las facturas se emiten como **borradores**.

## Trabajar con una factura

Las pestañas superiores muestran **Abiertas**, **Pagadas** y **Anuladas**, con los totales de cada moneda. Cada fila ofrece solo lo que le corresponde:

- **Ver factura**: abre el PDF. Funciona en todas las filas, incluidas las anuladas.
- **Emitir**: solo borradores. Emitir registra la fecha; no envía ningún correo electrónico.
- **Enviar factura por correo electrónico**: pide la dirección cada vez. Si no da ninguna, no se envía nada. Puede usarlo en cualquier factura no anulada, incluida una ya emitida.
- **Marcar como pagada**: pide confirmación.
- **Anular**: pide confirmación. La factura permanece en el registro, atenuada, pero ya no cuenta como adeudada.
- **Editar factura**: solo borradores. Cambie el importe antes de impuestos, la fecha de vencimiento y la tasa de impuesto.
- **Eliminar borrador**: solo para un borrador que nunca se ha enviado por correo electrónico. Una vez que una agencia tiene una copia, la factura solo puede anularse.

## Facturación automática

**Ajustes → Facturación → 🏦 Facturación de la plataforma** controla la ejecución nocturna de las 06:00. Con los dos interruptores desactivados, nada ocurre por sí solo.

[[show: #billing-settings @ Platform billing > Raise invoices automatically | Mostrarme la Facturación de la plataforma]]

- **Emitir facturas automáticamente**: crea borradores para cualquier agencia cuya fecha de facturación haya llegado. No se envía nada.
- **Enviarlas por correo electrónico a la agencia automáticamente**: emite cada factura y la envía por correo electrónico al contacto de la agencia con el PDF adjunto. Una agencia sin dirección de contacto utilizable se queda como borrador.

**Los datos de su negocio**, la **Dirección comercial** y el **número de registro de GST/HST**, se imprimen en cada factura que emite KiddieTrac. El envío automático por correo electrónico **no puede funcionar hasta que ambos estén rellenados**, y la pestaña lo indica. Sin ellos, cada PDF saldría marcado como incompleto.

## Quién puede usarlo

Solo **los admins de la plataforma**. Ningún rol de agencia puede verlo.

Vea también: [Configuración y ajustes de facturación](billing-setup) · [Resumen del admin de la plataforma](platform-admin-overview)
