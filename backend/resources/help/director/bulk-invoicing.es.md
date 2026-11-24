---
title: Generación masiva de facturas
category: Billing
order: 70
---
# Generación masiva de facturas

Ejecute la facturación mensual de todas las familias inscritas de un centro con un clic. Lo encontrará en **Administración → Facturación masiva** en la barra lateral.

## Cómo funciona

1. Elija el **periodo de facturación** en la parte superior: mes y año. Por defecto, el mes actual.
2. Elija un centro de la lista y haga clic en **Generar facturas**.
3. Confirme el aviso. Se crea una factura por cada familia inscrita con:
   - Líneas de matrícula por niño (una fila por cada niño inscrito en este centro)
   - Líneas de **subvención CWELCC** restadas automáticamente cuando el centro participa
   - Líneas de **descuento por hermanos** aplicadas cuando la agencia tiene niveles configurados (consulte `sibling-discounts.md`)
   - Inicio y fin del periodo establecidos en el mes completo
   - `issued_at` fijado el día uno del mes y `due_at` 15 días después
   - Estado = `sent`
4. Tras la ejecución, la etiqueta de resultado de cada fila de centro muestra cuántas facturas se crearon y su valor total en $.

## Volver a ejecutar

Las facturas ya generadas para ese mes **no se duplican**: las filas existentes se quedan como están. Ejecutar de nuevo el mismo mes crea facturas solo para las familias que aún no tienen una para ese periodo. Es seguro volver a lanzarlo.

Si necesita borrar y rehacer, elimine primero las facturas manualmente (o mediante SQL).

## Permisos

- **Administrador de agencia** — ejecuta en cualquier centro de su agencia
- **Administrador de la plataforma** — ejecuta en cualquier centro de la plataforma mediante el selector de agencia
- **Director del centro** — usa la ruta existente `/director/invoices/generate` desde su panel (solo su propio centro)
- **Educador** — sin acceso

## Qué se factura

- Una línea de **matrícula** por niño inscrito, calculada con la tarifa mensual de su sala
- Una línea de **subvención** por niño cuando el centro participa en CWELCC (se resta del total)
- Una línea de **ajuste** por cada nivel de descuento por hermanos que corresponda (se resta del total)

El encabezado de la factura contiene los importes acumulados `subtotal`, `subsidy_amount`, `discount_amount`, `total` y `balance_due`.

## Solución de problemas

Si una ejecución devuelve 0 generadas, compruebe:

- ¿Hay niños del centro actualmente `enrolled` (no en lista de espera, retirados o graduados)?
- ¿Esos niños tienen una matrícula activa (`enrollments.end_date IS NULL`)?
- ¿Las familias ya tenían una factura v22p42 para ese mes exacto? Las filas existentes se omiten.

La pantalla de facturación masiva llama al mismo servicio que el botón «Generar este mes» del portal del director: misma lógica, mismos resultados, pero se puede operar centro por centro en toda una agencia.
