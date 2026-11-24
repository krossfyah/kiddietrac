---
title: Bandeja de notificaciones
category: Settings
order: 67
---
# Bandeja de notificaciones

Todas las alertas de KiddieTrac para su cuenta en un solo lugar. La encontrará en **Ajustes → 🔔 Notificaciones** en la barra lateral (cualquier rol).

## Qué aparece aquí

Todo lo que dispara `DB::table('notifications')->insert()` en el servidor:

- Nuevos mensajes de chat (cuando se le pasó la notificación push del sistema)
- Campañas de marketing enviadas a usted
- Pagos registrados en las facturas de su familia
- Recargos por mora añadidos
- Reservas de visitas (vista de director / administrador)
- Confirmaciones de envío de resúmenes

## Filtrar

Tres opciones en la parte superior:

- **Todos** — todo, con los elementos leídos más antiguos mezclados con los no leídos
- **Solo no leídos** — lo que aún requiere atención
- **Solo lectura** — vista histórica

## Marcar como leído y como no leído

Tres maneras:

- **Hacer clic en cualquier fila** — la marca como leída al llevarle a lo que señala
- **El menú ⋮ de la fila** — **Marcar como leído** o **Marcar como no leído**, además de **Eliminar**
- **Marcar todo como leído** — pregunta primero y luego despeja todas las filas no leídas

Marcar algo como **no leído** es la forma de volver atrás si abrió una notificación por accidente.
Hasta hace poco no existía: una fila tocada por error salía del filtro de no leídos
y del contador de la campana para siempre.

## Eliminar

Use **Eliminar** en el menú ⋮ de la fila. Pregunta primero, porque eliminar una notificación
no se puede deshacer. Para borrar varias a la vez, use **Seleccionar** y marque las filas.

## Enlaces directos

Las notificaciones llevan un `data.url`, de modo que al hacer clic en una notificación de chat llega a `#chat`, en una factura a `#billing`, en una visita a `#tours`, etc.

## Señales visuales

- **Punto azul** en el borde izquierdo = no leído
- **Fondo azul claro** = no leído
- **Icono emoji por tipo**: 💬 chat, 🧾 factura, ✅ pago, 📢 anuncio, 📅 resumen, 📣 marketing, 🚪 visita, 📝 formulario
