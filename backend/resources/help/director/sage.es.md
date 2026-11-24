---
title: Sage (Sage Accounting y Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
---
# Sage

**Ajustes → Sage** cubre los dos productos de Sage. Elija la pestaña del que utilice:

- **☁️ Sage Accounting (nube)** se sincroniza en vivo, como Xero y QuickBooks.
- **🖥️ Sage 50 (escritorio)** no tiene conexión en línea, por lo que KiddieTrac genera los archivos que Sage 50 importa.

## Sage Accounting (nube)

### 1. Su aplicación de Sage

1. En [Sage Developer Self Service](https://developerselfservice.sageone.com/), cree una aplicación.
2. Añada la **URL de retorno** que aparece en la pantalla de KiddieTrac (use **Copiar**).
3. Pegue el **ID de cliente** y el **Secreto del cliente** de la aplicación en KiddieTrac y pulse **Guardar datos de la app**.

### 2. Conectar su negocio

Pulse **Conectar con Sage →**, inicie sesión y permita el acceso. Si tiene varios negocios en Sage,
elija aquel al que se enviarán las facturas (podrá cambiarlo más adelante).

### 3. Cuentas y sincronización

| Ajuste | Qué hace |
|---|---|
| **Cuenta de ventas para la matrícula** (obligatorio) | Dónde se contabilizan las líneas de factura. |
| **Cuenta para subvenciones** | La subvención entra como una línea negativa. En blanco usa la de matrícula. |
| **Cuenta bancaria para pagos** | Los pagos recibidos en KiddieTrac se convierten aquí en cobros de clientes. |
| **Tasa de impuesto para líneas exentas / con impuesto** | El cuidado infantil suele estar exento. Una factura que cobra impuesto espera hasta que se elija una tasa con impuesto. |
| **Solo facturas emitidas a partir del** | Su fecha de inicio en Sage, para no duplicar facturas anteriores. |
| **Registrar pagos y reembolsos** / **Sincronizar automáticamente cada 30 minutos** | Igual que en Xero. La sincronización automática está desactivada por defecto. |

Qué se envía: cada familia se convierte en un cliente de Sage (referencia `KT-` + número de familia); cada
factura emitida se convierte en una factura de venta cuya **referencia** es el número de factura de KiddieTrac (Sage numera
sus propias facturas); las anulaciones se anulan en Sage; los pagos se asignan a la factura y los reembolsos
los quitan. **Sincronizar ahora** y el **Registro de sincronización** funcionan exactamente igual que en la pantalla de Xero.

## Sage 50 (escritorio)

Sage 50 importa dos tipos de archivo: **registros** (clientes) y **asientos del libro diario general**.
KiddieTrac genera ambos.

### 1. Sus números de cuenta, y una cuenta que debe crear primero

Sage 50 **no** importa líneas de diario a su propia cuenta vinculada de **Cuentas por cobrar** una vez
configurada su empresa. Por eso, primero cree en Sage 50 una cuenta de activo para los saldos de las familias
(**Configuración → Plan de cuentas**), por ejemplo **1210 Cuentas por cobrar familias KiddieTrac**.

Después introduzca sus números de cuenta (de 4 a 8 dígitos) en KiddieTrac:

| Cuenta | Ejemplo | ¿Necesaria? |
|---|---|---|
| **Cuenta por cobrar de familias** | 1210 | Obligatoria. La cuenta que acaba de crear, no su cuenta por cobrar vinculada. |
| **Ingresos por matrícula** | 4100 | Obligatoria. |
| **Cuenta bancaria para pagos** | 1060 | Para incluir pagos. Debe ser distinta de la cuenta por cobrar. |
| **Cuenta de subvención** | 1220 | Opcional. En blanco: la matrícula se contabiliza neta de subvención. |
| **Cuenta de descuentos** | 4190 | Opcional. En blanco: la matrícula se contabiliza neta de descuentos. |
| **Impuesto por pagar** | 2310 | Solo si sus facturas cobran impuesto. |

### 2. Archivo de clientes

**⬇️ Descargar clientes (CSV)**: sus familias actuales, en el orden de columnas que espera Sage 50.
En Sage 50: **Archivo → Importar/Exportar → Importar registros → Clientes**, elija el archivo, compruebe que
las columnas coinciden y termine. Hágalo una vez y de nuevo cuando se incorporen familias nuevas.

### 3. Archivo de diario

1. Si lo desea, defina las fechas **De / Para**. La primera vez, ponga en **De** su fecha de inicio en Sage 50.
2. Pulse **Vista previa** para ver qué contendrá el archivo.
3. Pulse **⬇️ Descargar archivo de diario**.
4. Haga una copia de seguridad de su empresa en Sage 50 y luego **Archivo → Importar/Exportar → Importar transacciones → Importar asientos del libro diario general**, y elija el archivo `.TXT`.

Cada factura emitida es un asiento equilibrado: la cuenta por cobrar de familias se debita con lo que la familia
debe, la subvención y el descuento (si están asignados) se debitan, y los ingresos por matrícula y el impuesto se acreditan. Cada
pago debita el banco y acredita la cuenta por cobrar de familias.

**Cada archivo contiene solo lo nuevo desde el anterior.** Si los importes de una factura cambian después de
exportarla, el siguiente archivo incluye un **asiento de ajuste** por la diferencia; una factura exportada
que se anula, o un pago exportado que se reembolsa, genera un **asiento de reversión**.

Cada archivo que genere aparece en **Archivos generados** y se puede descargar de nuevo. Si Sage 50
rechaza un archivo, no se contabiliza nada: corrija los números de cuenta e importe el mismo archivo otra vez.

Vea también: [Xero](xero) · [Sincronización con QuickBooks Online](qbo-sync)
