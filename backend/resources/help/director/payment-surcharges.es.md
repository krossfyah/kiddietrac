---
title: Recargos por pago (comisiones de servicio de tarjeta y EFT)
category: Billing
order: 14
roles: agency_admin, centre_director, platform_admin
---
# Recargos por pago

**Ajustes → Facturación → Valores predeterminados y cargos → Recargos por pago.** Puede trasladar a las familias el costo de procesar los pagos con tarjeta y bancarios como una comisión de servicio visible.

[[show: #billing-settings @ Defaults & fees > Payment surcharges | Mostrarme los ajustes de recargos]]

## Las dos tasas

- **Tarjeta de crédito / débito (%)**: se usa cuando un pago se registra como **Tarjeta (cobrada sin conexión)**.
- **EFT / transferencia Interac (%)**: se usa cuando un pago se registra como **Transferencia Interac / EFT** o **Transferencia bancaria**.

Hay dos tasas porque los dos métodos le cuestan importes muy distintos. Cada tasa puede estar entre 0 y 10 %. Ambas empiezan en **0**, lo que significa que usted absorbe el costo y no se añade nada.

**El efectivo y el cheque nunca llevan recargo**, ni tampoco **Otro**. Sin un procesador al que pagar, una comisión sobre el efectivo sería una comisión por nada.

## Cuándo se añade la comisión

Un recargo **nunca se añade automáticamente**. Solo se añade cuando alguien registra un pago y marca la casilla correspondiente:

1. En **Contabilidad**, abra **Registrar pago** de la factura.
2. Introduzca el **Importe que se liquida** y elija **Cómo se pagó**.
3. Si ese método tiene una tasa, aparece una casilla con la comisión en dólares y el total a cobrar. Márquela para añadir la comisión.
4. Haga clic en **Registrar pago**.

Si el método no tiene tasa, o la tasa es 0, la casilla no aparece en absoluto.

> **Importante:** No se añade ninguna comisión de servicio a los pagos que las familias hacen en línea a través del portal. El recargo se aplica solo a los pagos que registra su personal.

## Cómo se calcula la comisión

La comisión es un **porcentaje del pago, no de la factura**. Una familia que paga 500 $ ahora y 500 $ el mes próximo paga la comisión sobre cada 500 $. Así es también como le factura el procesador.

El importe que introduce es el que se liquida, y la comisión se cobra además. Por ejemplo, al 2,9 %, liquidar un saldo de 500,00 $ añade una comisión de 14,50 $. El pago se registra como 514,50 $, y la factura queda en cero.

> **Consejo:** Introduzca el saldo que está liquidando, no el total con la comisión incluida. El cuadro de diálogo añade la comisión por usted.

## Lo que ve la familia

En una factura de KiddieTrac, la comisión se añade como una línea propia, por ejemplo **Comisión de servicio (2,9 % tarjeta)** o **Comisión de servicio (0,5 % transferencia Interac / EFT)**. Su recibo enumera la comisión de servicio por separado.

En una factura sincronizada desde un sistema de facturación externo, no hay una línea de KiddieTrac donde añadirla. La comisión se suma en cambio al total de la factura. Ese sistema de facturación sigue siendo el sistema de registro, por lo que su próxima sincronización puede restablecer el total.

## Preguntas frecuentes

??? ¿Por qué falta la casilla de la comisión en el cuadro Registrar pago? | El método elegido no tiene tasa de recargo (el efectivo, el cheque y Otro nunca la tienen), o la tasa de ese método es 0.
??? ¿Se cobra la comisión en un pago parcial? | Sí. Se cobra sobre el importe que introduzca como liquidado, así que cada pago parcial lleva su propia comisión.
??? ¿Cambiar una tasa afecta a las comisiones ya cobradas? | No. Una comisión se calcula cuando se registra el pago y queda guardada en la factura en ese momento.

Véase también: [Configuración y ajustes de facturación](billing-setup) · [Contabilidad](accounting) · [Proveedores de pago](payment-providers)
