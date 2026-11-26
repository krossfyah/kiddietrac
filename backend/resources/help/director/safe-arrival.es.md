---
title: Safe Arrival — cada niño programado, localizado
category: Operations
order: 5
roles: agency_admin, centre_director, platform_admin
---
# Safe Arrival — cada niño programado, localizado

Safe Arrival se asegura de que ningún niño esperado hoy pase inadvertido. Si un niño
no ha sido registrado a su hora de entrega prevista y nadie ha informado de
una ausencia, KiddieTrac consulta a los padres. Si no responden, puede **llamarlos por teléfono**,
y luego alerta a los educadores de la sala y al director, y el tablero **Safe Arrival**
muestra a quién falta llamar.

> Safe Arrival viene con el complemento **SMS y voz**. Sin él, los administradores de la agencia lo ven en gris con la insignia **Upgrade**.

[[open: #safe-arrival | Abrir Safe Arrival]]

## Cómo funciona

1. Cada 5 minutos, KiddieTrac revisa a cada niño **programado hoy** en un
   centro que está **abierto hoy**.
2. Cuando pasa la **hora de entrega prevista del niño + el período de gracia**, y no está
   registrado ni se informó de una ausencia, se consulta una vez a los padres: *«¿Ha
   llegado Maya?»*, por los canales que eligió cada padre (app, correo, mensaje de texto). Pueden tocar
   **No asiste hoy** en la app.
3. Si la llamada telefónica está activada y sigue sin haber respuesta tras el **tiempo de llamada**,
   los padres reciben una llamada automática (vea más abajo).
4. Si sigue sin haber registro ni ausencia tras el **tiempo de escalamiento**, los educadores
   de la sala y el director del centro reciben una alerta que abre este tablero.
5. En cuanto se registra al niño o se informa una ausencia, la verificación se cierra
   sola.

No se verifica nada en un día en que el niño no está programado, ni cuando el centro está cerrado.

## Cómo activarlo

Safe Arrival está **desactivado** hasta que un administrador de la agencia lo active, porque envía mensajes a los padres.

- [ ] Abra **Safe Arrival** y presione **⚙️ Ajustes**.
- [ ] Marque **Comprobar que llegue cada niño programado**.
- [ ] Defina cuántos minutos después de la hora prevista se consulta a los padres (15 por defecto).
- [ ] Defina cuánto esperar a los padres antes de alertar al personal (20 por defecto).
- [ ] Defina la hora prevista para los niños que no tienen una (9:00 a. m. por defecto).
- [ ] Opcional: marque **Llamar a los padres si no responden**, y defina **Llamar tantos minutos después de consultar a los padres** (10 por defecto).
- [ ] Guarde.

[[show: #safe-arrival @ Settings | Muéstreme el botón de ajustes]]

> **Consejo:** Asigne a cada niño su propia hora de entrega en su ficha (**Horarios habituales**). Los niños sin una se comprueban con la hora predeterminada, y el tablero los marca con «(predeterminada)».

Cuando Safe Arrival está activado, reemplaza el antiguo recordatorio de las 9:30 a. m. «¿ha llegado su hijo?»
en su agencia, de modo que los padres reciben un mensaje a la hora correcta en lugar de dos.

## La llamada telefónica

La llamada telefónica está **desactivada** hasta que un administrador de la agencia marque **Llamar a los padres si no responden**. Requiere que las llamadas de voz estén configuradas en su agencia (Ajustes → **Ajustes de operadores** → **Llamadas de voz**).

Una llamada automática nombra al niño y pide al padre que presione una tecla:

- **1 — ausente hoy.** Se registra como la ausencia de hoy, igual que **No asiste hoy**, por lo que aparece en el calendario y en la lista. La verificación se cierra.
- **2 — en camino, llegará tarde.** Se anota en la verificación. El niño sigue siendo esperado. Si aún no está registrado a la hora de escalamiento (contada desde la respuesta del padre), se alerta al personal de todos modos.
- **3 — ya lo dejé.** El niño **no** se registra solo por la palabra del padre. En su lugar, la sala, el director y los administradores reciben una alerta **urgente** — *«🚨 Safe Arrival: Maya fue entregada pero NO está registrada»* — para encontrar al niño y registrarlo. Si la alerta no llega al teléfono de nadie, también se envía por correo.

Sea cual sea la tecla que presione el padre, se informa a los educadores de la sala, a los directores del centro y a los administradores de la agencia, y queda en el registro de auditoría. Solo cuenta la primera respuesta; si otro tutor o el personal ya lo resolvió, quien llama oye que el niño ya está localizado.

Se llama a los tutores **uno a la vez, empezando por el principal**. Si una llamada termina sin que se presione ninguna tecla, se llama al siguiente tutor. Nunca se llama a los accesos de recogida, ni a quien haya rechazado las llamadas telefónicas.

## Texto de la llamada y opciones

En **⚙️ Ajustes**, abra **📞 Llamada telefónica — texto y opciones**:

- **Ofrecer 2 (en camino)** y **Ofrecer 3 (ya entregado)**: el 1 siempre se ofrece. Desmarque una para omitirla de la llamada.
- **A quién se llama** — **Cada tutor por turno, empezando por el principal**, o **Solo el tutor principal**.
- **Hacer la pregunta** — una vez, 2 veces o 3 veces si no se presiona ninguna tecla.
- **Esperar una tecla (segundos)** — de 5 a 30 (10 por defecto).
- **El texto** — la apertura, cada frase de «presione», y lo que oye el padre después de cada tecla, tras no presionar ninguna, tras una tecla incorrecta y cuando el niño ya está localizado. Deje un recuadro vacío para usar el texto estándar, que se muestra en gris. Use **{child}**, **{child_full}**, **{centre}**, **{room}** y **{time}**; se completan en cada llamada.

Las teclas siempre significan lo mismo (1 ausente, 2 en camino, 3 ya entregado), diga lo que diga el texto.

**▶ Reproducir llamada de prueba en mi teléfono** llama al número móvil de **su propio perfil** con el texto tal como está ahora en los recuadros, antes de guardar, usando «Test Child». Presione una tecla para oír la respuesta. No se registra nada en ningún niño. **Restablecer texto estándar** vacía los recuadros.

## El tablero

**Requiere atención** enumera primero a los niños atrasados: un padre que dice **ya entregado — NO registrado** va hasta arriba, luego aquellos cuyo personal ya fue alertado, con el nombre de cada tutor y un número de teléfono para llamar con un toque. Un niño cuyo padre presionó 2 muestra **En camino (padre)**. Cada tarjeta indica cuándo se llamó a los padres y qué respondieron. Cierre cada caso:

- **Registrar ausencia** — con un motivo. Se guarda como la ausencia de hoy, igual que el
  «No asiste hoy» de un padre, por lo que la lista y los recordatorios la ven.
- **Padre/madre contactado** — con una nota, p. ej. *«Hablé con la mamá a las 9:40, llega a las 10:15»*.
- **Otro…** — cualquier otro caso, con una nota.

**Todos los demás hoy** muestra quién ha llegado (y cuándo), quién está ausente y por qué, quién
está localizado y quién aún no debe llegar. El tablero se actualiza cada minuto.

Los educadores ven sus propias salas, los directores sus centros y los administradores de la agencia todos los centros
(con un filtro de centro).

## El registro

Cada verificación guarda cuándo se consultó a los padres, cuándo se les llamó y qué presionaron, cuándo se alertó al personal, y cómo se
cerró, quién lo hizo y con qué nota. El cierre de un caso y cada respuesta telefónica se escriben en el registro de auditoría.

## Preguntas

??? ¿Por qué no se verificó a un niño hoy? | No está programado hoy (revise sus días de inscripción), el centro está cerrado (feriado o cierre en el calendario), o ya se informó de una ausencia.
??? Un padre dice que no recibió el mensaje. | Los mensajes siguen las elecciones de cada padre en su perfil. Compruebe que tenga la app instalada con las notificaciones activadas, o una dirección de correo, o que haya aceptado los mensajes de texto.
??? No se llamó a un padre. | Compruebe que la llamada telefónica esté activada, que las llamadas de voz estén configuradas, que el padre tenga un número de teléfono registrado y no haya rechazado las llamadas, y si está seleccionado **Solo el tutor principal**.
??? La llamada de prueba no sonó. | Agregue su número móvil a su propio perfil. La prueba no puede llamar a un número que rechazó las llamadas.
??? ¿Pueden los educadores desactivarlo? | No. Solo un administrador de la agencia puede cambiar los ajustes o hacer una llamada de prueba.

Consulte también: [Mensajes de texto y llamadas telefónicas](texts-and-phone-calls).
