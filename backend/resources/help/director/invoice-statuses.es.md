---
title: Qué significa el estado de una factura
category: Billing
order: 41
roles: agency_admin, centre_director, platform_admin
---
# Qué significa el estado de una factura

![Facturas, con las de fecha futura marcadas como Programada](https://api.kiddietrac.com/help-img/invoices-scheduled.png)

Contabilidad y los planes de pago muestran la misma etiqueta de color con la misma palabra, de modo que una factura se lee igual en ambas pantallas.

| Estado | Significado |
|---|---|
| **Pendiente** | Aún no emitida. Factura de un plan de pago que espera su fecha de emisión. Nadie la debe todavía. |
| **Programada** | Emitida, sin pagar y **aún no vencida**. No se ha omitido nada. |
| **Emitida** | Emitida, vence ahora o ya pasó su fecha, y sin pagar. |
| **Vencida** | Pasó su fecha de vencimiento. |
| **Pago parcial** | Se ha recibido una parte del dinero. |
| **Pagada** | Saldada. |
| **Reembolsada** | Se ha devuelto el dinero. |
| **Anulada** | Cancelada. No tiene saldo. |

## Por qué existe Programada

En cualquier momento, la mayoría de las facturas sin pagar simplemente aún no han vencido. Llamarlas «abiertas» oculta las que realmente hay que reclamar: en esta plataforma, 159 de 166 facturas abiertas tenían fecha futura, así que una lista de 166 elementos eran en realidad 7.

**Programada** dice lo que es verdad: está en el calendario y nadie ha dejado de pagar nada.

## Cómo se calcula

No se almacena. Una factura vence por el paso del tiempo, así que el estado se calcula **cuando se dibuja la pantalla**, comparando la fecha de vencimiento con hoy en la zona horaria de su agencia. Nunca puede quedar desactualizado y no hay ningún proceso nocturno que pueda fallar.

Tres estados nunca se renombran a propósito:

- **Pago parcial**: ya se ha recibido dinero; ese hecho pesa más que el calendario.
- **Pendiente**: no se ha emitido en absoluto, algo distinto de «aún no vencida».
- **Vencida**: por definición, pasó su fecha de vencimiento.

Una factura que vence **hoy** sigue como Emitida. Vence, pero no está atrasada.

## Qué no cambia

Solo cambia la palabra. Los totales pendientes siguen incluyendo el dinero programado, porque aún se debe tarde o temprano; el libro mayor muestra **Vencida** por separado cuando solo quiere ver lo atrasado. Las facturas **Pendientes** son la excepción: no se han emitido, así que no se cuentan como saldo pendiente en ningún sitio.

Vea también: [Planes de pago](payment-plans) · [Contabilidad](accounting) · [Libros mayores de cuenta](account-ledgers)
