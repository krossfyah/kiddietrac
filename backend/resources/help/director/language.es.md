---
title: Preferencias de idioma (FR / ES)
category: Settings
order: 95
---
# Idioma

KiddieTrac está disponible en **inglés**, **francés** y **español**. La interfaz, la navegación y las etiquetas principales están traducidas. Los artículos de ayuda y el contenido redactado por la agencia permanecen en el idioma en que se escribieron.

## Cambiar de idioma

1. Barra lateral → **Idioma**.
2. Haga clic en el idioma que desee.
3. La página se recarga con el nuevo idioma aplicado.

Su preferencia se guarda en su registro de usuario: no tiene que cambiarla en cada visita.

## Idioma predeterminado de la agencia

Los administradores de la agencia pueden definir un **Idioma predeterminado** en **Administración → Ajustes de facturación**. Los usuarios nuevos de esa agencia empiezan en ese idioma. Cada usuario puede cambiarlo para sí mismo.

## Cobertura

Interfaz común: 100 %. Etiquetas de navegación: 100 %. Formularios y pantallas: ~80 % (en curso). Plantillas de correo electrónico: solo en inglés por ahora.

## Reemplazos específicos de la agencia

Si desea reemplazar un texto concreto para su agencia (p. ej., llamar «Maestros» a los «Educadores»), añada una fila en `agency_locale_overrides` (`key_path`, `value`) y el LocaleController la combinará sobre el paquete predeterminado.
