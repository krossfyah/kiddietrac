---
title: Ajustes del reloj
---
# Ajustes del reloj

Los ajustes del reloj reúnen todo lo relacionado con el reloj de fichaje: cuándo recordar a quienes no han fichado la entrada o siguen con el reloj activo, cuándo cerrar turnos y días que alguien olvidó cerrar, y cada cuánto recordar a los aprobadores las solicitudes de permiso pendientes. Todas las horas están en la zona horaria de su agencia.

[[open: #clock-settings | Abrir Ajustes del reloj]]

La pantalla tiene tres pestañas: **🔔 Recordatorios**, **⏱️ Cierre automático** y **🌴 Aprobaciones de ausencias**. Cada pestaña tiene su propio botón para guardar, así que guarde antes de cambiar de pestaña.

## Configurar los recordatorios de fichaje

1. Abra **Ajustes del reloj** y quédese en la pestaña **🔔 Recordatorios**. La tarjeta se llama **🔔 Recordatorios de fichaje**.
2. Marque **Remind people who have not clocked in** y elija una hora. Los educadores y directores sin fichaje ese día reciben un recordatorio.
3. Marque **Recordar a las personas que siguen con el reloj activo** y elija una hora. Cualquier persona con un turno abierto recibe un recordatorio, incluido un turno abierto de un día anterior.
4. Marque **Solo días laborables** para no enviar recordatorios en fin de semana.
5. Elija cómo se envían: **Enviar por correo electrónico**, **Enviar a la aplicación** (una notificación push en la aplicación móvil) o ambos.
6. Pulse **Guardar recordatorios**. Aparece **✓ Saved** junto al botón.

> ℹ️ Los recordatorios se revisan una vez por hora, así que salen durante la hora elegida, no en el minuto exacto.

## Recordar a los educadores cuando su sala está vacía

En **⏱️ Room empty, still clocked in**, en la misma pestaña:

1. Marque **Remind educators to clock out when their room is empty**. Reciben una notificación en la aplicación cuando todos los niños se han ido de su sala y siguen con el reloj activo. Nunca antes de que llegue el primer niño, y los directores y administradores no lo reciben.
2. En **After the last child leaves**, escriba cuántos minutos debe estar vacía la sala antes del primer recordatorio (de 5 a 240, 15 de forma predeterminada).
3. En **Remind again every**, escriba los minutos entre recordatorios. Hay como máximo 3; escriba 0 para enviar solo uno. Si vuelven niños, la cuenta empieza de nuevo.
4. Pulse **Guardar recordatorios**.

## Cerrar turnos y salidas olvidados

1. Pulse la pestaña **⏱️ Cierre automático**.
2. Marque **Cerrar los turnos del personal** y elija una hora. Un fichaje que nadie cerró se cierra a esa hora del día en que empezó, nunca a la hora en que corre el proceso; así un fichaje del martes nunca queda como un turno de 48 horas.
3. En **Abandonar un turno después de**, escriba un número de horas (de 1 a 24, 14 de forma predeterminada). Un fichaje aún abierto después de ese tiempo se considera abandonado.
4. Marque **Registrar la salida de los niños** y elija una hora para registrar la salida de cualquier niño que nadie registró al final del día.
5. Pulse **Guardar salida automática**.

## Recordar a los aprobadores las solicitudes de permiso

1. Pulse la pestaña **🌴 Aprobaciones de ausencias**. La tarjeta muestra cuántas solicitudes están pendientes y cuándo se envió el último recordatorio.
2. Marque **Enviar recordatorios**. Déjela sin marcar para no enviar ninguno; igual se avisa cuando se hace una solicitud.
3. En **Con qué frecuencia**, elija **Todos los días**, **Solo días laborables**, **Una vez por semana** (y luego el día) o **Cada pocos días** (y luego el número de días).
4. Configure **Hora del día**. Los recordatorios se revisan cada 15 minutos.
5. En **Solo solicitudes en espera desde hace al menos**, escriba las horas que debe esperar una solicitud antes de recordarla, para no insistir esa misma mañana con una hecha hace una hora.
6. En **A quién se recuerda**, elija **Directores y administradores**, **Solo directores** o **Solo administradores**. Los directores solo ven las solicitudes de su centro; un centro sin director envía sus solicitudes a los administradores.
7. Elija los canales: **Enviar por correo electrónico**, **Mostrar en la aplicación** (una notificación en su campana) y **Notificación push en los teléfonos**.
8. Pulse **👁 Vista previa** para ver el correo como lo recibirán los aprobadores. No se envía nada.
9. Pulse **📤 Enviar prueba…**, escriba una dirección de correo y pulse **Send test** para recibir una copia marcada [Test]. Sus botones solo abren el portal.
10. Pulse **Guardar**.

## Quién puede hacer qué

| Rol | Recordatorios | Cierre automático | Aprobaciones de ausencias |
|---|---|---|---|
| Administrador de agencia | Cambiar | Cambiar | Cambiar |
| Director de centro | Cambiar | Cambiar | Ve "Only agency administrators can change time-off approval reminders." |
| Administrador de plataforma | Cambiar | Cambiar | Cambiar |

Los educadores y los padres no ven esta pantalla. Los ajustes se aplican a toda la agencia.

## Preguntas frecuentes y solución de problemas

??? Una pestaña dice "Could not load:" y un mensaje. | Los ajustes no se cargaron. Vuelva a cargar la página; si sigue pasando, envíe el mensaje al soporte de KiddieTrac.
??? Pulsé guardar y veo "Could not save". | El cambio no se guardó. Revise su conexión y pulse de nuevo el botón para guardar de esa pestaña.
??? Un recordatorio llegó a las 10:40 y no a las 10:00. | Los recordatorios de fichaje se revisan una vez por hora, así que llegan durante la hora elegida.
??? Alguien recibió un recordatorio el sábado. | Marque **Solo días laborables** en la pestaña **🔔 Recordatorios** y pulse **Guardar recordatorios**.
??? Mi recordatorio de prueba dice "Not sent". | El texto que sigue indica el motivo. Revise la dirección que escribió e inténtelo de nuevo.
??? ¿Quién recibe el recordatorio de sala vacía? | Solo los educadores, en la aplicación. Los directores y administradores nunca lo reciben, y nunca se envía por correo.

## Relacionado

[[open: #time-clock | Fichar entrada y salida]]
[[open: #time-off | Permisos]]

- [[time-clock]]
- [[auto-sign-off]]
- [[time-off]]
