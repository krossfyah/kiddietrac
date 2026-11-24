---
title: Widgets del panel según el rol
category: Getting Started
order: 12
---
# Widgets de rol en su panel

Al cargar el panel, aparece una fila de tarjetas de indicadores en la parte superior de la vista de inicio de su rol (justo debajo del banner). Las tarjetas dependen del rol: muestran las cifras más relevantes para lo que usted hace día a día.

## Qué ve cada rol

**Tutor / padre (4 tarjetas):**
- Estado de entrada de hoy (dentro / fuera / aún no)
- Saldo pendiente de la familia
- Observaciones compartidas con usted esta semana
- Número de niños registrados

**Educador (4 tarjetas):**
- Niños presentes ahora / total de inscritos en su centro
- Medicamentos administrados hoy
- Observaciones que registró esta semana
- Total de inscritos en su centro

**Director de centro (4 tarjetas):**
- % de capacidad hoy (con colores: verde / ámbar / rojo)
- Importe de facturas abiertas + cantidad
- Niños que no han entrado a las 9:30 AM (se oculta antes de la hora límite)
- Total de inscritos

**Administrador de agencia / administrador de la plataforma**: ya tiene un panel más completo para toda la agencia, así que los widgets de rol se omiten en silencio.

## Cómo se actualizan

Las tarjetas vuelven a consultar `/widgets/me` cada vez que carga un hash de tipo panel (`#dashboard`, `#today`, etc.). No se actualizan solas mientras permanece en la página: actualice el navegador para obtener cifras recientes.

## Por qué algunas tarjetas dicen «—»

Agencia vacía, sin niños inscritos o sin asignaciones de rol. Las tarjetas siempre se muestran; el guion de relleno significa «sin datos, no es un error».
