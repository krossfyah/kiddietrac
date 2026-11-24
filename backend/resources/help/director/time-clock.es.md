---
title: Reloj de fichaje del personal
category: Daily Operations
order: 76
---
# Reloj de fichaje del personal

Cada miembro del personal ficha su entrada al comenzar el turno y su salida al terminar. Lo encontrará en **Personal → Reloj de fichaje** en la barra lateral.

## Fichar

La pantalla muestra un único botón grande:

- **«Fichar entrada» en verde** cuando no está en un turno
- **«Fichar salida» en rojo** cuando sí lo está, con las horas transcurridas a la vista

Púlselo. Ese es todo el proceso.

## Fichajes recientes

La lista bajo el botón muestra sus últimos 60 fichajes con las marcas de entrada y salida y el total de horas. Un turno abierto (en curso) muestra «— en curso —» en lugar de una duración.

## Vista del gerente

Los directores y administradores de agencia ven los fichajes de cada miembro del personal de su centro en
**Hojas de horas**, que reúne las entradas del reloj de fichaje y los registros de turnos manuales.

Cada fila allí tiene un menú **⋮**: corregir un turno, añadir uno que nunca se fichó o
eliminar uno que nunca ocurrió. El reloj de un educador solo alterna entre entrada y salida, así que un turno dejado abierto
durante la noche solo puede corregirse desde allí. Vea [Hojas de horas y corrección de horas](timesheets).

## Campo de origen

Cada fichaje registra de dónde vino (web, quiosco o móvil), para que pueda saber cómo se fichó un turno.

## Ajustes de recordatorios y cierre automático

**Ajustes → Ajustes del reloj** contiene las dos mitades del reloj de fichaje:

- **Recordatorios** — cuándo avisar a alguien que no ha fichado la entrada, y a alguien que sigue
  fichado. Ajuste las horas a su jornada; un centro que abre a las 06:00 no debería recibir el recordatorio
  a las 10:00 como todos los demás.
- **Cierre automático** — cierra los turnos y las jornadas de niños que alguien olvidó cerrar.
  El mismo ajuste también está en **Ajustes → Control de salas → Cierre automático**.

Los recordatorios se comprueban una vez por hora, así que salen durante la hora que elija en lugar de
a un minuto exacto. Ambos se configuran por agencia, en su propia zona horaria.

## Quién recibe el recordatorio de «sigue fichado»

A la hora que establezca en **Recordar a quienes siguen fichados**, KiddieTrac revisa cada
turno aún abierto, incluido uno dejado abierto un día anterior (hasta 14 días atrás). Se recuerda a una
persona cuando:

- su turno programado para el día ha terminado, si tiene uno; y
- lleva fichada más tiempo que su jornada habitual más una hora. Sin historial,
  son 6 horas. Nunca es menos de 5 horas y **nunca más de 8 horas**, de modo que el
  recordatorio siempre llega antes del cierre automático nocturno.

Los turnos que cerró el cierre automático se excluyen al calcular la jornada habitual de alguien.
Sin eso, una persona que olvida con frecuencia parecería trabajar jornadas de 12 horas y
nunca recibiría el recordatorio.

Nadie recibe recordatorio en un día con tiempo libre aprobado.

## Niños que siguen registrados

A la misma hora, también se avisa a los educadores sobre los **niños que siguen registrados**. El recordatorio
va a quien registró la entrada de cada niño. Si lo hizo un padre o madre, va a
los educadores asignados a la sala de ese niño. Enumera a los niños, pide al educador
que registre su salida a la hora de recogida y, si el cierre automático de niños está activado, indica a qué hora
se les cerrará automáticamente.

Esto solo se envía cuando **Recordar a quienes siguen fichados** está activado.

## Interruptores de correo electrónico y push

Los interruptores **Enviar por correo electrónico** y **Enviar a la aplicación** de la pestaña **Recordatorios** se aplican a
ambos recordatorios. Si desactiva el correo, solo se envían la notificación y el push; si desactiva el push,
solo se envían la notificación y el correo. La notificación dentro de la aplicación siempre se añade.

Vea también: [Cierre automático](auto-sign-off), [Hojas de horas y corrección de horas](timesheets).
