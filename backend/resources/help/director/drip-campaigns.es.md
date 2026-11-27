---
title: Campañas por goteo
---
# Campañas por goteo

**Engagement › Drip campaigns** envía automáticamente un correo de seguimiento cierta cantidad de días después de que
pasa algo: la visita de una familia, la inscripción de un niño, su cumpleaños o una familia que deja de participar.
Configúrela una vez y cada familia que llegue a ese momento recibe el correo, sin olvidos: una nota de agradecimiento
el día después de la visita, un paquete de bienvenida tres días después de la inscripción.

[[open: #drip-campaigns | Abrir campañas por goteo]]

## Crear una campaña por goteo

1. Abra **Engagement › Drip campaigns**. El encabezado dice **💧 Drip campaigns**.
2. Pulse **+ New drip campaign**.
3. Escriba un **Title** que su equipo reconozca («Gracias por la visita»).
4. Elija el **Trigger event**: **After tour booked**, **After enrollment complete**, **On child's birthday** o **After 30 days inactive**.
5. Escriba el **Delay (days)**: cuántos días después del evento enviar (0 lo envía ese mismo día).
6. Escriba el **Email subject** y el **Email body (HTML allowed)**. Ponga **{first_name}** donde quiera el nombre del padre o la madre.
7. Pulse **Create**. La campaña aparece en la lista como activa.

> 💡 En **After tour booked**, la espera cuenta desde la fecha de la visita: una espera de 1 envía el día siguiente.

## Comprobar que funciona

1. Mire los recuadros de arriba: **Active drip campaigns**, **Sent (all time)**, **Queued** y **Failed**.
2. Lea la lista: **Title**, **Trigger**, **Delay** y **Status** de cada campaña.
3. Si **Failed** aumenta, revise los correos en los registros de las familias.

KiddieTrac revisa cada hora qué familias llegaron a un disparador y envía cada campaña una sola vez a cada persona;
nunca la repite para la misma visita, niño o familia.

## Quién la recibe

- **After tour booked**: el correo que se dio al reservar la visita.
- **After enrollment complete** y **On child's birthday**: el correo principal de la familia, para niños aún inscritos.
- **After 30 days inactive**: familias que no usan el portal desde hace 30 días.

Se aplican las reglas de correo habituales: las familias que se fueron y las cuentas cerradas no reciben nada.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Crear campañas por goteo y ver sus cifras. |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |
| Director de centro, educador | No disponible. |

## Preguntas frecuentes y problemas

??? ¿Cómo cambio o detengo una campaña por goteo? | Esta pantalla aún no tiene botón para editar, pausar o borrar. Abra un ticket desde Ayuda y el soporte de KiddieTrac la cambiará o detendrá por usted.
??? Una familia no recibió nada. | Revise la fecha del disparador (por ejemplo, la fecha de visita o de inscripción en el registro), la espera y que la familia tenga correo. El envío corre cada hora: espere una hora.
??? ¿Puedo ver una vista previa del correo? | Aquí no. Escríbalo primero como campaña en **Marketing › Campaigns**, use **👁 Preview** y luego pegue el texto aquí.
??? ¿Funciona {first_name}? | Sí. Se convierte en el nombre de la persona si la dirección pertenece a una cuenta; si no, «there».

## Pantallas relacionadas

[[open: #marketing-campaigns | Campañas]] [[open: #tours | Visitas]] [[open: #engagement | Participación]]

Vea también [[marketing-campaigns]] y [[tour-bookings]].
