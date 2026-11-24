---
title: Termómetro Bluetooth en el quiosco
category: Daily Operations
order: 80
---
# Termómetro Bluetooth

Lea la temperatura de un niño directamente desde un termómetro Bluetooth emparejado con el quiosco.

## Configuración

1. Empareje el termómetro con la tableta del quiosco (configuración única, desde los ajustes Bluetooth del sistema).
2. El navegador web del quiosco (Chrome 90+, Safari 16.4+) solicita el permiso de Bluetooth la primera vez que se necesita una temperatura.

## A la llegada

1. El padre o el personal selecciona al niño en el quiosco.
2. Toque el botón **Tomar la temperatura**.
3. El navegador muestra el cuadro de emparejamiento de Web Bluetooth.
4. Elija el termómetro emparejado.
5. La lectura aparece en pantalla y rellena automáticamente el registro del quiosco.

## Qué se registra

Cada lectura se guarda en `check_events.temperature_c` con:
- La temperatura en °C
- El método: `bluetooth` / `manual` / `scanner`
- El ID del evento de entrada vinculado (así la temperatura queda asociada a la llegada de hoy)

## Alertas de fiebre

Si la lectura es **≥ 38 °C**:
- Se envía una notificación **fever_alert** al personal del centro
- El quiosco muestra el mensaje «Comuníquese con el centro»
- La lectura se marca en el resumen diario de bienestar

## Requisitos

- HTTPS (la API Web Bluetooth no funciona por HTTP)
- Un termómetro compatible (cualquier dispositivo que exponga el servicio BLE estándar Health Thermometer `00001809-0000-1000-8000-00805f9b34fb`)
- Un navegador moderno con la API Web Bluetooth
- Permiso de Bluetooth concedido

## Termómetros compatibles

Funcionamiento comprobado: iHealth, A&D, Beurer FT 90, Withings Thermo. La mayoría de los termómetros BLE de consumo que usan el servicio estándar funcionarán sin código personalizado.

## Alternativa

Si Bluetooth no está disponible, la misma ventana del quiosco permite al personal introducir la temperatura manualmente con **Método = manual**.
