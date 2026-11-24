---
title: Entender las proporciones
category: Daily Operations
order: 1
---

# Entender las proporciones educador-niños

La Ley de Cuidado Infantil y Primera Infancia de Ontario (CCEYA) establece las proporciones máximas de educadores por niños para cada grupo de edad. Kiddietrac las calcula en tiempo real y señala las salas que no cumplen.

## Proporciones estándar de la CCEYA en Ontario

| Grupo de edad | Proporción (educador : niños) |
|---|---|
| Lactantes (0-18 meses) | 1 : 3 |
| Niños pequeños (18-30 meses) | 1 : 5 |
| Preescolar (30-60 meses) | 1 : 8 |
| Kínder (44-67 meses) | 1 : 13 |
| Edad escolar | 1 : 15 |

Al crear una sala, usted define su proporción objetivo. Kiddietrac la usa para calcular el cumplimiento.

## Cómo calcula Kiddietrac las proporciones

Para cada sala, cada minuto, Kiddietrac comprueba:

1. Cuántos niños tienen registrada su entrada en esta sala
2. Cuántos educadores han fichado (distribuidos por sala si existen turnos asignados; de lo contrario, distribuidos por igual entre las salas)
3. Educadores requeridos = ceil(niños_presentes / niños_de_la_proporción)
4. Si educadores_presentes >= requeridos → CUMPLE (COMPLIANT)
5. Si educadores_presentes == requeridos Y niños_presentes > 0 → JUSTO (TIGHT) (está en el mínimo legal: un niño más y incumple)
6. Si educadores_presentes < requeridos → INCUMPLIMIENTO (BREACH)

## Las reglas de su provincia o estado (nuevo, octubre de 2026)

KiddieTrac ahora comprueba las proporciones según las reglas de la provincia o el estado configurado para su agencia (Resumen de la agencia → Editar agencia → País y cumplimiento). Cuando esas reglas se conocen en detalle, sustituyen la proporción propia de la sala:

- **El niño más pequeño presente fija la proporción.** En Ohio, un lactante menor de 12 meses en un grupo mixto hace que todo el grupo pase a 1:5.
- **Tamaño del grupo.** Cada franja de edad tiene un tamaño máximo de grupo; una sala que lo supera muestra **OVER GROUP SIZE** aunque haya suficientes educadores.
- **Cuidado infantil en el hogar.** Los hogares de Ohio Tipo A y Tipo B usan la regla del hogar: 7 niños por cuidador, no más de 3 menores de 2 años cada uno, y un tope total (7 para el Tipo B, 14 para el Tipo A). Sus propios hijos menores de 6 años cuentan: registre también su entrada.
- **Un segundo adulto en el lugar** cuando hay 7 o más niños presentes (centros de Ohio).
- **Hora de la siesta.** La tarjeta muestra cuántos educadores pueden supervisar durante la siesta (Ohio: el doble de la proporción hasta 2 horas, nunca con lactantes). Se muestra como apoyo para planificar; nunca convierte un incumplimiento en cumplimiento.

La tarjeta de proporción indica qué regla usó, por ejemplo *«Niño más pequeño: menor de 12 meses → 1:5 (Ohio)»*, y enumera cualquier otra cosa que haya encontrado.

**Centros con licencia de Ontario** (octubre de 2026): establezca el tipo de licencia del lugar en *Centro de cuidado infantil con licencia* en **Proporciones por sala → Ajustes de proporciones**, y KiddieTrac aplica el Reg. de Ont. 137/15 — Anexo 1: proporciones y tamaños de grupo, franjas de proporción reducida, cómputo de supervisores, regla de los dos adultos, edades mixtas, grupos de edad familiares y un RECE en cada grupo. Consulte *Reglas de proporción de Ontario (centros con licencia)*.

El cuidado infantil en el hogar de Ontario, los lugares sin tipo de licencia definido y las demás provincias conservan la proporción propia de cada sala. No cambia nada en cómo se miden esas salas.

## Qué hacer cuando una sala está en INCUMPLIMIENTO (BREACH)

- **Un educador olvidó fichar su entrada** — pídale que fiche (desde su teléfono o la tableta)
- **Un educador salió a un descanso** — asegúrese de que otro educador lo cubra
- **Tiene más niños de los previstos** — traslade temporalmente a un niño a otra sala
- **Tiene falta crónica de personal** — contrate a otro educador

Un incumplimiento de más de 15 minutos es una infracción de la CCEYA. Documente cualquier incumplimiento que no pudo evitar.

## Por qué importa JUSTO (TIGHT)

JUSTO significa que está en el mínimo legal. Si ocurre cualquier cosa —llega un niño, un educador va al baño— incumple. Tome JUSTO como una advertencia para conseguir apoyo adicional.

## Turnos frente a fichaje

Si ha configurado turnos de educadores con antelación, Kiddietrac usa los turnos programados para determinar quién está en cada sala. Si no hay turnos, cuenta quién ha fichado en el centro y lo reparte por igual entre las salas del centro.

Una agencia puede elegir en su lugar contar a cada educador que ha fichado en la sala a la que está asignado (Asignaciones de sala); los educadores asignados a varias salas, o a ninguna, se siguen repartiendo —redondeando hacia abajo, de modo que nadie se cuente dos veces. Pida al soporte de KiddieTrac que lo active para su agencia.

Para la mayoría de los centros pequeños basta con fichar la entrada y la salida. Los centros más grandes se benefician de la planificación de turnos.
