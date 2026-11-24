---
title: Alertas de seguridad y supervisión
category: Compliance
order: 32
roles: agency_admin, platform_admin
---

# Alertas de seguridad y supervisión

KiddieTrac vigila el registro de auditoría en busca de actividad de inicio de sesión sospechosa y la registra en **Ajustes → Alertas de seguridad** (administradores de la plataforma).

## Qué se detecta

- **Fuerza bruta** — muchos inicios de sesión fallidos desde una misma dirección IP en poco tiempo.
- **Martilleo de MFA** — intentos fallidos repetidos de autenticación de dos factores en una cuenta.
- **Relleno de credenciales** — muchos inicios de sesión fallidos dirigidos a una sola cuenta.

Cada detección se registra con una gravedad, el sujeto (IP o cuenta) y una descripción en lenguaje claro, y se puede marcar como **resuelta** una vez que la haya revisado.

## Detrás de escena

Una tarea programada analiza el registro de auditoría cada 15 minutos. Cada inicio de sesión fallido, fallo de MFA y cambio de contraseña se escribe en un rastro de auditoría a prueba de manipulaciones — la misma evidencia que busca un auditor de seguridad.
