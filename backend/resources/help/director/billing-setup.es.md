---
title: Configuración y ajustes de facturación
category: Billing
order: 10
roles: agency_admin, platform_admin
---
# Configuración y ajustes de facturación

**Ajustes → Facturación.** Todo lo que rige qué cobra su agencia y cómo lo cobra está en una sola pantalla dividida en pestañas.

[[open: #billing-settings | Abrir los ajustes de facturación]]

## Las pestañas

- **🧾 Impuesto**: su tasa de impuesto y cómo se llama en la factura (HST, GST, etc.). También hay un interruptor para marcar **Aplicar impuesto** por defecto en las facturas nuevas.
- **💵 Planes de matrícula**: planes de tarifas reutilizables. Consulte [Planes de matrícula](tuition-plans).
- **👨‍👩‍👧 Descuentos para hermanos**: consulte [Descuentos para hermanos](sibling-discounts).
- **⚙️ Valores predeterminados y cargos**: los ajustes que se describen a continuación.
- **🛠 Comunicaciones de facturación**: los interruptores de correo y SMS de la agencia, y el idioma predeterminado.
- **🔔 Recordatorios**: recordatorios automáticos sobre facturas próximas y vencidas. Consulte [Recordatorios de facturación automáticos](automated-billing-reminders).

Los administradores de la plataforma ven además una pestaña **🏦 Facturación de la plataforma**. Controla lo que KiddieTrac factura a las agencias, no lo que las agencias facturan a las familias. Consulte [Facturas de la plataforma](platform-invoices).

## Valores predeterminados y cargos

[[show: #billing-settings @ Defaults & fees > Billing defaults | Mostrarme la pestaña Valores predeterminados y cargos]]

**Valores de facturación predeterminados**: la **Frecuencia predeterminada** (semanal, quincenal o mensual), el **Depósito** y la **Cuota de inscripción** que aparecen preseleccionados al crear facturas y planes de tarifas.

**Planes de pago**: **Emitir las facturas con esta cantidad de días de antelación al vencimiento.** El valor predeterminado es **5** y el máximo es 60. Cada cuota de un plan de pago permanece como borrador pendiente hasta esa cantidad de días antes de su vencimiento. Entonces se emite automáticamente y la familia la debe. Fije **0** para emitirla el mismo día del vencimiento. Las fechas de vencimiento nunca cambian; este ajuste solo cambia cuánto aviso recibe la familia. Consulte [Planes de pago](payment-plans).

**Cargos por mora**: un **Cargo por mora (%)** sobre el saldo vencido, un **Tope** por cargo y un **Período de gracia (días)** después del vencimiento. Si nunca los ha cambiado, indican 1,5 %, 25 $ y 0 días. Consulte [Configuración de cargos por mora](late-fee-config).

> **Importante:** La ejecución automática de cargos por mora está actualmente desactivada para toda la plataforma, así que guardar una tasa aquí no empieza a cobrar a nadie. Consulte [Automatización de cargos por mora](late-fees).

**Métodos de pago aceptados**: marque las formas en que las familias pueden pagar: tarjeta de crédito o débito, transferencia bancaria (ACH), Interac e-Transfer, efectivo, cheque y otros.

**Pago automático**: **Inscribir a las nuevas familias en pago automático por defecto** y **Requerir pago automático (las familias deben mantener una tarjeta registrada)**.

**Recargos por pago**: una tasa de **Tarjeta de crédito / débito (%)** y una de **EFT / Interac e-Transfer (%)**. Cada una puede llegar al 10 % y ambas valen 0 por defecto. Un ejemplo resuelto bajo los cuadros muestra lo que cada tasa añade a un pago de 500 $. Consulte [Recargos por pago](payment-surcharges).

Haga clic en **Guardar configuración de facturación** para aplicar todo lo de esta pestaña.

## Ajustes relacionados en otros lugares

- **Proveedores de pago** (Ajustes): las cuentas que realmente reciben los pagos con tarjeta y bancarios. Consulte [Proveedores de pago](payment-providers).
- **Marca**: el estilo de las facturas, su numeración, el logotipo y el color. Consulte [Estilo y numeración de facturas](invoice-numbering-and-style).

> **Nota:** Solo los administradores de agencia y los administradores de la plataforma pueden cambiar estos ajustes. Los directores de centro solo pueden gestionar los recordatorios de facturación.

Vea también: [Recargos por pago](payment-surcharges) · [Proveedores de pago](payment-providers) · [Planes de pago](payment-plans) · [Configuración de cargos por mora](late-fee-config)
