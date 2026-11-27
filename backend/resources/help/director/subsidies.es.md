---
title: Subsidios — CWELCC y subsidio provincial de cuotas
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
---
# Subsidios — CWELCC y subsidio provincial de cuotas

**Cumplimiento → Subsidios** reúne los dos tipos de ayuda con las cuotas que reciben sus familias. La pestaña **CWELCC** es el informe mensual de CWELCC para su reclamación. La pestaña **Subsidio provincial** registra el subsidio de cuotas de cada niño otorgado por la provincia o el municipio, que luego se descuenta de la matrícula de ese niño en la factura de la familia. La pantalla recuerda la pestaña en la que estaba.

[[open: #cwelcc | Abrir Subsidios]]

Los administradores de agencia ven toda la agencia. Un director de centro ve y gestiona solo sus propios centros.

## Generar el informe mensual de CWELCC

[[show: #cwelcc @ CWELCC | Muéstreme la pestaña CWELCC]]

1. Abra **Cumplimiento → Subsidios** y pulse **CWELCC**.
2. Elija el **Mes**. El **Informe mensual CWELCC** se recarga para ese mes.
3. Lea los recuadros: **CHILDREN** (niños), luego (en Ontario) **BASE FEE REVENUE**, **FEE SUBSIDY PAID** y **PARENTS PAID**.
4. Revise las filas: una por familia inscrita, con los **Niños**, el **Centro**, las fechas de inscripción y los montos **Base fee**, **Subsidio de cuotas** y **Padres** del mes.
5. Pulse **⤓ CSV** o **⤓ PDF** para descargar el mes para su reclamación.

En Ontario, desde 2025 los padres pagan la cuota base, menos cualquier subsidio de cuotas. Los límites de la cuota base y la revisión de cargos adicionales están en **Cumplimiento → Informes OCCMS**.

El informe lee los **periodos de inscripción** de cada familia, así que un mes pasado siempre muestra lo que aplicaba entonces: una familia que ya salió de CWELCC sigue apareciendo en los meses en que estuvo inscrita, y un cambio solo afecta los meses desde su fecha de inicio. No se cuentan las facturas anuladas ni los borradores.

## Inscribir a una familia en CWELCC

1. Abra **Administración → Familias** y abra la familia.
2. Desplácese hasta **Subsidios**. Muestra todo el historial de la familia: cada periodo de inscripción en CWELCC con quién lo registró, y cada subsidio provincial, incluidos los finalizados.
3. Pulse **Inscribir en CWELCC**, indique **De** (el primer día) y la **Subsidy rate (%)** si su provincia la usa, y pulse **Guardar**.
4. Cuando algo cambie, pulse **Cambiar tarifa** (la nueva tarifa aplica desde la fecha que elija; los meses anteriores conservan la anterior) o **Finalizar CWELCC** e indique el **Último día** (los meses anteriores conservan su inscripción).

Un cambio nunca reescribe un periodo anterior, así que una reclamación se lee igual después que cuando se presentó. Cada cambio queda en el registro de auditoría.

## Agregar un subsidio provincial de cuotas

[[show: #cwelcc @ Provincial subsidy | Muéstreme la pestaña Subsidio provincial]]

1. Abra **Cumplimiento → Subsidios** y pulse **Subsidio provincial**.
2. Pulse **＋ Agregar subsidio**. Se abre la ventana **Agregar un subsidio provincial**.
3. Elija el **Niño**.
4. Escriba el **Número de caso** de la carta de aprobación.
5. En **El subsidio se paga**, elija **Por mes** o **Por día**, y escriba el **Monthly amount ($)** o el **Daily amount ($)**.
6. Indique el **Primer día**, y el **Last day (optional)** si la aprobación lo tiene. Agregue **Approved on (optional)** y **Notes (optional)** si sirven.
7. En **Documentos de la provincia o del gobierno**, adjunte la carta de aprobación (PDF, foto o Word, hasta 15 MB cada uno). Puede adjuntar varios.
8. Pulse **Guardar**. El subsidio aparece en la lista del mes.

Cómo se descuenta de la factura mientras aplica:

- **Por mes**: el monto mensual se descuenta de cada factura mensual.
- **Por día**: el monto diario × los días que el niño tiene **programados** ese mes, sin contar los días en que el centro está cerrado. La línea de la factura lo muestra, por ejemplo *Subsidy (21 scheduled days × $20.00)*. Registre los feriados en el calendario del centro para que no se cuenten.

A la familia solo se le cobra el resto, nunca menos de cero. Las facturas ya emitidas no cambian.

## Cambiar, finalizar o quitar un subsidio

1. En la pestaña **Subsidio provincial**, busque la fila del niño. El monto de **Subsidio** se pone en rojo si supera la **Matrícula**: conviene revisarlo.
2. Abra el menú **⋮** de la fila.
3. Elija **Editar** para corregir el monto, el número de caso, las fechas o los documentos.
4. Elija **Finalizar subsidio** e indique el **Último día** cuando el subsidio termine. Las facturas posteriores a ese día ya no lo incluyen y su historial se conserva.
5. Elija **Quitar** solo para un subsidio registrado por error, y confirme.

> ⚠️ Un niño puede tener un solo subsidio provincial a la vez. Si las fechas se superponen con uno existente, se rechaza el guardado y se indica cuál. Para una renovación, finalice el anterior el día antes de que empiece el nuevo.

La pestaña lista los subsidios que aplican en el **Mes** elegido, con **CHILDREN** y **SUBSIDIO ESTE MES**. Marque **Mostrar todos los subsidios, incluidos los finalizados** para ver todo el historial. **⤓ CSV** descarga el mes para conciliar con la provincia o el municipio. Cada alta, edición, finalización y eliminación queda en el registro de auditoría.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de agencia | Ambas pestañas para cada centro; registra cambios de CWELCC y subsidios provinciales. |
| Director de centro | Lo mismo, solo para sus propios centros. |
| Administrador de plataforma | Lo mismo que un administrador de agencia, en la agencia donde trabaja. |

Los educadores no ven esta pantalla. Los padres solo ven el resultado: una línea de **Subsidio** en su factura. Solo los directores y administradores con sesión iniciada pueden abrir los documentos del subsidio.

## Preguntas frecuentes y problemas

??? Una familia salió de CWELCC. ¿Desaparecerá del informe de la primavera pasada? | No. El informe lee los periodos de inscripción, así que la familia sigue en cada mes en que estuvo inscrita.
??? La pestaña CWELCC dice "No CWELCC-enrolled families for this month." | Ninguna familia de sus centros estaba inscrita ese mes. Inscriba a las familias desde su ficha, en **Subsidios**.
??? "Choose the child." / "Enter the monthly amount." / "Enter the first day." | Falta un campo obligatorio. Complételo y vuelva a pulsar **Guardar**.
??? "The last day cannot be before the first day." | Revise las dos fechas; el último día debe ser igual o posterior al primero.
??? "The subsidy was saved, but the document was not uploaded" | El subsidio se guardó. Ábralo con **Editar** y adjunte de nuevo el documento (PDF, foto o Word, hasta 15 MB).
??? ¿El subsidio provincial aparece en la factura de los padres? | Sí. La factura muestra la matrícula, una línea de **Subsidio** que descuenta el monto y el saldo que debe la familia.
??? ¿Un niño puede tener CWELCC y un subsidio provincial? | Sí. El informe de CWELCC sale de la inscripción de la familia en CWELCC; el subsidio provincial es una línea por niño en la factura.
??? ¿Dónde quedó "Subsidios CWELCC"? | Ahora es **Subsidios**, con **CWELCC** como primera pestaña. El informe no cambió.
??? ¿Aquí se registran las subvenciones que recibe la agencia? | No. Las subvenciones de operación y salariales que recibe la agencia están en **Finanzas → Subvenciones**.

## Pantallas relacionadas

[[open: #subsidy-roa | Informes OCCMS]] [[open: #admin-families | Familias]] [[open: #grants | Subvenciones]]

Vea también: [Informes OCCMS](occms-reporting) · [Generar las facturas mensuales](generating-monthly-invoices) · [Estados de las facturas](invoice-statuses)
