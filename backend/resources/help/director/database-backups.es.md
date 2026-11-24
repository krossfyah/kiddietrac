---
title: Copias de seguridad de la base de datos
category: Settings
order: 45
roles: agency_admin, platform_admin
---
# Copias de seguridad de la base de datos

**Ajustes → Copias de seguridad.** Indica si la copia nocturna de la base de datos de la plataforma
se está realizando, cuándo fue la última buena y cuántas se conservan.

[[open: #backups | Abrir Copias de seguridad]]

## Quién puede usarlo

**Solo los admins de la plataforma.** Una sola base de datos respalda a todas las agencias de la plataforma, así que una
copia contiene los datos de todos, no de una sola agencia. Un administrador de la agencia no ve el elemento
del menú, y el servidor rechaza a cualquier otro.

## La tarjeta de estado

La tarjeta superior responde «¿estamos cubiertos?» en una línea:

- **Saludable**: se hizo una buena copia dentro del último día aproximadamente.
- **Más de un día**: la ejecución de anoche no produjo una.
- **Obsoleta — más de 2 días**: trátelo como un fallo.
- **Falló la última ejecución**: el error se muestra debajo en rojo.
- **Aún no hay copias registradas**.

Debajo: **Última copia buena** (hace cuánto, y la hora exacta), cuántas **copias conservadas**,
el total de **MB en disco** y el tamaño de la más reciente.

**Último aviso del programador** es la línea que debe revisar cuando algo parezca raro. Todas las tareas programadas
de la plataforma, no solo las copias, dependen de un programador que se ejecuta cada minuto. Si
indica *nunca — puede que cron no se esté ejecutando*, no ocurre nada programado. También aparece una advertencia
si la carpeta de copias no es escribible.

## Ajustes

- **Copia nocturna**: activada o desactivada. Desactivada significa que no se hace ninguna copia de la base de datos.
- **Hora**: cuándo se ejecuta la copia nocturna, en **hora del servidor**. Elija una hora tranquila. La copia
  no bloquea nada, pero lee todas las tablas. El valor predeterminado es 03:30.
- **Conservar**: cuántas copias diarias retener, de 1 a 90. El valor predeterminado es 14. Las copias más antiguas
  se eliminan después de cada ejecución.

Pulse **Guardar**. Al desactivar las copias, se guarda con una advertencia ámbar, *Guardado — las copias nocturnas
ahora están DESACTIVADAS*, en lugar de una marca verde.

> **Importante:** Con las copias desactivadas no hay copia nocturna de esta base de datos. Desactivarlas queda registrado en el registro de auditoría con esas mismas palabras.

## Hacer copia ahora

**Hacer copia ahora** toma una copia de inmediato y espera el resultado, de modo que vea si
**funcionó**, y no solo que se solicitó. Lee todas las tablas, así que dele un momento.

[[show: #backups @ Back up now | Muéstreme el botón Hacer copia ahora]]

## Copias en disco

La tarjeta inferior enumera hasta 30 de las copias más recientes, con la hora de cada una,
su nombre de archivo y su tamaño.

## Por qué no puede descargar una

**Las copias no se pueden descargar desde el portal, y es a propósito.** Un solo archivo contiene
a todos los niños, tutores, direcciones y notas médicas de la plataforma. Las copias se almacenan
fuera de la raíz web, y la restauración la hace una persona por SSH. La pantalla muestra el comando de restauración
para quien haga ese trabajo.

## Cuando algo sale mal

- Cada copia se comprueba para verificar su integridad después de escribirse. Una copia fallida o corrupta se
  descarta en lugar de conservarse.
- Una ejecución fallida se muestra aquí como **Falló la última ejecución** y genera una alerta de alta gravedad en
  **Ajustes → Alertas de seguridad**.
- Desactivar las copias a propósito no se trata como un fallo, así que no genera
  una alerta cada noche.
- Cambiar los ajustes y ejecutar una copia a mano se registran en el registro de auditoría,
  con los valores anteriores y posteriores.

Vea también: [Alertas de seguridad y monitoreo](security-alerts),
[Admin de la plataforma (resumen)](platform-admin-overview),
[Retención de datos y cumplimiento](data-retention-compliance).
