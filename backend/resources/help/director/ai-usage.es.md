---
title: Uso de IA, créditos y conexión con Claude
---
# Uso de IA, créditos y conexión con Claude

**Platform › AI usage** muestra cada llamada de IA que hace KiddieTrac y lo que cuesta, una estimación del crédito de IA restante, la conexión con Claude (clave y modelo) y las agencias con la IA desactivada. Ábralo para revisar el gasto, saber qué función, agencia o persona usa más la IA, registrar una recarga de crédito, o cambiar la clave o el modelo.

[[open: #ai-usage | Abrir AI usage]]

La pantalla tiene cinco pestañas: **📊 Overview**, **🧩 Breakdown**, **💳 Credits**, **🤖 Connection** y **🚫 Switched off**. Se vuelve a abrir en la última pestaña que usó.

## Ver el estado de un vistazo

1. Abra **Platform › 🤖 AI usage** y pulse **📊 Overview**.
2. Lea la etiqueta de estado: **✅ Connected**, **⛔ No key set**, **⛔ Out of credit**, **⚠️** con el último error, o **Not used yet**. Al lado: el modelo, cuánto crédito queda aproximadamente (y para cuántos días) y cuántas agencias están desactivadas.
3. Lea los cuatro mosaicos **Today**, **Last 7 days**, **Last 30 days** y **This month**: costo estimado, solicitudes y tokens de entrada y salida.
4. Mire **📈 Estimated cost per day** de los últimos 30 días. Pase el cursor sobre una barra para ver el costo y las solicitudes de ese día.

## Saber quién y qué usa la IA

1. Pulse **🧩 Breakdown**.
2. Elija un **Period** (**Last 7 days**, **Last 30 days**, **This month**, **Last month**, **Last 90 days**) o escriba las fechas **From** y **To** para **Custom dates**.
3. Lea los mosaicos: **Estimated cost**, **AI requests** (cuántas fueron automáticas), **Tokens** y **People using AI**.
4. Elija una vista: **🧩 By feature**, **🏢 By agency**, **🧠 By model** o **👤 By user**. Cada una es una tabla normal: búsquela, ordénela por cualquier columna y expórtela a CSV, Excel o PDF.
5. En **👤 By user**, pulse el nombre de una persona para ver sus **Recent calls**: qué lo pidió, cuándo, el modelo, los tokens y el costo. Pulse **Close** al terminar.

> ℹ️ Las llamadas sin una persona conectada (resúmenes nocturnos, el chatbot del sitio web) aparecen como **Automatic** ("System"). Una tarea en segundo plano cuenta para la persona que la inició. Las preguntas y respuestas nunca se guardan; solo qué lo pidió, cuándo, el modelo, el tamaño y el costo.

## Registrar una recarga de crédito

Anthropic no comparte el saldo prepagado con una clave de API, así que el crédito restante es una **estimación**: las recargas que usted registra menos lo que KiddieTrac gastó desde entonces.

1. Pulse **💳 Credits**.
2. Escriba el monto en **Credits added ($)**.
3. Revise la fecha en **On** y agregue una **Note** si quiere.
4. Pulse **+ Record top-up**. Aparece el aviso **Top-up recorded** y la estimación se actualiza.
5. Para quitar una entrada equivocada, pulse **Remove** a su lado.

> 💡 De vez en cuando revise el saldo exacto en la consola de Anthropic (el enlace está en la pestaña) y registre cada recarga el día que la compra.

## Cambiar la clave o el modelo

1. Pulse **🤖 Connection**. Verá los últimos 4 caracteres de la clave, dónde está configurada, el modelo y la última respuesta o error.
2. Elija un **Model** y, solo si va a reemplazar la clave, péguela en **New API key** (déjela vacía para conservar la actual).
3. Pulse **Save**. El botón muestra **Testing…**: la nueva clave o modelo se prueba primero con Anthropic y solo se guarda si funciona (**Saved and tested**).
4. Pulse **Test connection** cuando quiera para comprobar que la clave actual sigue respondiendo.

## Desactivar la IA para una agencia

1. Pulse **🚫 Switched off**.
2. Marque cada agencia que no debe usar crédito de IA (agencias de prueba y de demostración, por ejemplo).
3. Pulse **Save**.

Las agencias marcadas pierden las funciones del AI Bundle, y "Ask AI" de la ayuda, el chatbot y la lectura de tarjetas funcionan como si la IA no estuviera disponible.

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Todo en esta pantalla. |
| Administradores de agencia y todos los demás | Sin acceso. Las agencias solo ven sus funciones de IA, nunca costos ni claves. |

## Preguntas frecuentes y problemas

??? La etiqueta dice "Out of credit". | La última llamada falló porque la cuenta de Anthropic no tiene crédito. Compre crédito en la consola de Anthropic y luego registre la recarga en Credits.
??? "Nothing to change" al pulsar Save. | La casilla de la clave está vacía y el modelo es el mismo. Elija otro modelo o pegue una clave nueva.
??? "Not saved" u otro mensaje rojo después de Save. | Anthropic rechazó la nueva clave o modelo, así que se conserva el anterior. Compruebe que copió la clave completa.
??? "Enter the amount of credits added". | Escriba un número mayor que cero en Credits added ($) antes de pulsar + Record top-up.
??? Un modelo dice "flat rate: not on the price list". | Ese modelo aún no tiene precio registrado, así que su costo usa las tarifas de IA de Usage & spend.
??? "No AI calls in this period." | Nada usó IA en las fechas elegidas. Amplíe el Period.
??? ¿Se avisa a alguien? | No. Los cambios aquí no envían correos ni notificaciones.

## Pantallas relacionadas

[[open: #usage-spend | Uso y gasto]] [[open: #admin-features | Indicadores de funciones]] [[open: #server-health | Estado del servidor]]
