---
title: RR. HH.: verificación de ubicación al marcar entrada y aviso de horas extra
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director, educator, home_visitor
---
# RR. HH.: verificación de ubicación al marcar entrada y aviso de horas extra

Dos reglas opcionales para marcar entrada, incluidas en el módulo de RR. HH. de KiddieTrac. Un administrador de la agencia las configura en **RR. HH. > Horario planificado > Reglas para marcar entrada**.

## Verificación de ubicación al marcar entrada

Defina un área alrededor de cada centro u hogar: un radio en metros alrededor de la dirección, o alrededor de un punto que marque en el mapa. Después elija la regla:

- **Desactivada**: nunca se pide la ubicación.
- **Permitir y señalar**: las entradas lejos del centro, o sin ubicación, se registran igual. La dirección y la administración las ven en una lista.
- **Bloquear**: las entradas lejos del centro, o sin ubicación, se rechazan con un motivo claro. Una dirección puede marcar la entrada de la persona de todos modos.

La ubicación solo se pide cuando alguien marca entrada en un centro que tiene un área. Antes de que el dispositivo la comparta, la persona ve por qué se pide. KiddieTrac solo guarda «en el centro» o «lejos» y la distancia, nunca la ubicación en sí. No se rastrea a nadie y nunca se bloquea marcar salida. El quiosco no se verifica.

## Aviso de horas extra

Indique cuántas horas por semana deben pasar para que empiecen las horas extra (44 por defecto, el límite de las normas laborales de Ontario) y cuándo avisar (40 por defecto). La semana suma las horas ya trabajadas más los turnos que aún están programados. Cuando ese total llega al aviso, y otra vez cuando llega a las horas extra, la persona y la dirección de su centro reciben un aviso. Cada uno se envía una vez por semana, y de nuevo solo si un cambio en el horario baja el total por debajo del límite y luego lo vuelve a superar. Los contratistas quedan fuera, salvo que desactive esa opción.

## Lo que ve la dirección

**RR. HH. > Horario planificado** muestra las entradas de la semana marcadas lejos del centro, y las personas cerca de las horas extra o que ya las superan, para sus propios centros.
