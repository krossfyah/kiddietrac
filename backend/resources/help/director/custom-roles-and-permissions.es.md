---
roles: agency_admin, platform_admin
title: Roles personalizados y permisos
category: Administration
order: 20
---
# Roles personalizados y permisos

KiddieTrac incluye roles integrados (del sistema): Administrador de la agencia, Director del centro, Educador, Visitador a domicilio, Padre / tutor y Auditor (el Admin de la plataforma está por encima de las agencias). Consulte [[roles-and-responsibilities]] para saber qué hace cada uno. Para la mayoría de las agencias son suficientes: cubren los tipos de personal que tienen casi todas las operaciones de cuidado infantil.

Cuando necesite modelar estructuras de personal que no encajan bien en esos roles —por ejemplo, una Educadora principal que puede firmar planes de clase pero no facturas, o un Contador que puede ejecutar informes pero no modificar centros—, cree un **rol personalizado** con una matriz de permisos a la medida.

## Dónde encontrarlo

Barra lateral → **Ajustes → Roles y permisos**. Verá una pantalla de dos columnas: la lista de roles a la izquierda y la matriz de permisos a la derecha.

## Cómo leer la matriz

El panel derecho muestra el nombre y la descripción del rol seleccionado, y una cuadrícula de 56 claves de permiso agrupadas en categorías: Centros, Familias y niños, Alcance de padres, Personal, Salas, Facturación, Informes, Incidentes, Médico, Observaciones y currículo, Asistencia y quiosco, Recogidas tardías, Tareas, Visitas a domicilio, Documentos (incl. el Administrador de formularios), Comunicaciones (incl. las plantillas de correo electrónico), Eventos y RSVP, Ayuda y formación, y Ajustes de la agencia.

Cada permiso tiene una etiqueta corta en fuente monoespaciada (p. ej., `centres.edit`) y una descripción en lenguaje sencillo (p. ej., «Editar los ajustes y la marca del centro»). Marque la casilla de cada permiso que deba tener el rol.

Debajo de cada categoría, haga clic en **Alternar todo** para cambiar todos los permisos de esa categoría a la vez; es útil para «este rol tiene todo en esta área y nada en las demás».

## Roles del sistema y roles personalizados

A los roles del sistema (con la insignia gris **SISTEMA**) se les pueden ajustar los permisos, pero no cambiar su nombre, clave ni existencia, porque el código subyacente los referencia. Los roles personalizados (con la insignia azul **PERSONALIZADO**) se pueden editar y eliminar por completo.

## Crear un rol personalizado

1. Haga clic en **+ Nuevo** en la parte superior de la lista de roles.
2. Escriba un nombre (p. ej., «Educadora principal»).
3. El nuevo rol aparece sin permisos marcados. Marque los que deba tener.
4. Haga clic en **Guardar cambios**.

El rol ya es visible en la ventana Gestionar de Gestión de usuarios; asígnelo a los usuarios igual que un rol del sistema.

## Asignar un rol personalizado a un usuario

Gestión de usuarios → haga clic en **Gestionar** en el usuario → en la sección Roles, seleccione el rol en la lista desplegable → haga clic en **Aplicar**. Los roles son acumulativos: agregar un rol nuevo no elimina los existentes.

## Aislamiento de datos entre centros y agencias

Los roles también deciden **qué datos puede ver**, independientemente de la matriz de permisos:

- **Administrador de la agencia / Admin de la plataforma** ven todos los centros de su **agencia activa**; por ejemplo, la lista **Niños** muestra a todos los niños de todos los centros de la agencia, y el filtro de centro permite acotarla.
- **Director del centro / Educador** ven solo las familias, los niños, las salas y los informes de **su propio centro**.
- Las agencias están totalmente separadas: una agencia nueva (o un centro nuevo de otra agencia) empieza con sus propios ajustes y **nunca** ve los datos de otra agencia. Cambie la **agencia activa** desde la barra superior si administra más de una.

Si alguna vez ve «agencia no encontrada», asegúrese de que haya una **agencia activa seleccionada** en la barra superior.

## Nota sobre la fase A

En la versión actual, los permisos guardados en los roles personalizados se registran pero aún no se aplican: todas las verificaciones de rutas existentes usan las claves de rol originales. La fase B (un despliegue planificado) hará que esas verificaciones consulten la matriz de permisos. Hasta entonces, trate la matriz como documentación de lo que cada rol DEBERÍA poder hacer; es útil para fines de auditoría.
