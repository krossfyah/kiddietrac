---
title: Automatización de cargos por mora
category: Billing
order: 72
roles: agency_admin, platform_admin
---
# Automatización de cargos por mora

**Ajustes → Facturación → Valores predeterminados y cargos → Cargos por mora** es donde se define su regla. Este artículo explica cómo la aplica la ejecución automática.

> **Importante:** La ejecución de cargos por mora está actualmente **desactivada en toda la plataforma**. No se añade ningún cargo por mora de forma automática hasta que KiddieTrac la active. Añadir un cargo cambia lo que deben las familias, por lo que es una decisión deliberada y única, no un efecto secundario de guardar un ajuste.

## Cuándo se ejecuta

Una vez activada, la ejecución se realiza **cada noche a las 02:00 (hora de Toronto)**. Si se omite una noche, la siguiente ejecución lo recupera y ningún cargo se aplica dos veces.

## Qué facturas reciben un cargo

Una factura recibe un cargo por mora solo cuando se cumplen **todas** estas condiciones:

- Se creó en KiddieTrac. Las facturas sincronizadas desde un sistema de facturación externo no se tocan.
- Su estado es emitida, pago parcial o vencida. Una factura **Pendiente** de un plan de pago nunca se cobra.
- Todavía tiene saldo.
- Su fecha de vencimiento, más sus **días de gracia**, ya pasó.
- No ha recibido ya un cargo por mora **este mes calendario**.
- El porcentaje de cargo por mora de su agencia es superior a 0.

## Qué le ocurre a la factura

1. Se añade a la factura una línea llamada **Cargo por mora · AAAA-MM**, correspondiente al mes en que se cobró.
2. El total y el saldo de la factura aumentan por el importe del cargo.
3. Una factura emitida que aún no estaba marcada como atrasada pasa a **Vencida**.
4. Cada tutor de la familia recibe una notificación en la aplicación: *Cargo por mora aplicado a la factura …*, con el importe.
5. El cargo se registra en el registro de auditoría.

El cargo es el saldo × su porcentaje, limitado a su tope. Vea [Configuración de los cargos por mora](late-fee-config) para los ajustes.

## Detener los cargos por mora de una factura

Una factura pagada o anulada nunca se cobra. Para detener los cargos en una factura que no debe reclamarse, sáldela o anúlela en **Contabilidad**. Para detener los cargos por mora en toda su agencia, ponga **Cargo por mora (%)** en 0.

## Preguntas frecuentes

??? ¿Por qué no se ha cobrado ningún cargo por mora aunque una factura está vencida? | La ejecución de cargos por mora de toda la plataforma está actualmente desactivada. Sus ajustes están guardados y surtirán efecto cuando se active.
??? ¿Se puede cobrar dos veces a una familia en un mismo mes? | No. Una factura recibe como máximo un cargo por mora por mes calendario.
??? ¿Se aplican los cargos por mora a las facturas sincronizadas? | No. Solo a las facturas creadas en KiddieTrac.

Vea también: [Configuración de los cargos por mora](late-fee-config) · [Recordatorios de facturación automáticos](automated-billing-reminders) · [Contabilidad](accounting)
