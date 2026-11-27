---
title: Plantillas de documentos
---
# Plantillas de documentos

**Configuración → Plantillas de documentos** controla cómo se ven las nóminas y las facturas
cuando se imprimen o se envían por correo. Traiga un diseño que ya usa, véalo con cifras de
ejemplo y actívelo cuando le parezca bien. Hasta entonces, los documentos usan el diseño
integrado.

[[open: #document-templates | Abrir Plantillas de documentos]]

## Traer un diseño que ya usa

1. Abra **Plantillas de documentos** y pulse **⤵ Importar una plantilla**.
2. En **Documento**, elija **Nómina** o **Factura**.
3. En **Póngale nombre**, escriba un nombre que su equipo reconozca, por ejemplo «Nuestra nómina 2026».
4. Pegue el diseño en **Plantilla**. Sirve un archivo Blade o HTML simple.
5. Pulse **Traducir**. El diseño se traduce, nunca se ejecuta.
6. Lea el resultado. Un recuadro verde dice **✓ Translated. Nothing executable was carried across.** Un recuadro naranja lista lo que no se pudo traer, para que sepa qué terminar a mano.
7. Pulse **Vista previa** para verlo con cifras de ejemplo y luego **Guardar plantilla**.

La plantilla se suma a la lista como **Sin usar**. Guardar no cambia ningún documento por sí
solo.

## Editar una plantilla

1. Pulse **✏️ Editar** en su fila.
2. Cambie el **Nombre** o el **Diseño**.
3. En **Fields you can use — click to insert:**, haga clic en un campo para insertarlo donde está el cursor. Solo existen esos campos; cualquier otro queda en blanco.
4. Pulse **Vista previa** para verla con cifras de ejemplo y el nombre de su agencia.
5. Pulse **Guardar**.

## Activar una plantilla

1. Pulse **👁 Vista previa** en su fila una última vez.
2. Pulse **✅ Usar esta**.
3. Confirme «Use this template for every document of that kind from now on?».

Aparece el mensaje **Ahora se usa esa plantilla** y su estado cambia a **En uso**. Solo hay una
plantilla en uso por documento, así que la anterior vuelve a **Sin usar**.

## Cómo funciona el lenguaje del diseño

Una plantilla es HTML con marcadores:

- `{{ name }}` inserta un valor, seguro para la página.
- `{{{ name }}}` inserta un valor tal cual, para un bloque de marcado que aporta el sistema.
- `{{#if x}} … {{#else}} … {{/if}}` muestra una sección solo cuando hay algo que mostrar.
- `{{#each rows}} … {{/each}}` repite una sección por cada línea, como cada línea de pago.

La plantilla no ejecuta código ni hace cálculos. Cada total, fecha e importe se calcula y se
formatea antes de llegar a la plantilla, así que una plantilla nunca puede alterar las cifras
de una nómina ni mostrar algo que no se le dio.

## Leer la lista

| Columna | Significado |
|---|---|
| **Nombre** | Cómo la llamó. «imported with notes» significa que algunas partes no se pudieron traer. |
| **Documento** | Nómina o Factura. |
| **Alcance** | **Esta agencia** para las suyas, **Predeterminado de la plataforma** para el diseño inicial compartido. |
| **Origen** | De dónde vino. |
| **Estado** | **En uso** o **Sin usar**. |
| **Actualizado** | Cuándo se guardó por última vez. |

## Quién puede hacer qué

Los administradores de la agencia (y de la plataforma) usan esta pantalla. Los directores de
centro, educadores y padres nunca la ven; solo ven las nóminas y facturas que produce.

## Preguntas frecuentes y cuando algo falla

??? La lista dice «No templates yet.» | Las nóminas y facturas usan el diseño integrado hasta que agregue uno. Pulse ⤵ Importar una plantilla.
??? Una fila no tiene botón Eliminar. | No se puede eliminar una plantilla en uso ni una predeterminada de la plataforma. Active otra primero.
??? ¿Eliminar una plantilla cambia las nóminas ya enviadas? | No. Los documentos ya emitidos conservan su propio registro.
??? ¿Cómo vuelvo al diseño integrado? | Si su lista tiene una fila Predeterminado de la plataforma, actívela con ✅ Usar esta. Si no, contacte al soporte de KiddieTrac para desactivar su plantilla.
??? «Could not translate». | El texto pegado no se pudo leer como diseño. Compruebe que pegó el archivo completo y pulse Traducir otra vez.
??? Un campo sale en blanco en la vista previa. | El nombre no es uno de los campos de Fields you can use. Haga clic en el campo de esa lista para insertar el nombre correcto.

## Pantallas relacionadas

[[open: #payroll | Nómina]] [[open: #email-templates | Plantillas de correo]]

- [Plantillas de correo — cambiar el texto](email-templates)
