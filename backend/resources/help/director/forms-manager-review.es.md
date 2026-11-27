---
title: Gestor de formularios para directores y auditores
---
# Gestor de formularios para directores y auditores

El **Gestor de formularios** guarda los formularios PDF de la agencia: quién debe firmarlos,
qué se ha enviado y cada copia completada. Un **director de centro** lo usa para las personas
de sus propios centros: subir formularios, enviarlos, revisar y contrafirmar lo que vuelve.
Un **auditor** ve los mismos registros en modo de solo lectura, para comprobar que los
formularios se enviaron, firmaron y revisaron.

[[open: #forms-manager | Abrir el Gestor de formularios]]

La pantalla tiene cuatro pestañas: **📚 Biblioteca**, **📦 Varios formularios**,
**📥 Solicitar archivos** y **✅ Completado**.

## Encontrar un formulario firmado (directores y auditores)

1. Abra el **Gestor de formularios** y pulse la pestaña **✅ Completado**.
2. Escriba un nombre o el título de un formulario en el cuadro de búsqueda sobre la tabla para acotar la lista. Haga clic en el encabezado de una columna para ordenar.
3. Lea la fila: **Formulario**, **Descripción**, **Firmado por**, **Firmado (hora de la agencia)** y luego lo que pasó después.
4. Pulse el **⋮** de la fila y elija **Ver formulario** para abrir la copia completada, o **Descargar el formulario completado** para guardarla.

Las columnas de estado responden a las preguntas habituales de una inspección:

| Columna | Lo que le indica |
|---|---|
| **Copia enviada por correo electrónico** | Una marca y una hora cuando la copia completada llegó a la dirección indicada en el formulario. **No enviado**: tiene dirección pero aún no salió nada; **Sin configurar**: el formulario no tiene dirección. |
| **Devuelto** | **Esperando al firmante** mientras la agencia espera una corrección, **Reenviado** cuando llegó una copia nueva. |
| **Contrafirmado** | La fecha y quién contrafirmó, **Esperando revisión** si nadie lo ha hecho, o **No requerido** para un formulario que no necesita contrafirma. |
| **Sincronizada** | **✓ Filed** con el nombre del expediente donde está. **Sin archivar**: aún no está en el expediente de la persona; **Huérfano**: la cuenta donde se archivó ya no existe. |

[[show: #forms-manager @ Completed > Counter-signed | Mostrarme la columna Contrafirmado]]

## Comprobar qué se envió y a quién

1. Pulse la pestaña **📦 Varios formularios**.
2. El historial lista cada paquete enviado, quién lo envió y cuándo.
3. Lea **Firmado** (cuántas firmas han vuelto), **Enviado por correo** y **Leído**. **No entregado** en Leído es lo preocupante: el correo nunca llegó.
4. Pulse **⋮ > View forms** para ver quién recibió cada formulario y cuándo firmó cada persona. **⋮ > Download PDF** une todos los formularios completados del paquete en un solo PDF para su archivo. Los directores también tienen **⋮ > Print summary**.

## Revisar y contrafirmar (directores)

1. En **✅ Completado**, busque una fila que diga **Esperando revisión**.
2. Pulse **⋮ > Review & counter-sign**. El formulario completado se abre a tamaño completo.
3. Léalo. Agregue una nota en **Agregar información** si hay algo que dejar registrado.
4. Pulse **Next: counter-sign →** y luego **✍️ Add your signature**.
5. Deje marcado **Enviar por correo electrónico la copia contrafirmada a ambas partes** y pulse **Completar la contrafirma**.

Si falta algo o hay un error, pulse en cambio **⋮ > Send back to the signer**, indique en
**Qué hay que cambiar** exactamente qué corregir y pulse **Devolverlo**. Todos los pasos
están en [Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms).

## Subir y enviar formularios (directores)

1. En **📚 Biblioteca**, pulse **+ Upload a form**.
2. Si dirige más de un centro, elija primero el centro en **🏫 Centre for new forms and new parents**.
3. Complete el título y la descripción, elija **Who must sign it?** o personas en **O enviar a personas específicas**, elija el PDF y pulse **Subir formulario**.
4. Para enviar varios formularios por correo de una vez, vaya a **📦 Varios formularios** y pulse **+ Send multiple forms**.

Los pasos completos para subir y enviar paquetes están en
[Gestor de formularios y paquetes de formularios](forms-manager).

## Quién puede hacer qué

| | Director de centro | Auditor |
|---|---|---|
| Ver formularios, envíos y copias completadas | Las personas de sus centros | Solo lectura, la agencia que audita |
| Ver y descargar formularios | Sí | Sí |
| Subir, editar, activar o desactivar, eliminar un formulario | Solo los de sus centros | No |
| Enviar paquetes, recordatorios y solicitudes de archivos | Sí | No |
| Contrafirmar, devolver, eliminar una firma | Sí | No |
| **Sincronizar ahora** y formularios de toda la agencia | No (solo administradores de la agencia) | No |

Un auditor ve arriba la etiqueta gris
**🔒 Read-only (auditor) — you can view and download, not change**. Todos los botones que
cambiarían algo están ocultos, y los menús **⋮** solo ofrecen ver, descargar y abrir.

## Preguntas frecuentes y cuando algo falla

??? Soy director y un formulario no tiene botones de editar ni eliminar. | Es un formulario de toda la agencia o creado para otro centro. Solo un administrador de la agencia puede cambiarlo. Usted puede enviarlo y revisar lo que vuelva.
??? ¿Por qué no tengo el botón Sincronizar ahora? | La sincronización abarca toda la agencia, así que es solo para administradores. Pida a uno que pulse Sincronizar ahora en la pestaña Completado.
??? Completado dice «No forms have been signed yet.» | Nadie que usted pueda ver ha firmado todavía. Un director solo ve a las personas de sus propios centros.
??? Como auditor, ¿puedo exportarlo todo? | Use ⋮ > Download PDF en un paquete de Varios formularios para obtener todos sus formularios completados en un solo PDF, o descargue los formularios uno por uno en Completado.
??? Hice clic y salió «Auditor accounts are read-only.» | Las cuentas de auditor pueden consultar, pero nunca cambiar nada. Pida a un administrador de la agencia el cambio si hace falta.
??? ¿Ver un formulario avisa a alguien? | No. Ver y descargar no envían nada. Contrafirmar y devolver sí envían un correo al firmante.

## Pantallas relacionadas

[[open: #forms-manager | Gestor de formularios]] [[open: #compliance | Cumplimiento]]

- [Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms)
- [Gestor de formularios y paquetes de formularios](forms-manager)
- [Solicitar archivos](requesting-files)
