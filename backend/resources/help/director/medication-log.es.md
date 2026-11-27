---
title: Medicamentos
---
# Medicamentos

La pantalla Medicamentos reúne todas las órdenes permanentes de medicamentos de los niños de sus centros: qué medicamento es, cuánto y con qué frecuencia, si un padre lo autorizó y cada dosis administrada. Una dosis solo se puede registrar cuando ya consta la autorización de los padres. Úsela cuando una familia trae un medicamento, cuando un padre envía una solicitud desde su portal y para revisar el historial de dosis.

[[open: #medications | Abrir Medicamentos]]

## Añadir un medicamento nuevo

1. Abra **Operaciones > Medicamentos** y presione **+ Nuevo medicamento**.
2. Elija al **Niño**. Solo aparecen los niños inscritos actualmente.
3. Escriba el **Nombre del medicamento** (por ejemplo «Tylenol infantil») y su **Concentración** (por ejemplo «160mg/5mL»).
4. Elija la **Ruta** (vía de administración): **Oral**, **Topical**, **Inhaled**, **Injection** u **Other**.
5. Complete la **Dosis** (por ejemplo «5 mL») y la **Frecuencia** (por ejemplo «cada 4 horas si es necesario»).
6. Añada el **Motivo** (por ejemplo «fiebre / dolor»).
7. Revise **Comienza el** (aparece la fecha de hoy) y, si la orden termina, indique **Vence el (opcional)**.
8. Añada **Special instructions (optional)**, como «Dar con alimentos».
9. Si es con receta, marque **Es un medicamento con receta** y escriba el nombre del médico que lo recetó.
10. Presione **Save (status: pending parent auth)**. El medicamento aparece en la lista con la insignia ámbar **PENDING PARENT AUTH**.

## Registrar la autorización de los padres

Un padre debe dar su consentimiento por escrito antes de que se administre cualquier dosis.

1. Busque el medicamento con la insignia **PENDING PARENT AUTH**.
2. Presione **Registrar autorización de los padres**.
3. Complete **Authorizing parent user ID** como lo pide la ventana.
4. Presione **Marcar como autorizado**. La insignia cambia a verde (**ACTIVE**) y aparecen los botones **Registrar dosis** y **Suspender**.

> ℹ️ Cuando un padre pide un medicamento desde su portal (**💊 Ask the centre to administer a medication**), la solicitud llega a esta misma lista como **PENDING PARENT AUTH**. Confirme los detalles con la familia y luego autorícela.

## Registrar una dosis

1. En un medicamento **ACTIVE**, presione **Registrar dosis**.
2. Escriba la **Dosis administrada** (por ejemplo «5 mL»).
3. Elija el **Resultado**: **Administrada según lo recetado**, **Dosis parcial**, **Rechazada por el niño** o **Missed (not given)**.
4. Añada **Notas**, y un testigo si su centro lo usa (recomendado para narcóticos).
5. Presione **Registrar dosis**. La tarjeta muestra una dosis más y la hora de la última dosis.

Los educadores registran las dosis igual desde su propia pantalla **Medications to administer**.

## Ver el historial de dosis

1. Presione **View dose log** en la tarjeta del medicamento (el número indica cuántas dosis hay).
2. La ventana **Historial de dosis** muestra **When**, **Dose**, **Outcome**, **By** y **Notes** de cada dosis.
3. Presione **Cerrar** cuando termine.

## Suspender un medicamento

1. Presione **Suspender** en un medicamento activo.
2. Confirme en la ventana **Discontinue medication**. Ya no se pueden registrar dosis nuevas y el historial se conserva.

La insignia muestra entonces **DISCONTINUED**. Una orden cuya fecha de fin ya pasó muestra **EXPIRED**.

## Quién puede hacer qué

- Los administradores de la agencia y los directores de centro añaden medicamentos, registran la autorización, registran dosis, ven el historial y suspenden medicamentos.
- Los educadores ven los medicamentos activos de su centro, registran dosis y ven el historial. No pueden añadir, autorizar ni suspender.
- Los padres ven los medicamentos de su hijo en modo de solo lectura y pueden pedir al centro que administre uno.
- La pantalla requiere la función Medicamentos en el plan de su agencia.

## Preguntas frecuentes y solución de problemas

??? ¿Por qué no aparece el botón Registrar dosis? | El medicamento todavía no está activo. Registre primero la autorización de los padres. Los medicamentos suspendidos o vencidos no admiten dosis nuevas.
??? Presioné Marcar como autorizado y vi «Enter a user ID». | El campo de autorización estaba vacío. Complételo y presione **Marcar como autorizado** otra vez. Si no sabe qué escribir, pregunte a su administrador de la agencia o al soporte de KiddieTrac.
??? Una ventana muestra «Error:» y un mensaje. | No se guardó y la ventana sigue abierta. Lea el mensaje, corrija el campo que indica e intente de nuevo.
??? Veo «No medications view for this role.» | Esta pantalla se abre para administradores de la agencia, directores de centro, educadores y padres. Como administrador de la plataforma, use [Ver como](view-as) para abrirla como administrador de la agencia.
??? La página dice «No medications recorded yet.» | Nadie ha añadido un medicamento en sus centros. Presione **+ Nuevo medicamento** para añadir el primero.
??? ¿Se avisa a los padres cuando se registra una dosis? | La dosis queda en el historial del medicamento. Los padres ven la orden y cuántas dosis se registraron en su propia pantalla de Medicamentos.

## Relacionado

[[open: #allergy-alerts | Alertas de alergia]] [[open: #immunizations | Vacunas]]

- [Alertas de alergias y alimentación](allergy-alerts)
- [Pestañas del expediente del niño](child-record-tabs)
