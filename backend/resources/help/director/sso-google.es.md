---
title: Iniciar sesión con Google
category: Getting Started
order: 8
---
# Iniciar sesión con Google

Olvídese de la contraseña. Inicie sesión en KiddieTrac con su cuenta de Google.

## Cómo usarlo

En la pantalla de inicio de sesión:
1. Toque **Continuar con Google**.
2. Elija su cuenta de Google o inicie sesión con sus credenciales habituales de Google.
3. Conceda a KiddieTrac permiso para leer su correo electrónico y su nombre.
4. Se le redirige de vuelta a KiddieTrac con la sesión iniciada.

## Qué ocurre entre bastidores

- KiddieTrac recibe su correo electrónico y su nombre de Google a través del flujo OAuth.
- Comprobamos si su correo electrónico ya tiene una cuenta de KiddieTrac:
  - **Sí** → se vincula y usted inicia sesión
  - **No** → se crea una cuenta automáticamente con su nombre y correo electrónico de Google
- A partir de entonces, puede iniciar sesión con Google o con contraseña: ambos funcionan.

## Privacidad

- Solo pedimos el correo electrónico y el perfil básico (nombre, avatar). Nunca pedimos sus contactos ni su calendario de Google.
- KiddieTrac no ve su contraseña de Google.
- Puede desvincular el inicio de sesión con Google en cualquier momento desde los ajustes de su perfil.

## Otros proveedores

Apple Sign In y Microsoft Sign In siguen el mismo esquema. Se activan cuando la plataforma configura los ID de cliente correspondientes.

## Para el administrador de la plataforma (usted)

Configure las credenciales de OAuth:
1. Vaya a https://console.cloud.google.com/apis/credentials
2. Cree un ID de cliente OAuth 2.0
3. Añada `https://api.kiddietrac.com/api/v1/sso/google/callback` como URI de redirección
4. Copie el ID de cliente y el secreto de cliente en su `.env`:
   ```
   GOOGLE_CLIENT_ID=…
   GOOGLE_CLIENT_SECRET=…
   ```
5. El botón «Continuar con Google» se activa automáticamente.
