---
title: Indicadores de funciones y planes
---
# Indicadores de funciones y planes

**Platform › Feature flags** es donde un administrador de la plataforma fija el plan de una agencia y activa o desactiva funciones sueltas solo para esa agencia. Úselo para dar a una agencia una función que su plan no incluye, para ocultar una función que todavía no debe usar, o para ver qué pantallas controla una función.

[[open: #admin-features | Abrir Feature flags]]

## Elegir la agencia

1. Abra **Platform › 🚩 Feature flags**. La pantalla se abre en la agencia en la que usted está (la del selector de agencias), no en la primera de la lista.
2. Revise la lista **Agency** arriba. Si muestra otra agencia, elija la correcta antes de cambiar nada: **Save changes** siempre guarda en la agencia elegida aquí.
3. Espere a que termine **Loading agency settings…**. Aparecen el plan, el aviso de región y todas las tarjetas de funciones de esa agencia.

> ⚠️ Mire el nombre en **Agency** antes de cada guardado. Los indicadores y los planes son por agencia, y un cambio se aplica de inmediato a todos en esa agencia.

## Cómo leer la página

- **Plan & billing**: la lista **Plan** (cada plan con su precio mensual) y **Billing status**. El estado de facturación es de solo lectura y dice **set by the licence**: sigue a la licencia de la agencia, así que cámbielo en la pantalla **Licences**, no aquí.
- Aviso **Platform**: la región de la agencia (país y provincia o estado) y sus datos. Si dice *Estimated from the time zone*, la agencia y sus centros aún no tienen provincia ni estado guardados.
- Mosaicos de lo incluido: lo que incluye toda licencia (por ejemplo soporte, Message Centre y One-Tap), cada uno marcado **Included** u **Off**.
- **Feature overrides**: una tarjeta por función, agrupadas y coloreadas por área. Cada tarjeta muestra el nombre, su código, el plan mínimo que la incluye (**plan ≥ …** o **add-on only**) y, en pequeñas etiquetas, las pantallas del menú que controla. *No menu item — gates the API only* significa que la función no tiene pantalla propia.
- Los contadores arriba a la derecha muestran cuántas funciones están **forced on**, **forced off** y **auto**.

## Cambiar una función para una agencia

1. Busque la tarjeta de la función en **Feature overrides**.
2. Pulse uno de sus tres botones:
   - **Auto**: seguir el plan (lo normal).
   - **✓ On**: forzarla encendida, aunque el plan no la incluya.
   - **✗ Off**: ocultar sus pantallas a todos en esta agencia y rechazar sus llamadas a la API.
3. La tarjeta se pone verde con **✓ On**, rosada con **✗ Off** y vuelve al color de su grupo con **Auto**. Todavía no se guarda nada.
4. Repita con las demás funciones.
5. Pulse **Save changes**. Aparece **Saving…** y luego **✓ Saved**, y la página se recarga con la nueva configuración.

[[show: #admin-features @ Save changes | Muéstreme]]

> 💡 ¿Cambió de opinión antes de guardar? Pulse **Cancel** para recargar la configuración guardada de la agencia.

## Cambiar el plan de la agencia

1. Elija el nuevo plan en la lista **Plan**.
2. Pulse **Save changes**.
3. Todas las funciones en **Auto** siguen ahora el nuevo plan. Las que usted forzó en **✓ On** o **✗ Off** conservan su ajuste.

> ℹ️ Los complementos pagados y la facturación de una agencia vienen de su licencia. Para una agencia que paga, cambie el plan o los complementos en **Licences** para que la factura coincida; use esta pantalla para las excepciones.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la plataforma | Ve **Feature flags** en el menú Platform y cambia planes y excepciones de cualquier agencia. |
| Administrador de agencia, director de centro | No ven esta pantalla. Ven lo que su plan permite; para más, usan los avisos de mejora o contactan a KiddieTrac. |
| Todos en la agencia | Simplemente ven aparecer o desaparecer las pantallas de una función, en su próxima carga de página. |

## Preguntas frecuentes y problemas

??? Cambié un indicador pero la gente de la agencia todavía ve la pantalla. | El menú se lee al cargar la página. Pídales que actualicen o vuelvan a iniciar sesión. Compruebe que guardó la agencia correcta en la lista Agency.
??? El Billing status no cambia. | Aquí es de solo lectura y sigue a la licencia. Cámbielo en la pantalla Licences.
??? Aparece un mensaje rojo en lugar de las tarjetas. | No se pudo cargar la lista de funciones o la agencia (por ejemplo "Agency not found" o "Forbidden"). Actualice; solo los administradores de la plataforma pueden abrir esta pantalla.
??? La lista Agency dice "No agencies found". | No se pudo listar ninguna agencia para usted. Actualice y compruebe que inició sesión como administrador de la plataforma.
??? ¿Qué hace una función en Off? | Sus pantallas salen del menú para todos en esa agencia y el servidor rechaza sus solicitudes. No se borra ningún dato: vuelva a ponerla en Auto u On y todo regresa.
??? ¿Alguien recibe un correo o una notificación? | No. Cambiar un plan o un indicador aquí no envía nada a la agencia.

## Pantallas relacionadas

[[open: #licences | Licencias]] [[open: #platform-agencies | Todas las agencias]] [[open: #admin-mrr | Panel MRR]] [[open: #sales-plans | Planes y precios]]
