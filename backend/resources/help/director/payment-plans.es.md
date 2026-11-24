---
title: Planes de pago
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Planes de pago

**Finanzas → Planes de pago.** Un plan de pago reparte un total acordado en pagos con fecha, y cada pago genera su propia factura poco antes de su vencimiento. Esta función se llamaba antes *plan de pagos a plazos*; solo cambió el nombre.

[[open: #payment-plans | Abrir Planes de pago]]

![Planes de pago, con la factura y la fecha de vencimiento de cada pago](https://api.kiddietrac.com/help-img/payment-schedules.png)

## Cómo crear uno

[[show: #payment-plans @ + New payment schedule | Mostrarme el botón Nuevo plan de pago]]

**+ Nuevo plan de pago** funciona en dos pasos.

**Primero**, el esquema. Elija la familia e introduzca el **Importe total**, con qué frecuencia vence un pago (**Cada** mes, 2 semanas o semana) y las fechas de **Primer vencimiento** y **Último vencimiento**. Rellene **¿Para qué es esto?**, que se imprime en cada factura como la línea a la que corresponde el total. También puede añadir **Cargos adicionales** y **Notas** internas, que nunca se imprimen.

En **¿Cuándo vence cada cuota?**, elija una de estas opciones:

- **Dar aviso**: la factura se envía un número fijo de días antes de cada vencimiento. El número parte del ajuste de su agencia (por defecto **5**) y puede cambiarse para este plan.
- **Vencimiento inmediato**: cada factura se emite en su fecha de vencimiento y se debe el mismo día.

**Segundo**, la revisión. Cada pago aparece con su fecha e importe, y **todo se puede editar** antes de guardar: cambie cualquier importe, mueva cualquier fecha, o añada o quite una línea. No se escribe nada hasta que usted confirme.

Antes de guardar, elija **¿Cuándo se envían?**:

- **Mantener como borradores**: cada factura queda pendiente hasta que empieza su período de aviso y luego se emite automáticamente. No se debe nada antes.
- **Emitir ahora**: todas las facturas se emiten hoy y se deben de inmediato. Las fechas de vencimiento no cambian.

## Cuándo se emite cada factura

Cada pago genera una factura al guardar, retenida como borrador **Pendiente**. Un proceso diario emite cada una la mañana en que empieza su período de aviso. Por ejemplo, con 5 días de aviso, una cuota con vencimiento el día 23 se emite el 18. A una familia que acuerda en junio pagar hasta diciembre no se le envían siete facturas en junio.

Hasta que se emite, una factura pendiente no se debe: queda fuera del saldo de la familia, de las cifras pendientes del libro mayor de la cuenta y de todos los recordatorios de facturación. La fecha de vencimiento nunca se mueve; solo cambia el plazo de aviso.

El valor predeterminado para toda la agencia se define en **Ajustes → Facturación → Valores predeterminados y cargos → Planes de pago**. Véase [Configuración y ajustes de facturación](billing-setup).

> **Nota:** Emitir no envía ningún correo electrónico. La factura aparece en la pestaña Facturación de la familia. Use **Reenviar** si además quiere enviársela por correo electrónico.

## Cómo leer la tabla

| Columna | Qué muestra |
|---|---|
| **Fecha de vencimiento** / **Importe** | el pago en sí |
| **Factura** | el número de la factura correspondiente |
| **Emitida** | la fecha de emisión. En una fila pendiente aparece en gris y marcada como *programada*, porque aún no ha ocurrido |
| **Emitida por** | quién emitió la factura. En una fila pendiente, la persona que creó el plan aparece en gris. **Automático** significa que la emitió el proceso diario, e **Importada** que viene de un sistema de facturación externo |
| **Estado** | el estado de la factura, en la misma etiqueta de color que en Contabilidad |

Los estados que verá:

- **Pendiente**: aún no emitida. Espera su turno.
- **Programada**: emitida, pero aún sin vencer. No se ha incumplido nada.
- **Emitido**: emitida y vencida ahora.
- **Pago parcial**, **Pagado**, **Vencido** y **Anular** significan aquí lo mismo que en el resto de la aplicación.

El estado que se muestra es el de la **factura**, no el de la línea del plan. La factura es la que registra si llegó el dinero.

## Acciones de fila

El menú **⋮** de cada fila ofrece solo lo que se aplica a esa fila:

- **Ver factura** y **Descargar factura**
- **Emitir ahora**: solo filas pendientes. Emite la factura hoy; no se envía ningún correo electrónico
- **Reenviar**: solo filas emitidas. Primero confirma la dirección de correo electrónico
- **Editar cuota**: solo filas pendientes. Cambia este pago sin tocar el resto del plan

Para cambiar todo el plan, use **Editar plan** en la tarjeta del plan. Eso retira y vuelve a generar los pagos que aún no se han emitido.

**Seleccione varias filas** para actuar sobre ellas a la vez: **Emitir ahora**, **Descargar** o **Eliminar**. Solo las filas pendientes pueden emitirse o eliminarse. Las filas ya emitidas se omiten, y el mensaje le indica cuántas.

## Anular un pago

La anulación se hace desde **Contabilidad**, no desde esta pantalla, porque retira un documento y descuenta dinero del saldo de una familia. Cuando anula una factura que generó un plan, **su cuota se retira con ella**. Sale del plan, y el total y el número de cuotas del plan se recalculan, de modo que el plan nunca cuenta dinero que ya no existe. Véase [Contabilidad](accounting).

## Cancelar un plan

**Cancelar plan** lista las facturas del plan. Las pendientes aparecen marcadas para retirarse, y puede desmarcar las que quiera conservar. Las facturas que **ya se emitieron no pueden retirarse aquí**, porque puede haber un pago en camino. Anúlelas deliberadamente desde Contabilidad si eso es realmente lo que quiere.

## Dónde aparecen

Un plan vigente aparece en el **libro mayor de la cuenta** de la familia en **Próximamente**, y cada pago indica la factura correspondiente. Véase [Libros de cuentas](account-ledgers).

## Preguntas frecuentes

??? ¿Por qué no ha salido aún una factura pendiente? | Se emite la mañana en que empieza su período de aviso, que es la fecha de vencimiento menos los días de aviso. Use Emitir ahora para enviarla antes.
??? ¿Puedo cambiar el importe de un pago? | Sí, mientras siga pendiente: use Editar cuota en el menú de la fila. Una vez emitida, anúlela en Contabilidad y añada una de reemplazo.
??? ¿Cambiar el ajuste de aviso de la agencia afecta a los planes existentes? | Un plan conserva el período de aviso elegido al crearlo, incluso cuando se edita. El nuevo ajuste es el valor inicial para los planes nuevos.

Véase también: [Contabilidad](accounting) · [Libros de cuentas](account-ledgers) · [Qué significa el estado de una factura](invoice-statuses)
