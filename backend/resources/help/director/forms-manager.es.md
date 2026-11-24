---
title: Gestor de formularios y paquetes de formularios
category: Administration
order: 66
roles: agency_admin, platform_admin
---
# Gestor de formularios y paquetes de formularios

**Participación → Gestor de formularios.** Suba un formulario en PDF, envíelo a las personas que deben firmarlo y vea lo que ha vuelto. Tiene cuatro pestañas: **Biblioteca**, **Varios formularios**, **Solicitar archivos** y **Completado**.

[[open: #forms-manager | Abrir el Gestor de formularios]]

## Biblioteca: los formularios en sí

La Biblioteca es donde se sube y se guarda un formulario. Al subir uno, usted le asigna:

- Un **título** y una **descripción**. La descripción indica para qué sirve el formulario y se muestra junto al título dondequiera que aparezca.
- **¿Quién debe firmarlo?** Padres, educadores, visitadores domiciliarios o directores. En su lugar puede elegir personas concretas en **O enviar a personas concretas**, y entonces solo les llega a ellas.
- **Permitir que los destinatarios rellenen este formulario**: marque esta opción cuando el PDF tenga campos rellenables. Las personas escriben entonces en el formulario en pantalla y lo firman allí mismo. Déjela sin marcar para un aviso de leer y firmar.
- **Permitir que este formulario se reutilice**: mantiene el formulario abierto después de enviarlo, para que se pueda completar de nuevo, una vez por niño o semana tras semana. Cada envío se conserva como un registro propio.
- **Enviar los formularios completados por correo electrónico a** (opcional): cada vez que alguien firma, el PDF completado se envía por correo electrónico a esta dirección.

> **Nota:** Asignar un formulario desde la Biblioteca no envía correo a nadie. Para avisar a las personas, envíelo desde **Varios formularios**.

## Varios formularios: enviar un paquete

Incorporar a una familia suele implicar varios formularios a la vez. Un paquete asigna cada formulario que marque a cada persona que elija, y envía a cada persona **un** correo que los enumera todos.

[[show: #forms-manager @ Multiple forms > + Send multiple forms | Muéstreme el botón Enviar varios formularios]]

1. Pulse **+ Enviar varios formularios**.
2. Marque los formularios. Cada uno está señalado como **📝 Rellenar y firmar** o **✍️ Solo firmar**, para que vea qué se le pedirá realmente al destinatario. Solo se listan los formularios activos. **+ Subir un formulario** le lleva a la Biblioteca para añadir uno y luego le devuelve aquí.
3. Elija personas de la lista, o escriba direcciones de correo separadas por comas.
4. Añada una nota si lo desea. Va en el correo.
5. Deje activada la opción **Enviarles el correo ahora** para enviar el correo. Desmárquela para asignar sin avisar: seguirán viendo los formularios en el portal.
6. Pulse **Enviar paquete**.

**A un padre que aún no está en el sistema también se le pueden enviar formularios.** Si una dirección escrita no tiene cuenta en su agencia, se crea una cuenta provisional y los formularios se envían. El enlace del correo le permite rellenar y firmar sin contraseña. No le invita al portal: eso sigue siendo un paso aparte.

> **Nota:** Una dirección escrita que comparten varias cuentas de su agencia va a una de ellas, y el resultado lo indica. Para elegir exactamente a quién, seleccione a la persona de la lista.

### El historial de envíos

La pestaña lista cada paquete enviado, con quién lo envió y cuándo. Úsela para responder «¿esto ya salió?». De cada envío se ve:

- **Firmado** es cuántas firmas han vuelto (formularios × personas).
- **Enviado por correo** es **No enviado** si asignó sin avisar, **No salió ninguno** si no se pudo escribir a nadie, y en otro caso cuántos correos se enviaron.
- **Leído** es **No entregado** si el correo nunca salió, **Sin abrir** si se entregó pero nadie lo ha abierto, y **✓ Leído** una vez abierto.

> **Consejo:** «Sin abrir» es una señal débil. Algunas aplicaciones de correo nunca informan de una apertura, así que revise primero **No entregado**: ese sí es un problema real.

El menú **⋮** de cada envío ofrece:

- **Ver formularios**: quién recibió qué y cuándo firmó cada persona. Haga clic en una fecha de firma para abrir ese formulario completado. Use **Enviar de nuevo** en una celda sin firmar para reenviar un formulario a una persona.
- **Imprimir resumen** y **Descargar PDF**. La descarga combina todos los formularios completados en un solo PDF. Si todavía no se ha firmado nada, le entrega los formularios en blanco.
- **Enviar recordatorio** vuelve a enviar el paquete por correo. Se omite a quien ya haya firmado todo lo que contiene.
- **Reenviar copias completadas** envía cada formulario terminado a los administradores y directores de su agencia, con una copia para la persona que lo firmó.
- **Eliminar del historial** quita el registro de que se envió. Los formularios, las asignaciones y las firmas permanecen exactamente como están.

## Solicitar archivos y Completado

- **Solicitar archivos** pide a alguien documentos que ya tiene, como una identificación o una cartilla de vacunación. Vea [Solicitar archivos](requesting-files).
- **Completado** lista cada formulario firmado. Desde aquí revisa y contrafirma un formulario, o lo devuelve para corregirlo. Vea [Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms).

## Preguntas frecuentes

??? ¿Por qué un paquete muestra «0 de 7 firmados»? | Revise primero la columna Leído: si dice No entregado, la familia nunca recibió el correo.
??? ¿Eliminar un envío anula la asignación de los formularios? | No. Eliminar del historial solo quita la fila del historial; las asignaciones y las firmas no se tocan.
??? ¿Puedo enviar un formulario a un padre nuevo sin acceso? | Sí. Escriba su dirección; se crea una cuenta provisional y firma desde el enlace del correo.

Vea también: [Solicitar archivos](requesting-files),
[Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms),
[Creador de formularios personalizados](custom-forms), [Flujos de trabajo de documentos](doc-workflows).
