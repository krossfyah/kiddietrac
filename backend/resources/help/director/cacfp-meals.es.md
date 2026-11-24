---
roles: agency_admin, platform_admin
title: Seguimiento de comidas CACFP
category: Compliance
order: 70
---
# Seguimiento de comidas CACFP

Registre las comidas servidas por niño y por día para las solicitudes de reembolso estatales o provinciales, bajo el equivalente **canadiense** del Child and Adult Care Food Program.

## Lista diaria (educador)

1. Barra lateral → **Comidas CACFP**.
2. Vea la lista de hoy: cada niño inscrito + 5 columnas de tipos de comida (desayuno / merienda de la mañana / almuerzo / merienda de la tarde / cena).
3. Toque cada casilla para registrar una comida como servida.
4. Los cambios se guardan al instante.

## Nivel de elegibilidad de la familia

Cada familia tiene un `cacfp_tier` de `free` / `reduced` / `paid` (lo define el administrador según los ingresos de la familia). El sistema etiqueta cada comida con el nivel para que el informe mensual desglose el recuento de comidas por nivel: son los datos que su agencia estatal o provincial necesita para el reembolso.

## Definir el nivel (administrador)

Edite el registro de una familia → defina `cacfp_tier` + `cacfp_eligibility_date`. Las certificaciones de elegibilidad deben renovarse cada año.

## Informe mensual

Barra lateral → Comidas CACFP → la parte superior de la página muestra:
- Comidas gratuitas servidas este mes: N
- Comidas de precio reducido servidas este mes: N
- Comidas de pago completo servidas este mes: N
- Total general: N

Exporte en PDF o XLSX para presentarlo ante su oficina estatal o provincial del CACFP.

## Buenas prácticas

- Marque las comidas en el momento del servicio (no al final del día): es más fácil ser preciso.
- Recertifique el nivel cada año con cada familia.
- Compare cada mes los recuentos de cocina con los de la lista.
