---
title: Plantillas de correo — cambiar el texto
category: Settings
order: 42
roles: agency_admin, platform_admin
---
# Plantillas de correo — cambiar el texto

**Ajustes → Plantillas de correo** le permite reescribir los correos que KiddieTrac envía en nombre de su
agencia, con su propia voz. Su logotipo, colores y diseño se aplican
automáticamente; usted solo cambia las palabras.

[[open: #email-templates | Abrir Plantillas de correo]]

## Las plantillas que puede editar

| Plantilla | Quién la recibe y cuándo |
|---|---|
| Bienvenida del proveedor | Las familias, para presentarles a su proveedor o educador. Su texto **Nuestro cuidado** es también el de los valores de cuidado de su agencia en la bienvenida del personal |
| Resumen diario para padres | Los padres, cada tarde, con un resumen del día de su hijo |
| Bienvenida de incorporación | Cualquier persona que termina la incorporación (excepto el personal, que recibe la bienvenida del personal) |
| Bienvenida del personal (educadores y proveedores) | Un educador, director o visitador a domicilio nuevo al terminar su incorporación, con copia a su(s) director(es) y a los administradores de la agencia |
| Invitación / definir contraseña | Una persona a la que invita, con sus datos de acceso |
| Anuncio | Un diseño de anuncio reutilizable |

## Editar una

- [ ] Elíjala en **Plantilla que desea editar**.
- [ ] Cambie el texto en cada cuadro. Las palabras entre dobles llaves, como `{{name}}` o `{{agency_name}}`, se rellenan para cada persona: consérvelas.
- [ ] Pulse **👁️ Vista previa** para verla tal como llegará.
- [ ] Pulse **Enviarme una prueba** para recibirla en su propia bandeja de entrada.
- [ ] Pulse **Guardar plantilla**.

[[show: #email-templates @ Template to edit | Mostrarme el selector de plantillas]]

![La plantilla de bienvenida del personal, con sus etiquetas de combinación y cuadros editables](https://api.kiddietrac.com/help-img/email-template-staff-welcome.png)

**Restablecer valor predeterminado** devuelve el texto original de KiddieTrac si quiere empezar de nuevo.

## Bienvenida del personal: qué es automático

Usted escribe el encabezado, la bienvenida, los primeros pasos y la despedida. KiddieTrac añade el resto para
cada persona:

- sus **valores de cuidado**, tomados del texto **Nuestro cuidado** de la bienvenida del proveedor: edítelos
  allí y cambiarán ambos correos;
- un breve recorrido por las funciones del portal que usará;
- un enlace a **Ayuda y guías**;
- a quién preguntar: el nombre y los datos de contacto de su director.

## Preguntas

??? Guardé un cambio, pero un correo sigue mostrando el texto anterior. | Los correos ya enviados conservan las palabras con las que se enviaron. El siguiente correo usará su nuevo texto.
??? ¿Puede cada centro tener su propio texto? | No. Las plantillas son por agencia.
??? ¿Dónde cambio el logotipo y los colores? | En la pantalla **🎨 Marca**. Las plantillas los usan automáticamente.

Vea también: [Ajustes de correo y resúmenes](email-and-digests),
[Invitar a padres y personal](inviting-parents-and-staff),
[Marca blanca](white-label-branding).
