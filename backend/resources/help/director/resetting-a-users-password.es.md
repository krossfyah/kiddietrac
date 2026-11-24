---
title: Restablecer la contraseña de un usuario
category: Troubleshooting
order: 2
roles: agency_admin, centre_director
---
# Restablecer la contraseña de un usuario

Puede hacerlo usted mismo. El soporte no interviene.

## Primero, déjelos hacerlo a ellos

**¿Olvidó?** en la página de inicio de sesión envía un **código de 6 dígitos**. La persona escribe su correo, su nombre de usuario o su número móvil, y elige recibir el código por **correo**, **mensaje de texto** o **llamada telefónica**. El
código sirve durante 10 minutos y permite 5 intentos; el código correcto abre la página donde se define
una nueva contraseña. El correo también incluye un enlace de restablecimiento, que dura 60 minutos. Esa es la
vía correcta para casi todos y no requiere nada de usted. Remítalos a
[¿Olvidó su contraseña?](forgot-password).

Un código por mensaje de texto o llamada va al número móvil de su cuenta, desde la línea de mensajes propia de su agencia. Solo llega si su agencia envía mensajes y llamadas. Como lo acaban de pedir, se envía aunque hayan desactivado otros mensajes o llamadas.

**Si varias cuentas comparten una misma dirección de correo o número de teléfono**, el formulario también admite un
**nombre de usuario** opcional. Con él, el restablecimiento va exactamente a esa cuenta. Sin él, igualmente se envía un
código y, tras ingresarlo, la persona elige qué cuenta restablecer, porque
quien se quedó sin su contraseña muy a menudo tampoco sabe su nombre de usuario, y
exigirlo reconstruye el muro que esto está para eliminar.

No se revela nada en ningún caso. Una dirección o un número que no existe, un nombre de usuario
que no coincide con nada y un nombre de usuario que pertenece a la dirección de otra persona reciben la
misma respuesta.

## Restablecerla por ellos

**Administración → Gestión de usuarios**, búsquelos y pulse **🔑 Restablecer contraseña**. Lo que ocurre
depende de la cuenta:

**Una cuenta activa que ya ha iniciado sesión recibe un enlace de restablecimiento.** Su contraseña actual
sigue funcionando hasta que elija una nueva, así que pulsar este botón en una cuenta que funciona
no puede dejar fuera a nadie. Esto importa más de lo que parece: el comportamiento anterior generaba una nueva contraseña
temporal cada vez, lo que significaba que pulsar Restablecer con alguien que acababa de elegir su propia contraseña
la reemplazaba en silencio y, desde su lado, el restablecimiento que había completado
diez minutos antes simplemente había dejado de funcionar.

**Una cuenta que nunca ha iniciado sesión recibe una contraseña temporal**, porque no hay ninguna
contraseña que conservar y a menudo tampoco una bandeja de entrada confirmada. La pantalla la muestra una sola vez, con
un botón **Copiar detalles** que incluye la dirección del portal, con quién iniciar sesión y la
contraseña.

## Una contraseña enviada por correo es una llave, no una credencial

Una contraseña que ha viajado por una bandeja de entrada en texto sin cifrar es una **llave de un solo uso**.
KiddieTrac la trata como tal: la cuenta no puede hacer más que iniciar sesión y elegir una contraseña
real. Cualquier otra pantalla se rechaza hasta que lo hagan.

Así, «dígales que la cambien de inmediato» ya no es un consejo que deba dar: se
exige. Entregue la contraseña temporal y se les pedirá reemplazarla en el momento en que
lleguen.

## Cuando alguien dice «no puedo iniciar sesión»

Repase esta lista antes de restablecer nada:

1. ¿Están en **https://app.kiddietrac.com**?
2. ¿Usan la dirección a la que llegó la invitación?
3. **¿Esa dirección tiene más de una cuenta?** Compruébelo en Gestión de usuarios. Si es así,
   deben indicar el **nombre de usuario** en el formulario de inicio de sesión; el formulario lo pide cuando
   lo necesita.
4. ¿La cuenta fue dada de baja o desactivada? Una cuenta dada de baja se rechaza en la puerta, y un restablecimiento solo produciría una contraseña que nadie puede usar. Revise
   la pestaña **Dados de baja / desactivados**.
5. ¿Han hecho una recarga forzada (Ctrl+Mayús+R)?
6. ¿Ven **«No pudimos conectar con KiddieTrac»** o **«Está sin conexión»**? Entonces el inicio de sesión
   nunca llegó a KiddieTrac y un restablecimiento no ayudará. El inicio de sesión ya reintenta por sí solo dos veces una
   conexión caída. Pídales que revisen el Wi-Fi o los datos móviles y que desactiven cualquier VPN o ahorro de datos.

## Si el correo nunca llega

Cada intento queda registrado, tenga éxito o no, incluido un rechazo y su motivo.

- **Administración → Registro de auditoría** muestra el intento de restablecimiento.
- **Ajustes → Ajustes de correo** tiene el interruptor de notificaciones de toda la agencia. Si está desactivado,
  no se envía nada.
- Revise el registro de correos por si hay un mensaje suprimido o rebotado antes de dar por hecho que se envió.
- Su carpeta de spam, y si su proveedor bloquea `kiddietrac.com`.

Si el correo no puede llegar, pueden pedir el código por **mensaje de texto** o **llamada**.
Si no llega nada de ninguna forma, restablezca con una contraseña temporal y léasela
en voz alta: la barrera de inicio de sesión descrita arriba hace que sea seguro entregarla.

Véase también: [¿Olvidó su contraseña?](forgot-password),
[Invitar a padres y personal](inviting-parents-and-staff),
[Autenticación de dos factores](mfa-two-factor), [Registro de auditoría](audit-log).
