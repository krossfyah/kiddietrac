---
title: RR. HH.: configurar los tipos de permiso
---
# RR. HH.: configurar los tipos de permiso

**Recursos humanos > Tiempo libre > Tipos de permiso** es donde un administrador de la agencia configura los permisos que usa su equipo (vacaciones, días por enfermedad, días personales…), cuánto recibe cada persona, cómo se acumula y qué pasa al año siguiente. Son las reglas de su agencia: KiddieTrac no aplica por usted ninguna ley laboral de provincia o estado. Configúrelo una vez al empezar con RR. HH. y otra vez cuando cambie su política de permisos.

[[open: #time-off?tab=types | Abrir Tipos de permiso]]

## Empezar con el conjunto de ejemplo

1. Abra **Recursos humanos > Tiempo libre > Tipos de permiso**.
2. Pulse **Cargar conjunto de ejemplo**.
3. Lea el mensaje **Load the example set?** y pulse **Load examples**. Se agregan Vacaciones, Días por enfermedad, Días personales, Duelo y Permiso sin goce con importes de ejemplo, cada uno marcado **Ejemplo**. Los nombres que ya usa se omiten.
4. Pulse **⋮ > Edit** en cada uno y cambie cada valor por su propia política.

> ⚠️ Los importes de ejemplo no son el mínimo de ninguna ley. Nunca se aplica nada por sí solo.

## Agregar un tipo de permiso

1. Pulse **Añadir tipo de permiso**.
2. Escriba el **Nombre** (por ejemplo Vacaciones) y elija un **Color**.
3. Elija **Se cuenta en**: **Días** u **Horas**.
4. Elija **Pago**: **Pagado** o **Sin pagar**.
5. Elija **Staff can see and request it**: **Sí**, o **No, managers only**.
6. Elija **Aprobación**: **Requiere aprobación**, o **No approval: recorded at once, manager reviews it**.
7. Elija **Se paga cuando alguien se va** (por defecto solo se pagan las vacaciones y la bolsa de horas extra).
8. Elija **Cómo se acumula** (vea abajo) y qué pasa **At the end of the leave year** (vea Arrastre).
9. Si su equipo usaba el formulario anterior, indique **Solicitudes antiguas archivadas como** (por ejemplo Vacaciones o Enfermedad).
10. Pulse **Guardar**. Luego se abre la ventana **Reglas de derecho** para indicar cuánto recibe cada uno.

> ℹ️ Un tipo sin aprobación se registra y se descuenta del saldo en cuanto se pide. Los responsables lo siguen viendo **Por revisar** en **Tiempo libre** hasta que uno pulsa **Revisado**, lo que no cambia el saldo.

## Cómo se acumula el permiso

- **Yearly, all at once**: al inicio del año de permisos.
- **Each pay period**: elija **Pay periods a year** (12, 24, 26 o 52).
- **By hours worked**: según los fichajes; indique **Hours worked to earn 1 unit**, hasta el importe anual.
- **Sin seguimiento**: las solicitudes se registran, pero no hay saldo.

Quien entra a mitad del año de permisos recibe una parte de la asignación anual por los días que quedan, redondeada a medio día (a un cuarto de hora en los tipos por horas).

## Definir las reglas de derecho

1. En la fila del tipo, pulse **⋮ > Reglas de derecho**.
2. En la primera fila, elija el **Tipo de empleo** (o **Todos**), el **After years of service** y el **Amount a year**.
3. Pulse **Add a rule** para cada escalón, por ejemplo 10 días para todos, 15 días después de 5 años.
4. Pulse **Quitar** en la fila que no necesite.
5. Pulse **Guardar reglas**.
6. Pulse **Aplicar asignaciones y acumulaciones ahora** para aplicarlas hoy. También se aplican cada noche.

Se aplica la regla con más años de servicio que la persona ya cumplió, y una regla para su tipo de empleo tiene prioridad sobre una para **Todos**.

## Arrastre al año siguiente

1. En **⋮ > Edit**, elija **At the end of the leave year**: **Ninguno**, **Todo** o **Up to a limit**.
2. Para **Up to a limit**, escriba el **Carry-over limit**.
3. Para que el saldo arrastrado venza, indique **Carried balance expires after (months)**. Déjelo vacío para **Never**.
4. Pulse **Guardar**.

## Cómo se cuentan los días

Las solicitudes cuentan días laborables. Se omiten los fines de semana, los feriados y los cierres del calendario del centro de la persona. Un cierre creado por el propio permiso de la persona, o un día de formación, sí cuenta. Para descontar un cierre de invierno de los permisos, vea [[hr-closures-leave]].

## Contar las solicitudes hechas antes de RR. HH.

1. Indique **Solicitudes antiguas archivadas como** en el tipo y pulse **Guardar**.
2. Configure sus reglas de derecho.
3. En la fila, pulse **⋮ > Count this year's approved requests** y luego **Count them**. Las solicitudes ya aprobadas este año de permisos se descuentan de los saldos, una sola vez. Las solicitudes en sí nunca cambian.

## Archivar un tipo

Pulse **⋮ > Archivar**. El tipo deja de ofrecerse y se conservan los saldos. **⋮ > Restaurar** lo recupera.

## Quién puede hacer qué

- **Administradores de la agencia**: configuran tipos y reglas. Solo ellos ven esta pestaña.
- **Directores de centro**: ven el resultado en **Saldos** y aprueban solicitudes, pero no cambian los tipos.
- **Personal**: elige los tipos visibles en su formulario de permiso y ve su saldo en **Mis RR. HH.**.

## Preguntas frecuentes y cuando algo sale mal

??? Agregué una regla y los saldos no cambiaron. | Pulse **Aplicar asignaciones y acumulaciones ahora**, o espere al proceso de la noche. Las asignaciones ya dadas nunca cambian; agregue un ajuste en **Saldos** para una diferencia pasada.
??? El personal no ve un tipo de permiso en su formulario. | Edite el tipo y ponga **Staff can see and request it** en **Sí**. Los tipos archivados nunca se ofrecen.
??? «Give it a name.» | El campo **Nombre** está vacío. Escriba un nombre y pulse **Guardar** otra vez.
??? ¿Dónde indico cuándo empieza el año de permisos, o si las solicitudes pueden bajar de cero? | En **Recursos humanos > Ajustes de RR. HH.**: **Inicio del año de permisos** y **Solicitudes que bajan de cero** (**Refuse them** o **Allow them, with a warning**).
??? ¿Se avisa al personal cuando cambio un tipo? | No. No se envía ningún correo. Cada persona ve su nuevo saldo en **Mis RR. HH.**.

## Pantallas relacionadas

[[open: #time-off?tab=balances | Saldos]] [[open: #time-off | Solicitudes de tiempo libre]] [[open: #hr-settings | Ajustes de RR. HH.]]

- [[hr]] — personas, expedientes y saldos
- [[hr-closures-leave]] — cierres descontados de permisos y proporcional para medio tiempo
- [[time-off]] — pedir y aprobar tiempo libre
