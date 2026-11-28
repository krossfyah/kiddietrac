---
title: Métodos de inicio de sesión (Google, Microsoft y Facebook)
---

# Métodos de inicio de sesión (Google, Microsoft y Facebook)

**🔐 Sign-in methods** permite que el personal y las familias inicien sesión con una cuenta de Google, Microsoft o Facebook que ya tienen, en lugar de escribir una contraseña de KiddieTrac. Un administrador de la plataforma configura cada proveedor una sola vez, para toda la plataforma, desde **Platform › 🔏 Sign-in methods**. El botón de un proveedor queda oculto en la página de inicio de sesión hasta que sus datos se guarden aquí.

[[open: #social-settings | Abrir métodos de inicio de sesión]]

## Cómo funciona para quien inicia sesión

- El botón **Google**, **Microsoft** o **Facebook** aparece en la página de inicio de sesión solo para los proveedores que aquí muestran **● Enabled**.
- El inicio de sesión social solo **vincula una cuenta invitada que ya existe**, por su correo verificado. Nunca crea una cuenta nueva. Si el correo de Google o Microsoft no coincide con una persona invitada, se rechaza.
- El correo y la contraseña siempre siguen funcionando, así que nadie está obligado a usar una cuenta social.

## Configurar un proveedor

Cada proveedor tiene su propia tarjeta. Haga esto una vez por proveedor.

1. Abra **Platform › 🔏 Sign-in methods**.
2. En el recuadro azul **Redirect / callback URL**, presione **Copy**. El botón muestra **Copied ✓**. Al pegar la dirección, cambie `{provider}` por `google`, `microsoft` o `facebook`.
3. En la tarjeta del proveedor, abra **How to create the Google app** (o la de Microsoft o Facebook) y siga sus pasos en la consola de desarrolladores del proveedor. Pegue la dirección de redirección donde lo indiquen los pasos.
4. Copie el ID y el secreto de la aplicación en la consola de desarrolladores.
5. Péguelos en **Client ID** y **Client Secret** en la tarjeta. Para Microsoft, deje **Tenant** como `common`, a menos que quiera permitir solo una organización.
6. Presione **Save Google** (o **Save Microsoft**, **Save Facebook**). Aparece un aviso **Saved** y la tarjeta muestra **● Enabled**.
7. Abra la página de inicio de sesión en una ventana privada y compruebe que el botón nuevo aparece.

[[show: #social-settings @ How to create the Google app | Muéstreme los pasos]]

> ℹ️ Para Microsoft, copie el **Value** del secreto, no su **Secret ID**. Para Facebook, la aplicación debe pasar la **App Review** de Meta y estar en **Live** antes de que la usen personas que no sean sus probadores.

## Cambiar o desactivar un proveedor

1. Para cambiar un secreto, escriba el nuevo en **Client Secret** y presione **Save …**. Deje el campo vacío para conservar el secreto guardado: muestra **•••••••• (saved — leave blank to keep)**.
2. Para desactivar un proveedor, presione **Disable** en su tarjeta.
3. Confirme **Disable google sign-in? Its credentials will be cleared.** El botón desaparece de la página de inicio de sesión y la tarjeta muestra **Not set up**.

> ⚠️ Desactivar no deja a nadie fuera: quienes usaban ese botón inician sesión con su correo y contraseña (o la restablecen con **Olvidé mi contraseña**).

## Notas de seguridad

- Los secretos son de **solo escritura**: una vez guardados, nunca se vuelven a mostrar, ni siquiera a usted.
- En el servidor solo se cambian estas claves de inicio de sesión; ningún otro ajuste se toca.

## Quién puede hacer qué

- **Administradores de la plataforma**: configuran, cambian y desactivan cada proveedor.
- **Administradores de agencia y todos los demás**: no pueden cambiar estos ajustes; un administrador de agencia que abre la pantalla ve **This page is available to platform administrators only.** Simplemente ven los botones en la página de inicio de sesión cuando un proveedor está activado.

## Preguntas frecuentes y problemas

??? El botón de Google no aparece en la página de inicio de sesión. | La tarjeta debe mostrar **● Enabled**. Actualice la página de inicio de sesión; una página en caché puede mostrar los botones anteriores por un momento.
??? Rechazan a alguien después de elegir Google. | Su correo de Google no coincide con el de su cuenta de KiddieTrac. Invítelo o actualice su cuenta con esa dirección, o pídale que inicie sesión con correo y contraseña.
??? El proveedor dice que la dirección de redirección no coincide. | La dirección registrada en la consola de desarrolladores debe coincidir exactamente, con `google`, `microsoft` o `facebook` en lugar de `{provider}`.
??? Apareció «Save failed». | Se rechazó el ID o el secreto, o se cortó la conexión. Revise que pegó el valor completo e intente de nuevo.
??? ¿Puede una agencia activar su propio inicio de sesión con Google? | No. Los métodos de inicio de sesión los configura para toda la plataforma un administrador de la plataforma.

## Pantallas relacionadas

[[open: #security-alerts | Alertas de seguridad]] · [[open: #audit-logs | Registro de auditoría]]

Vea también: [[security-alerts]] · [[passkeys]] · [[signing-in-and-unlocking]]
