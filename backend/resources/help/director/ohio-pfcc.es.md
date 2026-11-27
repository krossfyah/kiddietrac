---
title: Cuidado infantil con fondos públicos de Ohio (PFCC)
---
# Cuidado infantil con fondos públicos de Ohio (PFCC)

Para agencias de Ohio. Mantenga en un solo lugar la autorización PFCC de cada niño, el copago semanal de la familia y los días de ausencia, y revise la asistencia de cada semana antes de enviarla en TAP. Ábralo desde la barra lateral: **Operaciones** → **Ohio PFCC**. Aparece para las agencias de Ohio, o cuando un administrador de la agencia lo activó (**Activarlo de todos modos**, o **Límites y alertas**).

[[open: #pfcc | Abrir Ohio PFCC]]

La pantalla tiene cinco pestañas: **Autorizaciones**, **Copagos**, **Conciliación TAP**, **División de factura** y **Límites y alertas**. Las semanas van de domingo a sábado.

## Agregar una autorización

1. Abra la pestaña **Autorizaciones** y pulse **Agregar autorización**. Se abre la ventana **Agregar una autorización PFCC**.
2. Elija el **Niño**.
3. Escriba el **Número de caso**.
4. Elija la **Unidad de cuidado**: por hora (menos de 10 horas por semana), medio tiempo (10–33) o tiempo completo (33 o más), y escriba las **Unidades por semana**.
5. Escriba el **Provider rate per unit ($)** que figura en la autorización del niño y el **Weekly family copay ($)**.
6. Fije **Empieza**, y **Ends (optional)** si hay fecha de fin. Agregue la fecha de **Acuerdo de copago firmado** y cualquier **Notas**.
7. Pulse **Añadir**. Aparece el mensaje **Autorización agregada**.
8. En la fila, pulse **Subir** en **Acuerdo** para adjuntar el acuerdo de copago firmado. **Ver** lo abre de nuevo más tarde, y **Reemplazar** lo cambia.

KiddieTrac no guarda la tabla de tarifas de Ohio. Use **Editar** para cambiar una autorización. Cuando termine, pulse **Fin**, indique el último día de cuidado y pulse **Finalizarla**.

## Registrar los copagos

1. Abra la pestaña **Copagos**. El **Libro de copagos semanales** enumera cada semana de la autorización elegida.
2. Elija al niño en la lista de arriba.
3. En una semana, pulse **Record**.
4. Escriba el **Amount paid ($)** y la fecha **Pagada el**. Deje **Estado** en **Calcularlo**, o elija **Exonerado** o **No pagado**, y agregue una **Nota**.
5. Pulse **Guardar**.

Los copagos se llevan aparte de las facturas, y **nunca se escribe a las familias sobre ellos**. Cuando una familia acumula el número de semanas sin pagar fijado en **Límites y alertas** (2 por defecto), los directores del centro y los administradores de la agencia reciben un aviso en la campana. Ohio espera que la falta de pago de más de 2 semanas se informe al condado dentro de 3 semanas.

## Días de ausencia

Cada autorización muestra los días de ausencia del niño en el semestre actual (enero–junio o julio–diciembre) frente a su límite. Los directores y administradores reciben un aviso en la campana cuando a un niño le faltan 2 días para el límite, y de nuevo si lo supera. El límite empieza en **10**. Las fuentes de Ohio dan 10 o 20 días para distintos casos, así que **compárelo con sus autorizaciones** y cámbielo en **Límites y alertas**.

## Revisar una semana para TAP

1. Abra la pestaña **Conciliación TAP**.
2. Elija la semana con **‹ Semana** y **Week ›** (y un centro, si tiene varios).
3. Revise cada niño señalado por cuidado **sin autorización**, una entrada **sin la salida correspondiente** (o al revés), **horas de más** de lo que permite la unidad de cuidado, o un niño autorizado **sin asistencia** esa semana.
4. Haga coincidir la asistencia en TAP a mano. **Descargar CSV** le da la misma lista como hoja de cálculo.

El sistema TAP de Ohio no tiene una conexión que KiddieTrac pueda usar. Una semana de más de 3 semanas de antigüedad lleva una advertencia: TAP descarta la asistencia conciliada no enviada después de 4 semanas. Cuando todo coincide, ve **Nada marcado esta semana**.

## Dividir una factura entre el estado y la familia

1. Abra la pestaña **División de factura** y busque una factura en **borrador**.
2. Pulse **Vista previa**. KiddieTrac calcula la parte del estado para el período de la factura: unidades autorizadas × tarifa − copago semanal, prorrateado por semana. Muestra lo que **La familia debe después de la división**.
3. Si está bien, pulse **Aplicar a este borrador**. Aparece el mensaje **División aplicada** y la parte del estado va a la factura como subsidio.
4. Para quitarla antes de enviar la factura, pulse **Ver** y luego **Deshacer la división**.

Las facturas enviadas nunca se tocan, una factura que ya lleva otro subsidio no se divide, y los niños sin autorización no se ven afectados. Lo que paga la familia además del copago sigue el ajuste **Después de una división, la familia paga** en Límites y alertas: **Only the copay (the gap is written off)** o **The copay plus tuition above what the state pays**.

## Límites y alertas

Un administrador de la agencia fija los **Días de ausencia permitidos por semestre**, **Alertar tras semanas de copago sin pagar**, **Después de una división, la familia paga** y la **Calificación Step Up To Quality**, y luego pulsa **Guardar ajustes**. Fuera de Ohio, dos casillas más activan **Show publicly funded child care for this agency (not in Ohio)** y **Show Step Up To Quality for this agency**.

## Quién puede hacer qué

- Los **administradores de la agencia** ven todos los centros y cambian Límites y alertas.
- Los **directores de centro** ven y gestionan las autorizaciones, los copagos, la conciliación y las divisiones de factura de sus propios centros. Límites y alertas es de solo lectura para ellos: «Only agency admins can change these.»
- Los educadores y los padres no ven Ohio PFCC. Nunca se escribe a las familias sobre los copagos.

## Preguntas frecuentes y solución de problemas

??? No veo Ohio PFCC en el menú. | Solo aparece para las agencias de Ohio, o cuando un administrador de la agencia lo activa. Revise la provincia o el estado de la agencia.
??? Dice «Ohio publicly funded child care is not switched on for this agency.» | Un administrador de la agencia puede pulsar Activarlo de todos modos.
??? Añadir dice «Choose a child.» | Elija al niño antes de pulsar Añadir. Solo aparecen los niños inscritos.
??? Vista previa no muestra el botón Aplicar. | La factura no es un borrador, ya tiene otro subsidio o ningún niño de la factura tiene autorización.
??? ¿Los avisos en la campana llegan alguna vez por correo electrónico o texto? | No. Las alertas de días de ausencia y de copagos sin pagar son avisos en la campana solo para directores y administradores.

## Relacionado

[[open: #pfcc | Ohio PFCC]]
[[open: #sutq | Step Up To Quality]]

- [Step Up To Quality](step-up-to-quality)
- [Subsidios](subsidies)
- [Generar las facturas mensuales](generating-monthly-invoices)
