---
roles: agency_admin, platform_admin
title: Feed para compartir videos
category: Family Engagement
order: 22
---
# Feed de videos

Como las fotos, pero para videos cortos: mismo flujo de subida, etiquetado y reacciones.

## Personal: subir

1. Barra lateral → **Vídeos**.
2. Toque **+ Subir vídeo**.
3. Elija un archivo MP4 / MOV / WebM (máx. 64 MB tras el aumento del límite de PHP en v22p60).
4. Opcionalmente añada un pie de foto y etiquete a los niños que aparecen.
5. Suba.

## Vista del padre o madre

- Barra lateral → **Vídeos** bajo Su hijo.
- Vea los videos en que aparecen sus hijos, del más reciente al más antiguo.
- Toque para reproducir; toque los botones de reacción (❤️ 😊 😮 🎉 👏) para reaccionar.
- Bajo cada video se muestran el pie de foto, el nombre de quien lo subió y la fecha.

## Reacciones

Mismo sistema de reacciones que las fotos. Los padres tocan un botón de emoji para dar acuse de recibo o agradecer. El personal ve el número de cada reacción bajo cada video.

## Límites

- Máx. 64 MB por video (PHP `upload_max_filesize`)
- Duración máxima de 10 minutos por video (campo `duration_seconds`, aplicado en el cliente)
- Archivos guardados en `/storage/videos/YYYY/MM/`

## Cuándo usar videos

- Primeros pasos / primeras palabras
- Actuaciones de programas especiales (recital, presentación)
- Eventos de toda la familia (desfile, llegada de una excursión)
- Mostrar un momento de enseñanza en acción

Para momentos estáticos, las **fotos** siguen siendo la mejor opción: se descargan más rápido, se comparten con más facilidad y ocupan menos almacenamiento.
