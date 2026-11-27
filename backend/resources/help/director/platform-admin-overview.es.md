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

## Licencias y estado de facturación

El plan y los complementos de una agencia están en su licencia, en **Platform → Licences**.

- Cada agencia es un grupo. Haga clic en su encabezado (o pulse Intro o Espacio) para abrir o
  cerrar sus líneas de plan y complementos. Cerrado, el encabezado muestra los complementos como
  etiquetas junto al plazo, el número de líneas y el total. **Expand all** y **Collapse all**
  actúan sobre toda la lista, y su elección para cada agencia se recuerda en esta computadora.
- Un grupo se abre solo cuando la lista está filtrada a esa agencia, cuando la agencia está en su
  periodo de gracia o no tiene plan vigente, o cuando su licencia termina en 30 días sin
  renovación automática.
- El **⋮** del encabezado de una agencia tiene **Convert to paid** (pruebas), **Renew all**,
  **Term & renewal**, **＋ Add add-on** (o **＋ Add plan**), **Included centres** y, al final,
  **Cancel licence**. El **⋮** de cada línea tiene **Edit** (precio, cantidad, nota) y, para los
  complementos, **Remove add-on**.
- **Todas las agencias → Editar → Plan** muestra **Plan & add-ons** de solo lectura: el plan,
  cada complemento con su número de pieza y su precio, y la fecha de renovación.
  **Manage in Licences →** abre Licences filtrado a esa agencia. Cambie la licencia allí, no en la
  ventana Editar.
- Un cambio de licencia o de funciones llega a las pestañas abiertas de la agencia sin que nadie
  cierre sesión.

**El estado de facturación sigue a la licencia.** Un plan pagado o de cortesía deja la agencia
**active**, una prueba la deja en **trial**, el periodo de gracia la mantiene **active** (se
siguen emitiendo facturas de renovación) y no tener plan vigente la deja **suspended**. El estado
es de solo lectura en **My agency → Manage** y **Features**. **Suspend** mantiene la agencia
suspendida con cualquier cambio de licencia hasta que pulse **Resume**. **Pin the status** en
**Manage** mantiene una agencia en **trial** o **active** diga lo que diga su licencia (se usa
para agencias de demostración); fijar el estado nunca inicia la facturación, y una suspensión
siempre gana.

## Qué no pueden hacer los admins de la plataforma (todavía)

- Modificar los datos de un inquilino sin cambiar antes a esa agencia. El contexto de la agencia activa sigue limitando la mayoría de las operaciones de escritura.
- Ejecutar la facturación de la propia plataforma (la integración con Stripe es por inquilino).

## Conceder Admin de la plataforma a otro usuario

En Gestión de usuarios → haga clic en **Gestionar** en el usuario → en la sección Roles, elija **Admin de la plataforma** en la lista desplegable de roles y haga clic en **Aplicar**. Nota: solo un admin de la plataforma existente puede conceder el rol; no hay ninguna vía de escalada de privilegios desde agency_admin hacia arriba.

## Quitar Admin de la plataforma

Para quitar el rol, póngase en contacto con su contacto de soporte de la plataforma. La ventana Gestionar actualmente añade roles de forma aditiva por seguridad; revocar un rol es un paso manual deliberado.
