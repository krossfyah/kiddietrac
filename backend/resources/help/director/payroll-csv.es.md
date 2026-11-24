---
title: Exportación CSV lista para nómina
category: Staff Management
order: 32
---
# Exportación de nómina

Obtenga un CSV del período de pago con las horas fichadas de cada miembro del personal. Impórtelo directamente en ADP, Wave o su proveedor de nómina.

## Cómo ejecutarla

1. Barra lateral → **Nómina**.
2. Elija un rango de fechas (por defecto, los últimos 30 días).
3. Haga clic en **Ejecutar** para ver un resumen por miembro del personal, o en **⤓ CSV** para descargar el archivo.

## Qué se incluye

Para cada combinación (miembro del personal × centro) dentro del rango:
- Nombre y correo electrónico del miembro del personal
- Nombre del centro
- Número de fichajes (cuántos eventos de entrada)
- Total de minutos
- Total de horas (redondeado a dos decimales)

Solo se cuentan los fichajes cerrados: quien siga fichado no aparecerá hasta que fiche la salida.

## Formato del archivo

`payroll-YYYY-MM-DD-to-YYYY-MM-DD.csv`, UTF-8 con BOM, se abre sin problemas en Excel / Numbers / Sheets.

## Corregir primero las horas

La exportación lee los mismos fichajes que las **Hojas de horas**. Si un turno es incorrecto (una salida que nadie fichó, un turno que nunca se fichó), corríjalo allí antes de exportar, no después en la hoja de cálculo. Véase [Hojas de horas y corrección de horas](timesheets).
