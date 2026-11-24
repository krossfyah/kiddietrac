---
title: Acciones masivas y exportaciones CSV
category: Administration
order: 59
---
# Acciones masivas y exportaciones CSV

La mayoría de las listas de administración admiten acciones masivas con selección múltiple y una descarga CSV con un clic.

## Descargas CSV

Un botón verde **⤓ CSV** aparece en estas pantallas:

| Pantalla | Nombre del archivo | Columnas |
|---|---|---|
| **Administración → Gestión de usuarios** | `users-YYYY-MM-DD.csv` | ID, Nombre, Correo, Teléfono, Estado, Roles, Último acceso, Creado |
| **Administración → Familias** | `families-YYYY-MM-DD.csv` | ID, Apellido de la familia, Centro, Correo, Teléfono, Dirección, Ciudad, Niños, Tutores, Saldo pendiente |
| **Administración → Registro de auditoría** | `audit-log.csv` | Cuándo, Acción, Tipo de entidad, ID de entidad, Autor, Correo, IP, Datos |
| **Formularios personalizados → ventana de respuestas** | `<title>-responses-YYYY-MM-DD.csv` | Enviado el, Remitente, Correo + una columna por cada campo del esquema |
| **Facturación masiva** | `invoices-YYYY-MM-DD.csv` | N.º de factura, Familia, Estado, Periodo, Emitida, Vencimiento, Subtotal, Subvención, Descuento, Impuesto, Total, Pagado, Saldo |

Todas las exportaciones usan UTF-8 con BOM para que Excel muestre correctamente los caracteres especiales.

## Acciones masivas en Usuarios

La pestaña Usuarios tiene una columna de casillas. Seleccione varias filas para mostrar una barra de acciones masivas:

- **Reenviar la bienvenida** — ejecuta `/admin/users/{id}/resend-welcome` por cada fila
- **Eliminar** — elimina lógicamente a cada usuario (antes pide confirmación)

En el encabezado hay una casilla para seleccionar todo. La casilla de su propia fila de usuario está desactivada para que no pueda eliminarse a sí mismo en bloque.

## Acciones masivas en Familias

Cambie la pestaña Familias a la vista **Tabla** (interruptor en la barra de herramientas). Mismo esquema de casillas, más una barra de acciones masivas ámbar con **Eliminar**, que elimina lógicamente las filas de familia. Los niños y el historial de auditoría se conservan: solo se quita la fila de la familia.

## Funcionamiento

Los botones de descarga CSV y las barras de acciones masivas usan las mismas funciones (`downloadCsv()` para las descargas, `Api.delete` / `Api.post` secuenciales para las acciones masivas). Añadir este patrón a otras listas de administración es sencillo: abra una solicitud de soporte si lo quiere en una lista que aún no lo tiene.
