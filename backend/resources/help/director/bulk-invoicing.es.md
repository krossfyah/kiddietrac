---
title: Generación masiva de facturas
category: Billing
order: 70
roles: agency_admin, platform_admin
---
# Generación masiva de facturas

**Finanzas → Facturación masiva** genera de una vez las facturas de cuotas del mes para cada familia inscrita en un centro y luego las envía por correo. Los descuentos para hermanos y el subsidio CWELCC se aplican automáticamente. La misma pantalla también permite crear una sola factura para una familia, un educador o un contratista.

[[open: #bulk-invoices | Abrir Facturación masiva]]

La pantalla tiene tres pestañas: **👪 Padres** (facturas de cuotas para familias, por centro), **🧑‍🏫 Educadores** y **🧾 Contratistas y proveedores**.

## Generar las facturas del mes de un centro

1. En el menú lateral, abra **Finanzas** y elija **Facturación masiva**.
2. Arriba, elija el **Período de facturación**: el mes y el año. Empieza en el mes actual.
3. En la pestaña **👪 Padres**, busque el centro. Cada fila muestra su ciudad, cuántos niños hay inscritos y cuántas familias.
4. Abra el menú **⋮** del centro y elija **Generar facturas**.
5. Lea el mensaje y confirme. Se crea una factura por familia inscrita; se omiten las familias ya facturadas para ese mes, aquí o en un sistema de facturación conectado.
6. La fila muestra "✓ N created" con el total, y el aviso **Facturas generadas** indica cuántas se crearon y cuántas ya estaban facturadas.

Cada factura lleva:

- una línea de **cuota** por niño inscrito, según la cuota mensual de su inscripción;
- una línea de **subsidio CWELCC** que se descuenta cuando el centro participa;
- una línea de **descuento para hermanos** cuando su agencia tiene niveles configurados (vea [Descuentos para hermanos](sibling-discounts));
- el mes completo como periodo, emitida el primer día del mes y con vencimiento 15 días después.

## Revisar y enviar las facturas

1. Abra el menú **⋮** del centro y elija **Ver facturas**. Una ventana lista las facturas de ese centro para el periodo elegido.
2. Presione el ícono del ojo (**Ver factura**) en cualquier fila para abrir la factura con la imagen de su agencia.
3. Para cancelar una incorrecta, use el botón de anular de su fila y confirme. Una factura pagada muestra **PAID** en su lugar: primero reembolse el pago y después podrá anularla.
4. Cuando todo esté bien, cierre la ventana y elija **⋮ > Enviar las facturas a las familias por correo electrónico**.
5. Confirme. El aviso **Facturas enviadas por correo electrónico** indica cuántas se enviaron y cuántas se omitieron por no tener correo registrado.

> ⚠️ Generar facturas no envía nada. Las familias solo reciben correo cuando usted elige **Enviar las facturas a las familias por correo electrónico**, y solo si el correo de su agencia está activado.

## Crear una sola factura

1. Presione **🧾 Generar una factura** en la pestaña que necesite (en **🧑‍🏫 Educadores** también puede presionar **Invoice** junto a una persona).
2. Elija **Quién** de la lista, o escriba un nombre.
3. En **Basado en**, elija **Un monto fijo** e ingrese el **Importe**, o **Hours worked × a rate** e ingrese **Hours** y **Rate / hour** (para un educador, **Usar las horas de la hoja de horas** completa las horas).
4. Opcional: fije **Periodo desde** / **to**, **Detalles**, **Partidas adicionales** (**+ Add a line**), **Aplicar impuesto** y **Repetir según un horario** (cada semana, cada dos semanas o cada mes).
5. Presione **Generar**. Revise el resumen en **Revise esto antes de crearlo**.
6. Presione **Crear esta factura** (o, para una que se repite, revise cada periodo en **Confirme cada periodo** y presione **Crear estas facturas**). El aviso **Invoice created** lo confirma.

La factura aparece en la lista de esa pestaña, donde puede usar **View**, **Resend**, **Marcar como emitida**, **Marcar como pagada** o **Anular**. No se envía nada al crearla.

> 💡 Normalmente a los educadores se les paga según sus horas registradas en **Nómina**, y las facturas de proveedores se manejan mejor en **Gastos**. Las pestañas lo indican y llevan ahí.

## Volver a ejecutar un mes

Las facturas ya generadas para ese mes no se duplican. Ejecutar de nuevo el mismo mes crea facturas solo para las familias que aún no tienen una para ese periodo, así que es seguro hacerlo después de inscribir una familia nueva. Para rehacer la factura de una familia, primero anule la anterior (en **Ver facturas** o en **Contabilidad**) y vuelva a ejecutar.

## Quién puede hacer qué

- **Administradores de la agencia**: ejecutan cualquier centro de su agencia.
- **Administradores de la plataforma**: ejecutan cualquier centro de la agencia elegida en el selector de la barra superior.
- **Directores de centro**: no tienen esta pantalla. Pida al administrador de su agencia que ejecute el mes, o cree facturas una por una desde **Contabilidad**.
- **Educadores** y **padres**: sin acceso.

## Preguntas frecuentes

??? La ejecución creó 0 facturas. | Revise que los niños del centro estén Inscritos (no en lista de espera, retirados ni graduados) con una inscripción abierta, y que sus familias no se hayan facturado ya este mes, aquí o en un sistema de facturación conectado.
??? El aviso dice "Error al generar". | El texto de abajo dice por qué. Corríjalo (por ejemplo, elija la agencia correcta) y vuelva a ejecutar. Las familias facturadas en el primer intento se omiten.
??? Se omitieron algunas familias al enviar. | No tienen correo registrado. Agregue uno al perfil del tutor y envíe de nuevo, o use Reenviar factura en Contabilidad.
??? Error "Pop-up blocked". | Permita las ventanas emergentes para este sitio para ver una factura.
??? "No centres in this agency." | Un administrador de la plataforma puede necesitar elegir primero una agencia en el selector de la barra superior.
??? ¿Qué incluye Exportar facturas en CSV? | Todas las facturas de la agencia, no solo las de este centro o este mes.

## Pantallas relacionadas

[[open: #external-billing | Contabilidad]] [[open: #payment-plans | Planes de pago]] [[open: #billing-settings | Ajustes de facturación]]

Vea también: [Generar las facturas mensuales](generating-monthly-invoices) · [Descuentos para hermanos](sibling-discounts) · [Contabilidad](accounting)
