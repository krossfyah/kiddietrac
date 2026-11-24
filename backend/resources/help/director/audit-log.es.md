---
title: Visor del registro de auditoría
category: Administration
order: 58
roles: agency_admin, platform_admin
---
# Registro de auditoría

Cada acción relevante del portal queda registrada. El visor está en **Administración → Registro de auditoría**.

## Qué se registra

Ejemplos (más de 50 tipos de acción a la versión v22p49):

- `user.created` / `user.deleted` / `user.revived` / `user.role_changed`
- `centre.created` / `centre.updated` / `centre.archived`
- `agency.suspended` / `agency.resumed` / `agency.updated`
- `invoice.created` / `invoice.late_fee_applied` / `payment.recorded`
- `branding.updated`
- `campaign.email_sent` / `digest.daily_sent` / `digest.weekly_sent`
- `chat.email_notified` / `form.submitted`

## Cómo leer el orden

![El registro de auditoría, con marca de tiempo al milisegundo](https://api.kiddietrac.com/help-img/audit-log.png)

**Las marcas de tiempo incluyen milisegundos.** Los segundos completos no ofrecían resolución suficiente para leer este registro: de las 300 filas más recientes, 241 comparten su segundo con otra fila, y un solo segundo en medio de una sincronización de integración contiene 25 de ellas. Con precisión de segundos, todas se leían como un único momento, así que una lista correctamente ordenada parecía arbitraria.

Las filas se ordenan de la más reciente a la más antigua, y los empates dentro del mismo instante se resuelven según el orden en que se escribieron. Así, lo que ve es el orden en que realmente ocurrieron las cosas, y ahora la marca de tiempo lo demuestra.

Todas las horas de esta pantalla están en **la zona horaria de su agencia**, nunca en UTC ni en la de su dispositivo.

Las filas escritas antes de septiembre de 2026 muestran `.000`. Es honesto más que ordenado: nunca se registró su orden por debajo del segundo, e inventarlo sería peor que admitirlo.

## Filtros

Cinco filtros en la parte superior:

- **Acción** — elija de la lista desplegable (se llena automáticamente con los tipos de acción vistos en su agencia)
- **Entidad** — limita a un tipo de entidad (usuario / centro / factura, etc.)
- **Buscar** — texto libre sobre el nombre de la acción o los datos JSON
- **De / Para** — límites del rango de fechas

Los filtros se aplican tanto a las filas en pantalla como a la descarga CSV.

## Exportación CSV

Haga clic en el botón verde **⤓ CSV** junto a Restablecer. Descarga hasta 5000 filas con las columnas Cuándo / Acción / Entidad / Autor / Correo / IP / Datos. Respeta todos los filtros activos.

La exportación se ordena exactamente igual que la pantalla, empates incluidos. Antes se ordenaba solo por la marca de tiempo, lo que dejaba las filas que compartían un segundo en el orden en que la base de datos las devolviera, y esto en la única copia del registro que se archiva y se entrega a alguien. La columna **Cuándo** se escribe como texto para que una hoja de cálculo no pueda redondear los milisegundos y reordenarla sin avisar.

## Ventana de detalle

Haga clic en cualquier fila para ver el detalle completo, incluidos los datos JSON con formato legible; útil para entender *exactamente* qué cambió.

## Pestaña Conversaciones de chat

**Registro de auditoría → 💬 Conversaciones de chat** (administradores de agencia y administradores de la plataforma). Todas las conversaciones de su agencia, entre familias y personal y entre miembros del personal, con la actividad más reciente primero:

- Filtro **Tipo** (familias ↔ personal, personal ↔ personal), **Buscar** entre personas, familias, centros y cualquier cosa dicha, y fechas **Activa desde / Iniciada por**.
- **Ver transcripción** muestra toda la conversación por día. Los mensajes eliminados aparecen tachados en rojo con la hora en que se eliminaron, los editados se marcan y los archivos adjuntos se listan por nombre. **⬇️ Descargar transcripción** la guarda como archivo de texto.
- **Abrir una transcripción queda registrado** aquí como *Transcripción de chat consultada*, con el nombre de quién abrió qué conversación.

Los directores de centro no ven esta pestaña.

## En un teléfono

En un teléfono cada entrada es una tarjeta (la hora, luego la acción y después su detalle), las pestañas van en una sola línea deslizable, y los filtros se pliegan tras un botón **⚙️ Filtros** que indica cuántos hay activos.

## Alcance

Los administradores de agencia ven las acciones de los usuarios de su agencia (alcance por rol O por vínculo de tutor). Los administradores de la plataforma ven todas las filas de la plataforma.
