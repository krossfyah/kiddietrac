---
roles: agency_admin, platform_admin, centre_director
title: Puntos y recompensas para educadores (con tarjetas de regalo de Giftbit y Tango Card)
category: Staff Management
order: 20
---
# Puntos y recompensas para educadores

Los educadores ganan **puntos** cada mes por usar KiddieTrac. Al final del mes puede convertir
esos puntos en una recompensa: un **bono en la próxima nómina**, un **pago** o una **tarjeta de regalo**.

Abra **⭐ Recompensas para educadores** en el menú.

## Cómo se ganan los puntos

Los puntos provienen de lo que cada persona registró en KiddieTrac. Nadie tiene que llevar la cuenta.

| Actividad | Puntos | Límite diario |
|---|---|---|
| Observaciones | 10 | 10 |
| Planes de clase | 10 | 3 |
| Paseos y salidas | 5 | 4 |
| Informes de incidentes | 5 | 5 |
| Fotos y videos compartidos | 3 | 10 |
| Registros diarios | 1 | 40 |

Estos son los valores iniciales. Un **administrador de la agencia** puede cambiarlos en **Ajustes → 🏅 Reconocimientos a educadores → ⚙️ Valores de puntos y recompensas**,
junto con los límites diarios (superado el límite, una actividad sigue contando, pero deja de sumar puntos
ese día) y una **tasa de puntos a dólares** opcional que sugiere un importe para cada reconocimiento.

Los educadores, los visitadores a domicilio y los directores ganan puntos. Los educadores ven sus propios puntos, qué los
generó y una tabla de clasificación de puntos de su centro en **⭐ Mis puntos**. Nunca se muestra dinero
en la tabla de clasificación.

## Dar una recompensa

1. Elija el **mes** en la parte superior de **Puntos y reconocimientos**.
2. Haga clic en **🎁 Reconocer** en la fila de una persona.
3. Introduzca el importe y elija cómo:
   - **Bono en la próxima nómina.** Se añade como una línea en su recibo de nómina la próxima vez que
     procese su nómina, y se marca como pagado en ese momento. Puede cancelarlo hasta que eso ocurra.
   - **Pago.** Se paga fuera de KiddieTrac (transferencia electrónica, cheque). Añada una referencia si lo desea.
   - **Tarjeta de regalo que usted compró.** Pegue el código o el enlace de la tarjeta. Solo el educador puede verlo,
     en Mis puntos.
   - **Enviar una tarjeta de regalo mediante Giftbit o Tango Card.** Vea más abajo.
4. Añada una nota si lo desea y haga clic en **Dar recompensa**. El educador recibe una notificación y un
   breve correo electrónico. Una tarjeta de regalo nunca se incluye en el correo; lo espera en la aplicación.

Un **director** recompensa al personal de sus propios centros. Las recompensas para directores las otorga un
administrador de la agencia.

## Tarjetas de regalo con Giftbit

KiddieTrac se integra con **Giftbit** para enviar tarjetas de regalo digitales (Amazon, Tim Hortons y
cientos de marcas más) directamente desde el cuadro de diálogo de reconocimiento.

**Cada agencia usa su propia cuenta de Giftbit** y paga sus propias tarjetas.

1. Cree una cuenta de empresa en **giftbit.com**. Empiece con una cuenta **testbed**
   (gratuita, sin tarjetas reales) para probar.
2. En Giftbit, vaya a **Account → API keys** y cree un token.
3. En KiddieTrac, un administrador de la agencia abre **Ajustes → 💳 Proveedores de pago → 🎁 Tarjetas de regalo**,
   elige **Testbed** o **En vivo**, pega el token, marca **Usar Giftbit para las recompensas de
   educadores** y hace clic en **Guardar**. **Probar conexión** confirma el token y muestra su saldo.
4. Para tarjetas reales, **añada fondos** en su cuenta de Giftbit.

Después, en el cuadro de diálogo de reconocimiento, elija **Enviar una tarjeta de regalo de Giftbit**, seleccione una marca (o **Dejar que elijan**,
para que el educador elija al abrirla) e introduzca un importe que la marca permita.
KiddieTrac comprueba el importe con la marca y con su saldo de Giftbit antes de hacer el pedido.

- **En preparación** significa que Giftbit aún está creando la tarjeta. Aparecerá en Mis
  puntos del educador en breve. **Comprobar estado de la tarjeta de regalo** consulta a Giftbit en ese momento.
- **Esperando fondos de Giftbit** significa que su saldo fue insuficiente. Añada fondos en Giftbit y la tarjeta
  se envía automáticamente.
- **Cancelar tarjeta de regalo** funciona hasta que el educador la canjee. El valor vuelve a su
  saldo de Giftbit.

El token se almacena cifrado y nunca vuelve a mostrarse completo.

## Tarjetas de regalo con Tango Card

KiddieTrac también se integra con **Tango Card**. Funciona de la misma manera, desde la propia cuenta
de Tango de su agencia:

1. Obtenga una plataforma de Tango (póngase en contacto con Tango Card para abrir una cuenta). Recibirá un **entorno de pruebas** para probar
   (sin tarjetas reales) y una plataforma de producción.
2. En el portal de Tango, abra **API keys → Create credentials** para obtener el **nombre de la plataforma** y la
   **clave de API**. Anote el **identificador de cliente** y el **identificador de cuenta** que pagarán
   las tarjetas.
3. En KiddieTrac: **Ajustes → 💳 Proveedores de pago → 🎁 Tarjetas de regalo**, cuadro de Tango Card: elija **Entorno de pruebas** o
   **Producción**, complete los cuatro campos, marque **Usar Tango Card para las recompensas de educadores** y
   **Guardar**. **Probar conexión** muestra la cuenta y su saldo.
4. Añada fondos a la cuenta en Tango.

En el cuadro de diálogo de reconocimiento, si Giftbit y Tango Card están conectados, elija por cuál **enviar**
y luego seleccione la tarjeta. Las tarjetas de Tango son una tarjeta concreta (sin «Dejar que elijan»), y el
importe debe ser adecuado para ella. La tarjeta (un enlace, o un número de tarjeta y un PIN) aparece solo en Mis puntos
del educador, con las instrucciones de uso de Tango.

**Cancelar tarjeta de regalo** funciona con Tango solo dentro de las reglas de Tango: un enlace de elección sin canjear, dentro de
los 5 días posteriores al envío. De lo contrario, KiddieTrac muestra el motivo indicado por Tango.

> KiddieTrac no está afiliado a Giftbit ni a Tango Card. Los términos de las tarjetas de regalo los fija cada marca.

## Historial de reconocimientos

**⭐ Recompensas para educadores → 🗂 Historial de reconocimientos** muestra todos los reconocimientos otorgados, mes a mes (los más recientes primero): quién, qué, los puntos y el importe en ese momento, el método, quién lo aprobó, la fecha y si fue automático. Filtre por meses, centro y educador, y expórtelo con los botones CSV, Excel o PDF bajo la tabla. Los directores ven sus propios centros.

## Dónde están los ajustes

Todos los ajustes de reconocimientos a educadores están en **Ajustes → 🏅 Reconocimientos a educadores**: los reconocimientos automáticos de agradecimiento (y los borradores pendientes de revisión), los valores de puntos y el reconocimiento sugerido, y el método con el que se abre el cuadro de diálogo. Los directores pueden consultarlos; los administradores de la agencia los cambian.

Las cuentas de tarjetas de regalo (Giftbit y Tango Card, testbed/entorno de pruebas o en vivo) están junto con las demás cuentas de pago en **Ajustes → 💳 Proveedores de pago → 🎁 Tarjetas de regalo** (administradores de la agencia).
