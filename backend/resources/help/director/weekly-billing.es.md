---
title: Calendario de facturación semanal / quincenal
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
---
# Calendario de facturación

**Finanzas → Calendario de facturación** factura a una familia a su propio ritmo: cada semana, cada dos semanas o cada mes, el día que elija. En cada fecha KiddieTrac emite sola la factura de colegiatura de la familia, la envía por correo y, si la familia paga con cobro automático, cobra su tarjeta guardada.

[[open: #billing-schedule | Abrir Calendario de facturación]]

## Definir el calendario de una familia

1. En el menú, abra **Finanzas** y elija **Calendario de facturación**.
2. Elija la familia en la lista **Familia**. Su calendario se carga (o pulse **Cargar**).
3. Elija **Frecuencia**: **Semanal**, **Cada dos semanas** o **Mensual**.
4. Para semanal o cada dos semanas, elija el día en **Facturar el**. Para mensual, elija el día en **Facturar el día**.
5. Lea la línea **Próxima factura**: la fecha de la primera factura y el periodo que cubre. Cambia con los campos.
6. Pulse **Guardar calendario**. Desde entonces el calendario funciona.

### Qué cubre y cuesta cada factura

| Frecuencia | La factura cubre | Monto por niño | Vence |
|---|---|---|---|
| Semanal | los 7 días desde la fecha de la factura | colegiatura mensual × 12 ÷ 52 | 7 días después |
| Cada dos semanas | los 14 días desde la fecha de la factura | colegiatura mensual × 24 ÷ 52 | 7 días después |
| Mensual | el día 1, ese mes; otro día, el mes **siguiente** (por adelantado) | la colegiatura mensual | 15 días después |

Los subsidios y descuentos por hermanos se calculan como en la facturación mensual, proporcionales al periodo. Los días 29 a 31 usan el último día de un mes más corto (por ejemplo el 31 pasa a ser el 30 de abril). Todas las fechas siguen la zona horaria de su agencia.

## Qué pasa en cada fecha

- Se emite la factura con una línea de colegiatura por niño inscrito, y la familia recibe una notificación y el correo de la factura.
- Si la familia (o un tutor, en facturación dividida) tiene cobro automático, la tarjeta se cobra de inmediato por su proceso de pagos habitual, visible en **Finanzas → Proceso de pagos**.
- No se emite nada, y el registro dice por qué, cuando: otra factura ya cubre esas fechas (la facturación mensual o la del socio), su centro está cerrado todo el periodo (cierres que afectan la facturación), o ningún niño tiene colegiatura.
- Una fecha se factura una sola vez. Si una fecha pasó hace más de 3 días (falla del sistema), no se factura tarde; aparece como omitida y puede emitir esa factura a mano si hace falta.

## Ejecuciones recientes

Bajo el formulario, **Ejecuciones recientes** muestra las últimas 12 fechas: la fecha, el periodo, el resultado (**Facturada**, **Cobrada**, **Cobro rechazado**, **Omitida** o **Falló**), el monto, el número de factura y una nota.

Si no se pudo emitir una factura o se rechazó la tarjeta, los administradores de agencia y la dirección del centro reciben la notificación **La facturación programada necesita atención**. Una tarjeta rechazada deja la factura abierta; se envían los correos habituales de pago rechazado.

## Cambiar o detener un calendario

1. Elija la familia en la lista.
2. Para cambiarlo, elija otra frecuencia o día y pulse **Guardar calendario**. La próxima fecha se calcula de nuevo desde hoy.
3. Para detenerlo, pulse **Detener calendario** y confirme. No se emiten más facturas; las ya emitidas se quedan. La familia vuelve a su facturación mensual habitual.

> ⚠️ Un calendario guardado antes de la automatización muestra **Aún no activo**. Solo empieza cuando vuelve a pulsar **Guardar calendario**, para que nadie reciba un cobro por sorpresa.

## Quién puede hacer qué

- **Administradores de agencia** y **de plataforma**: definir, cambiar y detener un calendario para cualquier familia de la agencia.
- **Dirección de centro**: lo mismo, para las familias de sus propios centros.
- **Personal educador** y **padres**: sin acceso a esta pantalla.

## Preguntas frecuentes

??? ¿Sigo ejecutando la facturación mensual para esta familia? | No hace falta. La facturación mensual omite a una familia cuyo mes ya está cubierto, y el calendario omite un periodo ya facturado: nunca se factura dos veces.
??? El estado dice «Aún no activo». | El calendario es de antes de la automatización. Pulse **Guardar calendario** para iniciarlo.
??? Una ejecución dice «Ya facturado para este periodo». | Otra factura ya cubre esas fechas. No se emitió nada más.
??? ¿Se avisa a la familia? | Sí, por notificación y correo, como cualquier factura. Las agencias de prueba no envían nada.
??? ¿Por qué no se cobró la tarjeta? | No hay cobro automático, no está en su plan, o no hay procesador de pagos conectado. La nota lo indica; la factura queda abierta.

## Pantallas relacionadas

[[open: #payment-plans | Calendarios de pago]] [[open: #payment-run | Proceso de pagos]] [[open: #external-billing | Contabilidad]]

Vea también: [Calendarios de pago](payment-plans) · [Cobro automático](autopay) · [Libros de cuentas](account-ledgers)
