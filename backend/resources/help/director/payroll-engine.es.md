---
title: Motor de nómina: nóminas, recibos de pago y remesas
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=payruns, payroll?tab=remit, payroll?tab=ytd, hr-settings
---
# Motor de nómina: nóminas, recibos de pago y remesas

El motor de nómina calcula el pago de cada persona con todas las deducciones canadienses (CPP, EI, QPIP e impuesto sobre la renta), emite los recibos de pago y muestra lo que debe a la CRA. Forma parte del complemento de RR. HH. de KiddieTrac y está **apagado** hasta que un administrador de la agencia lo encienda. Mientras está apagado, su exportación de nómina funciona exactamente igual que antes.

## Encenderlo (administradores de la agencia)

Abra **Ajustes de RR. HH.** y busque **Motor de nómina**.

1. Lea el aviso y márquelo: **consulte con su contador antes de su primera nómina real**. Lo acepta una sola vez.
2. Marque **Motor de nómina encendido**.
3. Escriba su **cuenta de nómina de la CRA** (9 dígitos, RP, 4 dígitos), su **número de Revenu Québec** si tiene personal en Quebec, su **tipo de remitente** y, si quiere, su **tasa de compensación laboral** (solo para una estimación en el informe de remesas).

Cada persona también necesita una **configuración fiscal de nómina** en su perfil de RR. HH. (provincia de empleo, frecuencia de pago) y una tarifa de pago. El personal llena su propio TD1 en **Mis RR. HH. → Formularios fiscales**.

## Una nómina

Abra **Nómina → Nóminas** y elija **Nueva nómina**. Elija el centro (o todos los centros), el período y la fecha de pago.

- Las horas vienen del reloj (hojas de horas): el tiempo pagado de cada persona, con las horas extra y los reembolsos aprobados.
- Cada línea muestra horas, pago bruto, CPP, EI, impuesto y pago neto. Abra **⋮ → Cómo se calculó** para ver cada cifra, el acumulado del año antes de este pago y las tablas de la CRA usadas.
- En un borrador puede añadir un bono, un beneficio gravable o una deducción después de impuestos, o dejar fuera a alguien. **Recalcular** vuelve a leer el reloj.

Luego: **Enviar a revisión → Aprobar → Finalizar y emitir recibos.** Quien aprueba no puede cobrar en esa nómina. Al finalizar, cada persona recibe su recibo en la aplicación y un correo que dice que está listo, sin importes.

Una nómina finalizada nunca se cambia. Para corregirla, use **⋮ → Hacer un ajuste**: paga lo que cambió desde entonces (por ejemplo, un fichaje corregido tarde).

## Ver un recibo de pago

Los administradores y directores (para el personal de sus centros) pueden abrir un recibo en el portal sin su contraseña. **Descargar**, y cualquier recibo enviado por correo al empleado, siempre es el archivo protegido con contraseña. Cada consulta queda en el registro de auditoría.

## Remesas

**Nómina → Remesas** suma las nóminas finalizadas por período de remesa: CPP y EI (ambas partes) e impuesto que se deben a la CRA, lo que se debe a Revenu Québec por el personal de Quebec, y la fecha de vencimiento según su tipo de remitente. Úselo para llenar su comprobante PD7A y revíselo con su contador.

## Saldos iniciales (administradores de la agencia)

Si empieza a mitad de año, escriba en **Nómina → Saldos iniciales** lo que su nómina anterior pagó a cada persona este año, para que el CPP y el EI se detengan en el máximo anual y el acumulado de los recibos sea correcto. Puede pegar una hoja de cálculo. No puede escribir el suyo.

## Lo que muestra el recibo de pago

Todos los recibos de pago en PDF tienen el mismo diseño:

- **Una franja con el color de su agencia** con su logotipo en una insignia redonda, su razón social, dirección, teléfono, correo, número de empresa de la CRA (si está configurado) y el centro.
- **El empleado**: nombre completo, ID de empleado, puesto, centro, tipo de empleo, fecha de inicio, domicilio, teléfono y correo.
- **Datos del pago**: el SIN se muestra solo como **XXX-XXX-123** (nunca el número completo), provincia de empleo, periodo de pago, fecha de pago, número de recibo, nómina, tarifa y forma de pago (en el depósito directo solo los últimos 4 dígitos de la cuenta).
- **Acumulado del año y este pago**: bruto, deducciones y neto, con el **pago neto** en un recuadro grande.
- **Ingresos** (tarifa, horas, este pago, acumulado del año) y **Deducciones** (CPP o QPP, EI o QPIP, impuesto federal y provincial, otras) con totales. Las contribuciones del empleador y los saldos de permisos se muestran como información.
- Las **observaciones** de la nómina, sus **términos y condiciones** y una marca de agua diagonal suave **NO NEGOCIABLE** con la línea **NO NEGOCIABLE — ESTO NO ES UN CHEQUE** en cada página.

Un recibo en el que la nómina no calculó deducciones (un recibo manual o solo el pago bruto) muestra lo registrado e indica **Deducciones no calculadas en KiddieTrac**. No se inventa ninguna cifra.

## Términos y condiciones del recibo (administradores de la agencia)

Abra **Configuración de nómina** y busque **Términos y condiciones del recibo de pago**. Escriba una declaración por línea. Indique cuántos días tiene el personal para informar de un error en el pago (30 por defecto); `{days}` en su texto se sustituye por ese número. Si deja el texto estándar, cada persona lo lee en su idioma (inglés, francés, español o hindi). **Usar el texto estándar** lo restablece.

## Contraseñas de los recibos de pago

Cada recibo de pago en PDF que una persona descarga o recibe por correo está protegido con contraseña, como iLearn protege sus recibos: recibos del motor de nómina, recibos de **Generate payroll** o añadidos a mano, recibos solo con el bruto, estados semanales y copias de T4, T4A y RL-1. El archivo se bloquea con cifrado AES-256; una vez abierto se puede imprimir y copiar.

- **La contraseña:** las 3 primeras letras del apellido en MAYÚSCULAS y luego la fecha de nacimiento como MMDDAAAA (mes, día, año). Por ejemplo, Jordan Ellis, nacido el 2 de marzo de 1990, usa `ELL03021990`.
- **Sin fecha de nacimiento en el expediente:** se usa el último día del período de pago (el 31 de diciembre del año fiscal para un comprobante fiscal). **Sin apellido:** las 3 primeras letras del nombre. Un recibo nunca se envía sin bloqueo.
- **El personal recibe el formato, nunca la contraseña**, en la lista de recibos de la aplicación y en el correo del recibo. El correo no incluye importes.
- **A quién afecta:** **Payroll settings → Payslip passwords** muestra a todas las personas pagadas aquí sin fecha de nacimiento o sin apellido en su expediente. Añada el dato en su expediente de RR. HH. y su próxima descarga usará la contraseña habitual.
- Abrir un recibo en el portal (la vista de oficina, para administradores y directores) no pide contraseña. Esta protección no se puede desactivar.
