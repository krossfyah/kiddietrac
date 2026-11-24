---
title: Cierre de sesión automático por seguridad
category: Settings
order: 60
---
# Por qué Kiddietrac cierra su sesión

Los portales de Kiddietrac contienen información sensible sobre niños, familias, facturación y medicamentos. Para cumplir con las exigencias de **PHIPA / PIPEDA** y proteger los dispositivos compartidos (quioscos, tabletas, puestos de recepción), el portal cierra su sesión automáticamente en dos casos:

## Tiempo de inactividad (30 minutos)

Si no hace clic, escribe, se desplaza ni toca la pantalla durante **30 minutos**, el portal cierra su sesión y vuelve a la página de inicio de sesión. Cinco minutos antes verá un aviso:

> *⏱️ Su sesión se cerrará pronto*
> [Mantener sesión]   [Cerrar sesión ahora]

Haga clic en **Mantener sesión** para reiniciar el temporizador. Haga clic en **Cerrar sesión ahora** para terminar la sesión de inmediato.

## Tiempo máximo (12 horas)

Aunque esté activo todo el tiempo, cada inicio de sesión está limitado a **12 horas**. Esto cubre el caso de una pestaña abierta toda la noche en un equipo compartido. Al llegar a las 12 horas volverá a la pantalla de inicio de sesión: inicie sesión de nuevo para continuar.

## ¿Qué pasa con mi trabajo?

El portal guarda los cambios cuando hace clic en **Guardar**, no mientras permanece en una pantalla. Si estaba escribiendo en un formulario cuando se activó el temporizador, ese texto sin guardar se pierde: tendrá que volver a escribirlo después de iniciar sesión de nuevo. (Recomendamos guardar cada pocos minutos en los formularios largos.)

## Ajuste por dispositivo

Si un director o administrador necesita un tiempo de inactividad distinto para un dispositivo concreto (por ejemplo, una tableta de pared que debe permanecer activa durante el día), abra la consola de desarrollo del navegador en ese dispositivo y ejecute:

    KT.SessionTimeout.setIdleMinutes(60);    // change idle to 60 minutes
    KT.SessionTimeout.setAbsoluteHours(8);   // change absolute cap to 8 hours

Los ajustes se guardan en el dispositivo: los demás dispositivos conservan los valores por defecto.

## Por qué aparece el mensaje en la pantalla de inicio de sesión

Cuando se cierra su sesión automáticamente, la página de inicio de sesión muestra un pequeño aviso ámbar que dice *«Se cerró su sesión tras un periodo de inactividad»* (o *«limitada a 12 horas»*). Es para que sepa que nada salió mal: no se le bloqueó el acceso, su sesión simplemente terminó según lo programado.
