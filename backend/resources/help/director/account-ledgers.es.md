---
title: Libros de cuentas
category: Billing
order: 40
roles: agency_admin, platform_admin
---
# Libros de cuentas

Una sola pantalla que responde, para cualquier persona: qué se le facturó, qué ha pagado, qué se le sigue debiendo y qué viene.

![El libro de cuentas: elija una persona y todo su historial financiero está en una sola página](https://api.kiddietrac.com/help-img/account-ledger.png)

## Cómo encontrar una cuenta

El selector de la parte superior tiene tres partes:

- **Rol** — reduce la lista a padres, educadores, directores, etc.
- **Buscar** — nombre o correo electrónico. Espera a que pulse **Intro** o el botón de búsqueda; no filtra mientras escribe.
- **Cuenta** — la persona en sí, mostrada como **nombre y rol entre paréntesis**: *Amarachi Ihenekwe (Padre/Madre)*.

La lista de cuentas deliberadamente **no** muestra saldos. En una lista de sesenta y ocho personas serían sesenta y ocho importes que ignorar para encontrar a alguien, y el saldo es lo primero que indica el libro al abrirse.

**⊞ Todas las cuentas** cambia a todas las cuentas a la vez, con lo que debe cada una. **📄 Una cuenta** vuelve atrás.

## Qué significan las cifras

Cuatro cifras siempre cuadran:

> **Facturado − (Cobrado − Reembolsado) = Saldo**

- **Facturado** — todo lo facturado, excluyendo lo anulado. Una factura anulada no tiene saldo y se informa por separado.
- **Cobrado** — lo que las propias facturas indican como liquidado.
- **Reembolsado** — dinero devuelto. Un reembolso *deshace un cobro*: el saldo vuelve a subir exactamente por lo devuelto.
- **Saldo** — lo que todavía se debe hoy.

**El dinero se cuenta una sola vez, a partir de la factura.** Cuando existe un registro de pago, se muestra en su propia línea —con el método, la referencia y la fecha real, que es mejor información que un resumen—, pero no se suma otra vez. Si alguna vez ambos discrepan, la factura tiene razón.

**El pago en exceso se informa, nunca se compensa.** Cuando se recibió más de lo facturado, la línea lo indica y el importe aparece en su propia cifra. Si se trata de un crédito que la familia puede usar o de un pago registrado dos veces en origen, lo decide la persona que lo lee; por eso se declara en lugar de absorberse en silencio.

## Próximamente

Todo lo que todavía se espera en la cuenta: cada pago de un calendario y cualquier facturación recurrente.

Cada fila indica **la factura que respalda el importe**, y su menú **⋮** abrirá, descargará o enviará por correo electrónico esa factura. Dos casos se señalan en lugar de ocultarse:

- **«Aún no se ha emitido factura»** — un calendario de facturación recurrente es solo *«emitiremos algo el día 1»*. El importe no se decide hasta que se emite, así que no hay nada que abrir.
- **«El total de la factura difiere de este importe»** — el calendario y la factura no coinciden, normalmente porque la factura se ajustó después de acordar el calendario. Conviene revisarlo.

Un pago omitido se conserva en esta tabla y se marca como **omitido**, no se elimina. Es lo más útil de la página.

## Aún pendiente

Todas las facturas impagadas, de la más antigua a la más reciente, con los días de retraso. El menú **⋮** de cada fila abre el documento de la factura.

## Generar un estado de cuenta

**📄 Generar estado de cuenta** crea un estado de cuenta completo. Desde esa ventana puede fijar un intervalo de fechas, leerlo en pantalla, descargar el PDF o enviarlo por correo electrónico.

El estado de cuenta lleva la marca de su agencia, los datos de contacto del titular y cada línea: facturas, recibos, reembolsos, anulaciones y lo que aún se debe. En un intervalo de fechas también muestra el saldo anterior y el saldo a trasladar.

**El envío por correo electrónico pide la dirección cada vez.** Aparece rellenada con la de la propia cuenta y debe confirmarse: no hay destinatario predeterminado, porque un estado de cuenta enviado a la persona equivocada no se puede deshacer.

Si el envío queda retenido —el interruptor de correo de la agencia está apagado o una regla de entrega bloquea a ese destinatario—, se le informa con claridad. Aquí «Enviado» significa que realmente salió.

## Emitir un reembolso

Consulte **[Reembolsar un pago](refunds)**.

## Una persona o todas las facturas

Esta pantalla responde a preguntas sobre **una cuenta**. Para trabajar con todas las facturas de la agencia, incluido registrar pagos, anular y filtrar por la parte con la que está la factura (padres, educadores, contratistas), use **Finanzas → Contabilidad**.

[[open: #external-billing | Abrir Contabilidad]]

Vea también: [Contabilidad](accounting) · [Reembolsar un pago](refunds) · [Calendarios de pago](payment-plans)
