---
title: Panel de cumplimiento
category: Administration
order: 56
---
# Panel de cumplimiento

Una sola pantalla con las tres señales de «qué requiere atención» que todo director de centro y administrador de agencia quiere ver de un vistazo. Ábrala desde **Administración → Cumplimiento**.

## Franja de indicadores

Cinco fichas en la parte superior:

- **Certificados vencidos** — certificaciones del personal con la fecha de vencimiento pasada
- **Vencen (30 d)** — certificaciones que vencen en los próximos 30 días
- **MFA no activado** — directores y administradores de agencia sin doble factor activado
- **En/por encima de la capacidad** — centros con el 100 % de matrícula o más
- **Ajustado (95 %+)** — centros cerca de su capacidad

## Tres tarjetas

### Certificaciones del personal

Lista todas las certificaciones vencidas o que vencen en 30 días. Cada fila muestra al miembro del personal, el tipo de certificación (RECE / Primeros auxilios / RCP / etc.) y el tiempo relativo (p. ej., «vence en 4 días»).

Estado vacío: «✅ Todas las certificaciones están al día.»

### MFA no activado

Lista los directores y administradores de agencia sin `two_factor_secret` definido. Cada fila muestra el nombre, el correo electrónico y la marca de la última sesión. Indíqueles que vayan a **Ajustes → Doble factor (MFA)** para activarlo.

Estado vacío: «✅ Todos los directores y administradores tienen la MFA activada.»

### Capacidad del centro

Para cada centro de la agencia: matriculados / capacidad autorizada, porcentaje y una barra de progreso con código de color (verde < 80 %, ámbar 80-95 %, rojo ≥ 95 %).

## Alcance de la agencia

Se acota con `getAgencyId()`. Los administradores de plataforma ven la parte correspondiente a la agencia que hayan fijado con el selector de agencia.
