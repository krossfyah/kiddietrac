---
roles: agency_admin, platform_admin
title: Añadir una nueva agencia
category: Administration
order: 5
---
# Añadir una nueva agencia

Añadir una agencia cliente es una acción de **administrador de plataforma**. Se realiza en un breve **asistente de 4 pasos** que prepara de una vez todo lo que la agencia necesita: la agencia, un primer centro y el primer acceso de administrador.

## Por dónde empezar

1. En la parte inferior izquierda, haga clic en el **selector de agencia** (la píldora que muestra la agencia actual) y elija **Todas las agencias**, o use **Plataforma → Todas las agencias**.
2. Haga clic en **+ Crear agencia** (arriba a la derecha). Se abre el asistente.

## Los 4 pasos (todo es obligatorio)

1. **Plan y logotipo**
   - **Plan** — elija **Starter (49 $)**, **Growth (149 $)** o **Enterprise (349 $)**. Growth y Enterprise **incluyen marca blanca** (el administrador de la agencia configura su logotipo y colores en el primer inicio de sesión).
   - **Logotipo de la agencia** — suba un PNG/SVG (máx. 2 MB).
   - **Color principal**.
2. **Datos de la agencia** — nombre de la agencia, dirección (línea 1, ciudad, provincia, código postal), **país de residencia** (fija la moneda y el cumplimiento normativo de la agencia), **idioma predeterminado**, correo electrónico de contacto y teléfono de contacto.
3. **Administrador** — nombre, apellido, correo electrónico y teléfono del primer administrador de la agencia.
4. **Revisión** — compruebe todo y después **Crear agencia**.

## Qué ocurre al crearla

- La agencia se crea con una **prueba de 30 días** y un **centro predeterminado** (con el nombre de la agencia y usando su dirección; cámbielo de nombre o añada más en **Centros**).
- El administrador se crea y recibe por **correo electrónico una invitación** para establecer su contraseña. (La pantalla de confirmación también muestra el enlace de invitación por si el correo se retrasa.)
- Si el plan incluye marca blanca, la marca se activa automáticamente: el administrador termina su logotipo, colores y enlaces de privacidad en el primer inicio de sesión, en **Marca**.

## Si el administrador no recibió el correo de invitación

La entrega del correo depende del DNS de su dominio (SPF/DKIM), por lo que una invitación puede acabar en el spam o no llegar. En la pantalla **Todas las agencias**:

- Haga clic en **✉ Reenviar** en la fila de la agencia para reenviar la invitación: un cuadro de diálogo muestra el **enlace para establecer la contraseña**, que puede copiar y enviar directamente, de modo que la incorporación nunca dependa de la entrega del correo.
- Haga clic en **📧 Registro de correos** (en la parte superior de la pantalla) para ver **todos los correos que envió el sistema**: a quién, el asunto y cuándo. (Esto confirma el envío; no puede confirmar que la bandeja de entrada del destinatario lo aceptara.)

> Quién puede usarlo: **Administrador de plataforma**.
