---
roles: agency_admin, platform_admin
title: Widgets del resumen de la plataforma
category: Administration
order: 90
---
# Widgets del resumen de la plataforma

La pantalla **Resumen de la plataforma** (visible solo para los admins de la plataforma) muestra un panel ejecutivo estilo SaaS con siete tarjetas de KPI, dos gráficos de tendencia, dos tarjetas de análisis, una octava tarjeta con la métrica de sesiones y una sección **Métricas del negocio** en tema oscuro.

## Tarjetas de KPI

Agencias · Centros · Niños · Familias · Personal · MRR · Sesiones (activas en las últimas 24 h).

Cada tarjeta tiene una franja de acento a la izquierda con un color según la categoría, y una línea de pista debajo del número.

## Gráficos de tendencia

**Tendencia del MRR** — historial de seis meses. Gráfico de barras en escala de verdes, con etiquetas en dólares sobre cada barra.

**Crecimiento de la agencia** — historial de seis meses de altas frente a cancelaciones. Cada barra se colorea según la dirección neta: morado = mes neto positivo, ámbar = hubo alguna cancelación, rojo = mes neto negativo.

## Principales agencias + actividad reciente

**Principales agencias por matrícula** — los cinco inquilinos más grandes por número de niños, con muestras del color de la marca y barras de proporción respecto al líder.

**Actividad reciente de la plataforma** — las últimas 10 filas del registro de auditoría unidas a los usuarios, de modo que cada evento muestra quién hizo qué y cuándo, con iconos emoji por tipo de acción.

## Sección Métricas del negocio

Franja en tema oscuro en la parte inferior con ocho tarjetas:

- **ARR** — ingresos recurrentes anualizados
- **ARPA** — ingreso medio por agencia activa
- **ARPU** — por niño matriculado
- **Crecimiento del MRR %** — mes a mes, con la variación absoluta en dólares
- **Cancelación %** (30 d) — canceladas / recuento inicial
- **LTV** — valor de vida estimado
- **NRR (6 m)** — MRR actual / MRR de hace seis meses
- **Capacidad %** — total de matriculados / total de plazas autorizadas

Todos los datos se derivan de agregados ya cargados para los gráficos de tendencia (sin SQL adicional).

## Acceso

Visible solo para los usuarios con el rol `platform_admin`. Los administradores de agencia ven su propio panel limitado a su agencia en `#dashboard`.
