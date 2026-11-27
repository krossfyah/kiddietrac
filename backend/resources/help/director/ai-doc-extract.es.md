---
title: Extracción de documentos con IA
---
# Extracción de documentos con IA

**Marketing › AI doc extract** lee una foto o un escaneo de un documento y saca sus datos clave (nombres, fechas,
números) para que no tenga que escribirlos. Sirve para certificados de personal nuevo, cartillas de vacunación,
verificaciones de antecedentes, identificaciones y formularios de inscripción en papel. Usted copia el resultado en el
registro correcto; no se guarda nada automáticamente.

[[open: #ai-docs | Abrir la extracción de documentos]]

## Leer un documento

1. Tome una foto o escaneo nítido del documento (PNG, JPEG o WebP), plano y con buena luz.
2. Abra **Marketing › AI doc extract**. El título dice **AI document extraction**.
3. En **Upload a file (image)**, elija la imagen.
4. Elija el **Document type**: **Immunization record**, **Staff certification**, **Background / VSS check**, **ID document** o **Enrollment form**.
5. Pulse **Extract fields**. Se muestra «Extracting…» mientras lee; luego los datos aparecen en el recuadro oscuro, uno por línea (por ejemplo, el nombre, la fecha de emisión y la de vencimiento).
6. Compare cada valor con el papel y luego escríbalo o péguelo en el registro del niño o del empleado.

> ⚠️ Siempre compare el resultado con el original. La letra a mano, un reflejo o un borde cortado pueden dar una fecha o un nombre equivocados.

## Usar un enlace en lugar de un archivo

1. Deje vacío el campo del archivo.
2. Pegue un enlace en **…or a document URL (publicly reachable)**.
3. Elija el **Document type** y pulse **Extract fields**.

> ⚠️ Use un enlace solo para un documento que deba ser público. Nunca ponga el documento personal de un niño o empleado en un enlace público: suba el archivo.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Usar la herramienta. |
| Director de centro | Usar la herramienta. |
| Administrador de plataforma | Usar la herramienta en la agencia elegida en el selector. |

La herramienta usa IA. El documento se envía solo para leerlo y obtener los datos; KiddieTrac no lo adjunta a ningún registro.

## Preguntas frecuentes y problemas

??? Aparece «Error: …» en el recuadro. | No se pudo leer la imagen. Pruebe una foto más nítida, otro formato (PNG o JPEG) o un archivo más pequeño.
??? Algunos datos están vacíos. | La herramienta no los encontró en el documento. Escríbalos usted.
??? ¿Se guarda el documento en algún lado? | No. Suba el original al registro de la persona si necesita conservarlo.
??? No aparece la opción en el menú. | Las herramientas de IA dependen de su plan. Pregunte a su administrador de agencia.

## Pantallas relacionadas

[[open: #compliance | Panel de cumplimiento]]

Vea también [[immunization-report]] y [[staff-training-and-checks]].
