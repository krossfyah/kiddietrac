---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
---
# Xero

**Ajustes → Xero** envía sus facturas, anulaciones, pagos y reembolsos de KiddieTrac a su organización de Xero. Las familias se convierten en contactos de Xero, las facturas conservan sus números de KiddieTrac, y al volver a sincronizar se actualizan en lugar de crear copias. Va en un solo sentido: KiddieTrac → Xero. Aquí no se mueve dinero; es solo contabilidad.

[[open: #xero | Abrir Xero]]

La pantalla tiene tres tarjetas numeradas. Cada una muestra un ✓ al completarse y desbloquea la siguiente.

## Paso 1: configurar su aplicación de Xero

KiddieTrac se conecta mediante **su propia** aplicación de Xero, así que la conexión pertenece a su agencia.

1. Vaya a [developer.xero.com → My Apps](https://developer.xero.com/app/manage) y cree una **Web app**.
2. En la tarjeta de KiddieTrac **Su aplicación de Xero**, pulse **Copiar** junto a la **URI de redirección** y péguela en la aplicación de Xero.
3. Copie el **ID de cliente** de la aplicación en KiddieTrac.
4. Genere un **Secreto del cliente** en Xero y péguelo en KiddieTrac.
5. Ajuste **Tipo de app de Xero**: elija **Created March 2026 or later** para una app nueva, o **Created before March 2026** para una más antigua.
6. Pulse **Guardar datos de la app**. Un aviso dice **Xero app details saved**.

El secreto se guarda cifrado. Más adelante, deje vacío el campo del secreto para conservar el guardado.

## Paso 2: conectar su organización

1. En la tarjeta **Conectar su organización**, pulse **Conectar con Xero →**. Xero se abre en una ventana nueva.
2. Inicie sesión en Xero y elija su organización.
3. Pulse **Allow access**.
4. La ventana se cierra y la tarjeta muestra **Connected to** con el nombre de su organización.
5. Si dio acceso a más de una organización, elija a cuál enviar las facturas y pulse **Usar esta organización**.

Para cambiarla después, pulse **Cambiar de organización**, elija otra y pulse **Cambiar**.

## Paso 3: elegir cuentas y opciones de sincronización

Las listas se leen en vivo desde su organización de Xero («Reading accounts from Xero…»).

1. Elija la **Sales account for tuition *** (obligatoria): donde se registran las líneas de factura.
2. Elija la **Cuenta para subsidios** (un subsidio como CWELCC entra como línea negativa), o deje **Igual que la matrícula**.
3. Elija la **Cuenta bancaria para pagos**: aquí se registran los pagos con tarjeta y bancarios recibidos en KiddieTrac. Si la deja vacía, los pagos no se envían.
4. Elija la **Tax rate (only for invoices that charge tax)**. El cuidado infantil suele estar exento; una factura que cobra impuestos espera hasta que se elija una tasa.
5. Elija cómo **Las facturas llegan a Xero como**: **Approved (ready for payments)** o **Draft (you approve them in Xero)**. Los pagos solo se pueden registrar en facturas aprobadas.
6. Ajuste **Solo facturas emitidas a partir del** a su fecha de inicio en Xero, para que las facturas antiguas no se envíen dos veces.
7. Marque **Registrar pagos y reembolsos en las facturas de Xero** y, si quiere, **Sincronizar automáticamente cada 30 minutos**. Está desactivado de forma predeterminada; desactivado significa que nada va a Xero hasta que pulse **Sincronizar ahora**.
8. Pulse **Guardar ajustes de cuentas y sincronización**. Un aviso dice **Xero settings saved**.

## Sincronizar y leer el registro de sincronización

1. Pulse **Sincronizar ahora**. El botón dice **Syncing…** mientras envía.
2. Lea el resultado: lo que se creó, actualizó, anuló, omitió o falló. Una primera sincronización grande puede decir *more waiting*; pulse **Sincronizar ahora** otra vez.
3. Revise el **Registro de sincronización**. Lista cada resultado con su motivo, por ejemplo «Choose the Xero sales account for tuition first» o «Changed in KiddieTrac, but invoice INV-0042 is paid in Xero, so it was left as it is.»

Qué se envía:

- **Familias → contactos.** Cada familia se convierte en un contacto de Xero, marcado con el número de contacto `KT-` más el número de familia, para encontrarla de nuevo en lugar de duplicarla.
- **Facturas emitidas** (enviadas, pagadas en parte, pagadas, vencidas) → facturas de venta con el mismo número. Los borradores de KiddieTrac nunca se envían. El subsidio y el descuento son líneas negativas aparte, para que el total coincida.
- **Los cambios** en una factura actualizan la misma factura de Xero. Una factura ya pagada en Xero no se toca, y el registro de sincronización lo indica.
- **Las facturas anuladas** se anulan en Xero (o se eliminan si todavía son borradores en Xero). Una con pagos en Xero no se anula; quite primero los pagos allí.
- **Los pagos** se registran en la factura en la cuenta bancaria elegida; **los reembolsos** quitan ese pago.

## Cambiar de app o desconectar

1. Para usar otra app de Xero, pulse **Cambiar aplicación**. Xero se desconecta, porque otra app no puede usar la conexión actual; vuelva a conectar en el paso 2.
2. Para dejar de sincronizar, pulse **Desconectar** y confirme. Todo lo que ya está en Xero se queda allí, y al reconectar la misma organización se sigue desde donde quedó.
3. Para quitar también el ID y el secreto de su app, pulse **Borrar y desconectar**.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Configurar, conectar, sincronizar y desconectar Xero para su agencia. |
| Administrador de plataforma | Lo mismo, para la agencia que está viendo. |
| Director de centro | No ve esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? Xero dijo «invalid scope». | Xero da permisos distintos a las apps más nuevas. Cambie el Tipo de app de Xero en el paso 1, guarde y vuelva a conectar.
??? Xero terminó la conexión. | Xero revoca una conexión sin uso durante 60 días, o que se quitó en Xero. Pulse Conectar con Xero → (o Volver a conectar) de nuevo.
??? Una familia aparece dos veces en Xero. | El nombre de un contacto de Xero debe ser único. Si otro contacto ya tenía el nombre de la familia, el de KiddieTrac se nombra con (KT-…) al final.
??? Los pagos no llegan a Xero. | Elija una cuenta bancaria para pagos, marque el registro de pagos y reembolsos, y envíe las facturas como Approved: Xero no puede registrar un pago en un borrador.
??? Los pasos 2 y 3 están en gris. | Cada tarjeta desbloquea la siguiente. Primero guarde los datos de la app y luego conéctese; el paso 3 dice «Connect first.» hasta que lo haga.
??? ¿Se avisa algo a las familias? | No. Desde esta pantalla no se envía nada a las familias.

## Pantallas relacionadas

[[open: #quickbooks | QuickBooks]] [[open: #sage | Sage]] [[open: #external-billing | Contabilidad]]

Vea también: [Sincronización con QuickBooks Online](qbo-sync) · [Sage](sage)
