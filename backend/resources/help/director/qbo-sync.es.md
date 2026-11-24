---
title: Sincronización con QuickBooks Online
category: Billing
order: 78
roles: agency_admin, platform_admin
---
# Sincronización con QuickBooks Online

**Ajustes → QuickBooks.** Envíe sus facturas, anulaciones, pagos y reembolsos de KiddieTrac a su
empresa de QuickBooks Online. Las familias se convierten en clientes, las facturas conservan sus números de KiddieTrac
y al sincronizar de nuevo se actualizan en lugar de crear copias. Es unidireccional: KiddieTrac → QuickBooks.

La pantalla tiene tres pasos, los mismos que en Xero y Sage.

## 1. Su app de Intuit

1. En [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), cree una app con el alcance **Accounting**.
2. En **Keys & credentials**, añada el **URI de redirección** que muestra KiddieTrac (use **Copiar**).
3. Pegue el **ID de cliente** y el **Secreto del cliente** en KiddieTrac.
4. **Entorno**: *Production* para su empresa real, o *Sandbox* para probar con una empresa de prueba y las claves de desarrollo.
5. **Guardar datos de la app.**

Si su agencia no tiene guardada una app propia, se usa la app de la plataforma KiddieTrac (la pantalla lo indica).

## 2. Conecte su empresa

Pulse **Conectar con QuickBooks →**, inicie sesión en Intuit, elija su empresa y pulse **Connect**.
La ventana se cierra y la pantalla muestra **Conectado a …**.

## 3. Productos, cuentas y sincronización

Las listas se leen en directo desde su empresa de QuickBooks.

| Ajuste | Qué hace |
|---|---|
| **Producto/servicio para la matrícula** (obligatorio) | Las líneas de factura usan este artículo, así que van a su cuenta de ingresos. |
| **Producto/servicio para las subvenciones** | La subvención entra como una línea negativa. En blanco usa el artículo de la matrícula. |
| **Depositar los pagos en** | Dónde se reciben los pagos. Predeterminado: Fondos sin depositar. |
| **Código de impuesto para líneas exentas** | Las empresas canadienses necesitan uno (normalmente *Exempt*). |
| **Código de impuesto para líneas con impuesto** | Solo para facturas que cobran impuesto; esas facturas esperan hasta que se elija uno. |
| **Solo facturas emitidas a partir del** | Su fecha de inicio en QuickBooks, para no duplicar facturas más antiguas. |
| **Registrar pagos y reembolsos** | Los pagos se reciben contra la factura; una factura reembolsada se elimina. |
| **Sincronizar automáticamente cada 30 minutos** | Desactivado de forma predeterminada. |

## Qué se envía

- **Familias → clientes**, que se vuelven a encontrar en cada sincronización en lugar de duplicarse.
- **Facturas emitidas** (nunca los borradores de KiddieTrac) con el mismo número de factura; la subvención y el descuento son líneas negativas para que el total coincida.
- **Los cambios** actualizan la misma factura de QuickBooks; una que ya esté pagada por completo en QuickBooks se deja como está.
- **Las anulaciones** se anulan en QuickBooks, salvo que allí tengan pagos aplicados.
- **Pagos y reembolsos**, como se indicó.

## Sincronizar ahora y el registro de sincronización

**Sincronizar ahora** envía todo lo pendiente e informa del resultado. El **Registro de sincronización** enumera cada
factura y pago con lo que ocurrió y por qué.

## Desconexión

**Desconectar** detiene la sincronización; lo que está en QuickBooks se queda. **Borrar y desconectar** también elimina
las credenciales de su app. Cambiar la app o el entorno desconecta, porque la conexión anterior
pertenece a la app anterior.

> **Antes de octubre de 2026**, la pantalla de QuickBooks podía conectarse, pero nunca enviaba realmente una factura.
> Nunca se envió nada, así que no hay nada que limpiar. Empiece por el paso 1.

Consulte también: [Xero](xero) · [Sage](sage)
