---
roles: agency_admin, platform_admin
title: Campañas automatizadas
category: Marketing
order: 65
---
# Campañas automatizadas

Envío automático de correos de seguimiento N días después de un evento desencadenante. Útil para acompañar a quienes reservaron una visita y para reactivar a las familias que se han quedado calladas.

## Crear una campaña

1. Barra lateral → **Campañas automatizadas**.
2. Toque **+ Nueva campaña automatizada**.
3. Elija un desencadenante:
   - **Después de reservar una visita** (p. ej., enviar un correo de «gracias por visitarnos» 1 día después)
   - **Después de completar la inscripción** (p. ej., paquete de bienvenida 3 días después)
   - **En el cumpleaños** (p. ej., sugerencia de programa adecuada a la edad)
   - **Después de 30 días de inactividad** (reactivación)
4. Elija el retraso en días (0-365).
5. Escriba el asunto y el cuerpo en HTML.
6. Guarde.

## Cómo funciona el envío

Una tarea cron se ejecuta cada hora. Busca los eventos desencadenantes que coinciden con el `(trigger_event, trigger_delay_days)` de cada campaña activa. Los eventos coincidentes se añaden a la cola `drip_sends` y se envían de inmediato.

Cada (campaña, origen) se envía una sola vez: las repeticiones son idempotentes.

## Seguimiento

La página de la campaña muestra: n.º de campañas · n.º enviados · n.º en cola · n.º fallidos.

## Plantillas de correo

Use las variables estándar de EmailTemplate en el HTML del cuerpo:
- `{{first_name}}`: nombre de pila del destinatario
- `{{family_name}}`: apellido de la familia
- `{{centre_name}}`: nombre del centro
- `{{agency_name}}`: nombre de su agencia

## Buenas prácticas

- Empiece con 1 o 2 campañas; mida la respuesta antes de añadir más
- Combine tonos: visita reservada = bienvenida cálida, inactividad = reactivación suave
- No envíe con demasiada frecuencia: calidad antes que cantidad
- Use la reescritura con IA (punto de acceso `/marketing/ai-rewrite`) para pulir el texto
