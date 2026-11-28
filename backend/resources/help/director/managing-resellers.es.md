---
title: Administrar distribuidores
---
# Administrar distribuidores

**Distribuidores** es la pantalla del equipo de la plataforma para los socios que venden KiddieTrac con su propia marca. Cada distribuidor es dueño de sus agencias y define una sola marca para ellas. Aquí crea un distribuidor, ajusta su marca y licencia, elige sus agencias, da acceso a personas, fija su comisión y prepara su propia aplicación Android con su marca.

[[open: #resellers | Abrir Distribuidores]]

## Crear un distribuidor

1. Abra **Distribuidores** en la sección **Reseller** del menú.
2. Pulse **+ Nuevo distribuidor** en el banner.
3. Escriba el **Company name** (nombre de la empresa) y pulse **Create**.
4. El nuevo distribuidor queda seleccionado en la lista de la izquierda, en su pestaña **Marca**. Su **App build code** aparece junto a las pestañas.

## Ajustar su marca y licencia

1. Elija el distribuidor en la lista de la izquierda (cada uno muestra su número de agencias y su estado).
2. En la pestaña **Marca**, complete **Nombre de la aplicación y del producto** y **Correo de soporte**, suba los **Logotipos** y ajuste los **Colores** y el **Banner de página**, mirando la **Vista previa en vivo**.
3. En **Licencia**, marque **Ocultar KiddieTrac en todas partes** si la licencia lo incluye: sus agencias solo verán el nombre y los logotipos del distribuidor en pantallas, correos, mensajes de texto, documentos y notificaciones. Solo la plataforma puede cambiar esta casilla.
4. Marque **Permitir que mis agencias usen su propio logotipo y colores** si sus agencias pueden reemplazar la marca.
5. Pulse **Guardar marca**.

## Elegir sus agencias

1. Abra la pestaña **Agencias**.
2. Marque cada agencia que pertenece a este distribuidor. Una agencia atenuada con «— with …» ya pertenece a otro distribuidor: desmárquela primero allí.
3. Pulse **Guardar agencias**. Aparece un aviso verde «Agencies updated.» y las agencias marcadas muestran la marca del distribuidor.

## Dar acceso a personas

1. Abra la pestaña **Personas**. Muestra quién administra el distribuidor.
2. Escriba el **Email of an existing account** (correo de una cuenta existente) y pulse **Añadir**. La persona recibe el rol Distribuidor: trabaja como administrador de agencia en cada agencia del distribuidor, añade agencias, administra a sus administradores, ve la facturación y la comisión, y cambia la marca.
3. Pulse **Quitar** junto a una persona para retirarle el rol.

[[show: #resellers @ People | Mostrarme la pestaña Personas]]

## Fijar la comisión

1. Abra la pestaña **Comisión**.
2. Escriba el **Percentage** (porcentaje) del dinero cobrado antes de impuestos.
3. Escriba el **Flat per paying agency ($)** (monto fijo por agencia que paga), o déjelo en 0.
4. Pulse **Guardar comisión**. Pulse **Monthly statement →** para abrir el extracto de **Facturación y comisiones** de ese distribuidor.

## Preparar la aplicación del distribuidor

1. Abra la pestaña **Aplicación**.
2. Escriba el **Package id** (por ejemplo `com.littlesteps.app`). Es permanente una vez publicada la aplicación.
3. Ajuste el **Status** (**Not started**, **Kit downloaded**, **Built**, **Published**) y, si quiere, **Version number (optional)** y **Version name (optional)**.
4. Añada el enlace de **Ficha de Google Play** cuando exista, y marque **El archivo de Firebase está listo** cuando lo esté.
5. Pulse **Guardar**, o **⬇ Download build kit** para guardar y descargar el archivo del kit para la computadora de compilación.
6. Avance por la **Lista de comprobación**; cada línea se marca sola cuando está hecha. **On the phone** muestra el icono (el logotipo cuadrado de la pestaña Marca) y el nombre que verá la gente.

> ℹ️ La gente también puede usar la aplicación estándar de KiddieTrac: cambia a la marca del distribuidor después de iniciar sesión.

## Quién puede hacer qué

| Rol | Qué puede hacer |
|---|---|
| Administrador de la plataforma | Todo lo que hay en esta pantalla. |
| Distribuidor | No ve esta pantalla. Cambia su marca en **Marca** y lee sus condiciones en **Facturación y comisiones**. |
| Administrador de agencia | No ve esta pantalla. |

## Preguntas frecuentes y cuando algo sale mal

??? «No account uses that email. Create the person first (Admin → Users), then add them here.» | La persona necesita una cuenta de KiddieTrac antes de recibir el rol Distribuidor. Cree el usuario y luego añada su correo de nuevo.
??? «Several accounts share that email…» | Pregunte a la persona qué cuenta usar y añada esa.
??? «That short name is already used by a reseller or an agency.» | Elija otro nombre de empresa al crear el distribuidor.
??? «That is the KiddieTrac app's own package id; a reseller app needs its own.» | Escriba un identificador propio del distribuidor, como com.sunombre.app.
??? «Choose the package id first.» | Complete **Package id** antes de pulsar **⬇ Download build kit**.
??? La lista dice «No resellers yet.» | Pulse **+ Nuevo distribuidor** para crear el primero.
??? ¿Se envía un correo cuando añado a una persona o guardo la marca? | No. La persona añadida ve la sección Reseller la próxima vez que inicia sesión o recarga la página; las agencias ven la marca guardada la próxima vez que abren una página.

## Pantallas relacionadas

[[open: #reseller | Resumen del distribuidor]] [[open: #reseller-billing | Facturación y comisiones]] [[open: #platform-agencies | Todas las agencias]]

- [[reseller-all-my-agencies]]
- [[reseller-billing-and-commissions]]
- [[white-label-branding]]
