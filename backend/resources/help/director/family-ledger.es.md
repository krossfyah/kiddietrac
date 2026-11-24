---
title: Libro mayor de la cuenta + estado de cuenta
category: Billing
order: 77
---
# Libro mayor de la cuenta

Su historial completo de facturas, pagos y reembolsos con un saldo acumulado. Se puede descargar como estado de cuenta en PDF con la marca de su agencia.

## Dónde encontrarlo

- **Padre/madre**: Barra lateral → **Libro mayor de la cuenta** bajo Cuenta.
- **Director / administrador**: Abra una familia en **Familias** → botón «Ver libro mayor». Ruta: `/api/v1/families/{id}/ledger`.

## Qué contiene

Cada fila está ordenada por fecha:

- Las **facturas** aparecen como débitos (dinero que usted debe)
- Los **pagos** aparecen como créditos (dinero recibido)
- Los **reembolsos** aparecen como débitos con el prefijo `Refund:`

La columna derecha muestra el **saldo acumulado** después de cada transacción. La parte superior de la página resume el saldo pendiente, el total facturado, el total pagado y el total reembolsado.

## Estado de cuenta en PDF

Toque **⤓ Descargar estado de cuenta en PDF** en la cabecera. Genera un PDF limpio con la marca de la agencia, con el logotipo de la agencia, la lista completa de transacciones y el saldo pendiente actual. Es útil para llevar registros o para fines fiscales.

## Diferente de la Cartera

La Cartera son sus métodos de pago guardados. El Libro mayor es su historial de transacciones. Son entradas distintas de la barra lateral.
