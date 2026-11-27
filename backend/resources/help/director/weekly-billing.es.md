---
title: Calendario de facturación semanal / quincenal
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
---
# Calendario de facturación

**Finanzas → Calendario de facturación** registra con qué frecuencia se factura a una familia: cada semana, cada dos semanas o cada mes, y qué día. Úselo para una familia que acordó pagar por semana o por quincena en lugar de una vez al mes, para que toda la oficina vea su ritmo y su próxima fecha de facturación.

[[open: #billing-schedule | Abrir Calendario de facturación]]

## Definir el calendario de una familia

1. En el menú lateral, abra **Finanzas** y elija **Calendario de facturación**.
2. Elija la familia por su nombre en la lista **Familia**. Su calendario se carga solo (o presione **Cargar**).
3. Elija la **Frecuencia**: **Semanal**, **Quincenal** o **Mensual**. Una familia sin calendario muestra **Mensual**.
4. Para semanal o quincenal, ingrese el **Day of week (0=Sun … 6=Sat)**. Por ejemplo, 1 es lunes y 5 es viernes.
5. Para mensual, ingrese el **Day of month (1-31)**, por ejemplo 1 para el primer día del mes.
6. Presione **Guardar horario**. El formulario se recarga y muestra **Próximo cargo** con la fecha calculada según lo que eligió.

> 💡 Solo se usa el campo que corresponde a la frecuencia: el día de la semana para semanal y quincenal, el día del mes para mensual. Puede dejar el otro como está.

### Cómo se calcula la próxima fecha

- **Semanal**: la próxima vez que llegue ese día de la semana.
- **Quincenal**: una semana después de la próxima vez que llegue ese día, así que la primera fecha cae dentro de 8 a 14 días.
- **Mensual**: ese día del mes siguiente.

## Cambiar o desactivar un calendario

1. Elija la familia en la lista **Familia**.
2. Para cambiarlo, elija otra **Frecuencia** u otro día y presione de nuevo **Guardar horario**. La nueva fecha reemplaza la anterior.
3. Para detenerlo, presione **Desactivar**. La familia vuelve a la facturación habitual de su agencia.

## Dónde aparece el calendario

El calendario activo de una familia aparece en su **libro de cuentas** (administradores de la agencia: **Finanzas → Libros de cuentas**), en **Próximamente**, como "Next weekly invoice" (o biweekly / monthly) con la fecha. Esa fila no tiene monto e indica que todavía no se emitió factura, porque el monto solo se decide al emitirla. Vea [Libros de cuentas](account-ledgers).

> ℹ️ Guardar un calendario de facturación no emite una factura ni cobra una tarjeta por sí solo. Las facturas siguen saliendo por sus vías habituales: la **Facturación masiva**, un **plan de pago** o una factura creada a mano. El pago automático cobra una tarjeta guardada cuando una factura vence. Vea [Pago automático](autopay).

## ¿Qué herramienta necesito?

| Usted quiere… | Use |
|---|---|
| Registrar que una familia se factura cada semana, cada dos semanas o cada mes | **Calendario de facturación** (esta pantalla) |
| Dividir un total acordado en pagos con fecha, cada uno con su factura | [Planes de pago](payment-plans) |
| Elegir con qué tarjeta o cuenta paga una familia | La billetera de la familia y el [Pago automático](autopay) |
| Fijar la frecuencia predeterminada de la agencia para nuevas facturas y planes de tarifas | **Ajustes → Facturación → Valores predeterminados y cargos** ([Configuración y ajustes de facturación](billing-setup)) |

## Quién puede hacer qué

- **Administradores de la agencia** y **administradores de la plataforma**: definir, cambiar y desactivar el calendario de cualquier familia de la agencia (la plataforma, para la agencia elegida en el selector de la barra superior).
- **Directores de centro**: lo mismo, para las familias de sus propios centros. No se puede abrir una familia de otro centro.
- **Educadores** y **padres**: sin acceso a esta pantalla.

## Preguntas frecuentes

??? La lista Familia dice "Could not load families". | No se pudo obtener la lista. Recargue la página; un administrador de la plataforma debe elegir primero una agencia en el selector de la barra superior.
??? Presioné Cargar y veo "Choose a family first." | Elija una familia en la lista Familia y luego presione Cargar.
??? No pareció pasar nada al presionar Guardar horario. | El formulario se recarga en el mismo lugar. Mire la línea Próximo cargo debajo de los campos: muestra la nueva fecha.
??? ¿Se avisa a la familia del cambio? | No. Esta pantalla no envía correo, mensaje de texto ni notificación. Avise usted a la familia si cambia su día de facturación.
??? ¿Esto cambia el monto que paga la familia? | No. Registra cuándo se le factura, no cuánto. Los montos vienen de su plan de tarifas y de sus facturas.
??? ¿Por qué el libro no muestra monto para la próxima fecha? | El monto solo se fija cuando se emite la factura, así que el libro indica la fecha y deja el monto en blanco.

## Pantallas relacionadas

[[open: #payment-plans | Planes de pago]] [[open: #external-billing | Contabilidad]]

Vea también: [Planes de pago](payment-plans) · [Pago automático](autopay) · [Libros de cuentas](account-ledgers)
