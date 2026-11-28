---
title: Roles personalizados y permisos
---
# Roles personalizados y permisos

**Roles y permisos** muestra lo que puede hacer cada rol de su agencia, como una lista de permisos agrupados por área. Los roles integrados de KiddieTrac (Agency admin, Centre director, Educator, Home visitor, Parent / Guardian, Auditor y los demás) llevan una insignia gris **SYSTEM**. Cuando su agencia necesita otro conjunto, por ejemplo una educadora líder o un auxiliar contable, crea un rol **CUSTOM** y marca los permisos que debe tener. Vea [[roles-and-responsibilities]] para saber qué hace cada rol integrado.

[[open: #admin-roles | Abrir Roles y permisos]]

## Leer un rol

1. Abra **Settings › Roles y permisos** (🛂 en el menú).
2. Elija un rol en la lista **Roles** de la izquierda. Cada uno muestra cuántos permisos tiene y, en los roles personalizados, si cubre **un centro** o **toda la agencia**. Agency admin se abre primero.
3. Lea el panel de la derecha: el nombre del rol, su descripción y, en **Permisos**, cada permiso agrupado por área (Centres, Families & children, Staff, Daily care, Attendance & kiosk, Medical, Communications, Invoicing & billing, Reports, Agency settings y más). Cada permiso muestra su clave, como `centres.edit`, y lo que permite.
4. Lea el contador al final («… / … permissions selected»): cuántos están marcados del total de permisos.

> ℹ️ Los roles integrados los administra KiddieTrac y muestran lo que cada rol puede hacer hoy. Sus casillas aparecen desactivadas para los administradores de agencia.

## Crear un rol personalizado a partir de uno integrado

1. Elija el rol integrado más parecido a lo que necesita, por ejemplo **Educator**.
2. Pulse **Copy as custom role**.
3. Escriba el nombre del rol (por ejemplo «Educadora líder») y pulse **Create**. El nuevo rol empieza con los mismos permisos.
4. Marque o desmarque permisos. Use **Toggle all** junto a un área para marcarla o borrarla completa de una vez.
5. Pulse **Guardar cambios**. Aparece el mensaje **Role saved** y el contador de la lista se actualiza.

## Crear un rol personalizado desde cero

1. Pulse **+ Nuevo** arriba de la lista **Roles**.
2. Escriba un nombre y pulse **Create**.
3. Elija **Based on** (el rol integrado más parecido, o **— none —**).
4. Elija **Covers**: **One centre** o **Toda la agencia**. Toda la agencia es solo para roles basados en Agency admin; con cualquier otra base, la pantalla lo indica y mantiene **One centre**.
5. Marque sus permisos, añada una descripción si quiere y pulse **Guardar cambios**.

## Renombrar o eliminar un rol personalizado

1. Elija el rol personalizado y haga clic en su nombre arriba para editarlo.
2. Pulse **Guardar cambios**.
3. Para quitarlo, pulse **🗑 Delete role** al final y confirme. El rol se archiva; quien lo tuviera necesitaría otro rol.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de agencia | Lee todos los roles; crea, edita y elimina roles personalizados de su agencia. No puede cambiar los roles integrados. |
| Administrador de la plataforma | Todo lo anterior, puede ajustar los roles integrados de una agencia y ve la pestaña **🔎 Permission check**. |
| Dirección de centro y demás | No ven esta pantalla. |

### La revisión de permisos (administradores de la plataforma)

La pestaña **🔎 Permission check** compara la lista de permisos con lo que la gente realmente hizo, sin bloquear nada. **Would block — must be zero before enforcing** muestra los casos que la lista rechazaría hoy. Use **Turn off** o **Turn on watching** para detener o iniciar la vigilancia.

## Preguntas frecuentes y cuando algo sale mal

??? ¿Por qué las casillas están desactivadas? | Está viendo un rol integrado. Los roles integrados los administra KiddieTrac. Pulse **Copy as custom role** para crear su propia versión.
??? ¿Marcar un permiso cambia lo que la gente puede hacer hoy? | Todavía no. Los permisos se registran y se vigilan, pero el acceso sigue dependiendo del rol integrado de cada persona. Use la lista para documentar lo que cada rol debería hacer.
??? ¿Cómo asigno un rol personalizado a una persona? | Todavía no se puede: el selector de rol en la gestión de usuarios solo ofrece los roles integrados. Por ahora asigne el rol integrado más parecido.
??? «Only a role based on Agency admin can cover the whole agency.» | Ponga **Based on** en Agency admin, o elija **One centre** en **Covers**.
??? «Built-in roles are managed by KiddieTrac.» | Intentó guardar un rol integrado. Cópielo como rol personalizado.
??? Aparece «Could not load roles». | Su sesión terminó o no hay agencia elegida. Elija la agencia activa en la barra superior y actualice la página.
??? ¿Se avisa a alguien cuando cambio un rol? | No. Los cambios quedan en el registro de auditoría; nadie recibe correo ni notificación.

## Pantallas relacionadas

[[open: #admin-users | Gestión de usuarios]] [[open: #audit-logs | Registro de auditoría]]

- [[roles-and-responsibilities]]
- [[audit-log]]
