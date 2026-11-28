---
title: Roles personalizados y permisos
---
# Roles personalizados y permisos

**Roles y permisos** muestra los roles integrados de KiddieTrac (Administrador de la agencia, Director del centro, Educador, Visitador domiciliario, Auditor y los demás) y los **roles personalizados** de su agencia. Un rol personalizado es su propio puesto, por ejemplo *Educadora líder* o *Auxiliar contable*, **basado en** un rol integrado. Empieza con todo lo que puede hacer su rol base, y usted desactiva lo que este puesto no debe hacer. Lo que desactive desaparece del menú de la persona y se le niega si lo intenta de todos modos. Vea [[roles-and-responsibilities]] para saber qué hace cada rol integrado.

[[open: #admin-roles | Abrir Roles y permisos]]

> ℹ️ Un rol personalizado solo puede quitar cosas. A quién y a qué centros cubre siempre viene de su rol base: un rol personalizado basado en Educador ve solo los centros y los niños a los que ese educador está asignado, nunca más.

## Leer la lista de roles

1. Abra **Configuración › Roles y permisos** (🛂 en el menú).
2. Lea la tabla: el **Tipo** de cada rol (Integrado o Personalizado), en qué rol está **Basado** un rol personalizado, sus **Permisos** («3 desactivados» o «Todo lo que tiene su rol base») y cuántas **Personas** lo tienen.
3. Pulse **⋮** en un rol integrado y elija **👁 Ver** para ver todos sus permisos, agrupados por área.

## Crear un rol personalizado

1. Pulse **＋ Nuevo rol personalizado**, o pulse **⋮** en el rol integrado más cercano al puesto y elija **⧉ Copiar como rol personalizado**.
2. Escriba un **Nombre** (por ejemplo «Educadora líder») y elija **Basado en**. Administrador de la agencia y Auditor cubren toda la agencia; Director del centro, Educador y Visitador domiciliario cubren los centros donde está asignada cada persona.
3. Agregue una **Descripción** si quiere.
4. En **Lo que puede hacer este rol**, desmarque cada permiso que este puesto no deba tener. Use **Todo sí / no** junto a un grupo (Día a día, Niños y familias, Cuidado diario, Personal y RR. HH., Dinero y los demás) para cambiar todo el grupo a la vez.
5. Mire **Lo que verá esta persona** a la derecha: los elementos del menú que oculta el rol aparecen tachados en rojo.
6. Pulse **Crear rol**. Aparece el mensaje **Rol creado** y el rol se suma a la tabla.

## Cambiar, copiar o eliminar un rol personalizado

1. Pulse **⋮** en el rol personalizado.
2. Elija **✏️ Editar** para cambiar su nombre, descripción o permisos, y pulse **Guardar cambios**. Quienes lo tienen reciben el nuevo menú la próxima vez que se actualice su pantalla.
3. Elija **👁 Vista previa del menú** para ver el menú de quienes lo tienen, **⧉ Duplicar** para empezar un rol nuevo a partir de este, o **🗑 Eliminar** para quitarlo.

> ⚠️ Un rol que alguien tiene no se puede eliminar, y su **Basado en** no puede cambiar. Primero dé otro rol a esas personas en **Gestión de usuarios**.

## Dar un rol personalizado a alguien

1. Abra **Gestión de usuarios** y abra a la persona.
2. Junto a **Agregar / fijar rol**, elija el rol personalizado (marcado con **★**, en **Sus roles personalizados**) y, para un rol de centro, el **Centro**.
3. Pulse **Aplicar**. La persona muestra una etiqueta **★** con el nombre del rol. El rol personalizado se aplica en cada centro donde tenga su rol base en esta agencia.
4. Para quitarlo, elija otra vez el rol integrado y pulse **Aplicar**.

## Quién puede hacer qué

| Rol | Lo que puede hacer |
|---|---|
| Administrador de la agencia | Crea, edita, copia y elimina los roles personalizados de su agencia y se los da al personal. No puede cambiar los roles integrados. |
| Administrador de la plataforma | Todo lo anterior, puede ajustar la descripción de un rol integrado y ve la pestaña **🔎 Revisión de permisos**. |
| Director del centro y los demás | No ven esta pantalla. |

### La revisión de permisos (administradores de la plataforma)

La pestaña **🔎 Revisión de permisos** lista lo que se negó a los roles personalizados y compara las listas de permisos de los roles integrados con lo que la gente hizo realmente (los roles integrados se observan, nunca se bloquean). Use **Dejar de observar** o **Empezar a observar** para detener o iniciar la observación.

## Preguntas frecuentes y cuando algo sale mal

??? ¿Un rol personalizado cambia algo para las personas con roles integrados? | No. Los roles integrados funcionan exactamente como antes. Solo se limita a quienes tienen un rol personalizado.
??? ¿Un rol personalizado puede hacer más que su rol base? | No. Solo puede desactivar cosas. Para dar más a alguien, dele otro rol integrado.
??? Alguien ve «Your role … does not include: …». | Su rol personalizado tiene ese permiso desactivado. Edite el rol y márquelo, o dele otro rol.
??? La persona tiene dos roles. ¿Cuál gana? | Si también tiene un rol integrado de personal en la misma agencia, se aplica el acceso completo de ese rol integrado y el rol personalizado no limita nada.
??? «… people hold … (counting deactivated accounts).» | El rol no se puede eliminar mientras alguien lo tenga, incluso una cuenta desactivada. Primero dé otro rol a esas personas en Gestión de usuarios y luego elimínelo.
??? Alguien ve «This part of KiddieTrac is not set up for custom roles yet». | Una pantalla es nueva y aún no tiene permiso, así que los roles personalizados quedan fuera hasta que KiddieTrac le agregue uno. Avise a soporte; mientras tanto, dé a la persona su rol integrado si lo necesita ya.
??? «Keep at least one permission ticked.» | Un rol sin nada marcado no abriría nada. Marque al menos un permiso.
??? «Built-in roles are managed by KiddieTrac.» | Intentó cambiar un rol integrado. Cópielo como rol personalizado.
??? Aparece «Could not load roles». | Su sesión terminó o no hay agencia elegida. Elija la agencia activa en la barra superior y actualice la página.
??? ¿Se avisa a alguien cuando cambio un rol? | No. Cada cambio queda en el registro de auditoría; nadie recibe correo ni aviso.

## Pantallas relacionadas

[[open: #admin-users | Gestión de usuarios]] [[open: #audit-logs | Registro de auditoría]]

- [[roles-and-responsibilities]]
- [[inviting-parents-and-staff]]
- [[audit-log]]
