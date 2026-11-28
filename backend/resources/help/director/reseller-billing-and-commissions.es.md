---
title: Facturación y comisiones (distribuidor)
---
# Facturación y comisiones (distribuidor)

**Facturación y comisiones** muestra, mes a mes, lo que KiddieTrac facturó a cada una de sus agencias por su plan, lo que se cobró, lo que todavía se debe y la comisión que usted ganó. Úsela a fin de mes para revisar sus ganancias y enviar un extracto a su contador.

[[open: #reseller-billing | Abrir Facturación y comisiones]]

## Revisar un mes

1. Abra **Facturación y comisiones** en la sección **Reseller** del menú.
2. Elija el mes en la lista de arriba a la derecha. La pantalla abre en el mes actual.
3. Lea los mosaicos: **Facturado este mes**, **Cobrado este mes**, **Pendiente (todos los meses)**, **Su comisión** y **Agencias que pagaron** (por ejemplo «3 / 5»).
4. Lea la línea bajo los mosaicos. Indica sus condiciones, por ejemplo «Your commission: 10% of the money collected before tax. Set by the platform.»
5. Lea la tabla, una fila por agencia: **Agency**, **Plan**, **Billed**, **Collected**, **Before tax**, **Still owed** y **Commission**.
6. Baje hasta **Invoices behind these numbers** para ver cada factura del plan que cuenta: su número, periodo, estado, monto y si se pagó este mes, se facturó este mes o se debía de antes.

## Cómo se calcula su comisión

- Un **porcentaje** del dinero cobrado ese mes, **antes de impuestos** (la columna **Before tax**, no **Collected**).
- Más, si sus condiciones lo incluyen, un **monto fijo por cada agencia que pagó** ese mes.
- El dinero facturado pero aún no cobrado no genera comisión hasta que se paga; aparece en **Still owed**.

> ℹ️ Sus condiciones las fija KiddieTrac. Puede verlas aquí, pero no cambiarlas.

## Descargar el extracto

1. Elija el mes.
2. Pulse **Descargar extracto (CSV)**.
3. Abra el archivo (con un nombre como `statement-2026-11.csv`) en una hoja de cálculo. Tiene las mismas filas y totales que la pantalla.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Distribuidor | Ve y descarga el extracto de su propio distribuidor. |
| Administrador de la plataforma | Elige cualquier distribuidor en **Viendo distribuidor** y fija las condiciones en **Distribuidores › Comisión** (el botón **Monthly statement →** abre esta pantalla para ese distribuidor). |
| Administradores de agencias de un distribuidor | No ven el extracto ni la comisión del distribuidor. |

## Preguntas frecuentes y cuando algo sale mal

??? Dice «No commission terms are set for this reseller yet.» | KiddieTrac aún no registró sus condiciones. Abra un ticket ([[raise-a-ticket]]) para que las añadan; los meses anteriores se calculan entonces con las nuevas condiciones.
??? Mi comisión es más baja de lo que esperaba. | Se calcula sobre el dinero cobrado **antes de impuestos** ese mes, no sobre lo facturado. Revise **Still owed** para ver las agencias que aún no pagan.
??? Una agencia muestra $0 facturado. | No se emitió ninguna factura del plan para ella ese mes (por ejemplo, sigue en prueba). Revise **Invoices behind these numbers** para ver lo que se contó.
??? Aparece «Could not download the statement». | Puede que su sesión haya terminado. Actualice la página, vuelva a iniciar sesión si se lo pide y pulse de nuevo **Descargar extracto (CSV)**.
??? La tabla dice «No agencies yet.» | Todavía no hay agencias en su distribuidor. Añada una en **Dar de alta una agencia**.
??? ¿Se envía un correo a alguien cuando abro esta pantalla? | No. Esta pantalla solo lee cifras; no se envía nada a sus agencias.

## Pantallas relacionadas

[[open: #reseller | Todas mis agencias]] [[open: #reseller-support | Mesa de ayuda]]

- [[reseller-all-my-agencies]]
- [[managing-resellers]]
- [[reseller-guide]]
