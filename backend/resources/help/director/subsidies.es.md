---
title: Subsidios — CWELCC y subsidio provincial de cuidado infantil
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
---
# Subsidios — CWELCC y subsidio provincial de cuidado infantil

**Barra lateral → Subsidios** tiene dos pestañas: **CWELCC** y **Subsidio provincial**. La pantalla
recuerda la pestaña en la que estaba.

[[open: #cwelcc | Abrir Subsidios]]

Los administradores de la agencia ven toda la agencia. Un director de centro ve y gestiona solo sus propios
centros.

## CWELCC

El informe mensual de CWELCC: una fila por familia matriculada, con sus hijos, las cuotas
facturadas ese mes, el subsidio a la tarifa de la familia y la parte del padre. Elija un
**Mes** para ver otro, y descárguelo con **⤓ CSV** o **⤓ PDF** para su
reclamación.

El informe lee los **períodos de matrícula** de cada familia, así que un mes pasado siempre muestra lo que
se aplicaba entonces: una familia que ha dejado CWELCC sigue apareciendo en los meses en que estuvo
matriculada, y un cambio de tarifa solo afecta a los meses desde su fecha de inicio. Las facturas anuladas y los borradores
no se cuentan.

[[show: #cwelcc @ CWELCC | Muéstreme la pestaña CWELCC]]

## En el registro de la familia

Abra una familia (**Familias**, y luego la familia) y desplácese hasta **Subsidios**. Muestra
todo el historial de la familia: cada período de matrícula en CWELCC con su tarifa y quién lo registró,
y cada subsidio provincial concedido a la familia, incluidos los terminados y los
eliminados por error.

Los directores y administradores registran allí los cambios de CWELCC:

- **Inscribir en CWELCC** — desde una fecha, con una tarifa.
- **Cambiar tarifa** — la nueva tarifa se aplica desde la fecha que elija; los meses anteriores conservan
  la tarifa antigua.
- **Finalizar CWELCC** — el último día. Los meses anteriores conservan su matrícula.

Un cambio nunca reescribe un período anterior, de modo que una reclamación se lee igual más tarde que
cuando se presentó. Cada cambio queda registrado en el registro de auditoría.

## Subsidio provincial

El **subsidio provincial de cuidado infantil** de un niño: el número de caso de la carta de aprobación, el
importe (por mes o por día), las fechas en que se aplica y los documentos del gobierno.
**Mientras se aplica, el subsidio se descuenta de la matrícula de ese niño en la factura de la familia**
— a la familia solo se le factura el resto, y nunca menos de cero.

- **Por mes** — el importe mensual se descuenta de cada factura.
- **Por día** — el importe diario × los días en que el niño está **programado** para asistir ese
  mes (sus días de la semana reservados), sin contar los días en que el centro está cerrado. Se calcula al
  generar la factura; la línea de la factura lo muestra, p. ej. *Subsidio (21 días programados
  × 20,00 $)*. Registre los festivos en el calendario del centro para que no se cuenten.

[[show: #cwelcc @ Provincial subsidy | Muéstreme la pestaña Subsidio provincial]]

### Registrar uno

- [ ] Pulse **＋ Añadir subsidio**.
- [ ] Elija al **niño**.
- [ ] Introduzca el **número de caso**, elija **Por mes** o **Por día**, e introduzca el importe.
- [ ] Defina el **primer día**, y el **último día** si la aprobación lo tiene.
- [ ] En **Documentos de la provincia o del gobierno**, adjunte la carta de aprobación (PDF, foto o Word, hasta 15 MB cada uno). Puede adjuntar varios.
- [ ] Pulse **Guardar**.

Los documentos son privados: solo los directores y administradores con sesión iniciada pueden abrirlos, desde la ventana
**Editar** del subsidio o desde el registro de la familia. Quitar uno lo oculta; cada subida y cada
eliminación consta en el registro de auditoría.

La lista muestra la matrícula de cada niño junto al subsidio, y pone el importe en rojo si el
subsidio supera la matrícula: conviene revisarlo.

### Cuando cambia

- **Editar** corrige el importe, el número de caso o las fechas.
- **Finalizar subsidio** fija su último día. Las facturas emitidas después de ese día ya no lo incluyen.
  Úselo cuando un subsidio termina, para conservar su historial.
- **Quitar** es solo para un subsidio introducido por error.

Los tres están en el menú **⋮** de la fila.

> **Importante:** Un niño puede tener un solo subsidio a la vez, porque las facturas aplican uno por niño. Si las fechas se superponen con un subsidio existente, se rechaza el guardado y se indica cuál lo impide. Finalice primero el anterior; en una renovación, termínelo el día antes de que empiece el nuevo.

> **Nota:** Los cambios se aplican a las facturas que se generen a partir de ahora. Las facturas ya emitidas conservan los importes con que se emitieron.

### La lista mensual

La pestaña muestra los subsidios que se aplican en el **Mes** elegido, con el número de niños
y el total del mes. Marque **Mostrar todos los subsidios, incluidos los terminados** para ver el
historial completo. **⤓ CSV** descarga el mes para conciliarlo con la provincia o
el municipio.

Cada alta, edición, finalización y eliminación queda registrada en el registro de auditoría con el niño, el número de caso,
el importe y las fechas.

## Preguntas

??? Una familia dejó CWELCC. ¿Desaparecerá del informe de la primavera pasada? | No. El informe lee los períodos de matrícula, así que la familia permanece en todos los meses en que estuvo matriculada.
??? ¿Adónde fueron los «subsidios CWELCC»? | Ahora es Subsidios, con CWELCC como primera pestaña. El informe no ha cambiado.
??? ¿Un subsidio provincial aparece en la factura del padre? | Sí. La factura muestra la matrícula, una línea de Subsidio que resta el importe, y el saldo que debe la familia.
??? ¿Puede un niño tener CWELCC y un subsidio provincial? | El informe CWELCC se calcula a partir de la matrícula de la familia en CWELCC. En una factura, un niño tiene una sola línea de subsidio a la vez, y la pestaña Subsidio provincial rechaza uno que se superponga.

Vea también: [Generar facturas mensuales](generating-monthly-invoices),
[Estados de las facturas](invoice-statuses).
