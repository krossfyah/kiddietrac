---
title: Planes y precios (lista de precios de ventas)
---
# Planes y precios (lista de precios de ventas)

**Planes y precios** es la lista de precios propia de KiddieTrac para las propuestas: los planes, complementos,
servicios únicos y descuentos que un representante agrega a una propuesta con **+ Añadir plan o descuento predefinido…**, y las
condiciones con las que empieza cada propuesta nueva. Los superadministradores la abren en **Sales › Plans & pricing**
del menú de la plataforma; los representantes, desde el mosaico **Planes y precios** de su pantalla de inicio. Cambiar
un precio aquí nunca cambia una propuesta ya hecha.

[[open: #sales-plans | Abrir planes y precios]]

## Las cinco pestañas

- **📦 Planes** — el plan del cliente define qué funciones obtiene (su **Nivel de funciones**).
- **🧩 Complementos** — se venden además de un plan; cada uno activa las funciones que usted marque.
- **🛠️ Servicios únicos** — instalación, capacitación, importación de datos: se cobran una vez, sin licencia.
- **🏷️ Discounts** — porcentaje o monto de descuento, agregado a una propuesta desde el mismo menú.
- **📄 Condiciones de la propuesta** — validez, contacto «Prepared by», condiciones, impuestos y probabilidades por etapa.

Cada artículo recibe un número de parte la primera vez que se guarda (KT-PLN planes, KT-ADD complementos, KT-SVC
servicios, KT-DSC descuentos). Los números de parte aparecen en propuestas, licencias y facturas, y las ventas se
informan por ellos.

## Agregar o cambiar un plan, complemento o servicio

1. Abra la pestaña y pulse **➕ Añadir plan** (o **➕ Añadir complemento**, **➕ Añadir servicio**). Aparece una tarjeta nueva arriba.
2. Escriba el nombre y el precio, y elija **/mes** o **/año** (un servicio siempre es **una sola vez**).
3. Para un plan, elija el **Nivel de funciones** (**Funciones Inicial**, **Funciones Crecimiento** o **Agencia (todas las funciones)**).
4. Para un complemento, marque lo que activa en **Activa** (por ejemplo **Mensajes de texto (SMS)**, **Nómina**, **Marca blanca**).
5. Indique **Centres included (blank = no centre cap)** si el artículo incluye cierto número de centros.
6. Escriba la **Descripción** que aparece en las propuestas y pulse **Guardar**. Aparece un aviso **✅ Guardado** y el número de parte se muestra en la tarjeta.

## Agregar un descuento

1. Abra **🏷️ Discounts** y pulse **➕ Añadir descuento**.
2. Póngale nombre (por ejemplo «Descuento para organizaciones sin fines de lucro»), escriba cuánto descuenta y elija **% de descuento** o **$ de descuento**.
3. Elija a qué se aplica en **Se aplica a**: **toda la propuesta**, **conceptos recurrentes (mensuales / anuales)** o **conceptos únicos**. La línea «Shows as:» muestra cómo se verá en la propuesta.
4. Pulse **Guardar**.

## Configurar los valores predeterminados de las propuestas

1. Abra **📄 Condiciones de la propuesta**.
2. Configure **Las propuestas son válidas durante** … **days**, y el **Cargo / equipo**, **Correo de ventas** y **Teléfono de ventas** que se muestran como contacto «Prepared by» (las respuestas van a ese correo).
3. Edite **Condiciones de la propuesta (breves, bajo los precios)** y **Términos y condiciones (página propia, antes de las firmas)**. `{days}` se reemplaza por el número de días.
4. Agregue **Impuestos predeterminados** con **+ Añadir impuesto** (hasta 5), y configure la **Probabilidad de cierre por etapa** de cada etapa para el pronóstico ponderado del Pipeline.
5. Pulse **Guardar** («Proposal defaults saved»).

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Representante de ventas | Agregar, cambiar y eliminar planes, complementos, servicios y descuentos, y editar los valores predeterminados de las propuestas. |
| Administrador de plataforma (superadministrador) | Lo mismo, desde el menú Sales de la plataforma. |
| Personal de las agencias | Nunca ve esta pantalla: el plan de una agencia está en su configuración, no aquí. |

## Preguntas frecuentes y cuando algo sale mal

??? «Give it a name» / «Give the discount a name» | Todo artículo necesita un nombre para que **Guardar** funcione.
??? «Choose the feature level this plan gives» | Un plan debe tener un **Nivel de funciones**; elija uno y guarde de nuevo.
??? «Enter how much it takes off» o «A percentage can be at most 100» | Escriba un descuento mayor que 0, y no más de 100 para **% de descuento**.
??? Cambié un precio pero una propuesta antigua sigue mostrando el anterior. | Es a propósito: cada propuesta guarda su propia copia. Edite la línea de la propuesta, o haga una propuesta nueva.
??? ¿Qué pasa cuando elimino un artículo? | Se quita para siempre de esta lista. Las propuestas que ya lo usan conservan sus líneas.

## Pantallas relacionadas

[[open: #sales-quotes | Propuestas]] [[open: #sales | Pipeline]]

Vea también [[sales-proposals]] y [[sales-pipeline]].
