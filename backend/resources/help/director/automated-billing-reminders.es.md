---
title: Recordatorios de facturación automáticos
category: Billing
order: 25
---

# Recordatorios de facturación automáticos

**Ajustes → Facturación → 🔔 Recordatorios.** Programe correos electrónicos automáticos a las familias sobre facturas próximas a vencer y facturas vencidas. Los administradores de agencia **y** los directores de centro pueden gestionarlos.

[[show: #billing-settings @ Reminders > Send upcoming-invoice reminders | Mostrarme los ajustes de recordatorios]]

Los mismos ajustes aparecen también en **Ajustes → Ajustes de correo → Facturación**, junto a sus otros correos automáticos. Es un único conjunto de ajustes con dos accesos, así que un cambio en cualquiera de los dos se refleja en ambos.

Los **directores de centro** ven solo los recordatorios, no el resto de los ajustes de facturación:

[[open: #billing-setup | Abrir recordatorios de facturación]]

## Qué puede configurar

- **Recordatorios de factura**: marque **Enviar recordatorios de facturas próximas** y luego indique los **Días antes de la fecha de vencimiento**, separados por comas. El valor predeterminado es `7,3`.
- **Recordatorios de pago vencido**: marque **Enviar recordatorios de pagos vencidos** y luego indique los **Días después de la fecha de vencimiento**. El valor predeterminado es `1,7,14`.
- **Hora de envío diaria**: la hora a la que salen los recordatorios, **en la zona horaria de su agencia**. El valor predeterminado es 09:00.
- **✉️ Enviar por correo**: los recordatorios se envían por correo electrónico.
- **Copiar también al contacto de facturación de la agencia**: la oficina recibe una **copia oculta** de cada recordatorio. Las familias nunca ven la dirección de la oficina.
- **Nota personalizada añadida a cada recordatorio (opcional)**: se añade a todos los recordatorios que envíe.

Haga clic en **Guardar recordatorios**.

Los días se cuentan a partir de la fecha de vencimiento de cada factura, según el calendario de su agencia. Cada recordatorio se envía **una sola vez**, exactamente el día indicado.

## Quién los recibe

Los recordatorios van a los tutores de la familia:

- Si algún tutor está marcado para **recibir la facturación**, solo esos tutores reciben el correo.
- De lo contrario, lo recibe cada tutor que pueda iniciar sesión.
- Se omite a los tutores que aún no han aceptado su invitación o cuya cuenta está desactivada.

## Qué facturas se incluyen

Se incluyen tanto las facturas emitidas en KiddieTrac como las sincronizadas desde un sistema de facturación externo, siempre que aún tengan saldo.

- **Pagada parcialmente**: se incluye. El recordatorio indica el **saldo restante**, no el total original.
- **Pagada**, **reembolsada**, **anulada**: nunca se incluyen. No se reclama dinero a nadie que ya pagó o a quien se le devolvió.
- Facturas **pendientes** de un plan de pago: no se incluyen. No se adeudan hasta que se emiten.

Cada recordatorio indica el número de factura, el saldo y la fecha de vencimiento. Así, una familia con varias facturas abiertas puede saber de cuál se trata.

## Activar los recordatorios

Los recordatorios están **desactivados por defecto** para todas las agencias. No se envía nada hasta que marque al menos uno de los dos tipos de recordatorio.

> **Importante:** También hay un **interruptor para toda la plataforma**, que actualmente está **desactivado**. Cuando lo está, la pestaña Recordatorios muestra un banner amarillo: *Los recordatorios automáticos están actualmente DESACTIVADOS en toda la plataforma.* Su programación queda guardada y empezará a enviarse cuando KiddieTrac active los recordatorios en la plataforma.

> **Consejo:** Revise sus listas de días, la hora de envío y la nota personalizada antes de que se active el interruptor de la plataforma. A partir de entonces, familias reales empezarán a recibir los recordatorios que programó.

## Preguntas frecuentes

??? ¿Por qué una familia no recibió un recordatorio? | Compruebe que tenga un tutor con inicio de sesión activo, que la factura todavía tenga saldo y que no siguiera pendiente ese día.
??? ¿Puedo enviar por SMS? | Por ahora no. Los recordatorios de facturación se envían solo por correo electrónico.
??? ¿En qué zona horaria está la hora de envío? | En la propia zona horaria de su agencia, así que 09:00 significa las 9 a. m. donde están sus centros.

Vea también: [Configuración de facturación](billing-setup) · [Planes de pago](payment-plans) · [Automatización de cargos por mora](late-fees)
