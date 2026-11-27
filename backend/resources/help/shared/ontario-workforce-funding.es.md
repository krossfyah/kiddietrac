---
title: Financiación de personal de Ontario (WEG y CWELCC)
category: Payroll
order: 40
roles: agency_admin, platform_admin
---
# Financiación de personal de Ontario (WEG y CWELCC)

**Finanzas → Financiación de personal** es para las agencias de Ontario que reciben la subvención de mejora salarial (WEG) y la compensación de personal de CWELCC (WCF). KiddieTrac calcula los montos por hora de cada persona, los pone en cada recibo de pago como líneas separadas, revisa la regla de los 32 días para cada pago que usted recibe y prepara la conciliación de fin de año. Solo lleva registros: nunca le paga a nadie.

[[open: #workforce-funding | Abrir Financiación de personal]]

La pantalla tiene cuatro pestañas: **Salarios del personal**, **Periodo de pago**, **Financiación recibida** e **Informe de fin de año**.

## Activarla

1. Abra **Ajustes → Nómina** y busque la tarjeta **🍁 Ontario workforce funding (WEG + CWELCC)**.
2. Marque **Usar la financiación de personal de Ontario en la nómina**.
3. En **Valores para**, revise el año. Los valores de 2026 vienen cargados de las pautas de 2026: un salario mínimo de $25.86 por hora para el personal de programa RECE y de $26.86 para supervisores RECE y visitantes de cuidado infantil en el hogar; un aumento WCF de hasta $4.00 por hora hasta que el salario llegue a $28 (personal de programa) o $31 (supervisores); WEG de hasta $2.00 por hora hasta que el salario llegue a $33.81.
4. Cuando su administrador de servicios publique los valores del año siguiente, elija ese año y escríbalos.
5. Redacte su **Política de distribución**, con **Approved by (name / title)** y **Aprobada el**, o pulse **Agregar a la biblioteca de políticas** para subir la copia firmada y dar seguimiento a su revisión anual.
6. Pulse **Guardar ajustes de financiación**.

## Registrar el salario de una persona

1. Abra **Finanzas → Financiación de personal** y quédese en **Salarios del personal**.
2. Pulse **Salarios** en la fila de la persona. Se abre la ventana **Componentes del salario**.
3. Indique **Vigente desde**, y escriba el **Base wage ($/h)** (salario base) y cualquier **2024 GOF kept ($/h)** que aún pague.
4. Deje **Situación de la financiación** en **Automatic (role + RECE registration)**, o elija una para fijarla.
5. Deje **WEG override ($/h)**, **Ajuste manual del aumento WCF** y **Ajuste manual del complemento al mínimo** en blanco para usar el cálculo, o escriba un monto. Si supera un tope o máximo, aparece una advertencia.
6. Pulse **Guardar**. Aparece el aviso **Wage record saved** y la fila muestra **Base**, **WEG**, **WCF increase**, **Floor top-up** y **Total /h**.

KiddieTrac calcula los montos en el orden de las pautas: WEG, luego el aumento WCF y luego el complemento al salario mínimo. La elegibilidad depende del rol de la persona y de una certificación **RECE** activa en su ficha de personal. Un director sin RECE, o un educador aprobado por la dirección, cuenta como no RECE: puede recibir WEG pero no WCF.

Cuando cambie el sueldo de alguien, agregue un **registro nuevo** desde la fecha en que rige; el anterior termina el día antes. Para corregir un error, pulse **Corregir** en el registro. Cada cambio queda en el registro de auditoría con los valores anterior y nuevo.

## Pagarlo en una nómina

1. Procese la nómina como siempre y pulse **Generar nómina**.
2. Cada persona financiada muestra sus tarifas de WEG, WCF y mínimo. Al marcarla se agrega a su pago una línea separada por cada parte (horas × tarifa).
3. En la pestaña **Periodo de pago**, elija las fechas y pulse **Mostrar** para ver las horas del reloj × cada tarifa, y si los recibos de ese periodo llevan las líneas de financiación. **⬇ Lines (CSV)** las descarga.

El recibo de pago en PDF muestra el salario base, la WEG y la compensación de personal en líneas separadas. La financiación se paga por cada hora trabajada, incluidas las horas extra, con el mismo monto por hora.

## Registrar la financiación recibida

1. Abra la pestaña **Financiación recibida**.
2. Pulse **+ Registrar financiación recibida**.
3. Indique **Recibido el** y el **Amount ($)** (monto), y en **Financiación** elija **WEG + compensación del personal**, **Solo WEG** o **Solo compensación del personal**.
4. Agregue **Período cubierto desde** / **to** (hasta), el **Centre (optional)** (o **Toda la agencia**), una **Referencia** y **Notas**.
5. Pulse **Guardar**. Aparece el aviso **Funding receipt recorded**.

Desde la fecha de recepción tiene 32 días para pagarla en la nómina regular. La columna **32-day check** muestra **Met** (con la primera fecha de pago financiada), **Pending** (con los días que quedan) o **MISSED**. Una entrada errónea se anula con **Void**, indicando el motivo: queda en el registro, marcada como anulada.

## Preparar el informe de fin de año

1. Abra la pestaña **Informe de fin de año** y elija el año.
2. Lea los recuadros (**Financiación recibida**, **WEG paid**, **WCF paid**, **Benefit allocation 17.5%**, y **Surplus to return** o **Shortfall**) y las tablas por persona y por puesto, con el personal no RECE aprobado por la dirección aparte.
3. Pulse **⬇ CSV** o **⬇ PDF** para descargarlo. Cada descarga queda en el registro de auditoría.

Conserve estos registros siete años. KiddieTrac nunca los borra.

## Quién puede hacer qué

Solo los **administradores de agencia** (y los administradores de plataforma que trabajan en la agencia) ven esta pantalla, y solo en una agencia de Ontario. Los directores de centro, los educadores y los padres no la ven. El personal ve las líneas de financiación en sus propios recibos de pago.

## Preguntas frecuentes y problemas

??? Financiación de personal no aparece en mi menú. | Solo aparece para los administradores de una agencia de Ontario. Revise la provincia de la agencia en sus ajustes.
??? La pantalla dice que las líneas de financiación están desactivadas para la nómina. | Abra **Ajustes → Nómina**, marque **Usar la financiación de personal de Ontario en la nómina** y pulse **Guardar ajustes de financiación**.
??? Alguien recibe WEG pero no WCF. | Cuenta como no RECE: revise que tenga una certificación **RECE** activa en su ficha, o fije **Situación de la financiación** a mano.
??? "The date and the amount are needed." | Complete **Recibido el** y **Amount ($)** y guarde de nuevo.
??? Un pago recibido muestra MISSED. | No se pagó nómina financiada dentro de los 32 días desde la fecha de recepción. Páguelo en la próxima nómina regular y guarde una nota para su administrador de servicios.
??? Algunos recibos no se cuentan en el informe de fin de año. | Los recibos sin desglose WEG/WCF (por ejemplo, sincronizados desde otro sistema de nómina) quedan fuera; el informe indica cuántos.
??? ¿Esta pantalla envía dinero o correos? | No. Lleva los registros y agrega líneas a los recibos de pago. No se envían correos ni mensajes de texto desde esta pantalla.

## Pantallas relacionadas

[[open: #payroll-settings | Ajustes de nómina]] [[open: #grants | Subvenciones]]

Vea también: [Subvenciones](grants) · [Nóminas, horas y recibos de pago](payroll-documents)
