---
title: Libros de cuentas
category: Billing
order: 40
roles: agency_admin, platform_admin
---
# Libros de cuentas

**Finanzas → Libros de cuentas** responde cuatro preguntas sobre cualquier persona de su agencia: qué se le facturó, qué ha pagado, qué debe todavía y qué viene. Ábralo cuando un padre pregunte por su saldo, antes de enviar un estado de cuenta o antes de emitir un reembolso.

[[open: #account-ledgers | Abrir Libros de cuentas]]

![El libro de cuentas: elija una persona y todo su historial financiero cabe en una página](https://api.kiddietrac.com/help-img/account-ledger.png)

## Abrir el libro de una persona

1. En el menú lateral, abra **Finanzas** y elija **Libros de cuentas**.
2. Opcional: elija un **Rol** para limitar la lista a padres, educadores, directores, etc. Cada rol muestra cuántas cuentas tiene.
3. Opcional: escriba un nombre o correo en la búsqueda y presione **Enter**. La búsqueda espera a Enter; no filtra mientras escribe.
4. Elija a la persona en la lista **Cuenta**. Cada una aparece con su nombre y su rol entre paréntesis, por ejemplo *Maya (Parent)*.
5. Se abre el libro: **Account details**, las cifras de arriba, los gráficos, **Aún pendiente**, **Próximamente** y el **Historial de la cuenta**.

> ℹ️ La lista de cuentas no muestra saldos a propósito. En una lista de sesenta personas serían sesenta montos que leer para encontrar a alguien, y el saldo es lo primero que muestra el libro al abrirse.

## Ver todas las cuentas a la vez

1. Presione **⊞ Todas las cuentas**. Cada cuenta aparece con lo que debe, y arriba se muestran los totales **Billed**, **Collected**, **Pendiente**, **Anulada** y **Pagado**.
2. Use el filtro para mostrar a **Todos**, solo las cuentas **Owing money**, o solo las personas **Paid by us** (personal y beneficiarios a quienes usted paga).
3. En una fila, use **⋮ > 📄 Abrir libro** para abrir el libro de esa persona, o **⋮ > ✉️ Enviar extracto por correo electrónico** para enviarle su estado de cuenta.
4. Presione **📄 Una cuenta** para volver a la vista de una sola cuenta.

## Cómo leer las cifras

Las cifras siempre cuadran:

> 💡 **Facturado − (Recibido − Reembolsado) = Pendiente**

- **Facturado**: todo lo facturado, sin contar lo anulado. Una factura anulada no tiene saldo y aparece en su propia cifra **Anulada**.
- **Recibido**: lo que las propias facturas indican como pagado.
- **Reembolsado**: dinero devuelto. Un reembolso "des-cobra": el saldo vuelve a subir exactamente lo devuelto.
- **Pendiente**: lo que se debe hoy. **Vencido** es la parte cuya fecha de vencimiento ya pasó.
- **Vence este mes**, **Perdidas** y **Scheduled in total** aparecen cuando la persona tiene cuotas o facturación recurrente por venir.

**El dinero se cuenta una sola vez, desde la factura.** Si existe un registro de pago, aparece en su propia línea, con el método, la referencia y la fecha real, pero no se suma otra vez. Si los dos no coinciden, la factura es la que vale.

**El pago en exceso se informa, nunca se compensa.** Si se recibió más de lo facturado, una cifra **Pagado de más** lo indica. Usted decide si es un crédito para la familia o un pago registrado dos veces; por eso se muestra en lugar de absorberse en silencio.

### Próximamente

Todo lo que todavía se espera en la cuenta: cada pago de un plan de pago y toda facturación recurrente. Cada fila nombra la factura detrás del monto, y su **⋮** abre, descarga o envía esa factura por correo. Se señalan dos casos:

- **No invoice raised yet.** Un calendario de facturación solo dice "emitiremos algo en esta fecha". El monto no se decide hasta que se emite, así que no hay nada que abrir.
- El total de la factura difiere del monto previsto: normalmente la factura se ajustó después de acordar el plan. Conviene revisarlo.

Un pago no realizado se queda en esta tabla, marcado como no pagado. Es lo más útil de la página.

### Aún pendiente y el historial

**Aún pendiente** lista cada factura sin pagar, de la más antigua a la más reciente, con los días de atraso. El **⋮ > 📄 Documento de la factura** de cada fila la abre. Más abajo, el **Historial de la cuenta** lista cada línea; fíltrelo con **Todo**, **Facturas**, **Pagos**, **Reembolsos**, **Anulaciones** o **Pagado**.

## Generar y enviar un estado de cuenta

1. Abra el libro de la persona.
2. Presione **📄 Generar estado de cuenta**. Se abre la ventana **Generar extracto de cuenta**.
3. Elija un **Periodo**: **Desde siempre**, **Este mes**, **El mes pasado**, **Últimos 3 meses**, **Este año** o **Custom range…** (y complete **From** y **To**).
4. Presione **👁 Ver** para leerlo en pantalla, **⤓ Descargar** para el PDF o **✉️ Email…** para enviarlo.
5. Para enviarlo: revise la dirección en **Enviar a** (viene con la de la cuenta), agregue una nota opcional, marque **Enviarme copia** si quiere una copia y presione **Enviar extracto**.
6. Un mensaje confirma "Statement emailed to" seguido de la dirección a la que realmente salió.

El estado de cuenta lleva la imagen de su agencia, los datos de contacto del titular y cada línea: facturas, recibos, reembolsos, anulaciones y lo que queda por pagar. En un rango de fechas también muestra el saldo inicial y el saldo final.

> ⚠️ La dirección se pide cada vez y debe confirmarse. No hay destinatario predeterminado, porque un estado de cuenta enviado a la persona equivocada no se puede recuperar.

## Emitir un reembolso desde el libro

1. Abra el libro de la persona y presione **↩ Reembolso**. La ventana **Emitir un reembolso** lista las facturas con dinero que aún puede reembolsarse.
2. Elija la factura y, si se pagó en más de un pago, elija **Qué pago**.
3. Ingrese el **Importe** (empieza en el máximo reembolsable) y elija un **Motivo**, por ejemplo **Solicitado por la familia**, **Pago en exceso** o **Cargo duplicado**. Agregue **Notas** si sirve.
4. En **Aprobación**, escriba **Su nombre completo** y firme en el recuadro.
5. Presione **Aprobar reembolso**. El libro se recarga y el saldo cambia de inmediato.

Para saber cómo vuelve el dinero a la familia, vea [Reembolsar un pago](refunds).

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la agencia | Todo en esta pantalla, para cada cuenta de la agencia |
| Administrador de la plataforma | Lo mismo, para la agencia elegida en el selector de la barra superior |
| Director de centro | No tiene esta pantalla. Los directores usan **Finanzas → Contabilidad**, que cubre sus centros |
| Educador, padre | Sin acceso. Los padres ven su propio saldo en su pestaña **Facturación** |

## Preguntas frecuentes

??? Generar estado de cuenta y Reembolso están en gris. | Solo funcionan con una cuenta abierta. Elija primero a una persona en la lista Cuenta.
??? La persona que busco no está en la lista. | Revise que el filtro Rol no esté limitando la lista, borre la búsqueda y presione Enter. Los registros cerrados no aparecen.
??? La ventana de reembolso dice que no hay nada que reembolsar. | "Nothing on this account has money against it that can be refunded." Una factura aparece ahí solo cuando se recibió un pago que no se ha devuelto.
??? El aviso dice que todavía no se envió dinero. | "Refund approved … no money has been sent yet" significa que el reembolso quedó registrado, pero el dinero se devuelve fuera de KiddieTrac (efectivo, cheque o transferencia Interac). Envíelo como lo hace normalmente.
??? El estado de cuenta no salió por correo. | Si el interruptor de correo de su agencia está apagado, o una regla de entrega bloquea esa dirección, se le dice claramente. Aquí "enviado" significa que realmente salió.
??? ¿Por qué una fila de Próximamente dice que no hay factura emitida? | Viene de un calendario de facturación. El monto solo se conoce cuando se emite la factura.
??? Error "The signature pad did not load". | Recargue la página. Un reembolso no puede aprobarse sin firma.

## Pantallas relacionadas

[[open: #external-billing | Contabilidad]] [[open: #payment-plans | Planes de pago]] [[open: #refunds | Reembolsos]]

Vea también: [Contabilidad](accounting) · [Reembolsar un pago](refunds) · [Planes de pago](payment-plans) · [Calendario de facturación](weekly-billing)
