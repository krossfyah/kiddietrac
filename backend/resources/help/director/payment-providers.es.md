---
title: Proveedores de pago (Helcim, Zum Rails, Stripe, Interac, tarjetas de regalo)
category: Billing
order: 12
roles: agency_admin, platform_admin
---
# Proveedores de pago (Helcim, Zum Rails, Stripe, Interac, tarjetas de regalo)

**Ajustes → Proveedores de pago** es donde conecta las cuentas de pago con las que cobra su agencia: **Helcim**, **Zum Rails** y **Stripe** para tarjetas y pagos bancarios, las instrucciones de **Interac e-Transfer** para las familias, y los servicios de **🎁 Tarjetas de regalo** que se usan para las recompensas de educadores.

[[open: #payment-providers | Abrir Proveedores de pago]]

Cada agencia maneja su propio dinero, así que cada agencia ingresa sus propias claves. Nada recurre a una cuenta de KiddieTrac ni a la de otra agencia. Mientras sus claves no estén ingresadas y el proveedor no esté activado, ese proveedor no puede cobrar ni devolver dinero por usted.

## Cómo leer la pantalla

Hay una pestaña por proveedor. El punto junto a cada nombre indica si está listo: verde significa que hay una credencial válida guardada, gris significa **Aún no configurado**. El mismo estado se repite abajo a la derecha de cada pestaña como **● Ready** o **○ Not configured yet**.

Cada pestaña de tarjetas o banco tiene los mismos dos controles arriba:

- **Activado**: si está desactivado, no se puede cobrar ni enviar ningún pago por ese proveedor, sin importar lo que esté guardado abajo.
- **Modo**: **Entorno de pruebas** o **Producción**. Quédese en pruebas hasta haber probado un pago real de principio a fin.

> ℹ️ Los secretos nunca se vuelven a mostrar una vez guardados. Una clave guardada aparece solo con sus últimos cuatro caracteres (por ejemplo ••••a1b2). Dejar vacío un campo secreto al guardar conserva la clave que ya está guardada, así puede cambiar una URL sin volver a ingresar todas las claves.

## Conectar Helcim (tarjetas de crédito y débito)

Helcim acepta tarjetas de crédito y débito, y maneja reembolsos y anulaciones. Las familias ingresan la tarjeta en la ventana de Helcim, así que un número de tarjeta nunca llega a KiddieTrac.

[[show: #payment-providers @ Helcim | Mostrarme la pestaña Helcim]]

1. En Helcim, vaya a **All Tools → Integrations → API Access** y cree un token de API con los permisos de **Payment API** activados.
2. En KiddieTrac, abra la pestaña **🍁 Helcim**.
3. Pegue el token en **Token de API**.
4. Si quiere, complete **Terminal ID (optional)**.
5. Ingrese la **Currency (CAD or USD)**.
6. Marque **Activado** y ajuste **Modo** según el tipo de token que pegó.
7. Pulse **Save Helcim**. Verá **✓ Saved** y el punto se pone verde.
8. Pulse **Probar conexión**.

La prueba le pregunta a Helcim si acepta el token guardado. Verá **✓ Helcim accepted this token** seguido de «LIVE, real cards will be charged» o «labelled as sandbox», y la moneda. Si el token es incorrecto o no tiene permisos, el motivo aparece en rojo.

> ⚠️ Helcim no tiene una dirección de pruebas aparte. Un token de prueba y uno real llegan al mismo servicio de Helcim, y **el token decide si se mueve dinero real**. El ajuste **Modo** solo etiqueta lo que ven el personal y las familias.

> 💡 Pulse siempre **Probar conexión** después de guardar. Un token incorrecto se guarda igual y muestra igual un punto verde; la prueba es la única forma de saber que funciona antes de que una familia intente pagar.

Un pago de Helcim se registra solo después de que KiddieTrac verifica el resultado de Helcim, por el monto que Helcim reporta como liquidado. Los reembolsos vuelven a la tarjeta automáticamente: un reembolso total el mismo día se intenta primero como **anulación** (desaparece del estado de cuenta); si no, se reembolsa por Helcim. Solo un reembolso puede ser parcial.

## Conectar Stripe (tarjetas, tarjetas guardadas, Apple Pay y Google Pay)

1. En su panel de Stripe, abra **Developers → API keys**.
2. En KiddieTrac, abra la pestaña **💳 Stripe**.
3. Pegue la **Publishable key** y la **Secret key**. Deje vacío **Webhook signing secret**: KiddieTrac lo completa al conectarse.
4. Marque **Activado**, elija el **Modo** y pulse **Save Stripe**.
5. En **Payment updates from Stripe**, confirme que la línea diga **● Connected**. Si dice **○ Not connected yet**, pulse **Conectar** (o **Volver a conectar**).
6. Para ofrecer Apple Pay y Google Pay en las facturas, pulse **Activar Apple Pay y Google Pay**. Verá **✓ Apple Pay and Google Pay are on for** seguido de las direcciones de su portal.

«Connected» significa que un pago que un padre termina después de cerrar la app se registra igual. Mientras no esté conectado, un pago se registra solo si el padre espera en la pantalla.

## Conectar Zum Rails (pagos bancarios y tarjetas)

1. En su portal de Zum, abra **Settings → Webhook & API**.
2. En KiddieTrac, abra la pestaña **🏦 Zum Rails**.
3. Complete **URL base de la API**, **ID de billetera Zum**, **Usuario de la API**, **Contraseña de la API**, **Secreto del webhook** y **Ruta del endpoint de reembolso**.
4. Marque **Activado**, elija el **Modo** y pulse **Guardar Zum Rails**.
5. Copie la **URL de retorno para su portal** que aparece debajo de los campos y péguela en Zum, con el mismo secreto de webhook.

> ⚠️ Los pagos de Zum se confirman solo cuando Zum llama a esa dirección, así que una URL incorrecta falla sin aviso al liquidarse el pago.

## Ofrecer Interac e-Transfer

No hay nada que conectar: las familias envían dinero desde su propio banco a su correo de e-Transfer, y usted registra el pago cuando llega.

1. Abra la pestaña **Interac e-Transfer**.
2. Marque **Accept Interac e-Transfer**. Las familias tendrán un botón «Pagar con Interac e-Transfer» en Facturación, y las facturas lo indican en la forma de pago.
3. Ingrese el **e-Transfer email (where families send money)** y, si quiere, el **Recipient name (as their bank will show it)**.
4. En **Autodeposit**, indique si el depósito automático está activado. Si no, complete los campos **Security question** y **Contestar**.
5. Si quiere, escriba **Your instructions for families (optional)** y marque **Families can ask for an e-Transfer request**.
6. Pulse **👁 Preview what families see** para revisar la hoja que recibirá una familia.
7. Pulse **Save Interac e-Transfer**.

## Conectar un servicio de tarjetas de regalo

La pestaña **🎁 Tarjetas de regalo** guarda las cuentas propias de su agencia en **Giftbit** y **Tango Card**, que se usan para comprar tarjetas de regalo como recompensa para educadores. Las tarjetas se pagan desde su propia cuenta en ese servicio.

1. Abra la pestaña **🎁 Tarjetas de regalo**.
2. Para Giftbit: elija el **Entorno**, pegue el **Token de API** y marque **Use Giftbit for educator rewards**. Para Tango Card: complete **Nombre de la plataforma**, **Clave de API**, **Identificador de cliente** e **Identificador de cuenta**, y marque **Use Tango Card for educator rewards**.
3. Pulse **Guardar**.
4. Pulse **Probar conexión** para confirmar que la cuenta responde.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Todo en esta pantalla para su propia agencia. |
| Administrador de plataforma | Lo mismo, para la agencia que está viendo. |
| Director de centro | No ve esta pantalla: estas claves mueven el dinero de la agencia y corresponden a quien es dueño de la cuenta bancaria. |

## Preguntas frecuentes y cuando algo sale mal

??? Faltan las pestañas de tarjetas y banco; solo aparecen Interac y tarjetas de regalo. | Los pagos con tarjeta son parte de la función de pagos de su plan. Sin ella, una nota gris explica por qué (por ejemplo «Payment accounts are not available.») y solo funcionan las pestañas de Interac e-Transfer y tarjetas de regalo. Consulte a su contacto de KiddieTrac para agregar pagos.
??? El punto está verde pero las familias no pueden pagar. | El verde solo significa que hay un valor guardado. Confirme que Activado esté marcado, pulse Probar conexión (Helcim), confirme que Payment updates from Stripe diga Connected (Stripe), o revise la URL de retorno en su portal de Zum.
??? Veo «Could not save» en rojo. | El mensaje que sigue indica el motivo (por ejemplo, un valor faltante). Corríjalo y vuelva a guardar. No se cambió nada.
??? «Add the e-Transfer email families send money to.» | Marcó Accept Interac e-Transfer sin una dirección de correo. Agregue la dirección y guarde.
??? «Add the security question and its answer, or choose Autodeposit.» | Eligió «No Autodeposit». Las familias necesitan una pregunta y una respuesta para aceptar la transferencia, así que complete ambas.
??? Apple Pay muestra una advertencia después de pulsar el botón. | La advertencia indica la dirección y lo que respondió Stripe. Confirme que Stripe esté en el modo correcto y vuelva a pulsar el botón; Google Pay no necesita nada más.
??? ¿Se avisa a las familias cuando cambio de proveedor? | No. Desde esta pantalla no se envía nada a las familias. Simplemente ven las opciones de pago que estén activadas.

## Pantallas relacionadas

[[open: #payment-run | Ejecución de cobros]] [[open: #refunds | Reembolsos]] [[open: #billing-settings | Ajustes de facturación]] [[open: #educator-rewards | Recompensas para educadores]]

Vea también: [Recargos por pago](payment-surcharges) · [Reembolsar un pago](refunds) · [Configuración de facturación](billing-setup) · [Ejecución de cobros](autopay)
