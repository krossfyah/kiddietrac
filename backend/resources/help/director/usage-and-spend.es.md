---
title: Uso y gasto (mensajes de texto, llamadas e IA por agencia)
---
# Uso y gasto (mensajes de texto, llamadas e IA por agencia)

**Platform › Usage & spend** muestra cuánto le cuestan a KiddieTrac los mensajes de texto, las llamadas y la IA de cada agencia, marca las agencias que superan un límite de gasto semanal y administra la **cuota mensual de textos y llamadas** (el complemento SMS Incremental), donde usted decide si una agencia que se pasó paga el excedente. Las agencias nunca ven esta pantalla.

[[open: #usage-spend | Abrir Usage & spend]]

## Cobrar a una agencia que superó su cuota de textos y llamadas

La tarjeta de arriba, **📶 Text & call allowance**, cuenta cada texto y llamada realizados, entrantes y salientes, por mes calendario. Cada bloque extra del tamaño de la cuota cuesta el precio indicado (por ejemplo 1,000 permitidos y 2,400 usados = 2 bloques). **No se cobra nada hasta que usted pulse Add to next invoice.**

1. Use **‹** y **›** para elegir el mes.
2. Lea el aviso: o las agencias que superaron la cuota con su monto, o **✅ No agency is waiting on an overage decision**.
3. En la tabla, mire las barras **Texts** y **Calls** de cada agencia (ámbar al 80 %, roja con **… blocks over**) y su columna **Billing** (**Next invoice …** o **Not billed yet**).
4. En la columna **Charge**, pulse **Add $… to next invoice**, lea la confirmación y pulse **Add to next invoice**. La fila muestra **✓ $… on next invoice**.
5. O pulse **Waive** para perdonarlo ese mes. La fila muestra **Waived**.
6. ¿Se equivocó? Pulse **Undo** junto al cargo o la condonación. Un cargo se puede deshacer hasta que se emita su factura; después muestra **invoiced**.

> ⚠️ Si **Billing** dice **Not billed yet**, la facturación no está activa para esa agencia, así que la línea aprobada espera hasta que lo esté.

## Cambiar la cuota

1. Escriba nuevos números en **Default texts / month** y **Default calls / month** (se aplican a todas las agencias).
2. Para dar a una agencia su propia cuota, escriba sus números en **Own allowance (texts · calls)**. Déjelos vacíos para usar la cuota por defecto.
3. Pulse **Save allowances**. Aparece el aviso **Allowances saved**.

## Revisar el gasto semanal y los límites

1. Debajo de la tarjeta de cuota, elija **Last 7 days** o **Last 30 days**.
2. Lea el aviso. En la vista de 7 días nombra las agencias **over a threshold this week**, o dice **✅ No agency is over a threshold for the last 7 days**. Los límites son semanales, así que la vista de 30 días no marca nada.
3. En la tabla, lea el costo **Texts**, **Calls**, **AI** y **Total** de cada agencia. Un costo sobre su límite sale en rojo.
4. En **💲 Rates**, indique lo que le cuesta una unidad: **Text, per segment**, **Call, per minute** y las dos tarifas de IA (solo se usan para un modelo de IA que no está en la lista de precios integrada; los modelos listados usan sus propios precios).
5. En **🚨 Default weekly thresholds**, fije el límite en dólares por semana para textos, llamadas e IA (0 = nunca marcar).
6. Para dar a una agencia sus propios límites, llene sus casillas en **Own weekly thresholds ($)**. Vacío usa el valor por defecto; 0 nunca marca a esa agencia.
7. Pulse **Save rates & thresholds**. Aparece el aviso **Rates and thresholds saved**.

## El correo del resumen semanal

Cada lunes a las 7:15, los administradores de la plataforma reciben por correo el **Platform weekly summary**, con arriba cualquier agencia que superó su límite semanal. Pulse **✉️ Email me this week's summary** para recibir una copia ahora en su propia dirección.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Todo aquí: tarifas, límites, cuotas, cargos y condonaciones. |
| Administradores de agencia y todos los demás | Sin acceso. Una agencia solo ve un excedente aprobado como una línea en su próxima factura de KiddieTrac. |

## Preguntas frecuentes y problemas

??? "Your account has no email address." | El resumen de prueba va a su propio correo. Agregue uno a su perfil primero.
??? Una agencia está en rojo pero no quiero cobrarle. | Pulse Waive para ese mes. No se cobra nada a menos que pulse Add to next invoice.
??? No puedo pulsar Undo. | El cargo ya está en una factura ("invoiced"). Corríjalo desde Accounting AR/AP.
??? "That did not go through". | El cargo, la condonación o el deshacer falló. Actualice e inténtelo de nuevo.
??? ¿Se avisa a las agencias cuando se pasan? | No. Esta pantalla y el resumen semanal son solo para administradores de la plataforma.

## Pantallas relacionadas

[[open: #ai-usage | Uso de IA]] [[open: #sales-invoices | Contabilidad CxC/CxP]] [[open: #sales-plans | Planes y precios]] [[open: #admin-mrr | Panel MRR]]
