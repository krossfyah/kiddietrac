---
title: Herramientas de IA (resumen / riesgo de abandono / extracción de documentos)
category: AI
order: 50
---
# Herramientas de IA

Tres herramientas con IA, todas basadas en Claude.

## Puntuación de riesgo de abandono

**Barra lateral → Riesgo de abandono** (director / administrador de la agencia).

Puntuación de riesgo heurística de 0 a 100 por familia. **Sin llamada a la IA**: es una simple agregación SQL de la actividad de observaciones, la asistencia registrada, las facturas vencidas y el saldo adeudado. Se actualiza cada vez que carga la página.

Categorías:
- **Alto** (≥60): requiere intervención esta semana
- **Medio** (30-59): vigilar
- **Bajo** (<30): comprometida

Las señales se detallan en la tabla: «Observaciones a la baja más de un 50 %», «2 facturas vencidas», etc. Úselas para saber *por qué* se marca a una familia.

## Resumen semanal (para los padres)

Un resumen amigable de un párrafo sobre la semana del niño. Se genera a petición y es gratuito para los padres.

**Padre:** Barra lateral → nombre del niño → **Portafolio** → **Generar resumen con IA**.

Usa Claude con las observaciones y actividades del niño registradas esta semana. Tarda de 5 a 15 segundos. No lo muestre en tiempo real: muestre un indicador de «generando…».

## Extracción de documentos

**Barra lateral → Extracción de documentos con IA** (administrador / director).

Suba una foto o un escaneo de un documento (registro de vacunas, certificación, verificación VSS, identificación, formulario de inscripción) y extraemos los campos estructurados a JSON.

1. Elija la foto, el escaneo o el PDF que va a subir. Se envía el archivo en sí para leerlo; nunca se pone en un enlace público y no se acepta un enlace.
2. Elija el tipo de documento.
3. Haga clic en **Extraer campos**.
4. Los datos aparecen debajo en una lista con sus nombres. **AI doc extractions** puede luego vincular automáticamente un certificado o una verificación al registro del empleado correspondiente.

Se aplican costos por cada llamada de visión de Claude. Calcule aproximadamente 0,01-0,03 USD por documento según el tamaño.
