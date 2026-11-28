---
title: Panel de MRR
---
# Panel de MRR

**Platform › MRR dashboard** muestra los ingresos recurrentes de KiddieTrac: lo que pagan las agencias cada mes, cuánto valen las licencias gratuitas y de prueba, lo facturado y lo cobrado, quién debe dinero y qué licencias se renuevan pronto. Ábralo para la revisión mensual de ingresos, antes de una llamada de renovación o al cobrar facturas pendientes. La pantalla solo lee cifras; nada en ella cambia una licencia ni una factura.

[[open: #admin-mrr | Abrir el panel MRR]]

## Revisar los ingresos del mes

1. Abra **Platform › 💰 MRR dashboard**. El encabezado dice cuándo se calcularon las cifras (**as of …**).
2. Lea los cuatro mosaicos de color de arriba:
   - **MRR**: ingreso mensual recurrente de las licencias pagadas, con el cambio neto del mes (▲ o ▼), o *No change this month*.
   - **ARR**: MRR × 12.
   - **Paying agencies**: cuántas agencias pagan, del total de clientes, y cuántas son gratuitas o están a prueba.
   - **ARPA**: ingreso promedio por agencia que paga, por mes.
3. Lea la segunda fila: **Complimentary value** (lo que costarían las licencias gratuitas a precio de lista, por mes y por año), **Trial pipeline** (agencias a prueba a precio de lista), **Collected this month** (con lo facturado) y **Outstanding** (se pone rojo si hay atrasos, con el monto vencido y el número de facturas).
4. En **This month**, revise el movimiento del MRR: **New**, **Churned** y **Net**.

> ℹ️ ¿Todavía no hay clientes que paguen? Una nota indica cuántas agencias tienen licencia gratuita y cuánto valen a precio de lista. Ese valor nunca se cobra.

## Seguir la tendencia y la mezcla de planes

1. Mire **MRR, last 12 months**. La línea continua es el MRR pagado; la línea punteada es el valor gratuito a precio de lista.
2. Mire **Plan mix** para la parte del MRR por plan (o del valor gratuito, cuando nadie paga aún). Cada plan muestra cuántas agencias tiene.
3. Mire **Invoiced vs collected** para las facturas de KiddieTrac emitidas y los pagos recibidos cada mes. Los reembolsos se restan de la barra cobrada.

## Cobrar lo pendiente y preparar renovaciones

1. Revise **Receivables**: lo que deben las agencias, en **Current**, **1–30 days**, **31–60**, **61–90** y **90+**. Pase el cursor sobre un color para ver su monto.
2. Revise **Agencies by value**: el plan, el tipo, la fecha **Boarded** y el valor **Per month** de cada agencia; primero el MRR pagado y luego el valor gratuito.
3. Revise **Renewals, next 90 days**: cuándo termina cada licencia. **✓** en **Auto** significa que se renueva sola; **Ends** significa que termina si nadie la renueva.
4. Revise **By part number** para las líneas activas, las pagadas, el **MRR** y el **Comp / mo** por producto.
5. Para actuar sobre una agencia (renovar, cambiar, facturar), vaya a **Licences** o a **Accounting AR/AP**.

[[show: #admin-mrr @ Renewals, next 90 days | Muéstreme]]

## Quién puede hacer qué

| Rol | Qué ve |
|---|---|
| Administrador de la plataforma | Todo el panel, para todas las agencias. |
| Administrador de agencia, director de centro, cualquier otro | Nada: la pantalla no está en su menú y el servidor rechaza las cifras. Las agencias ven su propia factura en su pantalla de Facturación. |

## Preguntas frecuentes y problemas

??? Un mosaico muestra $0. | Todavía no existe nada de ese tipo, por ejemplo licencias pagadas o facturas este mes. Las tablas también lo dicen ("No agencies on a licence yet.", "Nothing owing.").
??? "No licence renews in the next 90 days." | Ninguna licencia termina en los próximos 90 días. Revise Licences para fechas posteriores.
??? Las cifras no coinciden con una factura. | El MRR lleva cada licencia pagada a un mes (una licencia anual cuenta como un doceavo). Facturado y cobrado son las facturas y pagos reales, sin reembolsos.
??? Aparece un mensaje rojo en lugar de la página. | No se pudieron cargar las cifras. Actualice; solo los administradores de la plataforma pueden abrir esta pantalla.
??? ¿El panel envía correos? | No. Solo lee. Los avisos de renovación y las facturas se envían desde Licences y Accounting AR/AP.

## Pantallas relacionadas

[[open: #licences | Licencias]] [[open: #sales-invoices | Contabilidad CxC/CxP]] [[open: #platform-sales | Informes de ventas]] [[open: #usage-spend | Uso y gasto]] [[open: #admin-features | Indicadores de funciones]]
