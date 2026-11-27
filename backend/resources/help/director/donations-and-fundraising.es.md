---
title: Donaciones y recaudación de fondos
category: Billing
order: 20
roles: agency_admin, platform_admin
---
# Donaciones y recaudación de fondos

**Finanzas → Donaciones** le permite organizar campañas de recaudación con una página de donación para compartir, registrar cada donativo y emitir recibos a los donantes. Las donaciones están completamente separadas de las facturas y de las cuentas de las familias. La pantalla tiene tres pestañas: **Donativos**, **Campañas** y **Recibos y configuración**.

[[open: #donations | Abrir Donaciones]]

## Activar las donaciones

1. Abra **Finanzas → Donaciones** y vaya a la pestaña **Recibos y configuración**.
2. Marque **Aceptar donaciones (mostrar campañas y páginas de donación)**.
3. Si su agencia es una organización benéfica registrada, complete **Número de registro de organización benéfica ante la CRA** (se ve así: 123456789 RR 0001), **Nombre legal (según registrado)**, **Dirección de la organización benéfica (según registrada)**, **Lugar donde se emiten los recibos**, **Firmante autorizado** y **Cargo del firmante**. ¿No es una organización benéfica? Deje el número en blanco.
4. Si quiere, agregue una **Firma en los recibos**: pulse **Añadir**, firme y luego **Usar firma**.
5. Escriba **Cómo los donantes completan una promesa de donación (se muestra en la página y en el correo de agradecimiento)**, por ejemplo su dirección de e-Transfer y a nombre de quién se emiten los cheques.
6. Si quiere, complete **Enviar por correo electrónico las nuevas promesas a (opcional)**. Sus administradores de agencia siempre reciben aviso en el portal.
7. Pulse **Guardar ajustes**. Un aviso dice **Donation settings saved**.

Cuando los datos de su organización están completos, verá **✓ Ready to issue official receipts.** Si falta algo, la pantalla lo indica después de «Official receipts need:».

[[show: #donations @ Receipts & settings | Mostrarme Recibos y configuración]]

## Crear una campaña y compartir su página de donación

1. Vaya a la pestaña **Campañas** y pulse **+ Nueva campaña**.
2. Ingrese el **Title *** y una descripción que explique a los donantes para qué es.
3. Si quiere, defina un **Goal (optional)**, **Montos sugeridos**, las fechas en que **Empieza** y **Termina**, y un **Thank-you message (sent with each pledge)**.
4. Elija el **Estado**: **Active — taking pledges**, **Draft — page hidden** o **Cerrado**.
5. Deje activado **Mostrar el monto recaudado en la página** si quiere que la página muestre el avance hacia su meta.
6. Pulse **Guardar**. El aviso dice **Campaign saved**.
7. Pulse **🔗 Copy link** en la campaña y pegue el enlace en un anuncio, boletín o publicación en redes sociales. **Abrir página** muestra la página de donación tal como la ven los donantes.

Los donantes eligen un monto, ingresan sus datos y hacen una promesa de donación. Reciben un correo de agradecimiento con sus instrucciones para completar el donativo.

> ℹ️ La página de donación recibe promesas; los donantes pagan como indican sus instrucciones (e-Transfer, cheque, efectivo). El pago en línea con tarjeta en la página aún no está disponible.

## Marcar una promesa como recibida

1. Vaya a la pestaña **Donativos**. Filtre con **Todas las campañas** y **Cualquier estado** si la lista es larga.
2. Busque la promesa y pulse **Marcar como recibido**.
3. Revise la **Fecha de recepción** y agregue la **Referencia (n.º de cheque, ID de e-Transfer)**.
4. Pulse **Guardar**. El aviso dice **Gift saved** y los totales de arriba (**Recibido**, **Pledged, not yet received**, **Donativos**, **Receipts issued**) se actualizan.

## Registrar un donativo que no llegó por la página

1. En **Donativos**, pulse **+ Registrar un donativo**.
2. Ingrese el **Nombre *** del donante y sus demás datos. Para un recibo oficial con fines fiscales, ingrese el nombre completo y la dirección postal (**Street address**, **City**, **Province / state**, **Postal code**).
3. Elija la **Campaign** (o **— General donation —**) e ingrese el **Monto *** y el **Método**.
4. Si el donante recibió algo a cambio (por ejemplo, una cena de gala), ingrese su valor en **Value of anything given back (advantage)**. El recibo muestra el monto elegible.
5. Marque **Mantener anónimo a este donante en los totales y listas públicos** si lo pidió.
6. Pulse **Guardar**.

## Emitir, enviar y anular un recibo

1. En un donativo recibido, pulse **Emitir recibo** (o **Emitir acuse de recibo** cuando no es posible un recibo oficial).
2. Lea la confirmación y confirme. El recibo recibe el siguiente número de serie (por ejemplo 2026-0001).
3. Pulse **PDF** para descargarlo, o **Correo** y luego **Enviar correo electrónico** para enviarlo al donante con el PDF adjunto.

Se emite un **recibo oficial para fines del impuesto sobre la renta** cuando los datos de su organización están completos y el nombre completo y la dirección del donante figuran en el donativo. Si no, el donante recibe un **acuse de recibo**: un agradecimiento que dice claramente que no es un recibo fiscal. La confirmación le indica qué falta antes de emitirlo.

Los recibos no se pueden editar. Si algo estaba mal:

1. Pulse **Anular** en el recibo.
2. Escriba el motivo en **Reason (kept with the voided receipt)** y pulse **Anular recibo**.
3. Elija **Emitir reemplazo** (un nuevo número de serie que indica qué recibo reemplaza) o **Solo anular**. Corrija primero el donativo si el error estaba en el donativo.

## Exportación de fin de año

1. En **Donativos**, elija el año.
2. Pulse **⬇ Export CSV**. Obtiene cada donativo, su monto elegible y su número de recibo, listos para su contador.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Todo: configuración, campañas, donativos y recibos. |
| Administrador de plataforma | Lo mismo, para la agencia que está viendo. |
| Director de centro | No ve Donaciones. La recaudación y los recibos fiscales los maneja la agencia. |
| Familias y donantes | Hacen promesas desde la página de donación; nunca ven esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? La pestaña Donativos dice «Donations are switched off for this agency.» | Vaya a Recibos y configuración, marque Aceptar donaciones y pulse Guardar ajustes.
??? La lista de donativos está vacía. | Dice «No gifts yet.» Comparta la página de donación de una campaña, o pulse + Registrar un donativo para uno que ya recibió.
??? Emitir recibo da un acuse de recibo y no un recibo oficial. | La confirmación explica por qué («This gift can’t get an official receipt because …»). Casi siempre falta la dirección del donante o los datos de su organización están incompletos. Edite primero el donativo o la configuración.
??? Copy link no hizo nada. | Su navegador bloqueó la copia. El enlace aparece en un recuadro: selecciónelo y cópielo usted mismo.
??? ¿Una familia puede donar además de pagar las cuotas? | Sí. Las donaciones están completamente separadas de las facturas y las cuentas.
??? No somos una organización benéfica registrada. ¿Podemos recaudar fondos igual? | Sí. Deje en blanco el número de registro; los donantes reciben un acuse de recibo en lugar de un recibo fiscal.
??? ¿Quién recibe correos? | El donante recibe un correo de agradecimiento al hacer su promesa, y el correo con el recibo cuando usted pulsa Correo. La dirección indicada para nuevas promesas recibe aviso de cada promesa.

## Pantallas relacionadas

[[open: #expenses | Gastos]] [[open: #grants | Subvenciones]]

Vea también: [Proveedores de pago](payment-providers)
