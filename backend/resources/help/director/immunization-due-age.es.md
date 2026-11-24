---
roles: agency_admin, centre_director, platform_admin
title: Calendario de vacunas «Debido a la edad de»
category: Compliance
order: 32
---
# Vacunas debidas a la edad de

**Vacunas → Debido a la edad de.** Las dosis registradas de cada niño comparadas con el
calendario de su agencia, de modo que las vacunas atrasadas se señalan según la edad del niño sin que
nadie tenga que revisar las cartillas a mano.

[[show: #immunizations @ Due at age | Mostrar la pestaña Debido a la edad de]]

## Las pestañas

- **Debido a la edad de** — toda la lista, cada niño frente al calendario.
- **Solo atrasados** — la misma vista, limitada a los niños con una dosis atrasada.
- **Registros recibidos** — todos los registros de vacunación archivados de sus niños, y quién los
  envió. Vea [Archivar un registro de vacunación](filing-an-immunization-record).
- **Calendario predeterminado** — el calendario en sí.

En la fila de un niño, el nombre o **Ver vacunas** abre su calendario completo junto a lo
registrado, y **📄 Registros archivados** va directo a las cartillas archivadas para él.

## El calendario

Cada agencia tiene su propio calendario de vacunas y dosis, con una edad objetivo en meses:

| Vacuna | Dosis | Debida a la edad de |
|---|---|---|
| DTaP-IPV-Hib | 1.ª dosis | 2 meses |
| Neumococo | 2.ª dosis | 4 meses |
| SRP | 1.ª dosis | 12 meses |
| Varicela | 1.ª dosis | 15 meses |
| SRPV | 2.ª dosis | 48 meses |
| DTaP-IPV | 5.ª dosis (refuerzo) | 60 meses |
| … |

El calendario **NACI canadiense** se completa por usted la primera vez que se abre. A partir de ahí
es suyo para modificarlo.

### Editarlo

En **Calendario predeterminado**, **+ Añadir elemento** agrega una fila: **Vacuna**, **Dosis**, **Debido a la edad de
(meses)**, **Notas** y una casilla **Obligatorio**. El menú **⋮** de cada fila tiene **Ver**,
**Editar** y quitar.
Cambiar una fila vuelve a calcular el estado de cada niño.

- Añada elementos propios de la agencia (una vacuna estacional contra la gripe a los 24 meses, por ejemplo) junto con los
  valores NACI predeterminados.
- Desmarque **Obligatorio** en una vacuna que su centro trate como opcional. Sigue apareciendo en el
  calendario, marcada como **Opcional**. El recordatorio a las familias puede limitarse a las dosis obligatorias, y
  **Marcar todas las obligatorias** en un formulario de exención marca solo estas.

## Estado por niño

Cada dosis del calendario está en uno de estos estados:

- **Registrada** — hay una dosis coincidente en el registro del niño.
- **Aún no debida** — el niño todavía no tiene esa edad.
- **Próxima a vencer** — debida dentro de los próximos dos meses.
- **Atrasada** — pasó la edad debida y no hay nada registrado.
- **Exenta** — hay una exención registrada, con su motivo al lado. Vea
  [Exenciones de vacunación](immunization-exemptions).

> **Nota:** Un registro archivado no es una dosis registrada. Una cartilla enviada por un padre aparece como **Detalles pendientes** hasta que alguien registre las dosis que contiene, y hasta entonces el niño sigue mostrando lo que está atrasado.

## Recordatorios

Nadie recibe recordatorios automáticamente a menos que su agencia lo active. Los administradores de la agencia configuran
un recordatorio al equipo y, opcionalmente, uno a las familias, en **Ajustes → Ajustes de correo →
Vacunas**. Las dosis exentas nunca se incluyen. Vea
[Recordatorios de vacunación](immunization-reminders).

Vea también: [Archivar un registro de vacunación](filing-an-immunization-record),
[Exenciones de vacunación](immunization-exemptions),
[Informe de vacunación](immunization-report).
