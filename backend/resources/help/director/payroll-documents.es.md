---
title: Ciclos de nómina, horas y recibos de nómina
category: Staff Management
order: 30
roles: agency_admin, platform_admin
---
# Ciclos de nómina, horas y recibos de nómina

Una sola pantalla responde a las dos mitades de la misma pregunta: qué trabajó esta persona y cuánto se le pagó por ello.

![Nómina: Educadores, Otro personal y Contratistas, cada uno con sus recibos de nómina](https://api.kiddietrac.com/help-img/payroll.png)

## Tres grupos

**Educadores**, **Otro personal** y **Contratistas** son pestañas separadas, no una lista larga. Se les paga en condiciones distintas y los aprueban personas distintas, y cada una lleva su propio subtotal. La pantalla se abre en la primera pestaña que tiene a alguien.

El **Total de horas** general de ambos grupos de personal permanece visible debajo.

## Los contratistas son diferentes, a propósito

Un contratista no registra su entrada. No tiene acceso de inicio de sesión y se le paga por nombre, así que no hay fichajes ni horas que informar: mostrarlo junto a personas con recuento de fichajes pondría una columna vacía y cero horas junto a dinero que sí se pagó.

Su pestaña informa en cambio **lo que se les pagó** en el periodo, y lo indica en la página.

## Alguien pagado sin fichar

El informe incluye a cualquier persona **pagada** en el periodo, no solo a quien **fichó**. Un visitador domiciliario pagado por visita, un gerente asalariado, cualquiera con un importe fijo: aparecen con 0 fichajes y 0 horas, lo cual es cierto en su caso, junto con sus recibos de nómina.

Sin esto, un mes en el que nadie fichó se mostraba como un informe vacío aunque existieran recibos de nómina para ese mes.

## Recibos de nómina en la fila

La columna **Recibos de nómina** muestra cuántos documentos tiene una persona en el periodo y a cuánto ascienden. Ábrala y cada documento aparece debajo con su propio menú **⋮**:

- **Recibo de nómina**: abre el PDF.
- **Imprimir**: lo abre y lo envía a la impresora.
- **Descargar**: guarda el PDF.
- **Enviar recibo de nómina por correo electrónico**: lo envía, con el PDF adjunto, a una dirección que usted confirma.
- **Enviar por Interac**: lo paga, si la persona tiene una cuenta (vea más abajo).
- **Marcar como pagado** / **Marcar como no pagado**: el registro manual, que alterna.

El envío por correo electrónico pide la dirección cada vez. Viene rellenada cuando hay una en el archivo y debe confirmarse: no existe un destinatario predeterminado para el pago de alguien.

## Pagar un recibo de nómina

**Enviar por Interac** mueve dinero real, por lo que primero pide confirmación y nombra el importe y la persona:

> ¿Enviar $1,424.90 por Interac a Bruni Meeser? Esto mueve dinero real.

El pago queda vinculado al recibo de nómina que liquida, de modo que el registro muestra qué se pagó y por qué.

**A los contratistas no se les puede pagar de este modo.** Interac envía a la cuenta de una persona, y un contratista es un nombre y no una cuenta. Sus recibos de nómina se marcan como pagados a mano; todo lo demás del menú funciona con normalidad.

## Generar la nómina

**＋ Generar nómina** abre el asistente del ciclo: elija el periodo, marque a las personas y las horas se calculan a partir de sus fichajes cerrados. Añada conceptos y notas, apruebe, y se crean los recibos de nómina.

Solo cuentan los fichajes **cerrados**. Quien sigue con la entrada registrada aparece nombrado en su fila para que vea qué se dejó fuera.

El ciclo tiene las mismas tres pestañas: **Educadores**, **Otro personal** y **Contratistas**. La pestaña **Contratistas** lista los beneficiarios a quienes su agencia ya ha pagado antes. No tienen reloj, así que solo se introduce un importe. El **Conceptos y notas** de cada fila contiene conceptos (kilometraje, un complemento, una deducción) y una nota para el recibo de nómina. **Aprobar y crear recibos de nómina** los crea.

Cuando marca a alguien y escribe su tarifa por hora, **Pago** se rellena como horas × tarifa como punto de partida. Puede sobrescribirlo. A quien **ya tiene un recibo de nómina manual para el mismo periodo se le omite**, y se le indica cuántas filas se omitieron y por qué, de modo que un ciclo no pueda pagar dos veces a la misma persona.

**La tarifa nunca se rellena por usted.** Empieza vacía y alguien la escribe, en cada ciclo. Las cifras históricas no distinguen una tarifa por hora del pago de un periodo completo: los datos indican «horas» junto a importes que claramente no son por hora, y multiplicar por el valor equivocado produce un recibo de nómina que nadie quería aprobar.

## La exportación CSV

**⤓ CSV** exporta el informe de horas del periodo. Es un archivo de horas, así que lista a las personas que ficharon; los recibos de nómina de quienes no tienen fichajes están en la pantalla y no en el archivo.

## De dónde salen las horas

Cada hora de esta pantalla fue un fichaje en el reloj de control. **Hojas de horas** es donde se leen y corrigen esos fichajes, con un **⋮** por turno, y es el lugar para resolver un turno abierto o ausente *antes* de un ciclo de nómina y no después de que existan los recibos de nómina.
Vea [Hojas de horas y corrección de horas](timesheets).
