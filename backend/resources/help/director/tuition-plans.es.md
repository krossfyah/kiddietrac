---
title: Planes de matrícula y cuotas
category: Billing
order: 20
roles: agency_admin, centre_director, platform_admin
---
# Planes de matrícula y cuotas

Un plan de cuotas es un monto con nombre y la frecuencia con que se factura, por ejemplo "Niños pequeños tiempo completo, $1,250 mensual". Usted crea los planes una vez y luego los usa para crear las facturas de las familias de ese plan. Los directores lo encuentran en **Finanzas → Planes de matrícula**; los administradores de agencia también lo tienen en **Ajustes → Facturación**, pestaña **💵 Planes de matrícula**.

[[open: #tuition-plans | Abrir Planes de matrícula]]

## Crear un plan

1. Abra **Finanzas → Planes de matrícula** (o **Ajustes → Facturación → 💵 Planes de matrícula**).
2. Pulse **+ Nuevo plan**. Se abre la ventana **Nuevo plan de cuotas**.
3. Escriba el **Nombre del plan**, por ejemplo "Niños pequeños tiempo completo".
4. Escriba el **Amount ($)** (monto).
5. Elija la **Frecuencia de facturación**: **Semanal**, **Quincenal**, **Mensual** u **One-time** (una sola vez).
6. Si el plan es para un solo centro, elíjalo en **Centre (optional)**. Agregue un **Age group (optional)** y una **Description (optional)** si ayudan.
7. Deje marcada la casilla **Active (available to apply to families)**.
8. Pulse **Crear plan**. Aparece el aviso **Plan saved** y el plan se suma a la lista con su monto y frecuencia.

## Cambiar o retirar un plan

1. Haga clic en la fila del plan. Se abre la ventana **Editar plan**.
2. Cambie lo necesario y pulse **Guardar cambios**.
3. Para dejar de usar un plan sin borrarlo, desmarque **Active (available to apply to families)** y guarde. Aparece como **INACTIVE** en la lista y pierde su botón **💸 Generar facturas**.
4. Para eliminar un plan creado por error, pulse **Eliminar** y confirme. Aparece el aviso **Plan deleted**.

> ℹ️ Cambiar el monto de un plan no modifica las facturas ya creadas. Las facturas nuevas usan el monto nuevo.

## Crear facturas a partir de un plan

[[show: #tuition-plans @ Generate invoices | Muéstreme Generar facturas]]

1. En un plan activo, pulse **💸 Generar facturas**.
2. En **Crear como**, elija **Draft (review first)** (borrador, para revisarlas antes de que las vean las familias) o **Sent (issue now)** (emitidas de inmediato).
3. Indique **Due in (days)** (vencimiento en días). Empieza en 14.
4. En **Familias**, marque las familias a facturar, o pulse **Seleccionar todo**. Un plan de un solo centro muestra solo las familias de ese centro.
5. Pulse **Generar facturas**. Un aviso indica cuántas se crearon, por ejemplo "Generated 12 invoices (draft)."
6. Abra **Finanzas → Contabilidad** para encontrar las facturas nuevas, revisar los borradores y enviarlos.

Cada factura tiene una línea de matrícula con el nombre, la frecuencia y el monto del plan, con fecha de hoy, numerada como su agencia acostumbra, en la moneda de su agencia (según **Country & compliance**).

> ⚠️ **Generar facturas** crea una factura nueva cada vez que lo pulsa. Pulsarlo dos veces para las mismas familias les cobra dos veces: anule las sobrantes desde la lista de facturas.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de agencia | Crea, edita y elimina todos los planes, de toda la agencia o de un centro, y genera facturas con ellos. |
| Director de centro | Ve los planes de sus propios centros y los de toda la agencia. Crea y cambia planes de sus centros y genera facturas para sus familias. Los planes de toda la agencia siguen siendo del administrador de agencia. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia donde trabaja. |

Los educadores y los padres no ven esta pantalla. Los padres solo ven las facturas.

## Preguntas frecuentes y problemas

??? Un plan no tiene el botón Generar facturas. | El plan está inactivo. Ábralo, marque **Active (available to apply to families)** y guarde.
??? La lista dice "No plans yet. Click + New plan to create your first fee plan." | Aún no hay planes para sus centros. Pulse **+ Nuevo plan**.
??? "Choose one of your centres." | Como director de centro solo puede guardar planes de sus propios centros. Elija uno en **Centre (optional)**; los planes de toda la agencia los define el administrador de agencia.
??? "Plan name is required" | Escriba un **Nombre del plan** antes de guardar.
??? "Seleccione al menos una familia" | Marque al menos una familia en **Familias** y vuelva a pulsar **Generar facturas**.
??? No aparece una familia que esperaba. | Un plan de un solo centro muestra solo las familias de ese centro. Las familias eliminadas no aparecen.
??? ¿Las familias reciben un correo cuando genero facturas? | Este botón no envía correos ni mensajes de texto. Créelas como borrador, revíselas en **Finanzas → Contabilidad** y luego envíelas como de costumbre.

## Pantallas relacionadas

[[open: #external-billing | Contabilidad]] [[open: #billing-schedule | Calendario de facturación]] [[open: #tuition-increases | Aumentos de matrícula]]

Vea también: [Generar las facturas mensuales](generating-monthly-invoices) · [Estados de las facturas](invoice-statuses) · [Programar un aumento de matrícula](tuition-increases)
