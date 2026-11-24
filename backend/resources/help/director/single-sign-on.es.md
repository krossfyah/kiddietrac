---
title: Inicio de sesión único (Google y Microsoft)
category: Settings
order: 40
roles: agency_admin, platform_admin
---

# Inicio de sesión único (Google y Microsoft)

Permita que su personal y las familias inicien sesión con su cuenta de Google o Microsoft existente en lugar de una contraseña aparte. *Lo configura un administrador de la plataforma en **Ajustes → Métodos de inicio de sesión**.*

## Cómo funciona

- Los botones de inicio de sesión aparecen en la página de acceso **solo** para los proveedores que haya configurado.
- El inicio de sesión social **se vincula a una cuenta ya invitada** mediante el correo verificado: nunca crea cuentas nuevas. Si alguien inicia sesión con una dirección de Google/Microsoft que no coincide con un usuario invitado, se le rechaza amablemente.
- Nada cambia para quienes prefieren correo y contraseña; esa opción siempre permanece.

## Activarlo

1. Vaya a **Ajustes → Métodos de inicio de sesión** (solo administradores de la plataforma).
2. Para cada proveedor, copie la **URL de redirección** que aparece en la página en la consola de desarrollador del proveedor (Google Cloud Console o Microsoft Entra) y luego pegue el **ID de cliente** y el **Secreto del cliente** en KiddieTrac.
3. Guarde. El botón del proveedor aparece de inmediato en la página de inicio de sesión.

Para desactivar un proveedor, borre sus credenciales con el botón **Desactivar**.

## Notas de seguridad

- Los secretos son **de solo escritura**: una vez guardados, nunca se vuelven a mostrar.
- Solo se escriben las claves de OAuth en la configuración del servidor; no se toca ningún otro ajuste.
