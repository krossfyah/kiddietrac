---
title: Hojas de horas y corrección de horas
category: Staff Management
order: 31
---
# Hojas de horas y corrección de horas

**Personal → Hojas de horas.** Cada turno trabajado en sus centros en un rango de fechas, por persona,
con el total arriba. Es la hoja a partir de la cual se elabora la nómina — los mismos fichajes que
**Nómina** lee para calcular lo que se le debe a alguien — así que una hora equivocada aquí se convierte en una
cifra equivocada en una nómina.

## Elegir lo que está viendo

Tres controles, todos se aplican en el momento en que los cambia:

- **Centro** — un solo proveedor, o *Todos los centros*, es decir, todos los centros que usted puede ver
  y no todos los centros de la agencia.
- **De** y **Para** — el período. Escríbalas en cualquier orden; las fechas se intercambian
  si las rellena de derecha a izquierda. Se abre con los últimos 30 días.
- **🔄** vuelve a leer el rango, **⬇️** lo descarga como CSV para su proveedor de nómina.

## Leer una fila

Cada fila es un turno: la fecha, quién lo trabajó, entrada, salida, descanso y horas.

- **Un turno abierto** muestra una raya en la salida y **0 h**. Nadie fichó la
  salida. Deliberadamente *no* se suma — un turno sin terminar es algo que hay que perseguir, no
  algo que pagar — y deliberadamente tampoco se oculta.
- **«Sin horas registradas»** es una persona asignada a su centro que no fichó nada en el
  período. Una fila en cero es una pregunta que vale la pena hacer; una fila ausente es invisible.
- **Descanso** muestra 0 min para todos. El reloj actual no registra descansos, y las
  horas se indican sin ajustar en lugar de presentarse como si se hubiera descontado un descanso.

## Corregir un turno

Cada fila lleva el menú estándar **⋮** en su extremo derecho. En un teléfono está al
pie de la tarjeta de la fila.

### Editar horas

Abre el turno con su entrada y su salida listas para cambiar, y un cuadro para el motivo.

- Las horas son las del **reloj del centro**, no las de su dispositivo. El cuadro lo indica. Un administrador
  que trabaja desde otra zona horaria escribe la hora a la que la persona realmente trabajó.
- Deje la salida vacía para dejar un turno abierto; rellénela para cerrar uno
  cuya salida nunca se fichó.
- El motivo es opcional aquí y conviene escribirlo de todos modos — queda registrado en el propio turno,
  para que la siguiente persona que lea la fila vea por qué cambió.

El cambio se rechaza, con el motivo en pantalla, si la salida no es posterior a
la entrada o si el turno supera las 24 horas. Un turno tan largo casi siempre es una
fecha equivocada en un extremo, y pagarlo en silencio es peor que rechazarlo.

### Añadir un turno

Para el turno que nunca se fichó: una tableta apagada, un olvido, alguien que cubre
a última hora. Está disponible en todas las filas, porque un turno partido es un segundo fichaje para
alguien que ya tiene uno, y es lo **único** que se ofrece en una fila «Sin horas registradas».

- **Centro** indica dónde se archiva el turno y, por tanto, qué nómina lo paga. Importa
  para quien está destinado a varias sedes; no se adivina por usted.
- Se exige un **motivo**. Un turno introducido a mano es una afirmación sobre horas que se le pagarán
  a alguien, y la nota del turno indica quién lo introdujo y por qué.
- Se rechaza si se solapa con un turno existente — contaría las horas dos veces.

### Eliminar turno

Para un turno que nunca ocurrió: un fichaje doble, una entrada fichada en la tableta de otra persona, un
registro escrito con el nombre equivocado. Editar responde a «estas horas están mal»; esto responde a
«esto no es real».

Pide un motivo y no se puede deshacer. El registro de auditoría conserva el turno eliminado completo —
las horas que contenía y sus notas — porque, una vez eliminado, no queda nada más que leer.

## Quién puede hacerlo

Un director o administrador de agencia, para el centro donde se **trabajó** el turno. Es el centro
registrado en el propio fichaje, no donde la ficha del miembro del personal esté archivada en primer lugar — así que, para una persona destinada a varias sedes, el director de la sede en la que
realmente trabajó es quien puede corregirlo.

## Qué se registra

Cada corrección, entrada y eliminación escribe una fila de auditoría con el nombre de la persona, las horas
antes y después, y quién hizo el cambio. Las encontrará en
**Administración → Registro de auditoría** como *Horas del personal corregidas*, *Turno del personal introducido a mano*
y *Turno del personal eliminado*. El propio turno también lleva una nota breve, de modo que la fila de la
hoja de horas indica que se corrigió sin que nadie tenga que ir a comprobarlo.

## Conviene saber

- Corregir un fichaje aquí es la misma corrección que la del propio registro de usuario de la persona, y escribe en el mismo lugar — hay una sola historia de un turno, no dos.
- Un educador no puede editar sus propias horas. Su reloj solo alterna, por eso un turno
  dejado abierto durante la noche solo puede corregirse desde aquí.
- Si alguien le dice que olvidó fichar la entrada, esta es la pantalla donde arreglarlo.

Vea también: [Reloj de fichaje del personal](time-clock), [Ejecuciones de nómina, horas y recibos](payroll-documents),
[Exportación CSV lista para nómina](payroll-csv), [Cierre automático](auto-sign-off).
