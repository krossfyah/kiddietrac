---
title: Ver como otro usuario
category: Administration
order: 36
roles: agency_admin, platform_admin
---
# Ver como otro usuario

**Pie de la barra lateral → Ver como….** Permite a un administrador de la plataforma ver el portal exactamente como lo ve una persona concreta (sus pantallas *y sus datos*) y luego volver a su propia sesión.

## Quién puede usarlo

**Solo los administradores de la plataforma.** Los controles aparecen al pie de la barra lateral, bajo su nombre y el selector de agencia, y nadie más los tiene. El servidor rechaza a cualquiera que no sea administrador de la plataforma.

## Dos controles, dos cosas distintas

Hay dos controles «Ver como» al pie de la barra lateral, y hacen trabajos distintos.

| Control | Qué cambia | De quién son los datos que ve |
|---|---|---|
| **👁 Ver como** (una lista desplegable de roles) | Los menús y pantallas, tal como los tendría ese *rol* | Siguen siendo los suyos |
| **Ver como…** (un botón que abre un selector de personas) | Todo. Inicia sesión como esa *persona* | Los de ella |

Use la lista de roles para comprobar cómo es el menú de un rol. Use **Ver como…** cuando necesite ver lo que realmente ve un padre, educador o director concreto, por ejemplo para un ticket de soporte que dice «No puedo ver a mi hijo».

## Previsualizar un rol

Elija un rol en la lista **👁 Ver como**. La página se recarga en la pantalla de inicio de ese rol y el control se vuelve morado, con el texto **👁 Ver como**, para que sea difícil de pasar por alto. Elija **Superadministrador (predeterminado)** para volver.

Las secciones de la barra lateral exclusivas de la plataforma (Plataforma, Ventas, Distribuidor, Sitio web) se ocultan mientras previsualiza otro rol, porque ese rol nunca las vería. Ayuda y guías también cambia a los artículos de ese rol.

## Ver como una persona

1. Pulse **Ver como…** al pie de la barra lateral.
2. Busque por nombre o correo electrónico y, si quiere, acote por **Padres**, **Educadores**, **Directores** o **Administradores**. Cada resultado muestra el rol, la agencia y el centro de la persona.
3. Pulse la fila de la persona (**Ver →**).

El portal se recarga como esa persona, en su pantalla de inicio, en su agencia.

Es un **inicio de sesión real como esa persona**, no un disfraz. Cada pantalla pide al servidor *sus* datos, con *sus* permisos. Lo que ve es lo que ella ve, y todo lo que haga se hace con su cuenta.

> **Importante:** Está actuando como esa persona. Mire, no cambie nada. Un mensaje enviado, un formulario firmado o un registro editado mientras ve como alguien se hace a su nombre.

## El aviso de salida

Mientras ve como alguien, una píldora naranja oscuro aparece en la parte superior de cada pantalla: **👁 Ver como *nombre* · *rol*** con un botón **Salir**.

**Salir** restablece su propia sesión (su cuenta, su agencia activa y cualquier vista previa de rol que tuviera) y recarga el panel. No necesita volver a iniciar sesión.

## Conviene saber

- **Solo afecta a esta pestaña del navegador.** Su propia sesión se aparta en la pestaña y se restablece al salir. Otra pestaña donde haya iniciado sesión sigue siendo usted.
- **La sesión prestada dura dos horas.** Después deja de funcionar; pulse **Salir** y empiece de nuevo si aún la necesita.
- **Cada inicio queda auditado.** El registro de auditoría anota quién empezó a ver como quién, con la dirección IP y el dispositivo.
- **La vista previa de un rol se recuerda para su cuenta en este dispositivo**, así que su próximo inicio de sesión se abre en la misma vista previa. Vuelva a ponerla en **Superadministrador (predeterminado)** cuando termine.

## Preguntas comunes

??? ¿La persona sabe que vi como ella? | No se le notifica. El inicio queda escrito en el registro de auditoría.
??? ¿Por qué no puede hacerlo un administrador de agencia? | Ver los datos de otra persona como ella es un poder de nivel de plataforma, así que solo lo tienen los administradores de la plataforma.
??? Previsualicé un rol y ahora a mi menú le faltan elementos. | Sigue en vista previa. Vuelva a poner la lista Ver como en Superadministrador (predeterminado).

Vea también: [Administración de la plataforma (resumen)](platform-admin-overview),
[Registro de auditoría](audit-log), [Gestionar más de una agencia](multi-agency-switching).
