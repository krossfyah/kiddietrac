---
title: Cierre de año de nómina: comprobantes T4, T4A y RL-1
category: Staff Management
order: 69
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=yearend
---
# Cierre de año de nómina: comprobantes T4, T4A y RL-1

Cuando el motor de nómina está activado, **Recursos humanos › Nómina › Cierre de año** prepara los comprobantes fiscales de cada persona para el año, los compara con sus recibos de pago y sus remesas, y le da el archivo para subir a la CRA. Los comprobantes vencen el **último día de febrero** (28, o 29 en año bisiesto). De noviembre a marzo, el panel de RR. HH. muestra una ficha con la cuenta regresiva.

La nómina de KiddieTrac es canadiense: solo produce comprobantes de la CRA y de Revenu Québec.

## De dónde salen los importes
- **Nóminas finalizadas** en KiddieTrac, según la fecha de pago (un período de diciembre de 2026 pagado el 8 de enero de 2027 va en los comprobantes de 2027).
- **Saldos iniciales** (Nómina › Saldos iniciales): lo que pagó una nómina anterior a principios de año.
- **Recibos de otra nómina que muestran deducciones** (CPP, EI o impuestos). Un recibo con solo el bruto nunca llega a un comprobante.
- Los **contratistas** reciben un **T4A** con la casilla 048 (honorarios) si cobraron más de $500 en el año o si se retuvo impuesto.

Nada se cuenta dos veces: un recibo ya incluido en un saldo inicial se deja fuera.

## Pasos (administradores de la agencia)
1. Abra **Ajustes de cierre de año** y elija el **código de beneficios dentales** (casilla 45) y el contacto para la declaración (nombre, teléfono, correo). El número de cuenta de nómina de la CRA viene de Ajustes de RR. HH. › Motor de nómina.
2. Pulse **Generar comprobantes**. Puede generarlos de nuevo cuantas veces quiera hasta finalizarlos.
3. Use **⋮ › Editar casillas escritas** para importes que KiddieTrac no registra: cuotas sindicales (44), donativos (46), aportes a un RPP (20) y su número (50), ajuste de pensión (52), otro código dental (45).
4. Lea la **Conciliación**. Cada línea debe cuadrar al centavo: comprobantes contra recibos, contra el informe de remesas (PD7A) y contra los saldos iniciales y los recibos de otra nómina.
5. Desde el **1 de enero**, cuando todo cuadra y no queda abierta ninguna nómina con fecha de pago en el año, pulse **Finalizar comprobantes**. Cada persona los ve en **Mis RR. HH. › Mis comprobantes fiscales**.
6. Pulse **Resúmenes y archivo CRA**: descargue el **Resumen T4** y el **archivo CRA (XML)**, y súbalo en **Internet File Transfer** de la CRA (o ingrese los comprobantes en My Business Account). KiddieTrac valida el archivo con el esquema de la CRA antes de entregarlo. KiddieTrac nunca presenta la declaración por usted.

## Empleados de Quebec (RL-1)
Los empleados de Quebec reciben un T4 (casilla 22 = solo impuesto federal) y una **hoja informativa RL-1** con las casillas del RL-1 (A, B.A, B.B, C, E, G, H, I), más una hoja de resumen RL-1. Revenu Québec certifica el software que produce los RL-1 y sus archivos, y KiddieTrac no está certificado: presente los RL-1 en Mon dossier pour les entreprises de Revenu Québec o con software certificado, usando estas hojas.

## Correcciones después de finalizar
Un comprobante finalizado nunca cambia.
- **⋮ › Enmendar este comprobante**: KiddieTrac lo recalcula con la nómina actual (conservando las casillas escritas) y lo marca **Enmendado**.
- **⋮ › Anular este comprobante**: para uno emitido a la persona equivocada o innecesario.
Luego pulse otra vez **Finalizar comprobantes** (la conciliación debe pasar) y descargue **Archivo CRA: enmiendas T4**. La persona ve la nueva versión en Mis RR. HH., marcada Enmendado.

## Quién ve qué
- Administradores de la agencia: todo, y el SIN completo en el PDF de un comprobante y en el archivo CRA.
- Directores de centro (con nómina): los comprobantes de las personas de sus centros, solo lectura, con el SIN oculto.
- Personal: sus propios comprobantes finalizados, en Mis RR. HH., con su propio SIN.
- Nadie puede editar, enmendar ni finalizar su propio comprobante.

Cada generación, finalización, edición, enmienda y descarga queda en el registro de auditoría. Revise los comprobantes con su contador antes de presentarlos.
