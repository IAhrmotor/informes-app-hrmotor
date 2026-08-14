# Design System de Informes

## Propósito

`resources/css/reports/design-system.css` es la base visual para módulos nuevos
y para la migración progresiva de los informes. No reemplaza todavía el CSS
legacy de los seis dashboards ni contiene reglas de negocio.

## Tokens

Todos los tokens usan `--report-ui-*`. Cubren fondo, superficies, texto, borde,
marca, acento, foco, una escala reducida de espaciado, radios, sombras, altura de
control, tipografía y los colores de los cinco estados oficiales. Un tema futuro
debe sustituir valores de tokens, no reescribir componentes.

## Naming y primitives

Las clases públicas usan `report-ui-*`:

- `report-ui-page-header`: título, eyebrow, descripción y acciones.
- `report-ui-card` y `report-ui-card--muted`: superficies.
- `report-ui-button`: acción primaria navy; modificadores `--secondary`,
  `--danger` y `--ghost`.
- `report-ui-badge`: metadatos o etiquetas neutrales.
- `report-ui-field`, `report-ui-label`, `report-ui-input`, `report-ui-select` y
  `report-ui-textarea`: futuros formularios y filtros.
- `report-ui-empty-state`: ausencia de datos, resultados o conexión.
- `report-ui-skeleton`: placeholder visual sin lógica de carga.
- `report-ui-table-shell` y `report-ui-table`: base densa con overflow horizontal.

Componentes Blade disponibles:

- `<x-reports.ui.page-header>`
- `<x-reports.ui.empty-state>`
- `<x-reports.ui.status>`

Los slots y atributos usan el escape estándar de Blade. Los componentes no
consultan BD, sesión ni servicios.

## Estados oficiales

| Clave | Etiqueta | Significado |
|---|---|---|
| `ok` | Correcto | Clasificación positiva determinada por la regla analítica vigente |
| `observation` | Observación | Señal que requiere seguimiento según la regla analítica vigente |
| `deviation` | Desviación relevante | Desviación relevante determinada por la regla analítica vigente, sin alcanzar nivel crítico; no implica por sí sola incumplimiento |
| `critical` | Crítico | Condición crítica determinada por la regla analítica vigente |
| `not-evaluable` | No evaluable | La regla no dispone de información suficiente o completa para emitir una evaluación |

`x-reports.ui.status` controla etiqueta, icono y clase. Un valor desconocido se
convierte explícitamente en `not-evaluable`; nunca en Correcto o Crítico. No se
debe mostrar un estado sin una regla o dato real que lo sustente.

La regla analítica versionada que evalúa el dato decide qué estado corresponde.
El Design System no clasifica métricas: únicamente representa visualmente el
resultado recibido.

## Geometría y densidad

- Las interfaces analíticas son principalmente rectangulares, densas y legibles.
- Controles, cards, paneles y table shells usan radios discretos de `8px`.
- Se priorizan borde y fondo frente a sombras grandes o decorativas.
- `--report-ui-radius-pill` queda reservado a badges, estados y futuros
  chips/tags o contadores compactos que sean semánticamente cápsulas.
- Botones, tabs, filtros, inputs, selects, cards, paneles, contenedores KPI,
  tablas y navegación analítica no deben usar pill como geometría por defecto.
- Las tablas deben mantener una presentación sobria, empresarial y de alta
  densidad legible. `border-radius: 999px` no es un recurso decorativo general.

El siguiente lote definirá los patrones completos de KPI strip, tablas
empresariales, tabs, filter bar, section headers, row highlighting y source
status. Esos patrones no forman parte de este lote.

## Accesibilidad

- Los estados combinan texto, icono y color; los iconos son decorativos y usan
  `aria-hidden="true"`.
- Texto y foco usan colores con contraste WCAG AA para texto normal.
- Botones y controles tienen `focus-visible`, estados disabled y una altura
  interactiva consistente.
- Skeleton y transiciones respetan `prefers-reduced-motion`.
- Empty states deben distinguir “sin datos” de un valor cero real.

### Semántica disabled

- Un `<button>` deshabilitado debe usar el atributo nativo `disabled`.
- En un `<a>`, `aria-disabled="true"` comunica el estado a tecnologías de
  asistencia, pero no impide por sí mismo la navegación. Un enlace deshabilitado
  no debe conservar un `href` funcional: se omite `href` o se renderiza con una
  semántica no interactiva adecuada.
- Las reglas `.report-ui-button:disabled` y
  `.report-ui-button[aria-disabled="true"]` representan visualmente el estado;
  CSS y `aria-disabled` no sustituyen su semántica funcional.
- No se añade JavaScript genérico para interceptar clicks de enlaces disabled.

## Reglas de uso y migración

1. Cargar `design-system.css` antes de `app-shell.css`.
2. Usar tokens y clases `report-ui-*`; no crear `.card`, `.button`, `.status` u
   otros nombres globales.
3. Migrar un dashboard por lote, con comparación visual y pruebas funcionales.
4. Mantener temporalmente clases legacy junto a primitives cuando exista un
   consumidor conocido, como `badge report-ui-badge` y `updatedBadge`.
5. No cambiar KPI, filtros, payloads o comportamiento para adoptar estilos.

## Qué no debe hacerse

- No añadir selectores globales para `button`, `input`, `table`, encabezados o
  clases legacy.
- No usar `!important` para vencer CSS antiguo.
- No inventar estados, métricas ni ceros para completar una pantalla.
- No convertir el rojo de marca en acción primaria general.
- No añadir lógica JavaScript, consultas o dependencias a primitives visuales.
- No migrar varios dashboards a la vez ni retirar CSS legacy sin inventario.
