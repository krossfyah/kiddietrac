---
title: RR. HH.: publicar el horario, copiar turnos, turnos predefinidos, presupuesto de personal, correcciones de fichaje
---
# RR. HH.: publicar el horario, copiar turnos, turnos predefinidos, presupuesto de personal, correcciones de fichaje

Herramientas de horario del módulo de RR. HH. de KiddieTrac. **Recursos humanos > Turnos y horarios > Herramientas de turnos** reúne el paso de publicación, los turnos predefinidos y el presupuesto semanal de personal; la pestaña **Horario** suma **📋 Copy** y una barra de publicación. **Recursos humanos > Fichajes y hojas de horas > Correcciones de fichaje** es donde se aprueban las solicitudes del personal para corregir un fichaje.

[[open: #schedule?tab=rotatools | Abrir Herramientas de turnos]]

## Activar el paso de publicación (administradores de la agencia)

1. Abra **Recursos humanos > Turnos y horarios** y elija **Herramientas de turnos**.
2. En **Paso de publicación**, marque la casilla para armar el horario como borrador y publicarlo cuando esté listo.
3. Si el personal debe confirmar cada turno, marque también "Staff must accept each shift".
4. Pulse **Guardar**. Aparece **Rota settings saved.**

Está apagado por defecto, y el horario funciona como antes. Encendido, los turnos nuevos, los cambios y los retiros en el **Horario** esperan como borradores, marcados **Borrador**, **Cambiado · sin publicar** o **Por quitar**. El personal, el fichaje, las alertas de proporción y la nómina solo ven turnos publicados. Todos los turnos que ya están en el horario cuentan como publicados.

## Publicar el horario

1. Arme o cambie la semana en la pestaña **Horario** como siempre (**+ Nuevo turno**, **⚡ Autofill week**).
2. Lea la barra sobre la cuadrícula: "📝 N changes not published yet".
3. Pulse **Publish N** en esa barra.
4. Revise cuántos turnos son nuevos, cambiados y por quitar, y pulse **Publicar**. Aparece **Horario publicado**.

Cada persona recibe un solo aviso con todos sus cambios. Un borrador que ya no encaja (la persona se fue, o ahora se cruza con otro turno) sigue como borrador y se lista. Para descartar todos los borradores, pulse **Descartar todos los borradores** en la misma ventana y confirme: los turnos publicados quedan como estaban. La pestaña **Plan de turnos** lista los borradores y las alertas de proporción como quedarían al publicar.

> ℹ️ El llenado nocturno y el horario fijo siguen publicando de inmediato.

## Copiar turnos

1. En la pestaña **Horario**, en vista de semana, pulse **📋 Copy**.
2. En **Qué copiar**, elija **La semana pasada en esta semana (todos)**, **La semana de una persona** o **Un día en otros días**.
3. Para una persona, elíjala y la semana a copiar; para un día, elija **Copiar este día** y marque los días en **En**.
4. Pulse **Copiar**.

Los días cerrados, el tiempo libre aprobado, las fechas no disponibles, los turnos que se cruzan y las horas ya pasadas se omiten, y el resultado los lista. Con el paso de publicación, las copias son borradores hasta publicar.

## Crear turnos predefinidos

1. En **Herramientas de turnos**, pulse **+ Nuevo turno predefinido**.
2. Póngale un nombre (por ejemplo "Temprano 7:00 – 3:00"), las horas, el rol, el centro (o **Todos los centros**) y un **Color**.
3. Pulse **Guardar**.

En el formulario de turno, elíjalo en **Turno predefinido** para llenar las horas y el rol; el turno se muestra en ese color. **Archivar** lo oculta (los turnos ya creados conservan su color); **Restaurar** lo recupera.

## Fijar un presupuesto semanal de personal (administradores de la agencia)

1. En **Herramientas de turnos**, busque **Presupuesto semanal de personal**. Solo quien puede ver los pagos ve esta tabla; un director que ve los pagos ve las cifras, pero no el botón.
2. Pulse **Fijar presupuesto** en la fila de un centro.
3. Escriba el monto semanal y pulse **Guardar**. Déjelo vacío para quitar el presupuesto.

El **Horario** muestra entonces "💵 Planned … projected …", y la tabla muestra **Previsto esta semana**, **Costo previsto** y **Del presupuesto**. El personal asalariado y quienes no tienen tarifa por hora se cuentan, pero no se cotizan.

## Aprobar correcciones de fichaje

1. Abra **Recursos humanos > Fichajes y hojas de horas** y elija **Correcciones de fichaje**.
2. **En espera** lista las solicitudes, con lo **Registrado**, lo **Solicitado** y **Por qué**.
3. Pulse **Aprobar**, agregue una nota si quiere y pulse **Aprobar y corregir**. O pulse **Rechazar**, explique por qué y pulse **Rechazar**.

Al aprobar, el fichaje cambia (marcado como corregido) y las horas se actualizan en la hoja de horas y la nómina. Se avisa a la persona en ambos casos. **Decidido** muestra las solicitudes pasadas.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de la agencia | Activar el paso de publicación y la aceptación, fijar presupuestos y todo lo demás. |
| Director de centro | Publicar, copiar, crear turnos predefinidos y aprobar correcciones en sus centros. Ve los presupuestos solo si ve los pagos. |
| Educador, visitador domiciliario | Aceptar turnos en **Mis RR. HH. > Turnos por aceptar**, pedir una corrección en **Mis RR. HH. > Corregir mi fichaje**. |

## Preguntas frecuentes y problemas

??? ¿Los borradores cambian lo que ve el personal? | No. Hasta publicar, el personal, el fichaje, las alertas de proporción, la nómina y los avisos de turno solo ven el horario publicado.
??? ¿Puedo apagar el paso de publicación? | Sí, cuando no haya borradores en espera. Publíquelos o descártelos primero.
??? No hay botón Aprobar en una corrección. | Es su propia solicitud: la decide otro director o un administrador de la agencia.
??? Una corrección se rechaza con "Locked timesheet". | El día está dentro de un periodo de pago aprobado y bloqueado. Un administrador de la agencia lo desbloquea primero en **Aprobar y bloquear**.
??? Un empleado pulsó "I can't do this shift". | Se avisa a los directores del centro. **Herramientas de turnos > Esperando que el personal acepte** lo muestra como **No puede**, con su motivo.
??? No veo el botón 📋 Copy. | Solo aparece en vista de semana, en agencias con el módulo de RR. HH.

## Pantallas relacionadas

[[open: #schedule | Horario]]
[[open: #schedule?tab=rota | Plan de turnos]]
[[open: #timesheets?tab=lock | Aprobar y bloquear]]

- [[hr-rota]]
- [[hr-open-shifts]]
- [[hr-overtime-timesheets]]
