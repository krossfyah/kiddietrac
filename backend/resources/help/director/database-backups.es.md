---
title: Copias de seguridad de la base de datos
---
# Copias de seguridad de la base de datos

**Platform › Backups** muestra si realmente se está haciendo la copia nocturna de la base de datos de la plataforma, cuándo fue la última copia buena y cuántas se guardan. También es donde se cambia la hora de la copia y cuánto tiempo se guardan, y donde se hace una copia a mano antes de un trabajo arriesgado. Ábralo en la revisión diaria, antes de un cambio grande, o cuando Server health muestre la copia más reciente en rojo.

[[open: #backups | Abrir Backups]]

## Comprobar que está cubierto

1. Abra **Platform › 🗄️ Backups**.
2. Lea la línea de color de la tarjeta de arriba:
   - **Healthy**: se hizo una copia buena en el último día, más o menos.
   - **Older than a day**: la ejecución de anoche no produjo copia.
   - **Stale — more than 2 days old**: trátelo como una falla.
   - **Last run failed**: el error aparece en rojo debajo.
   - **No backup recorded yet**.
3. Lea **Last good copy** (hace cuánto y la hora exacta), las **copies kept**, los **MB on disk** y el tamaño de la última copia.
4. Revise **Scheduler last seen**. Todas las tareas programadas de la plataforma, no solo las copias, dependen de un único programador que corre cada minuto. Si dice **never — the cron may not be running**, no se está ejecutando nada programado. Un **backup folder is not writable** en rojo significa que no se pueden guardar copias.

## Cambiar el horario

1. En la segunda tarjeta, deje **Nightly backup** activado. Desactivado significa que no se hace ninguna copia de la base de datos.
2. Fije **Time**, en hora del servidor. Elija una hora tranquila: la copia no bloquea nada, pero lee todas las tablas. Por defecto: 03:30.
3. Fije **Keep**, cuántas copias diarias conservar (de 1 a 90 días; 14 por defecto). Las más antiguas se borran después de cada ejecución.
4. Pulse **Save**. Verá **✓ Saved**, o, si desactivó las copias, un **⚠ Saved — nightly backups are now OFF** en ámbar en lugar de una marca.

> ⚠️ Sin copias no hay copia nocturna de la base de datos. Desactivarlas queda registrado en el registro de auditoría.

## Hacer una copia ahora

Hágalo antes de un cambio arriesgado, como una importación grande o una actualización.

1. Pulse **Back up now**.
2. Espere mientras aparece **Taking a backup… this reads every table, give it a moment.** El botón espera el resultado.
3. Verá **✓ Backup taken** y la nueva copia aparece en **Copies on disk**, o un error en rojo y la tarjeta de estado muestra **Last run failed**.

[[show: #backups @ Back up now | Muéstreme]]

## Copias en disco

La tarjeta de abajo lista las copias más recientes con la hora de cada una, su nombre de archivo y su tamaño. Cada copia se comprueba después de escribirse; una copia fallida o dañada se descarta en vez de guardarse. Las copias se cifran para que solo la llave fuera de línea de KiddieTrac pueda abrirlas; una copia que no se pudo cifrar se guarda igual y genera una alerta, porque una copia es mejor que ninguna.

> ℹ️ **Las copias no se pueden descargar desde el portal, a propósito.** Un solo archivo contiene a todos los niños, tutores, direcciones y notas médicas de la plataforma. Se guardan fuera del sitio web y las restaura una persona con acceso al servidor y a la llave fuera de línea, nunca desde un navegador.

## Restaurar una copia, y la copia externa

Cada copia se cifra con la clave de Anthony antes de escribirse, así que solo quien tiene esa clave privada puede leerla. La restaura una persona con acceso al servidor: `gpg --decrypt <copia>.sql.gz.gpg | gunzip | mysql <base>`. La clave privada nunca se guarda en el servidor.

La línea de estado también dice si hay una **copia externa** confirmada: Cloudways hace su propia copia de todo el servidor (cada 3 horas, guardada 29 días) y la pantalla muestra cuándo se hizo la última. Si dice **Off-site copy not confirmed**, conecte Cloudways en **Server health** para que el portal pueda comprobarlo. La misma línea aparece en el mosaico de copias de **Compliance evidence**.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Todo en esta pantalla. |
| Administradores de agencia y todos los demás | Sin acceso. Una sola base de datos sirve a todas las agencias, así que una copia tiene los datos de todos, no de una agencia. El servidor rechaza a cualquier otro. |

## Preguntas frecuentes y problemas

??? El estado dice "Last run failed". | Lea el error en rojo debajo. Corrija la causa (a menudo el espacio en disco) y pulse Back up now para comprobar que funciona. Una ejecución fallida también genera una alerta de alta gravedad en Security alerts.
??? "Scheduler last seen: never — the cron may not be running". | El programador se detuvo, así que no corre ninguna tarea programada. Es urgente: pida que lo reinicien en el servidor.
??? "Backups are managed by platform administrators only." | No inició sesión como administrador de la plataforma.
??? Desactivé las copias a propósito. ¿Recibiré alertas cada noche? | No. Desactivarlas no se considera una falla.
??? ¿Una agencia puede recuperar sus propios datos? | No desde una copia de esta pantalla. Use la exportación y las solicitudes de privacidad de la agencia.
??? ¿Se avisa a alguien cuando corre una copia? | No. Solo las fallas generan alerta; los cambios de configuración y las copias manuales van al registro de auditoría con los valores de antes y después.

## Pantallas relacionadas

[[open: #server-health | Estado del servidor]] [[open: #security-alerts | Alertas de seguridad]] [[open: #compliance-evidence | Evidencia de cumplimiento]] [[open: #maintenance | Mantenimiento]]

Vea también: [Alertas de seguridad y monitoreo](security-alerts), [Retención de datos y cumplimiento](data-retention-compliance).
