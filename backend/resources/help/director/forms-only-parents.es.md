---
title: Padres (solo formularios)
category: Enrolment
order: 3
roles: agency_admin, platform_admin
---
# Padres (solo formularios)

Cuando envía formularios a un padre que aún no está en KiddieTrac, se crea para él una **cuenta temporal, solo para formularios**, para que pueda firmar. Hasta que se agregue su familia, esa cuenta no pertenece a ninguna familia. Esta página explica cómo se vinculan, se señalan y se depuran esas cuentas.

[[open: #notification-settings | Abrir los ajustes de notificaciones]]

## Vincularlos a su familia

Agregue la familia como de costumbre con el asistente **Nueva familia**. En el paso **Tutores**:

- Escriba el nombre o el correo electrónico del padre. Si coincide una cuenta solo para formularios, aparece un cuadro amarillo **Cuenta solo para formularios encontrada** con su correo y los formularios firmados.
- Pulse **Vincular a esta familia**, revise los datos en la ventana y pulse **Usar esta cuenta**. El tutor se completa a partir de la cuenta.

O simplemente use el **mismo correo electrónico** al que recibió los formularios. En ambos casos, cuando se guarda la familia, la cuenta se une a ella con todos los formularios que firmó. Si nunca se le había enviado un acceso, se le invita como a cualquier tutor nuevo. El vínculo queda registrado en el registro de auditoría.

Vea [Agregar una familia](adding-a-family).

## En el resumen

Mientras no estén en una familia, estos padres aparecen en el correo de resumen para administradores bajo **🧾 Padres con formularios pero sin familia todavía**: nombre, correo, formularios firmados y la fecha en que se eliminará una cuenta sin usar. Solo los administradores de la agencia reciben esta sección.

## La lista y el ajuste

Barra lateral → **Ajustes** → **Notificaciones** → **🧾 Padres (solo formularios)**. Solo los administradores de la agencia pueden cambiarlo.

- **Eliminar cuentas sin usar después de … días** — **30** de forma predeterminada. Escriba **0** para no eliminarlas nunca. Pulse **Guardar**.
- La tabla lista a cada padre con sus formularios (**firmados / asignados**) y un estado:
  - **En uso — agregue su familia** — ha iniciado sesión o ha firmado un formulario.
  - **Sin usar · eliminada** *(fecha)* — nunca inició sesión ni firmó un formulario.
- **Quitar** elimina una cuenta de inmediato. Se elimina su acceso; los formularios ya firmados permanecen registrados. Hágalo solo si el padre no se va a incorporar.

## Eliminación automática

Cada noche se eliminan las cuentas solo para formularios sin usar más antiguas que su límite. Una cuenta que alguien ha usado —inició sesión o firmó un formulario— **nunca** se elimina automáticamente. Permanece en la lista hasta que usted agregue la familia o la elimine.

??? ¿Eliminar una cuenta borrará sus formularios firmados? | No. Los formularios permanecen registrados.
??? ¿Dónde está Ajustes → Notificaciones? | Barra lateral → **Ajustes** → **Notificaciones**. Cada tipo de recordatorio tiene su propia pestaña; **🧾 Padres (solo formularios)** es una de ellas.
