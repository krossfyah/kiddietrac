---
title: Informes — generar, descargar y programar
---
# Informes — generar, descargar y programar

**Administration › Reports** reúne los informes listos para sus centros y su agencia: asistencia y horas, facturas y
saldos, asistencia y cuotas mensuales, registro de asistencia, capacidad y vacantes, y más. Elija un informe, ponga las
fechas y el centro, y genérelo en PDF o en hoja de cálculo, o haga que se envíe por correo automáticamente cada día,
semana o mes.

[[open: #reports | Abrir Informes]]

## Generar un informe

1. Abra **Administration › Reports**.
2. Elija la pestaña: **Centre reports** o **Agency reports**.
3. Ponga el rango de fechas arriba (**Last 7 days**, **Last 30 days**, **This month to date**, **Last calendar month**, o sus propias fechas **From** y **To**).
4. Elija el **Centre** (o **All centres**).
5. Pulse **Run report** en la tarjeta del informe. Aparece «Generating report…» y luego se descarga el archivo.

Algunos informes hacen una pregunta más antes:

- **Invoices & balances**: marque los valores de **Status** que quiere incluir (por ejemplo **Overdue**, **Voided**), elija el **Period** (**The date range on the screen**, **Specific dates** o **Every invoice, all dates**) y el **Balance** (**Any balance**, **Only invoices with money still owing** u **Only invoices settled in full**), y pulse **Run report**.
- **Monthly attendance & fees**, **Monthly attendance & fees by family**, **Record of Attendance** y **Capacity & vacancies**: elija el mes (y la familia, en la hoja por familia).

> 💡 Los estados tienen los mismos colores en el PDF que en pantalla (por ejemplo pagado, pagado en parte, ausencia registrada).

## Enviar un informe por correo automáticamente

1. Abra la pestaña **Scheduled reports** y pulse **Schedule a report**.
2. Elija el **Report** y el **Format**: **PDF**, **Excel** o ambos.
3. Elija el período que cubre (por ejemplo **Last 7 days** o **Last calendar month**).
4. Fije la **Frequency** —**Daily**, **Weekly** (elija el **Day of week**) o **Monthly** (elija el **Day of month**)— y la hora de envío.
5. Escriba **Send to (email)**.
6. Pulse **Save schedule**. Aparece el aviso **Schedule created** y la programación figura en la lista como **Active**, con su fecha **Last sent**.

Para cambiarla, abra las acciones de su fila, elija editar y luego **Save changes**. **Send now** la envía al momento,
el interruptor la pausa (**Schedule paused**) y **Delete** la elimina.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Generar todos los informes de todos los centros y programar envíos. |
| Director de centro | Generar informes de su(s) centro(s). |
| Educador | Asistencia y horas de la(s) sala(s) que tiene asignadas. |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |

Los correos programados van solo a la dirección que escriba. Asegúrese de que esa persona puede ver ese informe.

## Preguntas frecuentes y problemas

??? «Pick at least one status, or the report has nothing to show.» | En las preguntas de Invoices & balances, marque al menos un estado.
??? «The start date is after the end date.» | Invierta las fechas o elija un rango listo.
??? Un educador ve «No rooms are assigned to you yet.» | Asígnelo a una sala en su registro de personal; sus informes siguen a sus salas.
??? No llegó un informe programado. | Revise la dirección de la programación y pulse **Send now**. Si ve «Could not send», abra un ticket desde Ayuda.
??? Quiero mis propias columnas y filtros. | Las exportaciones de cada lista (Familias, Niños, Facturas…) dan una hoja de cálculo de lo que ve en pantalla.

## Pantallas relacionadas

[[open: #forecast | Pronóstico de inscripciones]] [[open: #retention | Retención por cohorte]] [[open: #audit-logs | Registro de auditoría]]

Vea también [[enrolment-forecast]] y [[cohort-retention]].
