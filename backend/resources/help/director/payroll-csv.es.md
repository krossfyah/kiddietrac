---
title: Exportación CSV lista para nómina
category: Staff Management
order: 32
---
# Exportación CSV lista para nómina

Descargue una hoja de cálculo con las horas fichadas de cada miembro del personal en un período de pago, lista para importar en ADP, Wave o el proveedor de nómina de su agencia. El archivo sale de la pantalla **Nómina** en **Recursos humanos**.

[[open: #payroll | Abrir Nómina]]

## Descargar el archivo de horas

1. Abra **Recursos humanos > Nómina**. La vista **⏱️ Hours & payslips** se abre con los últimos 30 días.
2. Pulse un período rápido como **Último período de pago** o **Last month**, o escriba las fechas **From** y **To**.
3. Pulse **Ejecutar** para revisar primero las horas en pantalla.
4. Pulse **⤓ CSV**. El archivo se descarga en su computadora.

El archivo se llama `payroll-AAAA-MM-DD-to-AAAA-MM-DD.csv`. Se guarda en UTF-8 con marca de orden de bytes, así que los acentos y los nombres se abren bien en Excel, Numbers y Google Sheets.

## Qué contiene el archivo

Una fila por cada miembro del personal en cada centro donde fichó, con:

- nombre y correo;
- nombre del centro;
- número de fichajes (cuántas veces fichó la entrada);
- total de minutos;
- total de horas, redondeado a dos decimales.

Solo cuentan los fichajes cerrados. Quien sigue fichado no aparece hasta que fiche la salida.

> ℹ️ Es un archivo de horas. Las personas pagadas sin fichar (personal asalariado, contratistas) no tienen fichajes: están en pantalla con sus recibos, pero no en el CSV.

## Corregir las horas primero

La exportación lee los mismos fichajes que **Fichajes y hojas de horas**. Si un turno está mal (una salida que nadie fichó, un turno que nunca se fichó), corríjalo allí antes de exportar, no en la hoja de cálculo después.

1. Abra **Recursos humanos > Fichajes y hojas de horas**.
2. Corrija el turno con su menú **⋮**.
3. Vuelva a **Nómina** y pulse **⤓ CSV** otra vez.

## Quién puede usarla

Los administradores de la agencia y los directores de centro. Los directores obtienen al personal de sus propios centros.

## Preguntas frecuentes

??? El archivo está vacío. | Nadie fichó entre esas fechas. Amplíe las fechas y pulse **Ejecutar** para revisar.
??? Falta una persona en el archivo. | No tiene fichajes cerrados en el período, o sigue fichada.

## Relacionado

- [Ciclos de nómina, horas y recibos de nómina](payroll-documents)
- [Hojas de horas y corrección de horas](timesheets)
