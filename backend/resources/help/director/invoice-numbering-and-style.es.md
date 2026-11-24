---
title: Estilo y numeración de facturas
category: Billing
order: 16
roles: agency_admin, platform_admin
---
# Estilo y numeración de facturas

**Marca → 🧾 Facturas.** Aquí elige cómo se ven sus facturas y cómo se numeran. Está junto a su logotipo y el color de su marca, porque para una familia la factura es su marca.

[[open: #admin-branding?tab=invoices | Abrir Marca → Facturas]]

**Cómo llegar:** los administradores de la agencia van a **Resumen de la agencia → ✏️ Editar agencia → Abrir Marca →**. Los administradores de la plataforma también pueden usar **Distribuidor → Marca**. En ambos casos, la pantalla trabaja con la agencia que está viendo. Marca se abre en su primera pestaña; elija **🧾 Facturas** en la fila de pestañas de arriba.

## Estilo de factura

Elija un estilo en la lista **Estilo de factura**. Debajo de la lista aparece una breve descripción del estilo, y la **Vista previa de factura en vivo** de la derecha cambia a ese estilo al instante, para que lo vea **antes** de guardar.

Hay doce estilos:

- **KiddieTrac (predeterminado)**: la factura estándar de KiddieTrac.
- **iLearn**: el diseño de iLearn, para agencias cuyas familias ya reciben ese documento.
- **Classic Navy**, **Minimal Mono**, **Modern Teal**, **Bold Slate**, **Warm Sand**, **Sunrise**, **Forest**, **Plum Rail**, **Compact Receipt** y **Corporate Grey**.

Son diseños distintos, no un mismo diseño en diferentes colores. Todos los estilos siguen llevando **su propio logotipo, los datos de su negocio y su dirección de soporte**: el estilo decide la forma del documento, y la marca que contiene sigue siendo suya.

Haga clic en **Guardar cambios** para aplicarlo. El botón guarda juntas las pestañas **Marca blanca y logotipo**, **Facturas** y **Contacto y aviso legal**.

> **Importante:** El estilo se aplica cuando una factura se **dibuja**, no cuando se emite. Después de cambiarlo, todas las facturas, pasadas y futuras, usan el nuevo estilo la próxima vez que se vean, descarguen o envíen por correo. Las copias que ya están en la bandeja de entrada de una familia no cambian. Por eso, una copia de una factura antigua que vuelva a enviar no coincidirá con la que la familia recibió al principio.

## Numeración de facturas

Puede definir el formato de sus números de factura y desde dónde empieza un número correlativo. Elija uno de los ajustes predefinidos en **Empezar desde un ajuste predefinido…**, o escriba su propio formato en el cuadro.

Un formato es texto normal con marcadores entre llaves. Todo lo que no sea un marcador se imprime exactamente como se escribió, así que `ILH-{YYYY}-{SEQ}` es un formato válido.

| Marcador | Se convierte en |
|---|---|
| `{YYYY}` | el año, p. ej. 2026 |
| `{YY}` | el año corto, p. ej. 26 |
| `{MM}` | el mes, p. ej. 10 |
| `{DD}` | el día |
| `{FAMILY}` | el número de cuenta de la familia, cuatro dígitos |
| `{N}` | el número de cuota dentro de un calendario o plan, dos dígitos |
| `{SEQ}` | un número correlativo para su agencia |

**La próxima factura se verá así** muestra un ejemplo construido con su formato mientras escribe. Lo produce el mismo código que numera las facturas reales, así que coincide con lo que obtendrá.

**Las fechas son las del periodo facturado, no el día en que se envía la factura.** Una cuota con vencimiento en diciembre es una factura de diciembre, aunque se emita a finales de noviembre.

### El número correlativo, {SEQ}

- **Empezar la numeración en** define el primer valor de `{SEQ}`. El valor predeterminado es **1001**.
- Se conserva el número de dígitos con el que empieza, sin añadir ceros por delante. Si empieza en 1001, el siguiente es 1002. Si empieza en 5000, el primero es 5000.
- Puede **subir** el inicio más adelante para adelantar el contador. **Nunca puede bajarlo**: eso reemitiría números que ya llevan facturas reales.
- El contador solo se usa si su formato incluye `{SEQ}`, así que un formato de fecha y familia no gasta números.

### Conviene saber

- `{FAMILY}` es un número de cuenta interno. **No** es el ID de referencia de la familia (como ILH-F-0017), y no ha cambiado. Todos los estilos de factura también imprimen el ID de referencia de la familia junto al nombre del destinatario, así que no lo necesita en el número de factura. Consulte [ID de referencia](reference-ids).
- El formato predeterminado es `INV-{YYYY}{MM}-{FAMILY}-{N}`, por ejemplo **INV-202610-0081-01**. Si nunca cambia el ajuste, sus números se ven exactamente como siempre.
- Un formato nuevo se aplica solo a las facturas **nuevas**. Las facturas existentes conservan los números con que se emitieron.
- Dos facturas no pueden compartir un número. Si un formato produce un número que ya existe, se añade un sufijo como **-2**.
- Un marcador mal escrito, como `{MONTH}`, se elimina en lugar de imprimirse en la factura de una familia. Revise el ejemplo antes de guardar.
- Las facturas sincronizadas desde un sistema de facturación externo conservan los números propios de ese sistema.

## Preguntas frecuentes

??? ¿Qué facturas usan mi numeración? | Las facturas creadas en KiddieTrac: lotes mensuales, cuotas de calendarios de pago y facturas de planes de tarifas.
??? ¿Por qué se ignora mi nuevo número inicial? | El contador nunca retrocede. Si ya superó el número que escribió, continúa desde donde está.
??? ¿Puedo previsualizar un estilo sin cambiarlo para todos? | Sí. Al elegir un estilo, la vista previa se actualiza al instante. No cambia nada hasta que haga clic en Guardar cambios.

Véase también: [Marca blanca, marca y facturas](white-label-branding) · [ID de referencia](reference-ids) · [Configuración y ajustes de facturación](billing-setup) · [Calendarios de pago](payment-plans)
