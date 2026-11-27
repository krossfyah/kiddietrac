---
title: Panel de cumplimiento
---
# Panel de cumplimiento

**Administration › Compliance** es una sola página que responde «¿qué hay que resolver antes de que lo vea un
inspector?»: certificados del personal vencidos o por vencer, responsables que no han activado el inicio de sesión en
dos pasos y centros llenos o casi llenos. Ábrala al comienzo de cada semana. La página solo lee sus registros:
corrija las cosas en la pantalla donde está el registro, vuelva y el número baja.

[[open: #compliance | Abrir el panel de cumplimiento]]

## Leer la página

1. Abra **Administration › Compliance**. El encabezado dice **What needs attention**.
2. Mire los cinco recuadros de arriba: **Expired certs**, **Expiring (30d)**, **MFA not enrolled**, **At/over capacity** y **Tight (95%+)**. Rojo significa actuar ya; ámbar, planificarlo.
3. Desplácese a las tarjetas de abajo para ver los nombres detrás de cada número.

## Renovar certificados del personal

La tarjeta **Staff certifications** lista cada certificado (por ejemplo, primeros auxilios, RCP o registro de
educador) vencido o que vence en 30 días, con la persona, el tipo y «Expires … (in 4 days)» o «(3 days ago)».

1. Anote quién está en la lista.
2. Pida el certificado renovado a la persona, o abra su registro de personal y súbalo con la nueva fecha de vencimiento.
3. Vuelva a **Administration › Compliance**. Cuando se guarda la nueva fecha, la persona sale de la lista.

Cuando nadie está pendiente, la tarjeta dice **✅ Every cert is current.**

## Lograr que los responsables usen el inicio de sesión en dos pasos

La tarjeta **MFA not enrolled** lista a los directores de centro y administradores de agencia que entran solo con
contraseña, con su correo y su último inicio de sesión.

1. Envíe un mensaje a cada persona pidiéndole que active el inicio de sesión en dos pasos.
2. La persona abre la pantalla de dos pasos, escanea el código con una aplicación de autenticación y escribe el código de 6 dígitos para confirmar.
3. Actualice el panel: sale de la lista. Cuando todos están inscritos dice **✅ Every director + admin has MFA enrolled.**

[[open: #mfa | Abrir el inicio de sesión en dos pasos]]

## Vigilar la capacidad de los centros

La tarjeta **Centre capacity** muestra los niños inscritos de cada centro frente a sus plazas con licencia, en
porcentaje y en una barra: verde por debajo del 80 %, ámbar desde el 80 %, rojo desde el 95 %.

1. Busque los centros en rojo.
2. Antes de inscribir a alguien más allí, revise la licencia y la lista de espera.
3. Si la capacidad con licencia está mal, corríjala en los ajustes del centro para que la barra se mida con el número correcto.

Si las normas de su provincia incluyen capacitaciones y verificaciones del personal, otra tarjeta muestra quién no tiene qué elemento.

## Quién puede hacer qué

| Rol | Ve |
|---|---|
| Administrador de agencia | Todos los centros de la agencia activa. |
| Director de centro | Solo su(s) centro(s). |
| Auditor | La misma página, en solo lectura. |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |

## Preguntas frecuentes y problemas

??? Se renovó un certificado pero la persona sigue en la lista. | El nuevo certificado aún no se guardó con su nueva fecha de vencimiento. Súbalo con la fecha en el registro de personal.
??? La página dice «Could not load». | Actualice la página. Si sigue pasando, abra un ticket desde Ayuda.
??? La tarjeta de capacidad dice «No centres in this agency.» | La agencia aún no tiene un centro abierto. Un administrador de agencia lo agrega en la pantalla de centros.
??? ¿Esta página envía correos? | No. Solo muestra la lista; usted decide a quién contactar.

## Pantallas relacionadas

[[open: #inspection | Lista de inspección]] [[open: #audit-logs | Registro de auditoría]]

Vea también [[audit-log]] y [[compliance-and-reporting]].
