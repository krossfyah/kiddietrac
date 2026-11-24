---
title: Cierre automático
category: Staff Management
order: 45
---

# Cierre automático

Siempre hay alguien que se olvida. Un turno que queda abierto toda la noche se convierte en una semana de 40 horas en el informe de nómina, y un niño que sigue figurando como presente a medianoche deja las cifras de proporción inservibles. El cierre automático cierra lo que se olvidó, a la hora que usted elija, y avisa esa misma tarde a quienes necesitan saberlo.

Está **desactivado** hasta que lo active, y el personal y los niños se configuran por separado. Los directores y los administradores de agencia pueden cambiarlo.

## Pasos rápidos

1. Vaya a **Ajustes → Control de salas** y abra la pestaña **⏱️ Cierre automático**. (El mismo ajuste también está en **Ajustes → Ajustes del reloj → Cierre automático**.)
2. Active **Cerrar turnos del personal** y fije la hora, p. ej. 19:00.
3. Fije **Abandonar un turno después de**: las horas a partir de las cuales un fichaje abierto se considera abandonado y no simplemente largo. El valor predeterminado es 14.
4. Active **Registrar la salida de los niños** y fije esa hora, p. ej. 18:30.
5. Pulse **Guardar cierre automático**.

[[open: #educator-rooms?tab=signoff | Abrir ajustes del cierre automático]]

## Lo que importa

Un fichaje olvidado se cierra a la hora que usted eligió **del día en que comenzó**, nunca a la hora a la que se ejecute la tarea.

Esa distinción es el punto central. Un turno abierto el martes y detectado el jueves se cerraría de otro modo "ahora" y registraría un turno de 48 horas, una cifra peor que el dato que faltaba. Cerrarlo el martes a las 19:00 registra algo que un informe de nómina puede soportar.

Si un turno empezó *después* de su hora de cierre (un turno de tarde o de noche), la regla de la hora no puede aplicarse, así que lo cierra la regla de "abandonar después de N horas".

## A quién se avisa

Nada se cierra en silencio. Cada vez que el cierre automático cierra algo, KiddieTrac envía avisos esa misma tarde.

### Cuando se cierra un turno del personal

- **La persona** recibe una notificación, un aviso push en su teléfono y un correo electrónico: "Se registró su salida automáticamente". Indica la hora de entrada, la hora de salida automática y el centro, explica por qué importa (proporciones y licencias, nómina, informes y financiación) y le pide que diga a su director o administrador a qué hora se fue realmente.
- **Los directores del centro y los administradores de agencia** reciben una notificación y un correo electrónico: "Salida automática:" y el nombre de la persona, con los mismos detalles y un recordatorio para corregir la hora en **Hojas de horas**.

### Cuando se repite

Si a la misma persona se le ha registrado la salida automáticamente **3 o más veces en 7 días**, los directores y los administradores de agencia reciben además **Olvidos repetidos de fichaje de salida**, con las fechas. Sugiere conversar con la persona, ya que los recordatorios no han cambiado el hábito. Se envía como máximo una vez al día.

### Cuando se registra la salida de los niños

- **El personal del centro** (educadores, visitadores a domicilio, directores) y los administradores de agencia reciben un resumen por centro. Lista a cada niño, cuándo entró y la hora de salida automática, y pide al personal que corrija las horas en **Asistencia**.
- **Cada familia** recibe una notificación y un correo electrónico: "Confirme su hora de recogida". Le pide que avise al centro a qué hora recogió realmente al niño, e incluye el teléfono y el correo del centro si los tiene registrados. También le indica que no pasa nada.

Los avisos siguen los ajustes de correo de su agencia. No se contacta a las personas cuya cuenta está desactivada. Si un aviso no puede enviarse, el cierre se realiza igualmente.

## Qué deja registrado

- **Un fichaje del personal** recibe `[auto sign-off: no clock-out recorded]` añadido a sus notas, para que quien lea la hoja de horas vea que las horas no las escribió una persona.
- **El día de un niño** recibe una línea que indica que no se registró la salida, marcada para que el sistema distinga un cierre automático de una nota escrita por alguien.
- **La propia persona** también ve un aviso la próxima vez que inicie sesión.

Los turnos cerrados automáticamente no se usan para calcular el "día habitual" de alguien para los recordatorios de fichaje de salida, de modo que el hábito de olvidarse no impide que los recordatorios le lleguen.

## Lo que no hará

- No inventa horas. Un fichaje que no puede cerrar con sensatez se deja para una persona.
- No corrige una hora equivocada: solo cierra una abierta. Si las horas registradas son erróneas, edite el fichaje en la hoja de horas.
- No se ejecuta en los centros de una agencia que lo tenga desactivado.

## Si las horas son incorrectas

El cierre automático es una red de seguridad, no un sustituto de fichar la salida. Cuando la hora de cierre no es la real, edite el fichaje directamente: la nota permanece, de modo que la corrección queda visible y no oculta. En el caso de un niño, corrija la hora de salida en **Asistencia** cuando la familia le diga a qué hora lo recogió.

Los educadores pueden ver cuándo les ocurrió en **Mis horas**. El recordatorio que invita a fichar la salida antes de llegar a esto está en la pestaña **Recordatorios** de **Ajustes → Ajustes del reloj**. Consulte [Reloj de control del personal](time-clock).

??? ¿Seguirán los recordatorios de recuento fuera de horario? | No. Los recordatorios de recuento se detienen al terminar el turno programado del educador, o a la hora de cierre del centro si no tiene horario, aunque siga con la entrada fichada.
??? ¿Los padres reciben un mensaje cada vez? | Solo cuando se registró automáticamente la salida de su propio hijo. Cada familia recibe un solo correo para todos sus hijos en ese centro.
