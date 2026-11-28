---
title: Configuración y ajustes de facturación
category: Billing
order: 10
roles: agency_admin, platform_admin
---
# Configuración y ajustes de facturación

**Ajustes → Facturación** reúne todo lo que rige qué cobra su agencia y cómo: impuestos, planes de tarifas, descuentos para hermanos, valores predeterminados de las facturas, cargos por mora, métodos de pago, recargos, correos de facturación y recordatorios. Configúrelo una vez al empezar a facturar en KiddieTrac y vuelva cuando cambie una tarifa o una regla.

[[open: #billing-settings | Abrir ajustes de facturación]]

## Las pestañas

- **🧾 Impuesto**: su tasa de impuesto y cómo se llama en la factura.
- **💵 Planes de matrícula**: planes de tarifas reutilizables. Vea [Planes de matrícula](tuition-plans).
- **👨‍👩‍👧 Descuentos para hermanos**: vea [Descuentos para hermanos](sibling-discounts).
- **⚙️ Valores predeterminados y cargos**: valores predeterminados de las facturas, planes de pago, cargos por mora, cargo por recogida tardía, métodos de pago, pago automático y recargos.
- **🛠 Comunicaciones de facturación**: los interruptores de correo y SMS de la agencia y el idioma predeterminado.
- **🔔 Recordatorios**: recordatorios automáticos de facturas próximas y vencidas. Vea [Recordatorios de facturación automáticos](automated-billing-reminders).

Los administradores de la plataforma también ven **🏦 Facturación de la plataforma**: lo que KiddieTrac factura a las agencias, no lo que las agencias facturan a las familias. Vea [Facturas de la plataforma](platform-invoices).

La pantalla recuerda la última pestaña que usó.

## Definir su tasa de impuesto

1. Abra **Ajustes → Facturación**. Primero se abre la pestaña **🧾 Impuesto**.
2. Ingrese la **Tarifa** como porcentaje del subtotal de la factura, por ejemplo 13.
3. En **Llamado**, escriba el nombre que se imprime en la factura, por ejemplo HST o GST.
4. Marque **Tick "Apply tax" by default on new invoices** si su agencia está registrada para cobrar impuestos. Si no, déjelo sin marcar.
5. Revise la línea de ejemplo debajo de los campos: muestra lo que la tasa agrega a una factura de $100.00. Si la tasa pasa de 30 %, le avisa que parece un error de escritura.
6. Presione **Guardar cambios**. Aparece "✓ Saved" junto al botón y el aviso **Ajustes de facturación guardados** lo confirma.

El impuesto se aplica al subtotal: el cargo base más las líneas adicionales. En cada factura todavía puede cambiarse o quitarse, por ejemplo para un beneficiario al que no se le cobra impuesto.

## Definir valores predeterminados y cargos

[[show: #billing-settings @ Defaults & fees > Billing defaults | Mostrarme la pestaña Valores predeterminados y cargos]]

1. Abra la pestaña **⚙️ Valores predeterminados y cargos**.
2. En **Valores de facturación predeterminados**, elija la **Frecuencia predeterminada** (semanal, quincenal o mensual) e ingrese el **Deposit** y el **Registration fee** que se preseleccionan al crear facturas y planes de tarifas.
3. En **Planes de pago**, fije **Emitir las facturas esta cantidad de días antes de su vencimiento** (5 por defecto, hasta 60; 0 emite en la fecha de vencimiento).
4. En la misma tarjeta, fije **Mostrar a las familias las facturas impagas esta cantidad de días antes de su vencimiento** (14 por defecto). La pantalla Facturación de una familia y su total "a pagar ahora" solo incluyen facturas que vencen dentro de ese plazo; las vencidas siempre se muestran.
5. En **Cargos por mora**, ingrese el **Cargo por mora (%)** sobre el saldo vencido, el **Cap** (tope) por cargo y el **Período de gracia (días)** después del vencimiento.
6. En **Late pickup fee**, ingrese el **Fee per minute** (en su moneda, de 0 a 50) y el **Grace (minutes before the fee starts)** (de 0 a 120). Si nunca los cambió, indican 1.00 y 5.
7. En **Métodos de pago aceptados**, marque cada forma en que las familias pueden pagar: tarjeta, transferencia bancaria (ACH), transferencia Interac, efectivo, cheque u otro.
8. En **Pago automático**, marque **Inscribir a las nuevas familias en pago automático por defecto** y, si lo desea, **Requerir pago automático (las familias deben mantener una tarjeta registrada)**.
9. En **Recargos por pago**, ingrese una tasa **Credit / debit card (%)** y una tasa **EFT / Interac e-Transfer (%)** (cada una hasta 10 %, 0 por defecto). El ejemplo de abajo muestra lo que cada una agrega a un pago de $500.00.
10. Presione **Guardar configuración de facturación**. Aparece "✓ Saved." y el aviso **Configuración de facturación guardada** lo confirma.

### Qué hacen estos ajustes

- **Aviso de los planes de pago**: cada cuota de un plan de pago queda como borrador pendiente hasta esa cantidad de días antes de su vencimiento; luego se emite sola y la familia la debe. Las fechas de vencimiento nunca cambian; solo cambia el aviso. Vea [Planes de pago](payment-plans).
- **Cargos por mora**: si nunca los cambió, indican 1.5 %, $25 y 0 días. Vea [Configuración de cargos por mora](late-fee-config).
- **Late pickup fee**: cuando el personal registra una recogida tardía en **Recogidas tardías**, la tarifa sugerida es el monto por minuto por cada minuto después de la hora de cierre del centro, pasados los minutos de gracia. Con 1.00 y 5, un niño recogido 12 minutos tarde da una tarifa sugerida de $7.00. No se cobra nada hasta que un director o administrador lo aprueba; también puede cambiar el monto, condonarlo o rechazarlo. Vea [Recogidas tardías y cargos](late-pickup-fees).
- **Transferencia Interac**: la línea bajo los métodos de pago indica a dónde llegan las transferencias, o dice "Interac e-Transfer is not set up yet." con un enlace **Set up in Payment providers →**.
- **Credit/debit card (shown on invoices)**: agrega "Pagar con tarjeta de crédito o débito en la aplicación" a la sección Cómo pagar de las facturas y sus correos, una vez que sus pagos con tarjeta estén activos.
- **Anunciar las nuevas facturas a las familias**: cada factura nueva también aparece en las Noticias del padre que paga, solo para él, con su monto y los botones Ver factura / Pagar.
- **Los recargos** se cobran sobre el monto que se paga, no sobre el total de la factura, y nunca sobre efectivo ni cheque. El cargo aparece como su propia línea en la factura. Vea [Recargos por pago](payment-surcharges).

> ⚠️ El cobro automático de cargos por mora está apagado actualmente para toda la plataforma, así que guardar una tasa aquí no empieza a cobrarle a nadie. Vea [Automatización de cargos por mora](late-fees).

## Correo, SMS e idioma de facturación

1. Abra la pestaña **🛠 Comunicaciones de facturación**.
2. Marque o desmarque **✉️ Enable email for this agency (invoices, reminders, announcements)**.
3. Marque o desmarque **📱 Enable SMS for this agency (billing reminders, alerts)**.
4. Elija el **Idioma predeterminado** de los mensajes de facturación para los padres.
5. Presione **Guardar ajustes**.

> ⚠️ Apagar el correo detiene los correos de facturas, los recordatorios de facturación y los anuncios para toda la agencia, incluidos los estados de cuenta y los reenvíos desde Contabilidad.

## Quién puede hacer qué

- **Administradores de la agencia**: todas las pestañas.
- **Administradores de la plataforma**: todas las pestañas para la agencia elegida en el selector de la barra superior, más **🏦 Facturación de la plataforma**.
- **Directores de centro**: no tienen esta pantalla. Los recordatorios de facturación son su único ajuste de facturación; pida lo demás al administrador de su agencia.
- **Educadores** y **padres**: sin acceso.

## Preguntas frecuentes

??? La pestaña Impuesto dice "This section is available to agency administrators". | Su rol no puede cambiar los ajustes de facturación de la agencia. Pregunte al administrador de su agencia.
??? Una pestaña dice "Could not load this section." | Recargue la página. Si sigue pasando, contacte al soporte de KiddieTrac.
??? Guardé un cargo por mora, pero no se cobró a nadie. | El cobro automático de cargos por mora está apagado en toda la plataforma. No se cobra nada hasta que se active.
??? Cambié el cargo por recogida tardía. ¿Cambian las recogidas ya registradas? | No. Una recogida tardía conserva la tarifa sugerida calculada al registrarla. La nueva tarifa y la nueva gracia se aplican a las recogidas registradas después de guardar.
??? ¿Cambiar la tasa de impuesto cambia las facturas anteriores? | No. Se aplica a las facturas nuevas. Las ya emitidas conservan su propio impuesto.
??? ¿Cambiar los días de aviso mueve los planes de pago existentes? | No. Un plan conserva el aviso con el que se creó; el nuevo valor es el punto de partida de los planes nuevos.
??? No se pudo guardar. | El mensaje después de "Save failed:" dice por qué, por ejemplo un valor fuera de rango. Corrija el campo y guarde de nuevo.

## Pantallas relacionadas

[[open: #payment-providers | Proveedores de pago]] [[open: #external-billing | Contabilidad]] [[open: #payment-plans | Planes de pago]]

Vea también: [Recargos por pago](payment-surcharges) · [Proveedores de pago](payment-providers) · [Planes de pago](payment-plans) · [Configuración de cargos por mora](late-fee-config) · [Estilo y numeración de facturas](invoice-numbering-and-style)
