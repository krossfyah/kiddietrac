---
roles: agency_admin, platform_admin
title: Flujos de documentos de varios pasos
category: Administration
order: 65
---
# Flujos de documentos

Aprobación de documentos con varios firmantes y registro de auditoría completo. El padre firma → el administrador revisa → el director contrafirma, en secuencia.

## Crear un flujo (administrador)

1. Barra lateral → **Flujos de documentos**.
2. Toque **+ Nuevo flujo**.
3. Escriba el título, el tipo de documento y el texto completo del documento.
4. Indique la lista ordenada de firmantes (máximo 6 pasos). Cada paso tiene:
   - **Rol** (guardian / educator / centre_director / agency_admin)
   - **ID de usuario específico** (opcional: déjelo en blanco para permitir a cualquier usuario con ese rol)
   - **Etiqueta del firmante** (texto visible como «Firma del padre» o «Contrafirma del director»)
5. Guarde.

## Progreso del flujo

- Se avisa automáticamente al firmante del paso 1.
- Firma con un panel de firma (dibujo en lienzo) y su nombre.
- El sistema registra: la imagen de la firma, el usuario firmante, la marca de tiempo, la dirección IP y el hash SHA-256 de (document_text + user_id + timestamp).
- Se avisa automáticamente al firmante del paso 2 cuando se completa el paso 1.
- Y así sucesivamente hasta el último paso.

## Estado final

Cuando se firma el último paso:
- El estado del flujo pasa a `complete`.
- El originador recibe una notificación.
- El documento completo, cada firma y cada paso de auditoría quedan bloqueados.

## Qué registra cada paso

- Orden del paso
- Rol de firmante requerido
- Usuario firmante real
- Marca de tiempo de la firma
- Imagen de la firma (PNG en base64)
- Hash de la firma (SHA-256)
- Dirección IP del firmante
- Etiqueta del firmante
- Notas opcionales

## Cuándo usarlo

- Contratos de inscripción (padre + director)
- Autorización médica (padre + director + enfermera, si corresponde)
- Permiso para excursiones (padre + educador principal)
- Aceptación de políticas (solo padre, un solo paso)
- Documentos de terminación (padre + administrador)

## Registro de auditoría

Todo documento firmado es inmutable una vez completado. La cadena de hash le permite demostrar ante un tribunal que el documento no fue alterado después de la firma.

> **Nota:** Para enviar un formulario PDF subido a padres o personal, saber quién lo ha firmado, y revisarlo, contrafirmarlo o devolverlo, use en su lugar el **Gestor de formularios**. Consulte [Gestor de formularios y paquetes de formularios](forms-manager).

Vea también: [Gestor de formularios y paquetes de formularios](forms-manager),
[Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms).
