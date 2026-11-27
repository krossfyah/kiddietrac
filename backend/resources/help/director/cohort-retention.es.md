---
title: Retención por cohorte
---
# Retención por cohorte

**Compliance › Retention** muestra cuántos de los niños que empezaron con ustedes en cada uno de los últimos 12 meses
siguen inscritos hoy. Se trata de conservar a las familias, no de cuánto tiempo se guardan los registros (para eso,
vea [Retención de datos y cumplimiento](data-retention-compliance)). Úselo para detectar un mes en que más familias de
lo normal se fueron poco después de empezar, y averiguar por qué.

[[open: #retention | Abrir la retención por cohorte]]

## Leer el informe

1. Abra **Compliance › Retention**. El encabezado dice **📊 Cohort retention** y muestra su **Average retention** de todos los meses.
2. Mire la tabla **Cohort breakdown**. Cada fila es un mes de inicio (por ejemplo, 2026-09).
3. **Enrolled** es cuántos niños empezaron ese mes. **Still** es cuántos de ellos no se han dado de baja.
4. **Retention** es Still ÷ Enrolled, con una barra: verde desde el 80 %, ámbar desde el 50 %, roja por debajo del 50 %. Un guion (—) significa que nadie empezó ese mes.
5. Compare los meses recientes con los antiguos: las cohortes recientes han tenido menos tiempo para irse, así que suelen verse mejor.

> 💡 Un mes en rojo con uno o dos niños no es una tendencia. Fíjese en los meses con suficientes inicios.

## Actuar ante un mes débil

1. Anote el mes de inicio en ámbar o rojo.
2. Abra **Children** o **Families** y vea quién empezó ese mes y se fue después, y el motivo registrado.
3. Si se repite el mismo motivo (cuotas, horario, cambio de sala), háblelo con su equipo. Una encuesta corta con **Engagement › NPS** puede decirle más.

## Cómo se cuentan los números

- Un niño cuenta en el mes de su fecha de inscripción.
- Un niño cuenta como «sigue inscrito» hasta que se pone una fecha de baja en su registro.
- Los niños cuyo registro fue eliminado no se cuentan.
- El informe cubre los 12 meses más recientes, incluido el actual, y se calcula de nuevo cada vez que lo abre.

## Quién puede hacer qué

| Rol | Ve |
|---|---|
| Administrador de agencia | Toda la agencia. |
| Director de centro | Las cifras de la agencia (el informe no se separa por centro). |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |

El informe solo muestra cantidades: ningún nombre de niño o familia.

## Preguntas frecuentes y problemas

??? Todos los meses muestran un guion. | Ningún niño de la agencia tiene fecha de inscripción en los últimos 12 meses. Revise que las fechas de inscripción estén completas en los registros.
??? Una familia se fue pero el informe sigue contando a su hijo. | No se ha puesto la fecha de baja del niño. Dé de baja al niño en su registro y el informe se actualiza la próxima vez que lo abra.
??? ¿Puedo exportarlo? | No desde esta pantalla. Use **Administration › Reports** para crear un informe con fechas de inscripción y baja.
??? ¿Envía algo a las familias? | No.

## Pantallas relacionadas

[[open: #forecast | Pronóstico de inscripciones]] [[open: #ai-churn | Riesgo de abandono]]

Vea también [[enrolment-forecast]] y [[churn-risk]].
