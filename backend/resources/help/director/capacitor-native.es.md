---
title: Aplicaciones nativas iOS / Android
category: Mobile
order: 90
roles: agency_admin, platform_admin
---
# Aplicaciones nativas iOS y Android

Una configuración de envoltorio Capacitor se incluye en la v22p51. La aplicación web es la fuente de verdad: la capa nativa carga `https://app.kiddietrac.com` y añade notificaciones push nativas, pantalla de inicio, personalización de la barra de estado y distribución en las tiendas de aplicaciones.

## Requisitos previos de compilación (una sola vez)

- **Cuenta de Apple Developer** (99 $/año) para iOS
- **Cuenta de Google Play Developer** (25 $, pago único) para Android
- macOS con **Xcode** para las compilaciones de iOS
- Cualquier sistema con **Android Studio** para las compilaciones de Android

## Compilar en local

```bash
cd parent-portal
npm install
npx cap init "KiddieTrac" "com.kiddietrac.app" --web-dir=.
npx cap add ios
npx cap add android
npx cap sync
npx cap open ios     # opens Xcode
npx cap open android # opens Android Studio
```

En cada IDE: configure la firma de código, defina el identificador de paquete, añada los iconos de la aplicación y los recursos de la pantalla de inicio, genere un paquete de publicación y súbalo a App Store Connect / Play Console.

## Distribución

iOS: primero TestFlight → App Store público tras 1-2 semanas de revisión.
Android: pista interna → pruebas cerradas → producción.

## Qué cambia en la aplicación web

Nada para los usuarios finales. La capa nativa define `window.Capacitor`, lo que permite activar algunas funciones:
- Push nativo (mejor entrega en iOS: el push web no funciona en Safari de iOS)
- Cámara nativa para fotos de niños y del personal
- Selector de archivos nativo para subir documentos

Estos puntos de integración están esbozados en la v22p51 y se desarrollarán por completo en la v22p52.
