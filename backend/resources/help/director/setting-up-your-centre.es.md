---
title: Configurar su centro
---
# Configurar su centro

**Centros** es donde un administrador de la agencia agrega y mantiene cada ubicación de la agencia: un centro, un proveedor de cuidado en el hogar o una sala, según como los llame su agencia. Cada centro guarda su dirección, proveedor (titular), capacidad, horario, marca, quiosco y ajustes de correo. Ábralo desde la barra lateral: **Resumen > Centros**.

[[open: #admin-centres | Abrir Centros]]

## Agregar un centro

1. Abra **Centros** y pulse **+ Añadir centro** (arriba a la derecha). Se abre el formulario **Nuevo centro**.
2. Escriba el **Nombre del centro**, por ejemplo "Starlight Learning Centre".
3. Complete **Nombre del proveedor (titular)** y **Apellido del proveedor (titular)**. Es la persona que dirige el hogar o el centro, no su visitador domiciliario.
4. Si lo desea, agregue el **Número de licencia**, **Maximum children enrolled (capacity)** y **Máximo de niños a la vez**. La matrícula puede superar el segundo número; la asistencia no.
5. Empiece a escribir en **Buscar la dirección** y elija la coincidencia. Esto llena **Dirección**, **Ciudad**, **Province / State** y **Código postal**; revíselos o escríbalos usted mismo.
6. Marque los **Días de apertura** y fije **Abre** y **Cierra**. La hora de cierre es la referencia para una recogida tardía; déjela en blanco solo si el proveedor no tiene una hora de cierre fija.
7. Pulse **Crear centro**. El nuevo centro aparece en la lista.

> 💡 La dirección es lo que ubica el centro en el **Mapa de proveedores**, así que una dirección completa le ahorra volver aquí.

## Editar un centro

1. Busque el centro en la lista y abra su menú **⋮**.
2. Pulse **Editar**. Se abre el mismo formulario con los datos del centro.
3. Cambie lo que necesite. En **Marca** puede **Subir logotipo** (máximo 2 MB), elegir un **Color principal** y un **Color de énfasis**, y escribir un **Tagline (shown on the centre card)**.
4. Actualice la **Biografía del proveedor** si las familias deben ver una nueva presentación. Una vez escrita no puede quedar vacía, porque se envía a las familias cuando se unen.
5. Use el interruptor de correo para activar o desactivar los correos de este centro.
6. Pulse **Guardar cambios**. La fila se actualiza al instante.

Para leer un centro sin riesgo de cambiar nada, use **⋮ > Ver**.

## Activar el quiosco

1. Abra **⋮ > Editar** en el centro y baje hasta **🛡 Modo quiosco**.
2. Marque **Activar el quiosco para este centro**. Los padres podrán registrar la entrada o salida de los niños en una tableta en la puerta con su PIN de 4 a 6 dígitos.
3. Pulse **Generar token** si todavía no hay enlace, luego **Copiar URL** y ábralo en la tableta.
4. Si una tableta del quiosco se pierde o la roban, pulse **Sign out all kiosks**. El enlace anterior deja de funcionar en todas las tabletas a la vez; abra el nuevo enlace en las que siga usando.

## Nombrar un supervisor interino

1. Abra **⋮ > Supervisor interino** en el centro.
2. Elija quién actúa por el supervisor cuando no está en el sitio y guarde. Vea [[headcounts-and-acting-supervisor]].

## Cerrar un proveedor

1. Abra **⋮ > 🚪 Close this provider…**.
2. Elija el **Último día de operación**.
3. Para cada familia, elija a dónde va: una sala en otro centro, **Baja: deja el cuidado** o **Waitlist — place with a new educator later**.
4. Pulse **Vista previa** para revisar el plan y luego **Cerrar proveedor**. Se aplican los traslados y las bajas, se envía un correo a los padres y el proveedor pasa a **Archivado**.

Los administradores de la agencia pueden cambiar entre **Activo** y **Archivado** bajo el encabezado para ver o restaurar centros cerrados.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la agencia | Todo en esta pantalla: agregar, editar, quiosco, bloqueo de inicio de sesión, cerrar y restaurar centros |
| Administrador de la plataforma | Lo mismo, dentro de la agencia que está viendo |
| Director de centro | No tiene la pantalla **Centros**. Los directores ven sus propios centros en el **Panel** y piden los cambios a un administrador de la agencia |

## Preguntas frecuentes y solución de problemas

??? Pulsé Crear centro y no se guardó nada. | El formulario muestra el motivo en rojo. "Name is required." significa que el **Nombre del centro** está vacío. Al agregar un centro, el nombre del proveedor y la dirección también son obligatorios.
??? La pantalla dice "Could not load centres". | No se pudo obtener la lista. Actualice la página; si sigue pasando, revise su conexión o contacte al soporte de KiddieTrac.
??? ¿Por qué no puedo borrar la biografía del proveedor? | "The provider bio cannot be removed — families are sent it when they join." Edite el texto en su lugar.
??? Nunca se marcan recogidas tardías en un centro. | No tiene hora en **Cierra**. Sin hora de cierre nada puede contarse como tardío. Agréguela en **Días de apertura**.
??? Un centro muestra Correo desactivado. | Los correos de ese centro están desactivados, así que sus familias no reciben correos de KiddieTrac. Vuelva a activar el interruptor en **⋮ > Editar**.
??? No veo Centros en mi menú. | Solo los administradores de la agencia lo tienen. Un director de centro se lo pide a su administrador de la agencia.

## Relacionado

[[open: #dashboard | Resumen de la agencia]] [[open: #provider-map | Mapa de proveedores]] [[open: #provider-day | Resumen diario]]

- [[kiosk-mode]]
- [[closing-a-provider-or-educator]]
- [[centres-or-rooms]]
- [[archived-records]]
- [[first-time-setup]]
