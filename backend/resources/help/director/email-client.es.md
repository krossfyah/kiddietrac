---
title: Cliente de correo — leer y enviar correo en KiddieTrac
---
# Cliente de correo — leer y enviar correo en KiddieTrac

**Operaciones → Email Client** le permite leer y responder su propio buzón (Microsoft 365, Outlook.com, Gmail u otro proveedor) sin salir del portal. Se parece a Outlook: buzones y carpetas a la izquierda, la lista de mensajes en el centro y el correo abierto a la derecha. En un teléfono verá un panel a la vez, con **Back** para volver a la lista.

[[open: #email | Abrir el cliente de correo]]

## Conectar su buzón

1. Pulse **＋ Add account** al pie del panel izquierdo. Se abre la ventana **✉️ Add an email account**.
2. Escriba su dirección de correo y pulse **Next →**. KiddieTrac averigua quién gestiona el buzón. También puede elegir su proveedor en los recuadros bajo **or choose your provider**.
3. Para Microsoft 365, Outlook o Hotmail, pulse **Iniciar sesión con Microsoft**. Para Gmail o Google Workspace, pulse **Iniciar sesión con Google**. Inicia sesión en la página del propio proveedor: KiddieTrac nunca ve su contraseña.
4. Acepte los permisos que pide el proveedor. Vuelve al cliente de correo y el buzón aparece a la izquierda con sus carpetas y los mensajes sin leer.

> 💡 Para un buzón compartido (por ejemplo oficina@), inicie sesión como **usted mismo** si el buzón no tiene contraseña propia; inicie sesión como el buzón solo si tiene su propio acceso.

> ℹ️ Otros proveedores (Yahoo, servidores IMAP) se conectan con **Other mail provider**, pero aquí son de solo lectura: se responden desde su propia aplicación. El envío funciona desde buzones conectados con Microsoft o Google.

## Leer y ordenar su correo

1. Haga clic en una carpeta (por ejemplo **Inbox**) en el panel izquierdo.
2. Use **Buscar en esta carpeta** para encontrar un mensaje, y **Todos** / **No leído** para acotar la lista. **Cargar mensajes más antiguos**, al final, trae más.
3. Haga clic en un mensaje para abrirlo en el panel de lectura.
4. Use la barra de herramientas encima: **Marcar como no leído**, **Marca**, **Mover**, **Archivar** (o **No es correo no deseado** en la carpeta Junk) y **Eliminar**. En Elementos eliminados el botón pasa a ser **Delete permanently**.
5. Para actuar sobre varios mensajes, márquelos (o **Select all**) y use la barra que aparece sobre la lista.

## Escribir, responder y reenviar

1. Pulse **✏️ New mail**, o abra un mensaje y pulse **Responder**, **Responder a todos** o **Reenviar**.
2. Revise **From** si tiene más de un buzón. Una respuesta sale del buzón al que llegó el mensaje.
3. Agregue destinatarios en **To**. Pulse **Cc** o **Cco** para mostrar esas líneas.
4. Escriba su mensaje. Su firma ya está puesta y todavía puede cambiarla.
5. Pulse **📎 Attach** para adjuntar archivos, o arrástrelos sobre el mensaje (hasta 10 archivos y 25 MB por correo).
6. Pulse **👁 Preview** para ver exactamente lo que se enviará y luego **Enviar** (o **Ctrl+Enter**).

> ⚠️ Aparece **Discard this message?** si cierra un mensaje empezado. Elija **Discard** solo si ya no lo quiere.

## Firma, fuera de la oficina y alertas

1. Pulse **⚙️ Manage accounts** al pie del panel izquierdo.
2. Pulse **Settings** junto al buzón.
3. Pegue o escriba su **Firma**. Pegar desde Outlook o Word conserva el formato y el logotipo.
4. Active **Respuestas automáticas**, elija quién recibe respuesta y, si quiere, la hora de inicio y fin.
5. Marque **Alert me when new mail arrives in this inbox (bell, phone and sound)** para recibir una notificación en la campana con un timbre suave, y elija el **Sound for this inbox**.
6. Pulse **Guardar ajustes**.

## Archivar un correo en el expediente de una familia o de un empleado

Cuando su agencia activa **Link emails to people** (**Ajustes → Email settings → Mailbox**), el buzón de un administrador de agencia muestra **🔗 Link to person** en cada mensaje abierto.

1. Abra el correo y pulse **🔗 Link to person**.
2. Busque al padre, empleado o contacto por nombre o correo y selecciónelo.
3. El correo aparece ahora en **✉️ Emails** dentro del expediente de esa persona. Cada vínculo y desvínculo queda en el registro de auditoría.

[[show: #email @ ＋ Add account | Muéstreme Add account]]

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de agencia | Conectar sus buzones (los de la agencia), enviar y vincular correos a personas cuando está activado |
| Administrador de plataforma | Lo mismo, para las agencias que gestiona |
| Director de centro | Conectar **solo su propio** buzón. Es personal: nadie más lo ve y sus correos no se archivan en expedientes |

Cada buzón pertenece a quien lo conectó. Nadie más de la agencia puede leerlo.

## Preguntas frecuentes y problemas

??? Un aviso rojo dice que mi buzón debe volver a iniciar sesión. | Microsoft o Google dejó de confiar en el acceso anterior (cambio de contraseña o un administrador retiró el acceso). Pulse **🔌 Reconnect** e inicie sesión otra vez. Su correo y sus ajustes se conservan.
??? Puedo leer, pero Responder está gris o el envío falla. | El buzón es de solo lectura (otro proveedor) o se conectó antes de que existiera el envío. Pulse **🔌 Reconnect** (o **Iniciar sesión con Microsoft** una vez más) para permitir el envío, o responda desde la aplicación del buzón.
??? No recibo alertas de correo nuevo. | Las alertas funcionan en buzones conectados con Microsoft y solo si la casilla de alerta está marcada en los **Settings** de ese buzón. Revise también el sonido en **Mis ajustes**.
??? No veo 🔗 Link to person. | Solo aparece en el buzón de un administrador de agencia, cuando **Link emails to people** está activado en **Ajustes → Email settings → Mailbox**. El buzón de un director es personal y nunca se vincula.
??? La lista está vacía. | Aún no hay buzón conectado o la carpeta está vacía. Pulse **＋ Add account** o elija otra carpeta.
??? ¿Quién ve mis correos? | Solo usted. Los correos vinculados los ven en el expediente de la persona los administradores de agencia (y quien su agencia permita); el resto de su buzón sigue siendo privado.

## Pantallas relacionadas

[[open: #email-settings | Ajustes de correo]] [[open: #settings | Mis ajustes]]

Vea también: [Ajustes de correo](email-settings), [Correos por mensajes no leídos](missed-chat-emails), [Centro de mensajes](message-centre-rules).
