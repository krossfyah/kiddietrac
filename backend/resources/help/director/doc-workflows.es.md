---
title: Flujos de documentos en varios pasos
---
# Flujos de documentos en varios pasos

**Administración → Flujos de documentos** sirve para un acuerdo breve que varias personas
deben firmar **en orden**: por ejemplo, firma un padre y luego el director contrafirma.
Usted escribe el texto una vez, indica quién firma y en qué orden, y cada persona firma en
pantalla cuando le toca. Cada firma queda sellada y el documento terminado se bloquea.

[[open: #doc-workflows | Abrir Flujos de documentos]]

> 💡 Para que firmen un **PDF** subido, ver quién lo ha firmado y revisarlo o devolverlo, use el **Gestor de formularios**. Los flujos de documentos son para un texto escrito que pasa de un firmante al siguiente.

## Iniciar un flujo

1. Abra **Flujos de documentos** y pulse **+ Nuevo flujo**.
2. En **Nuevo flujo de documentos**, escriba el **Título** (por ejemplo «Consentimiento para la excursión»).
3. Escriba el **Tipo de documento**, una palabra corta para agrupar, como `consent` o `contract`.
4. Escriba o pegue el texto completo en **Document text (what signers agree to)**.
5. Pulse **Continuar**.
6. En **Orden de firma**, escriba en **Firmantes en orden** los roles de los firmantes en orden, separados por comas: `guardian`, `educator`, `centre_director` o `agency_admin`. Por ejemplo `guardian, centre_director`. Hasta seis.
7. Confirme. Aparece el mensaje **Flujo creado** y el flujo se suma a la lista en el **Paso 1**.

## Firmar su paso

1. Abra **Flujos de documentos** y pulse **Abierto** junto al flujo.
2. Lea el texto de arriba y la lista **Firmantes** debajo: **✓ signed** con fecha, **… awaiting** para el paso actual y **· pending** para los pasos por venir.
3. Cuando sea su turno, aparece la sección **Firmar ahora**. Escriba su nombre completo en **Su nombre completo**.
4. Dibuje su firma en el recuadro. **Borrar firma** vuelve a empezar.
5. Pulse **Firmar y avanzar**. Aparece el mensaje **Firmado** y el flujo pasa al siguiente paso.

Cuando firma la última persona, el estado cambia a **complete** y quien inició el flujo recibe
una notificación: «Document fully signed».

## Leer la lista

| Columna | Significado |
|---|---|
| **Título** | El nombre que le dio. |
| **Tipo** | El tipo de documento que escribió. |
| **Paso actual** | El firmante al que se espera: **Paso 1**, **Paso 2** … |
| **Estado** | **in_progress** mientras falten firmas, **complete** cuando estén todas. |
| **Creado** | Cuándo se inició. |

## Lo que registra cada firma

Bajo cada paso firmado, la pantalla muestra el nombre del firmante y su sello: la fecha y la
hora en la zona horaria de su agencia, la dirección IP y la ubicación aproximada de esa
dirección. El servidor también guarda una huella (un hash) del texto con cada firma, de modo
que más adelante se puede demostrar que el texto no cambió después de firmarse.

## Quién puede hacer qué

- Los **administradores de la agencia** inician flujos y ven todos los de su agencia.
- **Cualquier persona con el rol del paso actual** en su agencia puede firmar ese paso: un padre para un paso `guardian`, un director para un paso `centre_director`.
- Nadie puede firmar un paso que todavía no le toca, y un flujo completo no se puede cambiar.

## Preguntas frecuentes y cuando algo falla

??? ¿Se avisa al siguiente firmante de que es su turno? | No en los pasos definidos por rol, que es como los crea esta pantalla. Avise al siguiente firmante que abra Flujos de documentos, o use el Gestor de formularios, que envía correos.
??? No veo Firmar ahora. | Aún no es su turno, o su rol no es el que necesita este paso. Revise la lista Firmantes para ver a quién le toca.
??? «Name required» o «Please sign first». | Escriba su nombre completo y dibuje su firma antes de pulsar Firmar y avanzar.
??? Escribí mal los roles. | Un flujo no se puede editar. Inicie uno nuevo con el orden correcto y deje el anterior sin firmar.
??? ¿Puedo adjuntar un PDF? | No. Un flujo contiene texto escrito. Para un PDF, use el Gestor de formularios o los Documentos electrónicos.
??? La lista dice «No workflows yet.» | Nadie en su agencia ha iniciado uno. Pulse + Nuevo flujo.

## Pantallas relacionadas

[[open: #forms-manager | Gestor de formularios]] [[open: #edocuments | Documentos electrónicos]]

- [Gestor de formularios y paquetes de formularios](forms-manager)
- [Revisar, contrafirmar y devolver formularios](reviewing-and-counter-signing-forms)
