---
title: Programar un aumento de la colegiatura
category: Billing
order: 83
roles: agency_admin, centre_director, platform_admin
---
# Programar un aumento de la colegiatura

**Finanzas → Aumentos de colegiatura** sirve para planificar un aumento futuro de la colegiatura: un porcentaje (por ejemplo 3.5 %) o un monto fijo en dólares al mes, a partir de una fecha. Después KiddieTrac hace el resto: avisa a cada familia afectada antes de la fecha y, ese día, guarda el nuevo monto en su colegiatura y en sus planes de cuotas.

[[open: #tuition-increases | Abrir Aumentos de colegiatura]]

## Cómo avanza un aumento

| Estado | Qué significa |
|---|---|
| **Programado** | Guardado. Todavía no se ha enviado nada. Aún puede editarlo o eliminarlo. |
| **Avisado** | Cada familia afectada recibió el aviso (notificación y correo) con la colegiatura mensual actual y nueva de sus propios hijos. Ya no se puede cambiar. |
| **Aplicado** | En la fecha de inicio, el nuevo monto se guardó en la colegiatura mensual de cada niño y en los planes de cuotas. Las facturas emitidas desde entonces lo usan. |
| **Atrasado** | La fecha pasó hace más de 14 días y nunca se aplicó (por ejemplo, se planificó antes de esta automatización). No se aplica tarde por sí solo: pulse **Aplicar ahora** o **Eliminar**. |

Todas las fechas siguen la zona horaria de su agencia. Las familias reciben el aviso el **día del aviso**: la fecha de inicio menos los días de aviso.

## Programar un aumento

[[show: #tuition-increases @ + Schedule increase | Muéstrame Programar aumento]]

1. Abra **Finanzas → Aumentos de colegiatura** y pulse **+ Programar aumento**.
2. Elija el **Centro**. Un administrador de agencia también puede elegir **Todos los centros**; una dirección elige uno de sus propios centros.
3. Elija la fecha en **La nueva colegiatura empieza el**. Debe ser posterior a hoy.
4. En **Tipo**, elija **Porcentaje (%)** o **Monto fijo ($ al mes)**.
5. Escriba el **Aumento** sin el signo % ni $: 3.5 para 3.5 %, o 25 para $25 más al mes.
6. **Avisar a las familias con estos días de anticipación** toma la regla de aviso de su agencia (30 días salvo cambio). Cámbielo para este aumento si lo necesita.
7. Agregue una **Nota para las familias** si quiere (por ejemplo «Nuestro ajuste anual por costo de vida»). Aparece en su correo.
8. Revise la vista previa bajo los campos: cada familia afectada, la colegiatura mensual de cada niño **Ahora** y **Nueva**, y los planes de cuotas que cambian. La línea de arriba indica el día en que se avisará a las familias.
9. Pulse **Programar aumento**.

> ⚠️ Si el día del aviso ya pasó (por ejemplo 30 días de aviso para una fecha dentro de 10 días), el formulario le advierte. Las familias reciben el aviso en la siguiente ejecución automática y tienen menos anticipación que su regla.

### Qué cambian un porcentaje y un monto fijo

- Un **porcentaje** aumenta la colegiatura mensual de cada niño afectado y cada plan de cuotas activo del centro (o de todos los centros) en ese porcentaje, redondeado al centavo.
- Un **monto fijo** suma los dólares a la colegiatura **mensual** de cada niño y solo a los planes **mensuales**. Los planes semanales, quincenales y de pago único no cambian; la vista previa los nombra.
- Solo se incluyen niños inscritos, con colegiatura mensual mayor a $0, de familias activas, en centros abiertos.

## Ver, editar y eliminar

El menú **⋮** de cada fila tiene:
- **Ver**: los detalles y a quién llega. Antes del aviso, la lista se calcula con las cuotas de hoy; después, es el registro de lo que se avisó a cada familia y de lo que se cambió.
- **Editar** (solo Programado): abre el mismo formulario con la vista previa.
- **Eliminar** (Programado o Atrasado): lo quita. A las familias ya avisadas no se les informa la cancelación; avíseles usted.
- **Aplicar ahora** (solo Atrasado): avisa a las familias que aún no recibieron el aviso y luego guarda los nuevos montos de inmediato.

## La regla de aviso de su agencia

Un administrador de agencia pulsa **⚙ Regla de aviso** para fijar con cuántos días de anticipación se avisa a las familias. Es el valor inicial de los nuevos aumentos; los ya programados conservan el suyo.

## Qué reciben las familias

Cada tutor de una familia afectada recibe una notificación y un correo «Cambio de colegiatura a partir del (fecha)» con sus propios hijos, la colegiatura mensual actual y nueva, su nota y un botón a su facturación. Nadie ve los montos de otra familia. Cuando el aumento se aplica, los administradores de agencia y la dirección del centro reciben una notificación con cuántas cuotas se actualizaron.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de agencia | Programa, edita y elimina aumentos para un centro o todos, aplica los atrasados y fija la regla de aviso. |
| Dirección de centro | Ve los aumentos de sus centros y los de toda la agencia; programa, edita y elimina aumentos solo para sus propios centros. |
| Administrador de plataforma | Igual que un administrador de agencia, en la agencia donde trabaja. |

El personal educador y los padres no ven esta pantalla.

## Preguntas frecuentes y problemas

??? «La nueva colegiatura debe empezar después de hoy.» | Elija una fecha posterior.
??? «Un aumento porcentual puede ser como máximo 50 %.» | Revise el número. Para un cambio grande, use **Monto fijo** o cambie la colegiatura directamente.
??? La vista previa dice que ninguna familia cambiaría. | Ningún niño inscrito en ese centro tiene colegiatura mensual mayor a $0. Revise primero las inscripciones y los planes de cuotas.
??? ¿Por qué no puedo editar un aumento? | Ya se avisó a las familias. Elimínelo solo si está Atrasado; si no, programe un aumento de corrección y avise a las familias.
??? ¿Cambian las facturas ya emitidas? | No. Solo las facturas emitidas después de la fecha de inicio usan la nueva colegiatura.
??? ¿Se envía un mensaje de texto? | No. Las familias reciben una notificación y un correo. Las agencias de prueba no envían nada.

## Pantallas relacionadas

[[open: #tuition-plans | Planes de cuotas]] [[open: #payment-plans | Calendarios de pago]] [[open: #external-billing | Contabilidad]]

Vea también: [Planes de cuotas](tuition-plans) · [Generar facturas mensuales](generating-monthly-invoices)
