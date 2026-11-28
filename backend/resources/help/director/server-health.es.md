---
title: Estado del servidor y protección contra fugas
---
# Estado del servidor y protección contra fugas

**Platform › Server health** muestra en una sola página si el portal está sano: la máquina (procesador, memoria, disco), la base de datos, las tareas en segundo plano, el programador, las copias de seguridad, lo que la gente notó en las últimas 24 horas (errores, solicitudes lentas o fallidas), qué tan rápido fue el portal en sus dispositivos, las palabras de búsqueda, las comprobaciones de **Leak guard** y los certificados web. Ábrala cuando digan que el portal está lento, después de una actualización o como revisión diaria.

[[open: #server-health | Abrir Server health]]

La página se actualiza sola cada 30 segundos mientras está abierta. Tiene dos pestañas: **🖥️ This server** (las cifras del propio portal) y **☁️ Cloudways · new server** (las gráficas de la cuenta de alojamiento).

## Hacer la revisión diaria

1. Abra **Platform › 🖥️ Server health** y pulse **🖥️ This server**.
2. Lea las líneas de alerta de arriba. **✅ Everything looks healthy.** significa que no se cruzó ningún límite; si no, cada problema aparece en palabras sencillas en una línea 🟠 (advertencia) o 🔴 (urgente).
3. Lea las tarjetas: **Processor**, **Memory**, **Disk** (con archivos subidos, copias y registros), **Database** (tiempo de respuesta y conexiones), **Background jobs** (en espera, espera más larga, fallidas hoy), **Scheduled jobs** (**Running** o **Not running**, último aviso), **Newest backup** (antigüedad, cifrada o no, copias guardadas) y **Last 24 hours** (errores, solicitudes lentas, solicitudes que nunca llegaron, limitadas). Verde está bien, ámbar hay que mirarlo, rojo hay que actuar.
4. Para ver más atrás, elija un periodo (**Last 6 hours** a **Last 14 days**) y mire las gráficas **History**, que se llenan cada 5 minutos.
5. Pulse **↻ Refresh** para volver a leer todo de inmediato.

## Revisar la protección contra fugas

La tarjeta **Leak guard** muestra las comprobaciones automáticas que aseguran que ninguna agencia pueda ver los datos de otra.

1. Baje hasta **Leak guard**.
2. Lea el **Result** de cada fila:
   - **Route probe (latest)**: la prueba, cada hora, de una parte de las solicitudes del portal con cada rol.
   - **Route probe (full, nightly)**: la misma prueba sobre todas las rutas, cada noche.
   - **Data check**: comprobaciones sobre los propios datos guardados.
   - **Live sentinel**: una muestra de respuestas reales de los **Last 7 days**.
3. **Clean** (verde) significa que no se encontró nada. **… finding(s)** o **… hit(s)** (rojo) significa una posible fuga: los detalles se listan en rojo bajo la tabla. **Blind: … role(s)** (ámbar) significa que la prueba no pudo entrar con algunos roles, así que revisó menos de lo debido. **Not run yet** significa que la comprobación aún no se ha ejecutado desde que se configuró.

> ⚠️ Trate cualquier resultado rojo de Leak guard como urgente: lea las líneas bajo la tabla y corrija la causa el mismo día.

## Averiguar por qué el portal se siente lento

1. Lea **Connections (devices, last …)**: **Sessions measured**, **On a slow connection** (porcentaje en 2G/3G o con esperas de más de 2 s), **Typical wait per request** y **Requests that failed**. Una tabla lista las agencias con las sesiones más lentas.
2. Lea **Where problems happened (24 h)**: cada solicitud **Server error**, **Slow** o **Never arrived**, la pantalla o solicitud afectada y cuántas **Times**.
3. Revise la sección **Database** para ver consultas lentas y las tablas más grandes.
4. Pulse **☁️ Cloudways · new server**, elija un periodo y mire las gráficas del alojamiento (procesador, memoria, disco, ancho de banda, uso de disco por aplicación). Pulse **↻ Cloudways** para volver a leerlas.

## Otras secciones

- **Search words**: cuántas palabras conoce la búsqueda del portal por rol, cuándo se reportó una pantalla por última vez, qué se aprendió y qué se rechazó esta semana. **Last screen reported** en rojo significa que la búsqueda dejó de aprender.
- **Certificates**: cada dirección web, cuándo **Expires** su certificado, los **Days left** y el **Issuer**. Los certificados se renuevan solos; pocos **Days left** significa que la renovación falló.

## Conectar la cuenta de alojamiento

1. Pulse **☁️ Cloudways · new server**.
2. Siga las instrucciones de la pestaña para crear un token de acceso en la cuenta de alojamiento.
3. Péguelo en la casilla y pulse **Connect Cloudways**.
4. Para quitarlo, pulse **Disconnect** y confirme. El token se borra de KiddieTrac; puede volver a conectarlo cuando quiera.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Toda la página. |
| Cualquier otro | La página dice que solo está disponible para **platform administrators**. |

## Preguntas frecuentes y problemas

??? "Scheduled jobs: Not running". | El programador que ejecuta todas las tareas con horario (copias, recordatorios, resúmenes) se detuvo. Es urgente: no se ejecuta nada programado hasta que se reinicie.
??? "Newest backup" está en rojo o dice NOT encrypted. | No hay una copia buena en las últimas 30 horas, o la copia no está cifrada. Abra Backups para ver el último error.
??? "The server could not be read just now." | No se pudieron cargar las cifras. Pulse ↻ Refresh en un minuto.
??? "Collecting — the history fills in every 5 minutes." | El historial es nuevo o se vació. Espere unos minutos.
??? Leak guard dice "Blind". | La sonda no pudo actuar con uno o más roles, así que su revisión está incompleta. No la considere limpia todavía.
??? ¿Esta página avisa a alguien? | La página en sí no envía nada; solo muestra. Una copia fallida también genera una alerta en Security alerts.

## Pantallas relacionadas

[[open: #backups | Copias de seguridad]] [[open: #security-alerts | Alertas de seguridad]] [[open: #ai-usage | Uso de IA]] [[open: #maintenance | Mantenimiento]] [[open: #compliance-evidence | Evidencia de cumplimiento]]
