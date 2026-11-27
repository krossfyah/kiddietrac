---
title: RR. HH.: informe de ausencias, retrasos, puntuación de ausencias y coincidencias de permisos
---
# RR. HH.: informe de ausencias, retrasos, puntuación de ausencias y coincidencias de permisos

**Recursos humanos > Ausencias y retrasos** muestra quién ha faltado y quién ha llegado tarde, en las fechas que usted elija. Tiene dos vistas: **Tiempo libre** (permisos aprobados, ausencias cortas repetidas y la puntuación de ausencias) y **Retrasos** (primeros fichajes comparados con el turno del horario). Úselo antes de una conversación de seguimiento, al cierre del mes o cuando note un patrón. Forma parte del complemento KiddieTrac HR.

[[open: #hr-absence | Abrir Ausencias y retrasos]]

## Generar el informe de ausencias

1. Abra **Recursos humanos > Ausencias y retrasos**. Se abre la vista **Tiempo libre**.
2. Indique las fechas **De** y **Para**.
3. Elija cómo agrupar: **Por persona**, **Por tipo de permiso**, **Por mes** o **Por centro**.
4. Filtre con **Todos los centros** (si tiene más de uno) y **Todos los tipos de permiso**.
5. Lea los totales de arriba: **Días libres**, **Horas libres**, **Personas**, **Ausencias cortas repetidas** y **Por encima de la puntuación de ausencias**.
6. Pulse **Exportar a Excel** para descargar lo que ve.

La tabla muestra **Días libres**, **Horas libres**, **Ausencias**, **Ausencias cortas**, **Puntuación de ausencias** y el desglose por tipo de permiso. Pulse **⋮ > Ver perfil de RR. HH.** para ver los saldos y permisos recientes de una persona.

## Encontrar ausencias cortas repetidas

1. Agrupe el informe **Por persona**.
2. Marque **Solo ausencias cortas repetidas**.
3. Las personas que alcanzan la regla de su agencia (por ejemplo 3 o más ausencias de 2 días o menos en 6 meses) aparecen con una etiqueta ámbar **Ausencias cortas repetidas**.

La línea bajo los totales indica la regla. Los administradores de la agencia la cambian en **Recursos humanos > Ajustes de RR. HH.** (**Ausencias**, **Of this many days or less**, **Within (months)**).

## Usar la puntuación de ausencias

1. Agrupe el informe **Por persona**.
2. Marque **Solo por encima de la puntuación de ausencias**.
3. Lea la columna **Puntuación de ausencias**. Debajo de cada una verá el cálculo, por ejemplo «3 × 3 × 3».

La puntuación es ausencias × ausencias × días de ausencia en el período (la fórmula de Bradford), así que muchas ausencias cortas pesan más que una larga: tres ausencias de un día suman 27; una ausencia de tres días suma 3. Las ausencias separadas solo por un fin de semana o un día de cierre cuentan como una. Quien llega al umbral o lo supera (50, salvo que su agencia lo cambie) recibe una etiqueta **Above 50**.

> 💡 La puntuación es un motivo para conversar, nunca una decisión automática. Revise primero las razones de las ausencias.

## Revisar los retrasos

1. Pulse **Retrasos** en la parte superior de la pantalla.
2. Indique **De**, **Para** y, si aparece, el centro.
3. Marque **Solo las personas que llegaron tarde** para ocultar a quienes llegaron a tiempo.
4. Lea los totales: **Fichajes comparados**, **Tarde**, **Retraso en total** y **Personas con retraso**.
5. En una fila, pulse **⋮ > Ver fichajes**. La ventana **Fichajes frente al horario** lista cada día: el inicio del turno, la hora del fichaje y **A tiempo**, «dentro del margen» o «X min tarde».
6. Pulse **Exportar a Excel** para descargar la lista.

Solo se compara el primer fichaje de cada turno programado. Llega tarde quien ficha después del margen de cortesía (5 minutos, salvo que su agencia lo cambie) tras el inicio del turno. El regreso de un descanso y los días sin turno no se comparan. La columna **Patrón** señala hábitos como «Sobre todo los lunes».

## Definir las reglas de asistencia (administradores de la agencia)

1. Abra **Recursos humanos > Ajustes de RR. HH.**.
2. Baje hasta las secciones **Retrasos**, **Puntuación de ausencias** y **Coincidencias de permisos y personal mínimo**.
3. Indique el **Margen de cortesía (minutos)**, **Avisar a los directores del centro** y si se comparan los **Contratistas**.
4. Indique **Marcar desde**, **En los últimos (meses)** y marque los **Tipos de permiso que cuenta** (ninguno marcado = permisos por enfermedad).
5. Elija **Mostrar quién más está ausente** y qué pasa **Por debajo del personal mínimo**: **Avisar a quien aprueba** o **No permitir aprobarlo**.
6. Escriba el **Personal mínimo en un día laborable** de cada centro, o deje **No minimum**.
7. Pulse **Guardar las reglas de asistencia**.

## Coincidencias de permisos y personal mínimo

Cuando alguien pide tiempo libre, su formulario muestra cuántas personas de su centro ya están ausentes o piden esos días (nunca quiénes). Quien aprueba ve quiénes, en la ventana **Aprobar tiempo libre** y en el correo de aprobación («Also away at…»). Con un mínimo definido, se le indica qué días quedarían por debajo; en modo **No permitir aprobarlo**, esas aprobaciones se rechazan. Un permiso sin aprobación, como un día por enfermedad, nunca se rechaza, solo se muestra.

## Quién puede hacer qué

- **Administradores de la agencia**: todos los centros; cambian las reglas en Ajustes de RR. HH.
- **Directores de centro**: las personas de sus propios centros; reglas solo de lectura.
- **Personal**: no ve esta pantalla; solo ve el número de coincidencias en su propia solicitud.

## Preguntas frecuentes y cuando algo sale mal

??? Retrasos dice «No clock-ins to compare in this range.» | Los retrasos necesitan turnos en el horario. Un fichaje en un día sin turno no se compara. Revise que el horario de esas fechas esté publicado.
??? El informe de ausencias está vacío. | Solo muestra permisos aprobados. Las solicitudes pendientes y rechazadas no aparecen. Amplíe las fechas o quite los filtros de centro y tipo de permiso.
??? ¿Se avisa a los directores cuando alguien llega tarde? | Solo si un administrador puso **Avisar a los directores del centro** en **Sí, un aviso cuando alguien llega tarde**. Entonces los directores del centro reciben un aviso en la campana. Si no, solo aparece en el informe.
??? Alguien llegó tarde, pero no está en la lista. | Solo se compara el primer fichaje de un turno, y los contratistas se omiten salvo que su agencia los compare. Un fichaje dentro del margen cuenta como a tiempo.
??? ¿Puedo cambiar el umbral de la puntuación? | Los administradores de la agencia pueden, en **Recursos humanos > Ajustes de RR. HH.**, en **Puntuación de ausencias**.

## Pantallas relacionadas

[[open: #time-off | Solicitudes de tiempo libre]] [[open: #timesheets | Fichajes y hojas de horas]] [[open: #hr-reports | Informes de RR. HH.]]

- [[hr]] — personas y saldos de permisos
- [[time-off]] — aprobar tiempo libre
- [[hr-reports]] — los informes de retrasos y ausencias
