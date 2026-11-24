---
title: Modo quiosco para el registro con tableta
category: Daily Operations
order: 30
---
# Modo quiosco para el registro con tableta

El modo quiosco convierte una tableta en la puerta principal de su centro en una pantalla de autoservicio de entrada y salida para los padres. Los padres tocan la tarjeta de su hijo, escriben un PIN de 4 a 6 dígitos y Kiddietrac registra el evento de asistencia con la marca de origen quiosco: sin dedicar tiempo del personal a las entradas y recogidas.

## Activar el quiosco en un centro

1. Barra lateral → **Administración → Centros**.
2. Haga clic en **Editar** en el centro que debe usar un quiosco.
3. En la sección **Modo quiosco**, marque **Activar el quiosco para este centro**.
4. Kiddietrac genera una URL de quiosco única y la muestra en el campo URL. Haga clic en **Copiar URL**.

## Configurar la tableta

Abra la URL copiada en el navegador de la tableta. Añádala a la pantalla de inicio como PWA para que se abra a pantalla completa, sin barra del navegador. El quiosco muestra a todos los niños inscritos en el centro con su foto (o inicial), nombre, sala y estado actual (EN EL CENTRO o AUSENTE).

La tableta no requiere iniciar sesión: el token único de la URL concede acceso de solo lectura a la lista. Para cualquier escritura (entrada / salida) se exige el PIN.

## Definir los PIN de los padres

Cada padre que recoge necesita su propio PIN de 4 a 6 dígitos. Para definir uno:

1. Barra lateral → **Administración → Familias** → haga clic en la tarjeta de la familia.
2. En la sección **Tutores**, busque al tutor (debe tener **can_pickup** activado).
3. Haga clic en **Definir PIN del quiosco**.
4. Escriba un número de 4 a 6 dígitos → Guardar.
5. Comunique el PIN al padre por otro medio (mensaje de texto, correo electrónico, en persona).

Repita el proceso con cada tutor que vaya a usar el quiosco. No se puede definir un PIN a los padres sin derecho de recogida.

## Cómo funciona el flujo del padre

1. Toque la tarjeta del niño → se abre una ventana con un teclado numérico.
2. Escriba el PIN de 4 dígitos → Kiddietrac lo verifica con los tutores de la familia que tienen can_pickup.
3. Si coincide, Kiddietrac registra una entrada (o una salida: se alterna según el estado actual del niño).
4. La tarjeta cambia a EN EL CENTRO (verde) en una entrada, o vuelve a AUSENTE (gris) en una salida.

## Rotar la URL del quiosco

Si la URL se filtra (por ejemplo, alguien compartió una captura de la página de inicio de la tableta), haga clic en **Rotar token** en la sección Quiosco del centro. La URL anterior deja de funcionar al instante. Abra el navegador del quiosco y pegue la nueva URL.

## Desactivar el quiosco

Desmarque **Activar quiosco** en la ventana de edición del centro. La URL deja de funcionar al instante. Los PIN siguen guardados: vuelva a activarlo para recuperarlo todo.
