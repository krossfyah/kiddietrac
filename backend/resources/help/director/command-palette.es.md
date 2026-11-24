---
title: Paleta de comandos (Cmd/Ctrl-K)
category: Getting Started
order: 55
---
# La paleta de comandos

Pulse **`⌘K`** (macOS) o **`Ctrl + K`** (Windows / Linux) **desde cualquier lugar** del portal para abrir una paleta de búsqueda de acceso rápido. También puede pulsar **`/`** cuando no esté escribiendo ya en un campo.

## Qué puede hacer desde ella

| Escriba | Qué encuentra |
|---|---|
| Nada | Atajos a pantallas: Panel, Marketing, Calendario, Niños, Familias, Centros, Usuarios, Registro de auditoría, Facturación, Ayuda, MFA |
| 2 o más caracteres | Coincidencias en vivo entre **centros**, **salas**, **familias**, **niños** y **personal**, además de accesos filtrados |

Los resultados se muestran agrupados por tipo, de modo que al buscar `aco` verá todo lo que se llame Acorn (salas, niños, personal) bajo sus propios encabezados.

## Teclado

| Tecla | Acción |
|---|---|
| **`⌘K`** / **`Ctrl-K`** | Mostrar u ocultar la paleta (funciona desde cualquier pantalla, incluso mientras escribe) |
| **`/`** | Abrir la paleta (solo cuando no está escribiendo en otro campo) |
| **`↑` / `↓`** | Mover la selección |
| **`Enter`** | Abrir el resultado seleccionado |
| **`Esc`** | Cerrar la paleta |

También puede hacer clic en cualquier fila para ir a ella.

## Quién obtiene la búsqueda en vivo

Los administradores de agencia y de plataforma ven coincidencias en vivo entre centros, salas, familias, niños y personal. Los directores y educadores solo ven los atajos estáticos a pantallas (su rol no tiene acceso al servicio de búsqueda global). Todos disponen del atajo de teclado.

## Consejos

- La búsqueda se ejecuta sobre la **agencia activa** para los administradores de plataforma con varias agencias. Cambie de agencia con el selector de agencia (arriba a la derecha) antes de buscar para acotar a otro inquilino.
- Los resultados de `/admin/search` se limitan a 5–8 por tipo, ordenados por relevancia, de modo que la paleta sigue siendo rápida incluso en carteras grandes.
- Si la paleta va lenta, revise su red: cada búsqueda es un viaje de ida y vuelta HTTP con un retardo antirrebote de 180 ms.
