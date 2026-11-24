---
title: Inicio de sesión de dos factores (MFA)
category: Settings
order: 10
---
# Inicio de sesión de dos factores (MFA)

La autenticación de dos factores (MFA) añade una segunda prueba de identidad al iniciar sesión: además de su contraseña, introduce un código de 6 dígitos de una aplicación de autenticación en su teléfono. Aunque se filtre su contraseña, un atacante no puede entrar sin su teléfono.

Muy recomendada para todos los administradores de la agencia y directores de centro.

## Qué necesita

Cualquier aplicación de autenticación TOTP: Google Authenticator, Authy, 1Password, Bitwarden, Microsoft Authenticator y la mayoría de los administradores de contraseñas funcionan. Elija la que ya use.

## Configurarla

1. Abra la entrada **Ajustes → Doble factor (MFA)** de la barra lateral.
2. Haga clic en **Configurar MFA**. Kiddietrac le muestra una cadena secreta y una lista de 10 códigos de recuperación.
3. En su aplicación de autenticación, elija «Añadir cuenta» → «Introducir clave de configuración» y pegue el secreto. Guarde.
4. Guarde los 10 códigos de recuperación en un lugar seguro, como un administrador de contraseñas. No volverá a verlos.
5. De vuelta en Kiddietrac, escriba el código actual de 6 dígitos de su aplicación de autenticación y haga clic en **Confirmar y activar**.

Verá «MFA activada»: listo. La próxima vez que inicie sesión, después de su contraseña, se le pedirá un código.

## Iniciar sesión con la MFA activada

Tras escribir su contraseña, Kiddietrac muestra un campo nuevo: **Código de autenticación**. Escriba el código de 6 dígitos que muestra su aplicación en ese momento y envíelo.

Si no puede acceder a su teléfono, escriba en su lugar uno de sus códigos de recuperación de 10 caracteres. Cada código de recuperación funciona una sola vez: una vez usado, se consume.

## Desactivar la MFA

Abra Ajustes → Doble factor → introduzca su código actual (o un código de recuperación) → confirme. Vuelve al inicio de sesión solo con contraseña. Si la reactiva más adelante, empieza desde cero.

## Si pierde el acceso a su teléfono

Use un código de recuperación al iniciar sesión. Una vez dentro, vaya a Ajustes → Doble factor → Desactivar y vuelva a configurarla con el teléfono nuevo.

Si también perdió sus códigos de recuperación, póngase en contacto con el administrador de su plataforma: puede borrar la MFA de su cuenta desde su pantalla de gestión de usuarios.
