---
roles: agency_admin, platform_admin
title: Plantillas de documentos
category: Settings
order: 40
---
# Plantillas de documentos

**Ajustes → Plantillas de documentos.** Controle el aspecto real de una nómina, una factura o un recibo, sin esperar a una actualización.


## Pasos rápidos

1. Vaya a **Ajustes → Plantillas de documentos**.
2. Elija el tipo de documento: nómina, factura o recibo.
3. Parta del diseño integrado, o pulse **Importar** y elija un archivo Blade para convertir (así se trajeron los diseños propios de iLearn).
4. Edite el HTML, usando `{{ name }}` para un valor y `{{#each rows}}` para repetir una fila.
5. **Vista previa**: se genera con un registro real.
6. **Active** cuando se vea bien. Solo hay una plantilla activa por tipo a la vez.

Si no hay ninguna plantilla activa, los documentos se generan con el diseño integrado, así que activar una plantilla siempre es reversible.

## Cómo funciona una plantilla

Una plantilla es HTML con marcadores de posición:

- `{{ name }}`: un valor, con escape
- `{{{ name }}}`: un valor insertado tal cual, para un bloque de marcado que usted proporcione
- `{{#if x}} … {{#else}} … {{/if}}`: muestra una sección solo cuando hay algo que mostrar
- `{{#each rows}} … {{/each}}`: repite una sección por cada fila

Ese es todo el lenguaje. El motor no ejecuta código ni hace aritmética: cada total, fecha e importe se calcula y se formatea antes de llegar a la plantilla. Por lo tanto, una plantilla nunca puede estropear una nómina ni filtrar nada que no se le haya entregado.

## Importar un diseño existente

**Importar** acepta un archivo Blade, el formato en que están escritas las nóminas y facturas de iLearn. Convierte las partes que una plantilla puede expresar e informa de todo lo que no pudo convertir, para que sepa qué terminar a mano en lugar de descubrir una línea en blanco en una nómina.

## Conviene saber

- Solo hay una plantilla **activa** por tipo de documento a la vez; las demás se conservan.
- El diseño integrado es el respaldo: si no hay ninguna plantilla activa, los documentos se generan como siempre.
- Haga una vista previa antes de activar: usa un registro real.
