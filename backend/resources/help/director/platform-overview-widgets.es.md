---
title: Resumen de la plataforma
---
# Resumen de la plataforma

**Platform › 🌐 Platform overview** es el panel de todas las agencias para los administradores de la plataforma KiddieTrac: cada centro, cada familia y cada dólar de toda la plataforma en una sola página. Ábralo al empezar el día para ver si el negocio crece, si el correo y el sistema están bien y si algo falló. Solo muestra totales y eventos recientes; los cambios se hacen en las otras pantallas de **Platform**.

[[open: #platform-overview | Abrir resumen de la plataforma]]

## Hacer la revisión diaria

1. Abra **Platform › 🌐 Platform overview**. El encabezado dice **Cross-agency overview**.
2. Lea los mosaicos de arriba: **Agencies** (activas), **Centres**, **Children** (inscritos), **Families**, **Staff** (usuarios activos), **MRR** (ingreso mensual recurrente) y **Sessions** (activas en las últimas 24 horas).
3. Revise **Recent 30 days**: **New agencies**, **Cancellations** y **Net change**.
4. Baje hasta **Platform performance** y busque lo que esté en rojo: **Failed sends** por encima del 2 % de los correos, **API status** en **Down**, o agencias **Dormant**.
5. Abajo, revise **💥 Crash reports**. **✓ No server errors recorded. All clear.** significa que nada falló; si no, cada fila muestra **When**, **Who** y **Error**.

## Leer los gráficos de crecimiento

1. **MRR trend** muestra los últimos seis meses de ingreso mensual recurrente en dólares canadienses, con el monto sobre cada barra.
2. **Agency growth** muestra altas frente a cancelaciones en seis meses. Morado = **Net positive month**, ámbar = **Had cancellations**, rojo = **Net negative**.
3. **Top agencies by enrolment** lista las cinco agencias más grandes por niños inscritos, con una barra frente a la mayor.
4. **Recent platform activity** muestra los últimos 10 eventos en todas las agencias: qué se hizo, quién y cuándo.

## Leer las métricas del negocio

La franja **📊 Business metrics** se calcula a partir del MRR actual y su tendencia:

| Mosaico | Significado |
|---|---|
| **ARR** | Ingreso recurrente anualizado |
| **ARPA** | Ingreso promedio por agencia activa, al mes |
| **ARPU** | Ingreso por niño inscrito, al mes |
| **MRR growth** | Cambio frente al mes pasado, en % y en dólares |
| **Churn (30d)** | Agencias perdidas frente a las del inicio; rojo desde 5 % |
| **LTV** | Valor estimado de vida por agencia |
| **NRR (6m)** | MRR de hoy frente al de hace seis meses; más de 100 % significa que las agencias actuales gastan más |
| **Capacity** | Niños inscritos frente a lugares con licencia |

## Leer el rendimiento de la plataforma

- **Operational health**: **Email delivered** y **Open rate** (últimos 7 días), **Failed sends**, **Suppressed** (retenidos por el interruptor de emergencia del correo), tamaño de la **Database** y **API status**.
- **Usage & engagement**: **Logins today**, **Logins (7d)**, **Active users** (24 horas), **Active agencies** (30 días, del total) y **Dormant** (sin actividad en 30 días).

> 💡 Una agencia inactiva muchas veces dejó de usar KiddieTrac sin cancelar. Vale la pena una llamada del equipo de ventas.

## Quién puede hacer qué

- **Administradores de la plataforma**: ven esta pantalla. Abarca todas las agencias.
- **Administradores de agencia y directores de centro**: nunca ven cifras de todas las agencias. Usan su propio **Dashboard**, que solo cubre su agencia.

## Preguntas frecuentes y problemas

??? Aparece «Could not load» bajo el encabezado. | Se cortó la conexión o terminó su sesión. Actualice la página.
??? El MRR aquí es distinto al del panel de MRR o de los informes de ventas. | Cada pantalla mide en un momento y un periodo diferentes. Este mosaico es el total mensual recurrente actual; los informes de ventas cuentan lo facturado en el periodo que elija.
??? Crash reports muestra un error. | Anote quién y qué fue, y revise **Server health**. Un error después de una actualización suele ser inofensivo; si se repite, hay que corregirlo.
??? Failed sends está en rojo. | Más del 2 % de los correos fallaron en los últimos 7 días. Abra **Mail settings** y el registro de errores de correo para ver por qué.
??? ¿Por qué algunas cifras están en cero? | Una plataforma nueva o un periodo tranquilo. Los totales se llenan a medida que las agencias usan KiddieTrac.

## Pantallas relacionadas

[[open: #platform-agencies | Todas las agencias]] · [[open: #server-health | Estado del servidor]] · [[open: #admin-mrr | Panel de MRR]] · [[open: #platform-sales | Informes de ventas]] · [[open: #usage-spend | Uso y gasto]]

Vea también: [[platform-admin-overview]] · [[platform-sales-reports]]
