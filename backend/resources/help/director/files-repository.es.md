---
title: Repositorio de archivos
category: Administration
order: 37
roles: agency_admin, platform_admin
---
# Repositorio de archivos

**Ventas → Repositorio de archivos.** Un estante de referencia para el área de ventas: listas de precios, presentaciones, plantillas de contrato y documentación de licencias. Pertenece a KiddieTrac mismo, no a ninguna agencia.

[[open: #sales-library | Abrir el Repositorio de archivos]]

## Quién puede usarlo

**Solo los administradores de la plataforma (superadmins).** Está en la sección **💼 Ventas** de la barra lateral, que solo tiene un administrador de la plataforma, y además es un icono de la pantalla de inicio de Ventas para ellos.

Los representantes de ventas **no** lo ven, aunque trabajen en Ventas. El estante contiene material de precios y contratos, y el servidor rechaza a cualquiera que no sea administrador de la plataforma. Un administrador de agencia tampoco lo ve nunca.

## La disposición

- Las **carpetas** van a la izquierda. **Todos los archivos**, arriba, muestra todo, sea cual sea la carpeta; cada carpeta indica cuántos archivos contiene.
- **El estante**, en el centro, lista los archivos: **Archivo**, **Tipo**, **Carpeta**, **Categoría**, **Tamaño** y **Añadido** (fecha, hora y quién lo añadió).
- El cuadro de búsqueda y los encabezados de columna ordenables son los mismos que tiene cualquier otra tabla del portal. Tamaño y Añadido se ordenan por el número y la hora reales, no alfabéticamente.

La columna **Archivo** muestra el nombre real del archivo con su extensión, con el título debajo si es distinto, y las notas debajo de eso. La columna **Tipo** empieza con la extensión (PDF, DOCX, PPTX…) y nombra el formato con palabras debajo.

## Añadir un archivo

1. Elija primero la carpeta que quiera, si lo desea, para que el cuadro de diálogo empiece en ella.
2. **＋ Añadir un archivo**.
3. Elija el archivo. Hasta **25 MB**: un PDF, una presentación, una hoja de cálculo o una imagen.
4. Complete el resto:
   - **Carpeta**: dónde se guardará. Se permite *Todos los archivos (sin carpeta)*.
   - **Título**: se rellena solo a partir del nombre del archivo (sin la extensión).
   - **Categoría**: elija una que ya esté en uso, o **＋ Nueva categoría…** para escribir una nueva.
   - **Notas**: para qué sirve y a quién va dirigido.
5. **Añadir al repositorio**.

[[show: #sales-library @ Add a file | Muéstreme el botón Añadir un archivo]]

> **Varios a la vez:** en el selector de archivos, seleccione todos los archivos que quiera (Ctrl o Mayús, ⌘ en un Mac), o elija de nuevo para añadir más. La carpeta, la categoría y las notas se aplican a todos, y cada archivo conserva su propio nombre como título. Si alguno falla, sigue en la lista con el motivo, y **Añadir al repositorio** reintenta solo esos.

> **Consejo:** Ponga al archivo el nombre que dicte su convención antes de subirlo, p. ej. `KT-2026-Q4-RateCard-v3.pdf`. El título toma ese nombre, así que nunca tendrá que volver a escribirlo.

El título solo se rellena automáticamente mientras usted no haya escrito nada en él. Una vez que edite el título, elegir otro archivo no lo sobrescribirá.

La categoría es una lista desplegable a propósito. Un cuadro de texto libre es la forma en que una misma etiqueta acaba escrita de tres maneras («Precios», «precios», «Listas de precios»), y la columna Categoría deja de agrupar nada.

Algunos tipos de archivo que un servidor web podría ejecutar (por ejemplo `.php` o `.sh`) se rechazan con el mensaje *«Aquí no se puede almacenar ese tipo de archivo.»*

## La barra de carga

Mientras se sube un archivo, una barra oscura baja desde la parte superior de la pantalla y muestra **Subiendo** y el nombre del archivo, el porcentaje y cuánto se ha enviado. Termina con **Subida completada** o **Error al subir**.

La barra no es exclusiva de esta pantalla. Todas las subidas del portal la muestran, incluidos los adjuntos del chat, las fotos, los archivos de incidentes y los documentos electrónicos. Cuando el navegador no puede informar del tamaño, la barra muestra una franja en movimiento en lugar de un porcentaje.

## Carpetas

- **📁 Nueva carpeta** crea una. Los nombres son únicos sin importar las mayúsculas, así que «Precios» y «precios» son la misma carpeta. Si pide una que ya existe, simplemente se lo indica.
- **Eliminar una carpeta** (la **✕** junto a su nombre) **no** elimina sus archivos. Vuelven a **Todos los archivos**, y la confirmación indica cuántos.

[[show: #sales-library @ New folder | Muéstreme el botón Nueva carpeta]]

## Mover, abrir y quitar un archivo

Cada fila tiene el menú **⋮** estándar:

- **Ver** abre el archivo.
- **📁 Mover a una carpeta** le permite elegir otra carpeta, o *Todos los archivos (sin carpeta)*. Primero necesita al menos una carpeta.
- **↩ Quitar de la carpeta** solo aparece en un archivo que está en una carpeta. Devuelve el archivo al nivel superior.
- **🗑 Quitar** retira el archivo del estante. El archivo almacenado se conserva en el servidor, pero no hay ningún botón para recuperarlo, así que asegúrese antes de usarlo.

## Qué se registra

Subir, mover y quitar un archivo, y crear o eliminar una carpeta, escriben cada uno una fila de auditoría con el nombre del archivo o de la carpeta. Son eventos de nivel de plataforma, no vinculados a ninguna agencia.

## Preguntas frecuentes

??? ¿Puede un representante de ventas ver estos archivos? | No. La entrada del menú, el icono y el servidor se detienen en los administradores de la plataforma.
??? ¿Eliminar una carpeta hace perder los archivos que contiene? | No. Vuelven a Todos los archivos.
??? ¿Cuál es el archivo más grande que puedo añadir? | 25 MB.

Vea también: [Administrador de la plataforma (resumen)](platform-admin-overview),
[Abrir e imprimir documentos](opening-and-printing-documents).
