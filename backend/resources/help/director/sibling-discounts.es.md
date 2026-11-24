---
title: Niveles de descuento para hermanos
category: Billing
order: 25
roles: agency_admin, platform_admin
---
# Niveles de descuento para hermanos

Cuando una familia tiene más de un niño inscrito, lo habitual es cobrar al segundo y a los siguientes con descuento. Kiddietrac lo automatiza en el lote mensual de facturas: no hace falta calcular nada por familia.

## Cómo funcionan los descuentos para hermanos

El niño inscrito **hace más tiempo** en una familia es el rango 1: siempre paga la matrícula completa. El segundo más antiguo es el rango 2, el tercero es el rango 3, y así sucesivamente. Cada rango superior a 1 recibe el nivel de descuento coincidente más alto que haya configurado.

Ejemplo de tabla de niveles:
- Rango 2 -> 10 % de descuento
- Rango 3 -> 15 % de descuento
- Rango 4 -> 20 % de descuento

Una familia con 4 niños inscritos vería:
- Niño 1: precio completo
- Niño 2: 10 % de descuento (coincide con el nivel rango=2)
- Niño 3: 15 % de descuento (coincide con el nivel rango=3)
- Niño 4: 20 % de descuento (coincide con el nivel rango=4)

Una familia con 5 niños: el niño 5 también recibe 20 % de descuento: el nivel más alto definido se extiende a los rangos superiores.

## Configurar los niveles

Barra lateral → **Ajustes → Descuentos para hermanos**. El editor muestra los niveles actuales como filas: rango + porcentaje + botón Quitar.

Haga clic en **+ Añadir nivel** para agregar una fila. La sugerencia por defecto es el siguiente rango disponible con 10 %. Edite el rango y el porcentaje según sus necesidades.

Haga clic en **Guardar** para confirmar. Los cambios se aplican al SIGUIENTE lote de facturas: las facturas ya generadas no se ajustan con efecto retroactivo.

Configuraciones habituales:
- Conservadora: rango 2 -> 5 %, rango 3 -> 10 %
- Estándar: rango 2 -> 10 %, rango 3 -> 15 %, rango 4 -> 20 %
- Agresiva: rango 2 -> 15 %, rango 3 -> 25 %, rango 4 -> 35 %

## Desactivar los descuentos

Quite todas las filas de niveles y haga clic en Guardar con una lista vacía. El sistema registra los descuentos como desactivados: los lotes de facturas posteriores cobran la matrícula completa de cada niño.

## En la factura

Cuando se aplican descuentos, verá una línea por cada niño con descuento en el PDF de la factura:

```
Tuition - Sienna Patel (Sunflower Room) ............. $1,400.00
Tuition - Theo Patel (Sunflower Room) ............... $1,400.00
Sibling discount (10%) - Theo ....................... -$140.00
```

El `discount_amount` del encabezado de la factura refleja la suma de todas las líneas de descuento, y el `total` muestra el importe a pagar ya con descuento.

## Ajuste por agencia

Cada agencia configura sus propios niveles de forma independiente. Si cambia de agencia con el selector y actualiza los niveles, solo se ve afectado el cálculo de facturas de esa agencia.
