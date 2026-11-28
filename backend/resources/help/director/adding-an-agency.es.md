---
title: Todas las agencias: añadir y administrar agencias
---
# Todas las agencias: añadir y administrar agencias

**Todas las agencias** es la lista, para el equipo de la plataforma, de cada agencia cliente en KiddieTrac, con su estado, plan y tamaño. Desde aquí añade una agencia nueva con un asistente de cuatro pasos, abre cualquier agencia para trabajar dentro de ella, edita sus datos, reenvía la invitación de su administrador, la suspende o reactiva, y bloquea el inicio de sesión de toda la agencia.

[[open: #platform-agencies | Abrir Todas las agencias]]

## Leer la lista

1. Abra **Platform › Todas las agencias**.
2. Lea la tabla: **Name**, **Status** (por ejemplo prueba, activa o suspendida), **Plan** (con su monto mensual), **Centres**, **Families**, **Children**, **Created** y **Actions**.
3. Pulse **Open** en una fila para pasar a esa agencia. Llega a su pantalla de inicio y su menú refleja el plan y los complementos de esa agencia.

## Añadir una agencia nueva

1. Pulse **+ Crear agencia** (arriba a la derecha). El asistente **🌐 Create new agency** se abre en «Step 1 of 4».
2. **Plan & logo**: elija el **Plan** (**Starter — $49/mo**, **Growth — $149/mo (white-label included)** o **Enterprise — $349/mo (white-label included)**). Si quiere, pulse **Subir logotipo** (hasta 2 MB) y ajuste el **Primary colour**. Pulse **Next →**.
3. **Agency details**: complete **Agency name**, **Address line 1**, **Address line 2** (opcional), **City**, **Province**, **Postal code**, **Country (residence — sets currency & compliance)**, **Default language**, **Contact email** y **Contact phone**. Pulse **Next →**.
4. **Admin**: complete **First name**, **Last name**, **Email** y **Phone** del primer administrador de la agencia. Pulse **Next →**.
5. **Revisar**: compruebe todo (use **← Back** para corregir un paso) y pulse **✓ Create agency**.
6. Lea la confirmación: «… is ready. Provisioned on a 30-day trial with a default centre.» También muestra el enlace de invitación del administrador por si el correo se retrasa. Pulse **Hecho**.

> ℹ️ El centro predeterminado lleva el nombre de la agencia y usa su dirección. El administrador de la agencia lo renombra o añade más centros en **Centres**.

## Editar una agencia

1. Pulse **✏️** (**Editar agencia**) en la fila.
2. Use las pestañas: **Detalles** (nombre, contacto, propietario), **Address** (razón social, sitio web, dirección, país, idioma predeterminado), **Correo** (el servidor de correo saliente propio de la agencia, con **✉️ Send test**), **Plan** y **Marca blanca** (logotipo, color principal, correo de soporte, **Enable**).
3. En **Plan**, lea la licencia y los complementos de la agencia. Pulse **Manage in Licences →** para cambiar el plan, los complementos o el plazo; aquí no se cambian.
4. Pulse **Save changes**.

## Reenviar la invitación del administrador

1. Pulse **✉️** (**Reenviar invitación**) en la fila.
2. El cuadro **✉ Invite sent** muestra a quién se envió el correo y el enlace para crear la contraseña.
3. Pulse **Copiar enlace** si quiere enviarlo de otra forma, y luego **Hecho**.

## Suspender o bloquear una agencia

1. Pulse **⏸️** (**Suspend**) y confirme «Users will lose dashboard access immediately.» Pulse **▶️** (**Resume**) para devolver el acceso.
2. Pulse **🚷** (**Sign-in lock**), añada una nota y pulse **Lock sign-in** para que nadie del personal ni ningún padre de esa agencia pueda iniciar sesión. **Unlock sign-in**, en el mismo cuadro, quita el bloqueo.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la plataforma | Todo lo que hay en esta pantalla. |
| Distribuidor | Añade agencias con su propia marca en **Dar de alta una agencia**, no aquí. |
| Administrador de agencia | No ve esta pantalla; solo ve su propia agencia en **My agency**. |

## Preguntas frecuentes y cuando algo sale mal

??? El asistente dice «Required: …». | Falta un campo obligatorio en ese paso. Complete los campos indicados y pulse **Next →** de nuevo.
??? «Could not create the agency» con un mensaje. | Lea el mensaje (por ejemplo, el correo del administrador ya está en uso), pulse **← Back to review**, corríjalo y pulse **✓ Create agency** otra vez.
??? El administrador nunca recibió la invitación. | Pulse **✉️** (**Reenviar invitación**) y envíele usted el enlace con **Copiar enlace**. Revise **Audit log › 📧 Email log** para confirmar que el correo salió.
??? «Upload failed» o «Max 2 MB» bajo el logotipo. | Use un PNG, JPG o WebP de menos de 2 MB. También puede añadir el logotipo más tarde en la pestaña **Marca blanca**.
??? ¿Por qué no puedo cambiar el plan al editar? | El plan y los complementos vienen de la licencia de la agencia. Use **Manage in Licences →**.
??? ¿Qué reciben las familias y el personal cuando se suspende una agencia? | No se envía ningún correo; simplemente no pueden entrar al panel hasta que la reactive.

## Pantallas relacionadas

[[open: #licences | Licencias]] [[open: #resellers | Distribuidores]] [[open: #audit-logs | Registro de auditoría]]

- [[managing-resellers]]
- [[white-label-branding]]
- [[plan-and-add-ons]]
