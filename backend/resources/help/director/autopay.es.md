---
title: Pago automático (tarjeta guardada)
category: Billing
order: 76
---
# Pago automático

Guarde una tarjeta de crédito una sola vez y la cobraremos automáticamente por cada factura pendiente. Cancele cuando quiera.

## Configuración (padre o madre)

1. Barra lateral → **Pago automático**.
2. Haga clic en **+ Añadir tarjeta**.
3. Introduzca los datos de su tarjeta en el formulario de Stripe: número, vencimiento, CVC, código postal.
4. Haga clic en **Guardar tarjeta**. El pago automático se activa solo.

Los datos de su tarjeta nunca pasan por nuestros servidores: van directamente a Stripe mediante el formulario seguro.

## Cómo funciona el cobro

Una tarea programada diaria a las 03:00 (hora del Este) busca todas las facturas impagas de las familias con pago automático activado y cobra la tarjeta guardada. Un cobro exitoso inserta una fila de pago, reduce el saldo de la factura y cambia su estado a pagada cuando el saldo llega a cero.

Si un cobro falla (tarjeta vencida, fondos insuficientes, etc.), recibirá de inmediato una notificación `payment_failed` en su bandeja. Actualice la tarjeta y lo intentaremos de nuevo en la siguiente ejecución.

## Desactivarlo

Barra lateral → **Pago automático** → **Desactivar el pago automático**. Su tarjeta guardada permanece registrada (para que pueda reactivarlo más adelante), pero no se harán cobros automáticos.

## Cobro manual por parte de su proveedor

El director de su centro también puede pulsar **Cobrar tarjeta guardada** en cualquier factura; es útil si desea pagar antes o saldar un saldo de inmediato.
