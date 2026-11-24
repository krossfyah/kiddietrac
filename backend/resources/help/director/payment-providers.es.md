---
title: Proveedores de pago (Helcim, Zum Rails, Stripe)
category: Billing
order: 12
roles: agency_admin, platform_admin
---
# Proveedores de pago

**Ajustes → Proveedores de pago.** Aquí conecta las cuentas de pago por las que cobra su agencia: **Zum Rails**, **Stripe** y **Helcim**.

[[open: #payment-providers | Abrir Proveedores de pago]]

Cada agencia cobra su propio dinero, por lo que cada agencia introduce sus propias claves. Nada recurre a una cuenta de KiddieTrac ni a la de otra agencia. Hasta que sus claves estén introducidas y el proveedor esté activado, ese proveedor no puede cobrar ni devolver dinero por usted.

## Cómo leer la pantalla

Hay una pestaña por proveedor. El punto junto a cada nombre indica si está listo: verde significa que hay una credencial válida guardada, y gris significa **Aún sin configurar**. El mismo estado se repite al pie de cada pestaña como **● Listo** o **○ Aún sin configurar**.

Todas las pestañas tienen los mismos dos controles en la parte superior:

- **Activado**: cuando está desactivado, no se puede cobrar ni enviar ningún pago a través del proveedor, sea lo que sea que haya guardado más abajo.
- **Modo**: **Entorno de pruebas** o **Producción**. Permanezca en el entorno de pruebas hasta que haya probado un pago real de principio a fin.

**Los secretos nunca se vuelven a mostrar una vez guardados.** Una clave guardada aparece solo con sus últimos cuatro caracteres, por ejemplo **••••a1b2**. Si deja vacío un campo de secreto al guardar, se conserva la clave ya guardada, así que puede cambiar una URL sin volver a introducir todas las claves.

## Helcim (tarjetas de crédito y débito)

[[show: #payment-providers @ Helcim | Mostrarme la pestaña Helcim]]

Helcim acepta **tarjetas de crédito y débito**, y gestiona **reembolsos y anulaciones**. Las familias introducen la tarjeta en la ventana propia de Helcim, de modo que el número de tarjeta nunca llega a KiddieTrac.

- [ ] En Helcim, vaya a **All Tools → Integrations → API Access** y cree un token de API con los permisos de **Payment API** activados.
- [ ] Péguelo en **Token de API**.
- [ ] Si lo desea, rellene **ID del terminal (opcional)**.
- [ ] Introduzca la **Moneda (CAD o USD)**.
- [ ] Marque **Activado**, defina el **Modo** y haga clic en **Guardar Helcim**.
- [ ] Haga clic en **Probar conexión**.

**Probar conexión** pregunta a Helcim si acepta el token guardado. Verá **✓ Helcim aceptó este token** seguido de **EN VIVO, se cobrará a tarjetas reales** o de **etiquetado como entorno de pruebas**, y la moneda. Si el token es incorrecto o carece de permisos, el motivo se muestra en rojo.

[[show: #payment-providers @ Helcim > Test connection | Mostrarme Probar conexión]]

> **Importante:** Helcim no tiene una dirección de pruebas independiente. Un token de prueba y un token real llegan al mismo servicio de Helcim, y **el token decide si se mueve dinero real**. El ajuste Modo solo etiqueta lo que ven el personal y las familias, así que póngalo de acuerdo con el tipo de token que pegó.

> **Consejo:** Use siempre **Probar conexión** después de guardar. Un token incorrecto, o sin los permisos necesarios, se guarda igualmente y sigue mostrando un punto verde. La prueba es la única forma de confirmar que funciona antes de que una familia intente pagar.

### Cómo se registra un pago de Helcim

Un pago se registra **solo cuando KiddieTrac ha verificado el resultado de Helcim**. El importe registrado es el que Helcim informa como liquidado, no el que se solicitó. El saldo y el estado de la factura siguen entonces lo que realmente se ha pagado. Una factura ya anulada o reembolsada se deja como está para que una persona la revise, aunque llegue un pago contra ella.

### Reembolsar un pago de Helcim

Reembólselo desde **Reembolsos** o desde el libro mayor de la cuenta de la familia, igual que cualquier otro pago. El dinero vuelve a la tarjeta automáticamente:

- **El mismo día, importe completo**: KiddieTrac intenta primero **anular** el cargo, de modo que desaparezca del extracto del titular en lugar de aparecer como un cargo y un abono.
- **En otro caso**, o si se rechaza la anulación: se **reembolsa** a través de Helcim. Solo un reembolso puede ser parcial.

Si Helcim ya no está configurado para su agencia, el reembolso se rechaza con un mensaje que explica el motivo, y no se registra ningún reembolso. Véase [Reembolsar un pago](refunds).

## Zum Rails (pagos bancarios y tarjetas)

Zum Rails gestiona los **pagos bancarios (EFT) y los pagos con tarjeta** de las familias, y los reembolsos. Sus credenciales provienen de su portal de Zum, en **Settings → Webhook & API**. La pestaña solicita la **URL base de la API**, el **ID de billetera Zum**, el **Usuario de la API**, la **Contraseña de la API**, el **Secreto del webhook** y la **Ruta del endpoint de reembolso**.

Debajo de los campos, la pestaña muestra una **URL de retorno para su portal**. Péguela en Zum junto con el mismo secreto del webhook que introdujo aquí. Los pagos solo se confirman cuando Zum llama a esa dirección, así que una URL incorrecta falla en silencio cuando el pago se liquida.

## Stripe

Stripe acepta **pagos con tarjeta y métodos de pago guardados**. Sus claves provienen de su panel de Stripe, en **Developers → API keys**: la **Clave publicable**, la **Clave secreta** y el **Secreto de firma del webhook**.

## Quién puede usarlo

Solo los **administradores de la agencia** y los **administradores de la plataforma**. Los directores de centro no pueden abrir esta pantalla, porque estas claves mueven el dinero de la agencia y corresponden a quien es titular de la cuenta bancaria.

Véase también: [Recargos por pago](payment-surcharges) · [Reembolsar un pago](refunds) · [Configuración y ajustes de facturación](billing-setup)
