---
roles: agency_admin, platform_admin
title: Patrón de asistencia de varios días
category: Enrolment
order: 36
---
# Patrón de asistencia

Indique los días en que su hijo asiste normalmente. Se usa para los cálculos de proporción y las proyecciones de matrícula.

## Definir el patrón

1. Barra lateral → **Días de asistencia**.
2. Elija al niño (si hay más de uno).
3. Toque cada mosaico del día de la semana en que asiste (lun.–dom.).
4. Elija una fecha **Vigente desde** (por defecto, hoy).
5. Guarde.

## Qué cambia

- La pantalla **Hoy** del lado del personal solo muestra a los niños que se espera que asistan ese día.
- Las **proporciones por sala** se calculan según la asistencia prevista, no según el total de matriculados.
- La **matrícula prorrateada** usa el patrón para calcular costos mensuales justos (p. ej., los niños de 3 días por semana se facturan proporcionalmente frente a los de 5 días).

## Historial de fechas de vigencia

Cuando cambia un patrón, el anterior se conserva con `effective_until = new pattern's effective_from`. Así, el horario del personal de hace 6 meses sigue reflejando el patrón de ese momento, algo útil para la auditoría.

## Patrones habituales

- **Tiempo completo**: lun.-vie. sí, sáb./dom. no
- **Medio tiempo / mar.-jue.**: solo martes y jueves
- **Alterno**: lun./mié./vie. frente a mar./jue.
- **Cuidado de fin de semana**: sáb.-dom. sí (para familias de trabajadores por turnos)
- **Solo semana de vacaciones**: patrón en blanco + reserva manual

## Cuando asiste un día no programado

Toque el horario del personal y agregue una entrada puntual; no cambia el patrón recurrente.
