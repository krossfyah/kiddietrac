---
title: Recordatorios de vacunación
category: Compliance
order: 35
roles: agency_admin, platform_admin
---
# Recordatorios de vacunación

**Ajustes → Ajustes de correo → Vacunas.** Un correo electrónico programado para su equipo con la lista de los niños cuyo registro de vacunación falta o está desactualizado — y, si lo activa, un recordatorio para cada familia sobre su propio hijo.

Ambos están **desactivados** hasta que alguien los active. No conviene empezar a enviar correos sobre el registro de salud de un niño solo porque un valor predeterminado lo decidió.

[[show: #email-settings @ Immunization > Send these reminders | Mostrarme el interruptor]]

## Configuración

- [ ] Marque **Enviar estos recordatorios**. No se envía nada mientras esté desactivado.
- [ ] Elija **Frecuencia** — **Semanal** en un **Día de la semana**, o **Mensual** en un **Día del mes** (de 1 a 28, para que todos los meses lo tengan).
- [ ] Defina la **Hora del día**. Es la hora local de su agencia, y el envío se hace dentro de esa hora.
- [ ] En **Destinatarios**, marque **Administradores de la agencia**, **Directores de centro** y/o **Educadores**.
- [ ] En **Qué se considera pendiente**, decida si cuentan los **Niños sin ningún registro** y si los registros **con más de** 6, 12, 18 o 24 meses cuentan como desactualizados (o **Nunca se considera desactualizado**).
- [ ] Si lo desea, añada una **Línea adicional en el correo**.
- [ ] **Guardar**.

El panel muestra cuántos niños están pendientes en este momento, para que vea el tamaño de la lista antes de activarlo. No se pueden activar los recordatorios si no hay nadie a quien enviarlos: marque al menos un grupo, o **Enviar correo a los padres sobre su propio hijo**.

## El recordatorio para el equipo

Enumera cada niño matriculado **sin registro de vacunación y sin ninguna dosis registrada** (cuando **Niños sin ningún registro** está marcado), o cuya dosis más reciente es anterior al plazo elegido, con su centro, sala y el motivo. Trata de registros, no de dosis: le dice a quién pedirle una cartilla.

Cada persona recibe solo **los niños a su cargo**. Un educador recibe información de los niños de su propio centro; un administrador de la agencia ve toda la agencia. Quien no tenga un centro asignado no recibe nada, y a quien tenga todos sus niños al día no se le escribe en absoluto.

Si no hay nada pendiente, no se envía nada. Un correo semanal que informa de cero enseña a la gente a ignorar al remitente.

## Avisar también a la familia

La casilla **Avisar también a la familia** añade un segundo recordatorio, independiente, enviado con el mismo calendario. Solo se envía mientras **Enviar estos recordatorios** también esté activado.

- **Enviar correo a los padres sobre su propio hijo** — un correo por familia, incluso con dos niños.
- **Enviar copia oculta a la oficina** — el personal marcado en **Destinatarios** recibe copia oculta, pero solo de las familias cuyos niños atiende.
- **Enviar también una notificación en la app** — una notificación dentro de la app y una notificación push, además del correo.
- **Solo vacunas obligatorias** — omite las dosis que su calendario marca como opcionales.
- **Avisarles de las dosis que vencen en** — 1, 2, 3 o 6 meses por adelantado.

Solo se escribe a cuentas de padres aceptadas y activas.

### Qué se le dice a la familia

Lo que dice el correo depende de lo que el centro ha registrado realmente:

- **Algunas dosis registradas** — nombra las dosis **Vencidas** y **Próximamente**, y pide que envíen el registro actualizado si ya se administró alguna.
- **Nada registrado y ningún registro archivado** — pide el registro. No les dice que su hijo tiene vencida una lista de vacunas, porque nadie ha leído aún su cartilla.
- **Nada registrado, pero hay un registro archivado** — no se escribe a la familia en absoluto. Ya lo envió; está esperando a que alguien del centro lo lea.

> **Importante:** Una cartilla archivada de la que no se ha registrado nada deja a esa familia fuera del recordatorio, así que lea pronto los registros que envían las familias (**Detalles pendientes**).

Las dosis con exención nunca se incluyen. Consulte [Exenciones de vacunación](immunization-exemptions).

## Preguntas frecuentes

??? ¿Por qué una familia no recibió un recordatorio? | O bien no hay nada vencido ni próximo para su hijo, o su registro está archivado esperando a ser leído, o su cuenta no ha sido aceptada.
??? ¿Puede un director de centro cambiar estos ajustes? | Esta pantalla está en el menú Ajustes del administrador de la agencia. Los directores reciben el recordatorio si Directores de centro está marcado.
??? ¿Ve un educador a los niños de otros centros? | No. La lista de cada destinatario se elabora con los niños a su cargo.

Véase también: [Archivar un registro de vacunación](filing-an-immunization-record),
[Vacunas exigidas por edad](immunization-due-age),
[Exenciones de vacunación](immunization-exemptions).
