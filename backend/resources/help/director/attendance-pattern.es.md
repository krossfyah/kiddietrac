---
title: Patrón de asistencia de varios días
---
# Patrón de asistencia de varios días

**Días de asistencia** es la pantalla donde la oficina registra qué días de la semana viene normalmente cada niño y en qué parte del día. Una sola cuadrícula muestra a todos los niños a la izquierda y de lunes a domingo arriba, así que puede configurar los patrones de todo un período en pocos minutos y responder de un vistazo «¿quién viene el miércoles?». El patrón alimenta las proporciones, la planificación de salas y las proyecciones de colegiaturas.

**Dónde está:** barra lateral → **Administración** → **Días de asistencia**. El encabezado de la pantalla dice **Patrón de asistencia**.

[[open: #attendance-pattern | Abrir Días de asistencia]]

## Cómo leer la cuadrícula

- La columna **Niño** muestra el nombre del niño, su estado de inscripción (inscrito, en lista de espera, retirado) y su centro y sala.
- Cada columna de día tiene una sola opción: **Full** (día completo), **AM** (solo mañana), **PM** (solo tarde), **Before** (antes de clases), **After** (después de clases), **B&A** (antes y después de clases), o **—** para un día en que no viene. Cada opción tiene su color; un día sin nada es gris claro con borde punteado, para que los huecos resalten.
- Debajo del nombre de cada día, una pequeña etiqueta indica cuántos de los niños mostrados vienen ese día, por ejemplo **12 in**.
- La columna de hoy está sombreada y su encabezado termina en **(TODAY)**.

## Configurar los días de un niño

1. Escriba parte del nombre del niño, del centro o de la sala en **Buscar**. La cuadrícula se reduce mientras escribe.
2. Debajo de cada día, abra la lista y elija **Full**, **AM**, **PM**, **Before**, **After**, **B&A** o **—**. La fila se vuelve ámbar y muestra **• unsaved**.
3. Revise **Los cambios se aplican desde**. Empieza en hoy; elija una fecha posterior si los nuevos días comienzan la próxima semana o el próximo período.
4. Pulse **Save 1 change** (el botón cuenta sus filas sin guardar; sin cambios dice **Guardar cambios** y está atenuado).
5. Aparece un **✓ Saved 1** verde junto al botón y el sombreado ámbar desaparece.

## Configurar varios niños a la vez

La mayoría de los niños comparten un patrón, así que los botones de llenado rellenan la cuadrícula para todos los que se muestran.

1. Use **Buscar** y **Mostrar** para reducir la cuadrícula a los niños que desea, por ejemplo una sala.
2. En **Apply to everyone shown:** pulse **Lun–vie, día completo**, **Lun–vie, mañanas**, **Lun–vie, tardes**, **Lun–vie, antes y después** o **Borrar todos los días**.
3. Un cuadro le pregunta si desea aplicarlo a 14 niños. Pulse **Rellenar cuadrícula**. Se llenan de lunes a viernes y se vacían sábado y domingo. Todavía no se guarda nada.
4. Cambie a mano las excepciones, por ejemplo un niño que solo viene martes y jueves.
5. Pulse **Save 14 changes**. El mensaje va contando (**Saving 3 of 14…**) y termina con **✓ Saved 14**.

> 💡 Rellene en bloque, corrija las excepciones y guarde una sola vez. Si sale de la pantalla antes de guardar, las filas sin guardar se pierden.

## Encontrar niños sin patrón

1. Abra la lista **Mostrar**.
2. Elija **Sin patrón**. Solo aparecen los niños sin ningún día configurado.
3. Configure sus días y pulse **Guardar cambios**.

**Mostrar** también ofrece **Solo matriculados** (la opción predeterminada) y **Todos**, que agrega a los niños en lista de espera y retirados.

## Qué hace «Los cambios se aplican desde»

Al guardar, un nuevo patrón empieza en esa fecha y el anterior termina donde comienza el nuevo. El patrón anterior se conserva, así que las proporciones y los informes de semanas pasadas siguen usando los días que regían entonces. Elija la fecha antes de guardar: todo un lote se guarda con la misma fecha.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de agencia | Ver a todos los niños de la agencia, editar cualquier patrón, usar los botones de llenado. |
| Administrador de plataforma | Lo mismo, dentro de la agencia a la que se cambió. |
| Director de centro | Días de asistencia no está en el menú del director. Pida a un administrador de agencia que cambie los días de un niño. |
| Padre o madre | Ve los días de sus propios hijos en el portal familiar, una pestaña por niño, y puede pulsar **✏️ Edit days** para cambiarlos allí. Sus cambios se ven aquí. |

## Preguntas frecuentes y solución de problemas

??? La pantalla dice «Could not load attendance patterns». | La lista no se cargó. Vuelva a cargar la página; si sigue pasando, revise su conexión o abra un ticket de soporte con la hora en que ocurrió.
??? La cuadrícula dice «No children match.» | Su búsqueda o el filtro **Mostrar** ocultan a todos. Borre **Buscar** y ponga **Mostrar** en **Todos**.
??? El mensaje dice «3 saved, 1 failed». | Las filas que fallaron siguen en ámbar con **• unsaved**. Pulse **Guardar cambios** otra vez para reintentar solo esas.
??? Un niño viene un día extra una sola vez. ¿Cambio el patrón? | No. El patrón es la semana habitual. Registre la llegada del niño ese día como siempre y deje el patrón como está.
??? ¿Por qué sábado y domingo quedan vacíos después de un llenado en bloque? | Los botones de llenado son de lunes a viernes. Configure a mano el cuidado de fin de semana para los niños que lo necesitan.
??? ¿El padre recibe un correo cuando cambio los días? | No. Guardar un patrón no envía correo ni mensaje de texto. Avise a la familia por el Centro de mensajes si el cambio es suyo.

## Relacionado

- [[open: #attendance-pattern | Abrir Días de asistencia]]
- [Entender las proporciones](understanding-ratios)
- [Horas habituales](standing-hours)
- [Asignar educadores a salas](room-assignments)
- [Colegiaturas y planes de cuotas](tuition-plans)
