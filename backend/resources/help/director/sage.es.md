---
title: Sage (Sage Accounting y Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
---
# Sage (Sage Accounting y Sage 50)

**Ajustes → Sage** envía sus facturas y pagos de KiddieTrac a Sage. Cubre los dos productos de Sage; elija la pestaña del que usa:

- **☁️ Sage Accounting (cloud)** se sincroniza en vivo, como Xero y QuickBooks.
- **🖥️ Sage 50 (desktop)** no tiene conexión en línea, así que KiddieTrac genera los archivos que Sage 50 importa.

[[open: #sage | Abrir Sage]]

## Sage Accounting: configurar su aplicación de Sage

1. Abra la pestaña **☁️ Sage Accounting (cloud)**.
2. En [Sage Developer Self Service](https://developerselfservice.sageone.com/), cree una aplicación.
3. En la tarjeta de KiddieTrac **Su aplicación de Sage**, pulse **Copiar** junto a la **URL de retorno** y agréguela a la aplicación de Sage.
4. Pegue el **ID de cliente** y el **Secreto del cliente** de la aplicación en KiddieTrac.
5. Pulse **Guardar datos de la app**. Un aviso dice **Sage app details saved**.

## Sage Accounting: conectar su negocio

1. En la tarjeta **Conectar su negocio**, pulse **Conectar con Sage →**. Sage se abre en una ventana nueva.
2. Inicie sesión en Sage y permita el acceso.
3. Si tiene varios negocios en Sage, elija a cuál enviar las facturas y pulse **Usar este negocio**. Puede cambiarlo después con **Cambiar de negocio**.

## Sage Accounting: cuentas y sincronización

Las listas se leen en vivo desde Sage («Reading your accounts from Sage…»).

1. Elija la **Sales ledger account for tuition *** (obligatoria): donde se registran las líneas de factura.
2. Elija la **Cuenta contable para subsidios** (un subsidio como CWELCC entra como línea negativa), o deje **Igual que la matrícula**.
3. Elija la **Cuenta bancaria para pagos**: los pagos recibidos en KiddieTrac se convierten aquí en cobros de clientes.
4. Elija la **Tasa de impuesto para líneas exentas** (el cuidado infantil suele estar exento) y, solo si sus facturas cobran impuestos, la **Tasa de impuesto para líneas con impuesto**. Una factura que cobra impuestos espera hasta que se elija una tasa con impuesto.
5. Ajuste **Solo facturas emitidas a partir del** a su fecha de inicio en Sage, para que las facturas antiguas no se dupliquen.
6. Marque **Registrar pagos y reembolsos en las facturas de Sage** y, si quiere, **Sincronizar automáticamente cada 30 minutos** (desactivado de forma predeterminada).
7. Pulse **Guardar ajustes de cuentas y sincronización**. Un aviso dice **Sage settings saved**.
8. Pulse **Sincronizar ahora** y luego revise el **Registro de sincronización** para ver qué pasó con cada factura y pago, y por qué.

Qué se envía: cada familia se convierte en un cliente de Sage (referencia `KT-` + número de familia); cada factura emitida se convierte en una factura de venta cuya **referencia** es el número de factura de KiddieTrac (Sage numera sus propias facturas); las anulaciones se anulan en Sage; los pagos se aplican a la factura y los reembolsos los quitan.

**Desconectar** detiene la sincronización; todo lo que ya está en Sage se queda, y al reconectar se sigue desde donde quedó. **Borrar y desconectar** también quita el ID y el secreto de su app. **Cambiar aplicación** desconecta, porque otra app no puede usar la conexión actual.

## Sage 50: ingresar sus números de cuenta

Una vez configurada su empresa, Sage 50 **no** importa líneas de diario a su propia cuenta vinculada de **Accounts Receivable**, así que primero necesita una cuenta adicional.

1. En Sage 50, cree una cuenta de activo para los saldos de las familias (**Setup → Chart of Accounts**), por ejemplo **1210 KiddieTrac family receivables**.
2. En KiddieTrac, abra la pestaña **🖥️ Sage 50 (desktop)**.
3. En **Sus números de cuenta de Sage 50**, ingrese sus números de cuenta (de 4 a 8 dígitos):

| Cuenta | Ejemplo | ¿Necesaria? |
|---|---|---|
| **Cuenta por cobrar de familias** | 1210 | Obligatoria. La cuenta que acaba de crear, no su cuenta por cobrar vinculada. |
| **Cuenta de ingresos por matrícula** | 4100 | Obligatoria. |
| **Cuenta bancaria para pagos** | 1060 | Para incluir los pagos. Debe ser distinta de la cuenta por cobrar. |
| **Cuenta de subsidios** | 1220 | Opcional. En blanco: la matrícula se registra neta del subsidio. |
| **Cuenta de descuentos** | 4190 | Opcional. En blanco: la matrícula se registra neta de descuentos. |
| **Cuenta de impuestos por pagar** | 2310 | Solo si sus facturas cobran impuestos. |

4. Pulse **Guardar números de cuenta**. Verá **Sage 50 account numbers saved**.

## Sage 50: importar sus clientes

Hágalo una vez, y otra vez cuando se inscriban familias nuevas.

1. En **Customers file (once, then for new families)**, pulse **⬇️ Download customers (CSV)**. Sus familias actuales se descargan en el orden de columnas que espera Sage 50.
2. En Sage 50, elija **File → Import/Export → Import Records → Customers**.
3. Elija el archivo, revise que las columnas coincidan y termine.

## Sage 50: importar facturas y pagos

1. En **Journal file — invoices and payments**, si quiere, ajuste **From (optional)** y **Hasta (opcional)**. La primera vez, ajuste **From** a su fecha de inicio en Sage 50. Déjelos en blanco para incluir todo lo que aún no se ha exportado.
2. Pulse **Vista previa** para ver lo que tendrá el archivo, por ejemplo «12 invoices, 9 payments — 21 journal entries». Cualquier advertencia ⚠️ le dice qué corregir primero.
3. Pulse **⬇️ Download journal file**.
4. Haga una copia de seguridad de su empresa de Sage 50.
5. En Sage 50, elija **File → Import/Export → Import Transactions → Import general journal entries** y elija el archivo `.TXT`.

Cada factura emitida es un asiento balanceado: la cuenta por cobrar de familias se carga con lo que debe la familia, el subsidio y el descuento (si están asignados) se cargan, y los ingresos por matrícula y el impuesto se abonan. Cada pago carga el banco y abona la cuenta por cobrar de familias.

**Cada archivo contiene solo lo nuevo desde el anterior.** Si los montos de una factura cambian después de exportarla, el siguiente archivo trae un **asiento de ajuste** por la diferencia; una factura exportada que se anula, o un pago exportado que se reembolsa, recibe un **asiento de reversión**. Cada archivo que genera aparece en **📁 Files made** y se puede volver a descargar.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Configurar y usar las dos pestañas de Sage para su agencia. |
| Administrador de plataforma | Lo mismo, para la agencia que está viendo. |
| Director de centro | No ve esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? Sage 50 rechazó mi archivo de diario. | Cuando Sage 50 rechaza un archivo no se registra nada. Corrija los números de cuenta, vuelva a descargar el mismo archivo desde Files made e impórtelo.
??? ¿Por qué no puedo usar mi cuenta Accounts Receivable en Sage 50? | Sage 50 no acepta líneas de diario importadas en su cuenta por cobrar vinculada una vez configurada la empresa. Use la cuenta por cobrar de familias aparte.
??? La vista previa dice «Nothing new to export.» | Todo lo de ese rango de fechas ya se exportó. Cada archivo contiene solo lo nuevo desde el anterior; si lo necesita, vuelva a descargar un archivo anterior desde Files made.
??? Los pasos 2 y 3 de la pestaña cloud están en gris. | Cada tarjeta desbloquea la siguiente. Primero guarde los datos de la app y luego conéctese; el paso 3 dice «Connect first.» hasta que lo haga.
??? Una factura está en espera y no se envía a Sage Accounting. | Abra el registro de sincronización para ver el motivo, por ejemplo una factura que cobra impuestos sin que se haya elegido una tasa de impuesto para líneas con impuesto.
??? ¿Se avisa algo a las familias? | No. Desde esta pantalla no se envía nada a las familias.

## Pantallas relacionadas

[[open: #xero | Xero]] [[open: #quickbooks | QuickBooks]] [[open: #external-billing | Contabilidad]]

Vea también: [Xero](xero) · [Sincronización con QuickBooks Online](qbo-sync)
