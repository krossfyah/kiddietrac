---
title: Planes de pago
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Planes de pago

**Finanzas → Planes de pago** reparte un total acordado en pagos con fecha, y cada pago genera su propia factura poco antes de vencer. Úselo cuando una familia acepta pagar un saldo, una cuota de inscripción o las cuotas de un periodo en partes. (El nombre en pantalla cambió, pero la función es la misma.)

[[open: #payment-plans | Abrir Planes de pago]]

![Planes de pago, con la factura y la fecha de vencimiento de cada pago](https://api.kiddietrac.com/help-img/payment-schedules.png)

Arriba, **📊 Estadísticas** muestra los planes activos, lo que vence en los próximos 30 días, los pagos no realizados, cómo pagan las familias y los cobros previstos, para su agencia (o sus centros, si es director). Filtre por **Centre** y **Status**.

## Crear un plan de pago

[[show: #payment-plans @ + New payment schedule | Mostrarme el botón Nuevo plan de pago]]

1. Presione **+ Nuevo plan de pago**.
2. Elija la **Familia**.
3. Ingrese el **Total amount ($)** y elija cada cuánto vence un pago en **Every**: **Month**, **2 weeks** o **Week**.
4. Fije las fechas **Primer vencimiento** y **Último vencimiento**.
5. Complete **What is this for?**. Se imprime en cada factura. Puede usar {child}, {service}, {month} y {period} en el texto; si no nombra al niño, se agrega su nombre.
6. En **When is each instalment due?**, elija **Avisar** (la factura sale cierta cantidad de días antes de cada vencimiento, a partir del ajuste de su agencia, 5 por defecto) o **Vence de inmediato** (cada factura se emite en su fecha de vencimiento).
7. Opcional: agregue **Extra charges (optional)** con **+ Add line**, y **Notes (optional)** internas, que nunca se imprimen.
8. Presione **Crear el plan**. Se abre la revisión.
9. Revise cada pago. Puede cambiar cualquier monto, mover cualquier fecha, **Remove** una línea o **+ Add instalment**. Todavía no se guarda nada.
10. En **When do these go out?**, elija **Mantener como borradores** (cada factura queda pendiente hasta que empieza su aviso) o **Emitir ahora** (todas las facturas se emiten hoy y se deben de inmediato; las fechas de vencimiento no cambian).
11. Presione **Guardar horario**. Aparece la tarjeta del plan con una fila por pago.

## Cuándo se emite cada factura

Cada pago genera una factura al guardar, retenida como borrador **Pendiente**. Un proceso diario emite cada una la mañana en que empieza su aviso: con 5 días de aviso, una cuota que vence el 23 se emite el 18. Una familia que acepta en junio pagar hasta diciembre no recibe siete facturas en junio.

Mientras no se emita, una factura pendiente no se debe: queda fuera del saldo de la familia, de los montos pendientes del libro de cuentas y de todos los recordatorios de facturación. La fecha de vencimiento nunca se mueve; solo cambia el aviso. El valor predeterminado de la agencia está en **Ajustes → Facturación → Valores predeterminados y cargos → Planes de pago** ([Configuración y ajustes de facturación](billing-setup)).

> ℹ️ Emitir no envía ningún correo. La factura aparece en la pestaña Facturación de la familia. Use **Reenviar** si también quiere enviarla por correo.

## Cómo leer la tabla

| Columna | Qué muestra |
|---|---|
| **Fecha de vencimiento** / **Importe** | el pago en sí |
| **Factura** | el número de la factura detrás del pago |
| **Emitido** | la fecha de emisión; en una fila pendiente aparece en gris y marcada *programada* |
| **Emitido por** | quién emitió la factura. **Automatic** significa que la emitió el proceso diario; **Importado** significa que vino de un sistema de facturación externo |
| **Estado** | el estado de la factura, con la misma etiqueta de color que en Contabilidad |

- **Pendiente**: todavía no se emite, espera su turno.
- **Programada**: emitida, pero aún no vence. No se ha perdido nada.
- **Emitido**: emitida y vence ahora.
- **Pagada parcialmente**, **Pagado**, **Vencido** y **Void** significan lo mismo que en todas partes.

El estado que se muestra es el de la factura, no el de la línea del plan: la factura registra si llegó el dinero.

## Trabajar con un pago

1. Elija la familia en la lista **Familia** (la pantalla recuerda su última elección).
2. En la fila del pago, abra **⋮**.
3. Elija lo que necesita. Solo aparecen las acciones que aplican a esa fila:
   - **Ver factura** o **Descargar factura**;
   - **Emitir ahora** (solo filas pendientes): la emite hoy, sin correo;
   - **Reenviar** (solo filas emitidas): primero confirma la dirección de correo;
   - **Editar cuota** (solo filas pendientes): cambia este pago sin tocar el resto.

Para actuar sobre varias, marque sus filas y use **Emitir ahora**, **Descargar** o **Eliminar**. Solo las filas pendientes pueden emitirse o eliminarse; el mensaje indica cuántas se omitieron.

## Cambiar o cancelar un plan completo

1. En la tarjeta del plan, presione **Editar programación** para cambiar el esquema. Esto retira y vuelve a generar los pagos aún no emitidos, y conserva el aviso con el que se creó el plan.
2. Para detener un plan, presione **Cancelar plan**. Se listan sus facturas, con las pendientes marcadas para retirarse; desmarque las que quiera conservar.
3. Confirme. Las facturas ya emitidas no pueden retirarse aquí, porque puede haber un pago en camino. Anúlelas deliberadamente desde **Contabilidad** si eso es lo que realmente quiere.

Las anulaciones siempre se hacen en [Contabilidad](accounting). Cuando anula una factura generada por un plan, su cuota se retira: sale del plan y se recalculan el total y el número de cuotas.

## Quién puede hacer qué

- **Administradores de la agencia** y **administradores de la plataforma**: crean, editan y cancelan planes para cualquier familia de la agencia.
- **Directores de centro**: lo mismo para las familias de sus propios centros; las Estadísticas cubren solo sus centros.
- **Educadores**: sin acceso.
- **Padres**: ven sus propios planes, solo lectura, y pagan las facturas desde su pestaña Facturación.

Un plan activo también aparece en el **libro de cuentas** de la familia, en **Próximamente**. Vea [Libros de cuentas](account-ledgers).

## Preguntas frecuentes

??? ¿Por qué no ha salido todavía una factura pendiente? | Se emite la mañana en que empieza su aviso: la fecha de vencimiento menos los días de aviso. Use Emitir ahora para enviarla antes.
??? ¿Puedo cambiar el monto de un solo pago? | Sí, mientras esté pendiente: use Editar cuota en el menú de la fila. Una vez emitida, anúlela en Contabilidad y agregue una nueva.
??? ¿Cambiar el aviso de la agencia afecta a los planes existentes? | No. Un plan conserva el aviso elegido al crearse, también cuando se edita.
??? La pantalla dice "Select a family above to see their payment schedules." | Elija una familia en la lista Familia. Un administrador de la plataforma puede tener que elegir antes una agencia en el selector de la barra superior.
??? Error "That range does not contain a single due date." | Las fechas de primer y último vencimiento están demasiado cerca para la frecuencia elegida. Amplíe el rango o elija una frecuencia más corta.
??? Error "Say what this is for — it is printed on every invoice." | Complete What is this for? antes de crear el plan.
??? Estadísticas dice "Insights are for directors and administrators of this agency." | Su rol o su agencia activa no puede ver las cifras de la agencia. Cambie a la agencia correcta o pregunte a su administrador.

## Pantallas relacionadas

[[open: #external-billing | Contabilidad]] [[open: #billing-schedule | Calendario de facturación]]

Vea también: [Contabilidad](accounting) · [Libros de cuentas](account-ledgers) · [Qué significa cada estado de factura](invoice-statuses) · [Configuración y ajustes de facturación](billing-setup)
