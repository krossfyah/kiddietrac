---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
---
# Xero

**Ajustes → Xero.** Envíe sus facturas, anulaciones, pagos y reembolsos de KiddieTrac a su
organización de Xero. Las familias se convierten en contactos de Xero, las facturas conservan sus números de KiddieTrac y
al sincronizar de nuevo se actualizan en lugar de crear copias. Es unidireccional: KiddieTrac → Xero.

La pantalla tiene tres pasos. Cada uno desbloquea el siguiente.

## 1. Su app de Xero

KiddieTrac se conecta mediante **su propia** app de Xero, por lo que la conexión pertenece a su agencia.

1. Vaya a [developer.xero.com → My Apps](https://developer.xero.com/app/manage) y cree una **app web**.
2. Copie el **URI de redirección** de la pantalla de KiddieTrac (hay un botón Copiar) y péguelo en la app.
3. Copie el **ID de cliente** de la app, genere un **secreto de cliente** y pegue ambos en KiddieTrac.
4. **Tipo de app de Xero**: elija *Creada en marzo de 2026 o después* para una app nueva. Xero da
   permisos distintos a las apps más recientes; si Xero alguna vez muestra **«invalid scope»**, cambie este ajuste y vuelva a conectar.
5. **Guardar datos de la app.**

El secreto se almacena cifrado. Más adelante, deje el cuadro del secreto en blanco para conservar el guardado.

## 2. Conectar su organización

Pulse **Conectar con Xero →**. Xero se abre en una ventana nueva: inicie sesión, elija su organización y
pulse **Permitir acceso**. La ventana se cierra sola y la pantalla muestra **Conectado a …**.

Si dio acceso a más de una organización, KiddieTrac pregunta a cuál enviar las facturas.
Puede cambiar después con **Cambiar de organización**.

## 3. Cuentas y sincronización

Las listas de aquí se leen en vivo desde su organización de Xero.

| Ajuste | Qué hace |
|---|---|
| **Cuenta de ventas para la matrícula** (obligatoria) | Donde se contabilizan las líneas de factura. |
| **Cuenta para subsidios** | El subsidio (p. ej., CWELCC) entra como una línea negativa. En blanco usa la cuenta de matrícula. |
| **Cuenta bancaria para pagos** | Los pagos con tarjeta y bancarios recibidos en KiddieTrac se registran aquí. En blanco: los pagos todavía no se envían. |
| **Tasa de impuesto** | Solo para facturas que cobran impuesto. El cuidado infantil suele estar exento; una factura que cobra impuesto espera hasta que se elija una tasa. |
| **Las facturas llegan a Xero como** | *Aprobadas* (listas para recibir pagos) o *Borrador* (usted las aprueba en Xero). Los pagos solo se pueden registrar en facturas aprobadas. |
| **Solo facturas emitidas desde el** | Fije esto en su fecha de inicio en Xero para que las facturas anteriores no se envíen dos veces. |
| **Registrar pagos y reembolsos** | Envía los pagos contra las facturas y elimina un pago que fue reembolsado. |
| **Sincronizar automáticamente cada 30 minutos** | Desactivado por defecto. Desactivado significa que nada va a Xero hasta que pulse **Sincronizar ahora**. |

## Qué se envía

- **Familias → contactos.** Cada familia se convierte en un contacto de Xero, con el número de contacto `KT-` y el número de familia, para que se encuentre de nuevo en lugar de duplicarse.
- **Facturas emitidas** (enviadas, parcialmente pagadas, pagadas, vencidas) → facturas de venta con el mismo número. Los borradores de KiddieTrac nunca se envían. El subsidio y el descuento son líneas negativas separadas, así que el total coincide.
- **Los cambios** en una factura actualizan la misma factura de Xero. Una factura ya pagada en Xero se deja como está, y el registro de sincronización lo indica.
- **Las facturas anuladas** se anulan en Xero (o se eliminan, si todavía son un borrador de Xero). Una con pagos en Xero no se anula; quite primero los pagos allí.
- **Los pagos** se registran contra la factura en la cuenta bancaria que eligió; **los reembolsos** eliminan ese pago.

Nada de esto mueve dinero. Es solo contabilidad.

## Sincronizar ahora y el registro de sincronización

**Sincronizar ahora** envía todo lo pendiente e informa de lo creado, actualizado, anulado, omitido o
fallido. Una primera sincronización grande puede indicar *quedan más*; pulse **Sincronizar ahora** otra vez.

El **Registro de sincronización** lista cada resultado con el motivo, p. ej. *«Primero elija la cuenta de ventas de Xero para
la matrícula»* o *«Cambiada en KiddieTrac, pero la factura INV-0042 está pagada en Xero, así que se dejó como está».*

## Desconectar

**Desconectar** detiene la sincronización; todo lo que ya está en Xero se queda allí, y al reconectar la misma
organización se continúa donde se quedó. **Borrar y desconectar** también elimina el ID y el secreto de su app.

## Preguntas frecuentes

**«Xero dijo invalid scope».** Cambie el **Tipo de app de Xero** en el paso 1 y conecte de nuevo.

**«Xero terminó la conexión».** Xero revoca una conexión que no se usa durante 60 días, o que se eliminó
en Xero. Pulse **Conectar con Xero →** otra vez.

**«Una familia aparece dos veces en Xero».** El nombre de un contacto de Xero debe ser único. Si otro contacto
ya tenía el nombre de la familia, el de KiddieTrac se nombra con `(KT-…)` a continuación.

Véase también: [Sincronización con QuickBooks Online](qbo-sync) · [Sage](sage)
