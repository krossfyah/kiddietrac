---
title: Sincronización con QuickBooks Online
category: Billing
order: 78
roles: agency_admin, platform_admin
---
# Sincronización con QuickBooks Online

**Ajustes → QuickBooks (Intuit)** envía sus facturas, anulaciones, pagos y reembolsos de KiddieTrac a su empresa de QuickBooks Online. Las familias se convierten en clientes, las facturas conservan sus números de KiddieTrac, y al volver a sincronizar se actualizan en lugar de crear copias. Va en un solo sentido: KiddieTrac → QuickBooks. Aquí no se mueve dinero; es solo contabilidad.

[[open: #quickbooks | Abrir QuickBooks]]

La pantalla tiene tres tarjetas numeradas, igual que Xero y Sage. Cada una muestra un ✓ al completarse y desbloquea la siguiente.

## Paso 1: configurar su app de Intuit

1. En [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), cree una app con el alcance **Accounting**.
2. En la tarjeta de KiddieTrac **Su app de Intuit**, pulse **Copiar** junto a la **URI de redirección**.
3. En su app de Intuit, en **Keys & credentials**, pegue la URI de redirección.
4. Copie el **ID de cliente** y el **Secreto del cliente** de la app en KiddieTrac.
5. Elija el **Entorno**: *Production* para su empresa real, o *Sandbox* para probar con una empresa de prueba y las claves de Development.
6. Pulse **Guardar datos de la app**. Un aviso dice **QuickBooks app details saved**.

Si su agencia no tiene una app propia guardada, la pantalla dice «No app of your own saved — the KiddieTrac platform app is being used.» El secreto se guarda de forma privada; más adelante deje su campo vacío para conservar el guardado.

## Paso 2: conectar su empresa

1. En la tarjeta **Conectar su empresa**, pulse **Conectar con QuickBooks →**. QuickBooks se abre en una ventana nueva.
2. Inicie sesión en Intuit y elija su empresa.
3. Pulse **Connect** en la ventana de Intuit.
4. La ventana se cierra y la tarjeta muestra **Connected to** con el nombre de su empresa.

## Paso 3: elegir productos, cuentas y opciones de sincronización

Las listas se leen en vivo desde su empresa de QuickBooks («Reading your products and accounts from QuickBooks…»).

1. Elija el **Product/service for tuition *** (obligatorio). Las líneas de factura usan este artículo, así que van a su cuenta de ingresos.
2. Elija el **Producto/servicio para los subsidios** (un subsidio como CWELCC entra como línea negativa), o deje **Igual que la matrícula**.
3. Elija dónde **Depositar los pagos en** (predeterminado: **Undeposited Funds (default)**).
4. Elija el **Código de impuesto para líneas sin impuesto** (las empresas canadienses necesitan uno, normalmente *Exempt*) y, solo si sus facturas cobran impuestos, el **Código de impuesto para líneas con impuesto**.
5. Ajuste **Solo facturas emitidas a partir del** a su fecha de inicio en QuickBooks, para que las facturas antiguas no se dupliquen.
6. Marque **Registrar pagos y reembolsos contra las facturas en QuickBooks** y, si quiere, **Sincronizar automáticamente cada 30 minutos** (desactivado de forma predeterminada).
7. Pulse **Guardar ajustes de productos y sincronización**. Un aviso dice **QuickBooks settings saved**.

## Sincronizar y leer el registro de sincronización

1. Pulse **Sincronizar ahora**. El botón dice **Syncing…** mientras envía.
2. Lea el resultado y luego revise **📜 Registro de sincronización**. Lista cada factura y pago con lo que pasó y por qué.
3. Debajo del botón, **Last sync** muestra cuándo se ejecutó por última vez y si la sincronización automática está activada.

Qué se envía:

- **Familias → clientes**, que se encuentran de nuevo en cada sincronización en lugar de duplicarse.
- **Facturas emitidas** (nunca borradores de KiddieTrac) con el mismo número de factura; el subsidio y el descuento son líneas negativas para que el total coincida.
- **Los cambios** actualizan la misma factura de QuickBooks; una que ya está totalmente pagada en QuickBooks no se toca.
- **Las anulaciones** se anulan en QuickBooks, salvo que tengan pagos aplicados allí.
- **Los pagos** se reciben contra la factura; un pago reembolsado se elimina.

## Cambiar de app o desconectar

1. Para usar otra app de Intuit u otro entorno, pulse **Cambiar aplicación**. QuickBooks se desconecta, porque la conexión anterior pertenece a la app anterior; vuelva a conectar en el paso 2.
2. Para dejar de sincronizar, pulse **Desconectar** y confirme. Todo lo que ya está en QuickBooks se queda allí, y al reconectar la misma empresa se sigue desde donde quedó.
3. Para quitar también las credenciales de su app, pulse **Borrar y desconectar**.

> ℹ️ Antes de octubre de 2026, la pantalla de QuickBooks podía conectarse pero nunca enviaba una factura. Nunca se envió nada, así que no hay nada que limpiar. Empiece en el paso 1.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Configurar, conectar, sincronizar y desconectar QuickBooks para su agencia. |
| Administrador de plataforma | Lo mismo, para la agencia que está viendo. |
| Director de centro | No ve esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? Los pasos 2 y 3 están en gris. | Cada tarjeta desbloquea la siguiente. Primero guarde los datos de la app y luego conéctese; el paso 3 dice «Connect first.» hasta que lo haga.
??? «Could not read your products and accounts from QuickBooks.» | La conexión venció o se quitó en QuickBooks. Pulse Volver a conectar e inicie sesión de nuevo.
??? Una factura está en espera y no se envía. | Abra el registro de sincronización para ver el motivo. Uno común: una factura que cobra impuestos sin que se haya elegido un código de impuesto para líneas con impuesto.
??? «Choose the product/service for tuition.» | El paso 3 necesita un artículo de matrícula antes de poder guardar o sincronizar.
??? Aparecieron facturas antiguas duplicadas. | Ajuste Solo facturas emitidas a partir del a su fecha de inicio en QuickBooks antes de la primera sincronización.
??? ¿Se avisa algo a las familias? | No. Desde esta pantalla no se envía nada a las familias.

## Pantallas relacionadas

[[open: #xero | Xero]] [[open: #sage | Sage]] [[open: #external-billing | Contabilidad]]

Vea también: [Xero](xero) · [Sage](sage)
