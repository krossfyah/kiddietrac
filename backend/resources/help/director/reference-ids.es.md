---
title: Identificadores de referencia de niños, familias y personal
category: Administration
order: 30
---
# Identificadores de referencia de niños, familias y personal

Cada niño, familia, miembro del personal y padre de su agencia tiene un identificador de referencia, como **ILH-C-0042**. Úselo para encontrar un registro rápido, para citarlo por teléfono y para relacionar personas entre documentos y hojas de cálculo.

## Cómo se construye un ID

- **Las letras de su agencia**, por ejemplo **ILH**. De forma predeterminada son las iniciales del nombre de su agencia.
- **Una letra de tipo:** **C** niño, **F** familia, **S** personal, **P** padre o tutor.
- **Un número** que va en aumento dentro de su agencia, para cada tipo.

Cada registro nuevo recibe su ID automáticamente. Los números nunca se reutilizan ni se renumeran, aunque un niño se dé de baja o una familia se vaya.

Una persona que es personal y padre en su agencia tiene dos ID: uno **S** y uno **P**.

## Dónde aparecen los ID en el portal

Verá un ID en un pequeño cuadro gris. Pulse el botón de copiar (**⧉**) junto a él para copiarlo.

- **Lista de niños y ficha del niño:** bajo el nombre del niño, con la etiqueta **ID del niño**. La sección de familia de la ficha muestra el **ID de la familia**.
- **Lista de familias y detalles de la familia:** bajo el nombre de la familia, con el ID de cada niño al lado.
- **Gestión de usuarios:** bajo el nombre de cada persona. Su ficha muestra su **ID de personal**, su **ID de padre**, o ambos.
- **Sus propios ID:** **Ajustes → Perfil → Su ID de KiddieTrac**.

Los educadores ven el ID de cada niño en su propia lista de niños y en la página del niño.

## Buscar por ID

Escriba un ID en el cuadro **🔍 Buscar…** en la parte superior de la barra lateral, o pulse **Ctrl + K** (**⌘K** en Mac) para abrir la paleta de comandos. Los resultados muestran el ID junto a cada nombre, y los padres ahora tienen su propio grupo **Padres**.

La búsqueda es flexible. Todos estos encuentran **ILH-C-0042**:

- el ID completo: `ILH-C-0042`
- con espacios o sin guiones: `ilh c 42`, `ILHC42`
- el tipo y el número: `C-0042`, `c42`
- solo el número: `0042` o `42`
- el comienzo de un ID: `ILH-C-00` lista los ID que empiezan así

Una palabra simple se trata como un nombre, así que escribir un apellido funciona como antes. La misma coincidencia flexible funciona en el cuadro de filtro sobre las listas, de modo que puede pegar un ID para acotar una tabla.

Quién puede encontrar qué:

- Los **administradores de agencia** buscan en toda la agencia.
- Los **directores de centro** buscan solo en sus propios centros.
- Los **administradores de plataforma** deben elegir primero una agencia.
- Los **educadores** pueden escribir el ID de un niño en la búsqueda de su propia lista de niños.

> **Consejo:** Un ID impreso con las letras antiguas de su agencia sigue encontrando el registro, así que los formularios del año pasado siguen funcionando tras un cambio de prefijo.

## Cambiar las letras de su agencia

Solo un **administrador de agencia** (o un administrador de plataforma) puede cambiar el prefijo.

1. Vaya a **Resumen de la agencia → ✏️ Editar agencia → Abrir Marca →**.
2. Abra la pestaña **🌎 Región e identificadores**.
3. En **Prefijo del ID de referencia**, escriba de 2 a 6 letras o dígitos, comenzando con una letra.
4. Pulse **Guardar prefijo**. Los ejemplos bajo el cuadro se actualizan para mostrar los nuevos ID.

[[open: #admin-branding?tab=region | Abrir Marca → Región e identificadores]]

El cambio se aplica a todos los ID a la vez. Dos agencias no pueden usar las mismas letras. El cambio queda registrado en el registro de auditoría.

## ID en los documentos

Los ID se imprimen en los documentos que produce, para poder relacionar siempre una página con su registro:

- **Tarjeta de emergencia:** el ID del niño y el ID de la familia.
- **Informe de incidente:** el ID del niño. La copia del personal también muestra el ID de la persona que lo registró.
- **Facturas:** el ID de la familia junto al nombre de facturación, en todos los estilos de factura.
- **Recibos, estados de cuenta y el libro mayor de la familia:** el ID de la familia o del titular de la cuenta.
- **Informe mensual de CWELCC:** el ID de cada familia.
- **Portafolio y boletín de calificaciones:** el ID del niño.
- **Nóminas y documentos de nómina:** el ID del miembro del personal. Las plantillas de documentos de nómina pueden usar el marcador **{{payee_id}}**.
- **Formularios firmados:** el ID del firmante junto a la fecha de firma, cuando el formulario se firmó en el portal. Los formularios firmados mediante un enlace sin inicio de sesión no lo tienen.

## ID en las exportaciones

Las exportaciones CSV y los informes ahora incluyen una columna de ID, para que pueda relacionar filas entre hojas de cálculo. Esto incluye las exportaciones de niños, familias y personal, nómina, subvenciones, CWELCC, controles de sueño, verificaciones de antecedentes, acuses de recibo de políticas, envíos de formularios, financiación de la fuerza laboral, OCCMS y Ohio PFCC, los informes integrados y las hojas de horas. En la exportación de niños, la columna de ID muestra el ID de referencia.

??? ¿Un nuevo formato de número de factura usará estos ID? | No. Los números de factura tienen su propio formato en Marca → Facturas. Consulte [Estilo y numeración de facturas](invoice-numbering-and-style).
??? ¿Puedo asignar a un niño un número en particular? | No. Los números se asignan en orden y nunca cambian, así que un ID siempre apunta al mismo registro.
??? Un director buscó un ID y no obtuvo nada. | Los directores solo encuentran registros de sus propios centros. Un ID de otro centro no devuelve resultados.

Consulte también: [Marca blanca](white-label-branding) · [Acciones masivas y CSV](bulk-actions-and-csv) · [Paleta de comandos](command-palette)
