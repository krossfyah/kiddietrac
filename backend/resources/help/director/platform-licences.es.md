---
title: Licencias (planes y complementos de las agencias)
---

# Licencias (planes y complementos de las agencias)

**Platform › 🔑 Licences** es donde KiddieTrac decide qué puede usar cada agencia. Cada agencia tiene una licencia: un plan (por ejemplo Starter, Growth o Agency) más sus complementos, cada uno con número de parte, precio, plazo, fecha de fin y ajuste de renovación. La licencia activa y desactiva funciones, y las renovaciones se facturan en **Platform › 🏦 Accounting AR/AP** cuando la facturación está activa. Al aprobar una propuesta se crea la licencia; esta pantalla sirve para agregar, cambiar, renovar o cancelar a mano.

[[open: #licences | Abrir licencias]]

## Leer la lista

1. Abra **Platform › 🔑 Licences**. Los mosaicos muestran **Live licences**, **Monthly recurring**, **Renew within 30 days** e **In grace (needs attention)**.
2. Filtre la lista con **Agency** y **Status** (**Active**, **Grace**, **Renews within 30 days**, **Expired**, **Cancelled**).
3. Cada agencia es un grupo. Presione su fila de encabezado para abrirlo o cerrarlo, o use **▾ Expand all** / **▸ Collapse all**. Las agencias que necesitan atención se abren solas.
4. El encabezado muestra el plazo (**Renews** o **Ends** en una fecha, o **Grace until** una fecha), cómo se factura, los complementos, el estado, los **🏫 centros abiertos / permitidos**, y **Trial** o **Complimentary** cuando corresponde.
5. Las líneas de abajo muestran **Part #**, **Item**, **Price**, **Term** y **Status**. **Not renewing** significa que esa línea termina en su fecha.

## Agregar una licencia

1. Presione **＋ Add licence**.
2. Elija la **Agency** y el **Plan or add-on**. El **Price per term** se llena desde el catálogo; cámbielo si acordó otro precio.
3. Elija el **Licence type**: **Paid**, **Complimentary (billed at list, zeroed)** o **Trial**. Una prueba pide su **Trial length** en días, semanas, meses o años, no cuesta nada, no se renueva y no genera factura.
4. Defina **Billed**, **Starts**, una **Note** opcional y **Renews automatically**.
5. Presione **Add licence**. Aparece un aviso **Licence added**.

> ℹ️ Un complemento siempre se une al plazo del plan de la agencia. Un plan para una agencia que ya tiene uno lo reemplaza y conserva su fecha de fin.

## Cambiar la licencia de una agencia

Abra el **⋮** en la fila de encabezado de la agencia:

1. **＋ Add add-on** (o **＋ Add plan** si no hay): elíjalo y presione **Add add-on**.
2. **📅 Term & renewal**: defina **Ends / renews on**, **Billed**, **Contract term (months)** y **Renews automatically**, y luego **Save for the whole licence**. El plan y todos los complementos comparten este plazo; una fecha de fin futura reabre una licencia en gracia o ya terminada.
3. **🔁 Renew all**: renueva todas las líneas juntas hasta una nueva fecha de fin. Aquí no se genera factura; la facturación genera la suya.
4. **💳 Convert to paid** (solo pruebas): cada línea toma su precio y se renueva desde su fecha de fin.
5. **🏫 Included centres…**: cambia cuántos centros permite la licencia. Déjelo vacío para usar el número del producto. Los centros existentes nunca se cierran; solo se bloquea abrir uno nuevo.
6. **⛔ Cancel licence**: el plan y todos los complementos se detienen de inmediato, excepto la facturación y el pago automático, que siguen hasta el final del periodo ya pagado.

En una sola línea, el **⋮** ofrece **✏️ Edit (price, quantity, note)** y, para complementos, **🗑️ Remove add-on**, cuyas funciones se apagan de inmediato.

> ⚠️ Después de quitar un complemento o cancelar una licencia, KiddieTrac ofrece **Reissue invoice** para cada factura abierta que todavía lo cobra. Reemitir anula esa factura y genera una copia corregida del mismo periodo; los pagos se trasladan. Envíela por correo desde Accounting AR/AP.

## Ventas por número de parte

Elija la pestaña **📊 Sales by part #** para ver cada **Part #** con **Live**, **Monthly recurring**, **Invoiced this year** e **Invoiced all time**.

## Quién puede hacer qué

- **Superadministradores de la plataforma**: todo en esta pantalla.
- **Administradores de agencia**: ven su propia licencia, solo lectura, en su página **Licence** (desde **🏢 My agency**), y desde ahí pueden pedir que se agregue una función. Vea [[plan-and-add-ons]].
- **Todos los demás**: nunca ven las licencias. Las funciones que no están en el plan se ocultan al personal y a las familias.

## Preguntas frecuentes y problemas

??? «Choose the agency and the plan or add-on» | Ambas listas deben llenarse antes de que **Add licence** funcione.
??? Una agencia dice que una función sigue bloqueada después de agregarla. | Las pantallas abiertas reciben el cambio en unos instantes; si no, aparece al pasar a otra pantalla.
??? ¿Qué es Grace? | La fecha de fin pasó sin renovación. La agencia conserva sus funciones mientras se genera la factura de renovación. Renueve, cambie el plazo o cancele.
??? La lista dice «No licences yet.» | Al aprobar una propuesta se crean, o presione **＋ Add licence**.
??? El número de centros aparece en rojo. | La agencia tiene más centros abiertos de los que permite la licencia. Agregue un complemento de centro extra o aumente **🏫 Included centres…**.

## Pantallas relacionadas

[[open: #sales-invoices | Contabilidad AR/AP]] · [[open: #platform-sales | Informes de ventas]] · [[open: #platform-agencies | Todas las agencias]] · [[open: #admin-features | Indicadores de funciones]]

Vea también: [[platform-invoices]] · [[plan-and-add-ons]] · [[platform-sales-reports]]
