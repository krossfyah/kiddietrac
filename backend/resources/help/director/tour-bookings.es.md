---
title: Reservas de visitas
category: Daily Operations
order: 78
---
# Reservas de visitas

Las familias interesadas pueden solicitar una visita al centro a través de su punto de acceso público de reservas. Las visitas confirmadas pasan al calendario del personal para que los directores sepan quién viene y cuándo. La vista de gestión está en **Crecimiento → Visitas**.

## El enlace público

Su URL pública de reservas es:

    POST https://api.kiddietrac.com/api/v1/public/tours

Insértela en un formulario personalizado de su sitio web que envíe `{ agency_slug, centre_id, parent_name, parent_email, parent_phone, child_age_months, preferred_start_date, tour_at, notes }`. Una futura entrega v22p51 ofrecerá una página de destino pública alojada, para que no tenga que crear el formulario usted mismo.

El punto de acceso está limitado a 8 solicitudes por hora por IP para evitar el spam.

## La vista de administración

La pantalla Visitas enumera cada reserva con:

- Un riel de color a la izquierda según el estado (ámbar = solicitada, azul = confirmada, verde = completada, rojo = no se presentó, gris = cancelada)
- Nombre del padre o madre + correo electrónico + teléfono
- Fecha y hora de la visita
- Edad del niño en meses (si se indicó)
- Notas de texto libre de la familia

Un menú desplegable de estado en cada fila hace avanzar la reserva por el flujo de trabajo. Cada solicitud también crea una notificación para el director del centro y los administradores de la agencia, de modo que vean las nuevas en su bandeja de entrada.

## Correos electrónicos de confirmación

Enviar un correo electrónico a quien solicita la visita al confirmar es un seguimiento de v22p50. Por ahora, copie su correo electrónico de la fila y responda manualmente.
