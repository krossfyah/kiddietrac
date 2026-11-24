---
title: Configuración de los cargos por mora
category: Billing
order: 74
roles: agency_admin, platform_admin
---
# Configuración de los cargos por mora

**Ajustes → Facturación → Valores predeterminados y cargos → Cargos por mora.** Cada agencia define su propia regla de cargos por mora.

[[show: #billing-settings @ Defaults & fees > Late fees | Mostrar los ajustes de cargos por mora]]

## Qué puede configurar

- **Cargo por mora (%)**: el porcentaje del saldo vencido que se cobra. Puede llegar hasta 25 %. Póngalo en **0** para desactivar los cargos por mora en su agencia.
- **Tope**: el máximo que puede ser un solo cargo por mora, en su moneda. Puede llegar hasta 500.
- **Período de gracia (días)**: cuántos días después de la fecha de vencimiento pasan antes de aplicar un cargo. Puede llegar hasta 60.

Haga clic en **Guardar configuración de facturación** para aplicar.

Si nunca los ha cambiado, aparecen **1,5 %**, un tope de **25 $** y **0** días de gracia. Son valores iniciales, no una elección de su agencia, así que revíselos antes de activar los cargos por mora.

> **Importante:** La ejecución automática de cargos por mora está actualmente **desactivada en toda la plataforma**, por lo que no se añade ningún cargo por mora a ninguna factura. Sus ajustes quedan guardados y se aplicarán cuando KiddieTrac active la ejecución. Vea [Automatización de cargos por mora](late-fees).

## Cómo se calcula el cargo

**Cargo = el saldo actual de la factura × su porcentaje, limitado a su tope.**

Por ejemplo, con 1,5 % y un tope de 25 $, un saldo vencido de 900 $ recibe un cargo de 13,50 $, y un saldo de 2000 $ recibe 25,00 $.

Vea también: [Automatización de cargos por mora](late-fees) · [Configuración y ajustes de facturación](billing-setup) · [Recordatorios de facturación automáticos](automated-billing-reminders)
