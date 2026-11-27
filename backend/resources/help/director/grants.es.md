---
title: Subvenciones (operación, mejora salarial y fondos únicos)
category: Billing
order: 86
roles: agency_admin, centre_director, platform_admin
---
# Subvenciones (operación, mejora salarial y fondos únicos)

**Finanzas → Subvenciones** da seguimiento a los fondos que su agencia o centro recibe y debe gastar dentro de un periodo: subvenciones de operación, mejora salarial, financiación CWELCC basada en costos, capital y fondos únicos. Para cada subvención ve lo otorgado, recibido y gastado, y si el gasto va al ritmo del periodo antes de la fecha del informe. Ábrala cuando lleguen los fondos, cuando los gaste y cuando se acerque el informe para el financiador.

[[open: #grants | Abrir Subvenciones]]

> ℹ️ Subvenciones es para el dinero que recibe la agencia. Los subsidios de cuotas que se descuentan de la factura de una familia están en **Cumplimiento → Subsidios**.

## Agregar una subvención (administradores de agencia)

[[show: #grants @ + Add a grant | Muéstreme Añadir subvención]]

1. Abra **Finanzas → Subvenciones**.
2. Pulse **+ Añadir subvención**.
3. Escriba el **Nombre de la subvención** (por ejemplo "Subvención de mejora salarial 2026") y el **Financiador**.
4. Agregue el **Agreement / reference #** (número de convenio) de la carta de financiación.
5. Elija el **Tipo**: General operating, Wage enhancement, CWELCC cost-based funding, Capital / repairs, One-time / special purpose u Other.
6. Elija el **Centro**, o deje **All centres (agency-wide)**.
7. Indique el **Inicio del periodo** y el **Fin del periodo**, el **Importe concedido** y la fecha de **Informe vence el**.
8. Escriba las condiciones o costos admisibles en **Notes (conditions, eligible costs)**.
9. Pulse **Guardar**. Aparece el aviso **Grant saved** y la subvención se abre.

## Leer la lista de subvenciones

1. Use el filtro de la lista para ver **Subvenciones activas**, **Cerrado** o **Todos**.
2. Cada tarjeta muestra **Otorgado**, **Recibido**, **Gastado**, **Sin gastar** y **Pendiente de recibir**, el periodo y la fecha del informe.
3. Lea la barra: el relleno es la parte de la subvención gastada; la línea es la parte del periodo transcurrida.
4. Lea la etiqueta de ritmo: **En curso**, **Underspending** (el gasto va muy por detrás del calendario; quizá deba devolver los fondos sin gastar), **Gasto adelantado** (verifique que la subvención alcance) u **Overspent** (gasto mayor que la subvención).
5. Haga clic en una tarjeta para abrir la subvención.

## Registrar entradas y salidas de dinero

1. Abra la subvención.
2. En **Transacciones**, pulse el botón de lo que ocurrió:
   - **+ Fondos recibidos** cuando el financiador paga una cuota;
   - **+ Gasto** cuando gasta dinero de la subvención;
   - **Fondos devueltos** cuando devuelve dinero sin gastar;
   - **Ajuste** para una corrección (un monto negativo reduce).
3. Indique la **Fecha** y el **Monto**.
4. Para un gasto, elija la **Categoría** (Wages, Benefits, Rent / occupancy, Program supplies, etc.) y, si el costo fue una factura de proveedor, elíjala en **Linked expense bill (optional)**.
5. Agregue una **Descripción** y una **Reference (cheque, EFT, payroll run)**.
6. Pulse **Guardar**. Los totales, la barra y **Por mes** se actualizan de inmediato.

Para deshacer una entrada, pulse **Quitar** en su fila y confirme. Los totales se recalculan y la eliminación queda en el registro de auditoría.

## Informar al financiador

1. Abra la subvención.
2. Revise la nota de ritmo bajo la barra y la tabla **Por mes**.
3. Pulse **⬇ Exportar CSV**. El archivo lista cada transacción con los totales (otorgado, recibido, gastado, devuelto, ajustes, sin gastar, pendiente de recibir) para su informe.
4. Cuando termine el periodo y haya entregado el informe, pulse **Editar**, ponga el **Estado** en **Cerrado** y pulse **Guardar**.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de agencia | Agrega y edita todas las subvenciones y registra transacciones en todas. |
| Director de centro | Ve las subvenciones de sus propios centros y las de toda la agencia. Registra y quita transacciones en las subvenciones de sus centros. No puede agregar ni editar una subvención, ni cambiar una de toda la agencia. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia donde trabaja. |

Los educadores y los padres no ven esta pantalla.

## Preguntas frecuentes y problemas

??? No veo + Añadir subvención ni Editar. | Solo un administrador de agencia puede crear o editar una subvención. Un director de centro puede registrar transacciones en las subvenciones de sus propios centros.
??? "Only an agency admin can change an agency-wide grant." | La subvención abarca todos los centros. Pida a su administrador de agencia que registre la entrada.
??? "Name, period and amount are required." | Complete **Nombre de la subvención**, **Inicio del periodo**, **Fin del periodo** e **Importe concedido**.
??? "A date and an amount are required." o "The amount must be more than zero." | Cada entrada necesita una fecha y un monto mayor que cero. Solo un **Ajuste** puede ser negativo.
??? La lista dice "No grants yet." | No se ha creado ninguna subvención para sus centros. Un administrador de agencia las agrega con **+ Añadir subvención**. Revise también que el filtro no esté en **Cerrado**.
??? Mi factura de proveedor no aparece en Linked expense bill. | Una subvención de centro ofrece solo las facturas de ese centro y las de toda la agencia. Registre primero la factura en **Finanzas → Gastos**.
??? ¿KiddieTrac envía el informe o algún correo? | No. Subvenciones guarda sus registros; no se envía nada al financiador, a las familias ni al personal. Cada cambio queda en el registro de auditoría.

## Pantallas relacionadas

[[open: #expenses | Gastos]] [[open: #cwelcc | Subsidios]]

Vea también: [Gastos](expenses) · [Subsidios](subsidies) · [Financiación de personal de Ontario](ontario-workforce-funding)
