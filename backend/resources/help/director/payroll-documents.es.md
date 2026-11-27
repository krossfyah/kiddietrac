---
title: Ciclos de nómina, horas y recibos de nómina
category: Staff Management
order: 30
roles: agency_admin, platform_admin, centre_director
---
# Ciclos de nómina, horas y recibos de nómina

La pantalla **Nómina** responde una sola pregunta: qué trabajó cada persona en un período y cuánto se le pagó por ello. Muestra las horas fichadas junto a los recibos emitidos para las mismas fechas, le permite abrir, imprimir, enviar por correo o marcar cada recibo, y es donde un administrador de la agencia hace una nómina. Ábrala desde **Recursos humanos > Nómina**.

[[open: #payroll | Abrir Nómina]]

La pantalla tiene dos vistas: **⏱️ Hours & payslips** (lo trabajado, con los recibos de cada persona en su fila) y **📅 Pay periods** (una fila por período de pago, con totales y una revisión de horas).

## Ver las horas y los recibos de un período

1. Abra **Recursos humanos > Nómina**. La vista **⏱️ Hours & payslips** se abre con los últimos 30 días.
2. Pulse un período rápido: **Este período de pago**, **Último período de pago**, **This month** o **Last month**. O escriba las fechas **From** y **To** y pulse **Ejecutar**.
3. Elija una pestaña: **🧑‍🏫 Educadores**, **👥 Otro personal** o **📄 Contratistas**. La pantalla se abre en la primera pestaña que tenga a alguien.
4. Lea cada fila: **Staff**, **Role**, **Centre**, **Punches**, **Hours** y **Payslips**. Cada grupo tiene su subtotal, y el total general queda bajo las pestañas.
5. Pulse el enlace **Payslips** de una fila (por ejemplo "2 payslips · $1,850.00") para ver los documentos de esa persona en el período.

Solo cuentan los fichajes cerrados. Quien sigue fichado aparece nombrado en su fila para que vea qué quedó fuera. Las personas pagadas sin fichar (un gerente asalariado, una visitadora pagada por visita) también aparecen, con 0 fichajes y sus recibos.

> ℹ️ Los contratistas no fichan ni tienen cuenta, así que su pestaña muestra lo que se les **pagó** en el período en lugar de horas.

## Abrir, enviar o marcar un recibo

1. Abra los recibos de la persona desde el enlace **Payslips**.
2. Pulse **👁 View** para leer el PDF, **⬇️ Download** para guardarlo o **🖨 Print** para imprimirlo.
3. Pulse **✉️ Resend payslip** para enviarlo por correo. Confirme la **Email address** (viene del expediente, pero nunca se envía sin su confirmación), agregue una copia o una nota si quiere, y envíelo.
4. Pulse **Marcar como pagado** cuando el dinero haya salido. **Marcar como no pagado** lo deshace.
5. Pulse **Enviar por Interac** para pagar un recibo por Interac. Indica el importe y la persona y pide confirmación, porque mueve dinero real.

**Enviar por Interac** solo aparece para una persona con cuenta; a los contratistas se les paga a mano y se marcan como pagados.

## Revisar un período de pago completo

1. Pulse **📅 Pay periods**.
2. Elija el **Year** (año) o **All years**.
3. Lea las tarjetas: **Net paid**, **People paid**, **Average per person**, **Outstanding** (recibos aún no marcados como pagados) y **Hours check**.
4. Pulse **Open period ›** en una fila.
5. Compare **Payslip hrs** con **Clock hrs** de cada persona. Una fila **⚠ to review** tiene una diferencia; **✓ all match** significa ninguna.
6. Use **Ver recibo de nómina**, **Descargar recibo de nómina**, **✉️ Resend payslip** o **Mark paid** en una fila, o **✉️ Resend all payslips** para todo el período.

## Hacer una nómina (administradores de la agencia)

1. Pulse **＋ Generate payroll**.
2. Escriba **Period start** y **Period end** y pulse **Read the clock**.
3. En las pestañas **Educadores**, **Otro personal** y **Contratistas**, marque a quién pagar.
4. Escriba la tarifa (**Rate**) de cada persona. **Pay** se llena con horas × tarifa como punto de partida; puede cambiarlo.
5. Pulse **Líneas y notas** en una fila para **+ Add a line** (kilometraje, un extra, una deducción) o escribir **Notes on this payslip**.
6. Pulse **Aprobar y crear recibos de pago**.

La tarifa nunca se llena sola: escríbala en cada nómina. Quien ya tiene un recibo del mismo período se omite, y la nómina le dice cuántos se omitieron y por qué, para que nadie cobre dos veces. Si las hojas de horas del período aún no están aprobadas, la nómina le avisa que las apruebe en **Fichajes y hojas de horas > Aprobar y bloquear**.

## Exportar las horas

Pulse **⤓ CSV** para descargar el informe de horas de las fechas mostradas. Es un archivo de horas: las personas pagadas sin fichajes están en pantalla, no en el archivo. Vea [Exportación CSV lista para nómina](payroll-csv).

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la agencia | Todo lo de aquí, incluidos **＋ Generate payroll** y **Enviar por Interac**. |
| Director de centro | Ver horas y recibos de sus propios centros; abrir, imprimir, enviar y marcar recibos. Los directores no hacen nóminas. |
| Personal | Ve sus propios recibos en **Mis RR. HH.**, no en esta pantalla. |

## Preguntas frecuentes y qué hacer si algo falla

??? Dice "No punches in range." | Nadie fichó entre esas fechas. Pruebe **Último período de pago** o fechas más amplias; el personal asalariado solo aparece si tiene recibo.
??? Las horas de alguien parecen incorrectas. | Las horas vienen del reloj. Corrija el turno en **Fichajes y hojas de horas** y pulse **Ejecutar** otra vez.
??? "No payroll documents for the agency you are viewing." | Aún no se emitieron recibos en esta agencia. Aparecen al hacer una nómina o cuando llegan de su sistema de nómina.
??? Pulsé ＋ Generate payroll y no se abrió. | Las nóminas son para administradores de la agencia. Pida a un administrador que la haga.
??? Se omitió a una persona en la nómina. | Ya tiene un recibo del mismo período. Ábralo desde el enlace **Payslips**.
??? ¿De dónde salen las pestañas Pay runs y Remittances? | Son del motor de nómina, que solo se muestra cuando su agencia lo activó. Vea [Motor de nómina: nóminas, recibos y remesas](payroll-engine).

## Pantallas relacionadas

[[open: #timesheets | Fichajes y hojas de horas]] [[open: #payroll?tab=claims | Gastos y kilometraje]]

- [Hojas de horas y corrección de horas](timesheets)
- [RR. HH.: gastos y kilometraje](hr-claims)
- [Exportación CSV lista para nómina](payroll-csv)
