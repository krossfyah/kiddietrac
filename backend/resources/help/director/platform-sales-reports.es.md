---
title: Informes de ventas de KiddieTrac
---

# Informes de ventas de KiddieTrac

**Platform › 💹 Sales reports** muestra lo que KiddieTrac mismo ha vendido y facturado: ingresos por número de parte, plan, complemento, servicio y cliente. Son cifras solo de la plataforma. Ninguna agencia las ve, y no tienen nada que ver con lo que las agencias cobran a las familias. Úselo para los cierres de mes y de año, para ver qué productos se venden y para revisar cuánto dinero facturado sigue sin pagarse.

[[open: #platform-sales | Abrir informes de ventas]]

## Elegir el periodo

1. Abra **Platform › 💹 Sales reports**. Empieza en **This year**.
2. Presione un botón de periodo: **This year**, **This quarter**, **This month**, **Last 12 months** o **All time**.
3. O elija sus propias fechas en **From** y **to**. Los botones de periodo se desactivan y el informe se recarga con sus fechas.

## Leer los mosaicos

| Mosaico | Qué cuenta |
|---|---|
| **Revenue invoiced** | Facturas de la plataforma en el periodo, antes de impuestos, con el número de facturas |
| **Collected** | Pagos recibidos y la parte de lo facturado con impuestos incluidos |
| **Outstanding** | Facturado pero aún no pagado |
| **Recurring (MRR)** | Ingreso mensual recurrente a hoy, con la cifra anual (ARR) |
| **Booked on proposals** | Propuestas firmadas en el periodo, facturadas o no |
| **Paying customers** | Agencias facturadas en el periodo |
| **Tax collected** | Impuestos de las facturas del periodo |

Las facturas anuladas nunca se cuentan.

## Leer los gráficos y las tablas

1. **📈 Invoiced vs collected — last 12 months**: compare lo facturado cada mes con lo que entró. Una diferencia significa dinero que aún se debe.
2. **🧩 Revenue by kind**: la división entre **Plans**, **Add-ons**, **Services**, **Discounts** y **Other**.
3. **🏆 Top customers**: las ocho agencias con más facturación en el periodo.
4. **🔢 Invoiced by part number**: cada artículo facturado con **Part #**, **Item**, **Kind**, **Qty**, **Invoiced** y **Share**, y una fila **Total**.
5. **📝 Sold on proposals by part number**: lo vendido en propuestas firmadas o aprobadas, incluidos servicios únicos aún sin facturar.

> 💡 Las facturas antiguas, hechas antes de tener líneas detalladas, cuentan bajo su plan, así que su número de parte puede ser el del plan.

## Usar las cifras

1. Para revisar las facturas de un solo cliente, abra **Platform › 🏦 Accounting AR/AP** y escriba el nombre de la agencia en el filtro de la tabla.
2. Para cambiar el precio de un producto o su número de parte, use **Platform › 🔑 Licences** y el catálogo de planes; este informe solo lee lo que se facturó.
3. Para ver las propuestas detrás de **Booked on proposals**, abra **Sales › Proposals**.

## Quién puede hacer qué

- **Superadministradores de la plataforma**: ven todo el informe.
- **Representantes de ventas**: no ven esta pantalla. Ven sus propias propuestas en **Sales › Proposals**.
- **Administradores de agencia y todos los demás roles**: no pueden verla; cualquier otra persona que la abra ve **KiddieTrac sales reports are for superadmins.**

## Preguntas frecuentes y problemas

??? Apareció «Could not load the report». | Se cortó la conexión o terminó su sesión. Actualice la página y elija el periodo otra vez.
??? Las tablas dicen «Nothing in this range.» | No hay facturas ni propuestas firmadas entre sus fechas **From** y **to**. Pruebe **All time**.
??? ¿Por qué Booked on proposals es mayor que Revenue invoiced? | Las propuestas cuentan al firmarse; las facturas llegan a lo largo de los periodos de facturación del contrato. Los servicios únicos tal vez aún no se facturan.
??? ¿Por qué la parte de Collected no es la que esperaba? | Compara los pagos con lo facturado con impuestos incluidos, mientras que **Revenue invoiced** es antes de impuestos.
??? ¿Los descuentos bajan los totales? | Sí. Aparecen como su propio tipo en **🧩 Revenue by kind** y reducen lo facturado.

## Pantallas relacionadas

[[open: #sales-invoices | Contabilidad AR/AP]] · [[open: #licences | Licencias]] · [[open: #admin-mrr | Panel de MRR]] · [[open: #sales-quotes | Propuestas]]

Vea también: [[platform-invoices]] · [[platform-licences]]
