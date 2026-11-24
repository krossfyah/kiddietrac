---
title: Solicitar archivos
category: Administration
order: 67
roles: agency_admin, platform_admin
---
# Solicitar archivos

**Participación → Gestor de formularios → Solicitar archivos.** Pida a un padre o a un miembro del personal
documentos que ya tiene: una cartilla de vacunación, ambos lados de una identificación, un comprobante de impuestos. Los
sube desde un enlace, sin contraseña, y todo queda en su propio registro.

Un paquete de formularios envía un documento para que alguien lo complete. Una solicitud de archivos funciona al revés: pide
algo que la persona ya posee. Cada una se sigue línea por línea, de modo que
«¿ya enviaron todo?» tiene respuesta sin tener que releer una cadena de correos.

[[show: #forms-manager @ Request files > + Request files | Muéstreme el botón Solicitar archivos]]

## Hacer una solicitud

1. Pulse **+ Solicitar archivos**.
2. **Pedir a**: elija una persona o escriba su dirección. La dirección debe pertenecer a alguien
   con una cuenta en su agencia.
3. Establezca una **Prioridad** (Baja, Normal, Alta, Urgente) y, si importa, una fecha **Necesario para**.
4. Añada una **Nota** si necesitan saber dónde encontrar algo o por qué lo necesita.
5. En **¿Qué necesita?**, añada una línea por documento. Cada línea tiene una descripción,
   un tipo (**Cualquier archivo**, **PDF**, **Foto/escaneo**, **Documento de Word**) y cuántos archivos
   espera, hasta 20. Use **+ Añadir otro** para más líneas.
6. Deje marcado **Enviarles un correo ahora** y pulse **Enviar solicitud**.

Reciben un correo con un enlace y un botón por cada cosa que pidió. El enlace
funciona durante 21 días y solo acepta los documentos indicados. Cada archivo puede pesar hasta 15 MB.

## Qué ocurre cuando llegan los archivos

- Cada archivo se guarda en el registro de la persona, así que aparece en sus **Documentos** y en
  su registro de usuario. No hay otro lugar donde buscar.
- Cuando llega el último elemento, la solicitud se marca como completa. La persona que la pidió
  recibe un correo que lo indica, y el remitente recibe un acuse de recibo.

## Seguimiento

La tabla muestra cada solicitud con cuánto se ha recibido (**2 de 3**, o **✓ Los 3**),
su prioridad y su fecha de vencimiento. Una fecha vencida se marca como **atrasada** en rojo.

El menú **⋮** de cada solicitud:

- **Ver archivos**: lo que se pidió, lo que ha llegado, y **Ver** o **Descargar** para
  cada archivo.
- **Abrir enlace de subida** abre el mismo enlace que se les envió por correo y lo copia al
  portapapeles. Úselo cuando una familia dice que el correo nunca llegó, o cuando están
  en el mostrador con los documentos en el teléfono. El correo anterior sigue funcionando. La obtención
  del enlace queda registrada en el registro de auditoría.
- **Descargar n archivo(s)** guarda todo lo que enviaron, uno tras otro.
- **Enviar recordatorio** les escribe sobre los elementos que siguen pendientes.
- **Eliminar solicitud** elimina la solicitud en sí. Los archivos ya enviados permanecen
  en su registro.

> **Importante:** El enlace de subida es una llave real a la página de subida de esa persona. Compártalo solo con la persona para quien se creó.

## Quién puede hacerlo

Los administradores de agencia y los directores de su agencia. Los archivos se abren a través del portal, nunca
desde una dirección pública, porque son documentos de identidad y expedientes de salud.

Véase también: [Gestor de formularios y paquetes de formularios](forms-manager),
[Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms).
