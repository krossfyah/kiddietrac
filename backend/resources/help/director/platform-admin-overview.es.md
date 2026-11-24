---
roles: agency_admin, platform_admin
title: Admin de la plataforma (resumen)
category: Administration
order: 35
---
# Admin de la plataforma (resumen)

Admin de la plataforma es el rol de nivel más alto en KiddieTrac. Está por encima del rol de administrador de agencia de cada inquilino y concede supervisión de todas las agencias de la plataforma, lo que resulta útil si opera KiddieTrac como SaaS para varias marcas de cuidado infantil independientes.

Si tiene este rol, verá una sección morada **Plataforma** en la parte superior de su barra lateral con dos entradas: Resumen de la plataforma y Todas las agencias. El botón del selector de agencia gana una pequeña insignia morada **PLAT**.

## Qué pueden hacer los admins de la plataforma

- Ver a todos los usuarios de la plataforma en Gestión de usuarios, no solo a los de la agencia activa.
- Cambiar a cualquier agencia en segundos mediante el selector, sin invitación por inquilino.
- Dar de alta una agencia cliente nueva mediante Plataforma → Todas las agencias → + Crear agencia.
- Suspender o reanudar el estado de facturación de una agencia desde la tabla Todas las agencias.
- Ver totales entre agencias (MRR, altas, bajas) en la pantalla Resumen de la plataforma.

## Herramientas solo de la plataforma

Algunas pantallas existen solo para los admins de la plataforma, porque conciernen a toda la plataforma
y no a una sola agencia:

- **Ver como**, al pie de la barra lateral: previsualizar un rol, o ver el portal como una
  persona concreta. Vea [Ver como otro usuario](view-as).
- **Ajustes → Copias de seguridad**: la copia de seguridad nocturna de la base de datos y si se está ejecutando. Vea
  [Copias de seguridad de la base de datos](database-backups).
- **Ventas → Repositorio de archivos**: tarifarios, presentaciones y plantillas de contrato para el
  área de ventas. Vea [Repositorio de archivos](files-repository).
- **Ajustes → Alertas de seguridad**: vea [Alertas de seguridad y supervisión](security-alerts).

## Qué no pueden hacer los admins de la plataforma (todavía)

- Modificar los datos de un inquilino sin cambiar antes a esa agencia. El contexto de la agencia activa sigue limitando la mayoría de las operaciones de escritura.
- Ejecutar la facturación de la propia plataforma (la integración con Stripe es por inquilino).

## Conceder Admin de la plataforma a otro usuario

En Gestión de usuarios → haga clic en **Gestionar** en el usuario → en la sección Roles, elija **Admin de la plataforma** en la lista desplegable de roles y haga clic en **Aplicar**. Nota: solo un admin de la plataforma existente puede conceder el rol; no hay ninguna vía de escalada de privilegios desde agency_admin hacia arriba.

## Quitar Admin de la plataforma

Para quitar el rol, póngase en contacto con su contacto de soporte de la plataforma. La ventana Gestionar actualmente añade roles de forma aditiva por seguridad; revocar un rol es un paso manual deliberado.
