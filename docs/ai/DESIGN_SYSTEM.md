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
- `<x-reports.ui.section-header>`
- `<x-reports.ui.source-status>`

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

## Analytical UI Patterns

La jerarquía recomendada es: page header, KPI strip si corresponde, tabs si
corresponden, filter bar, secciones/data panels y tablas o contenido. Un informe
no está obligado a utilizar todos los niveles.

### KPI strip

`report-ui-kpi-strip` agrupa métricas como una banda continua con borde exterior
y divisores de 1 px. Sus items, labels, values y metadata usan las clases
`__item`, `__label`, `__value` y `__meta`. Los items no son cards, no tienen
radio o sombra propios y no usan colores de estado para decorar valores
ordinarios. El grid admite cardinalidad variable y los valores usan números
tabulares; tendencia y comparación pertenecen a la lógica analítica futura.

### Data panel y section header

`report-ui-data-panel` agrupa header, body y un `__scroll` opcional. El scroll
horizontal se limita al contenido ancho para que la cabecera no se desplace. No
impone altura máxima. `<x-reports.ui.section-header>` genera un `h2` compacto con
eyebrow, descripción y acciones opcionales; dentro del header del panel no añade
otro borde ni radio.

### Tablas empresariales

`report-ui-table-shell` proporciona borde, radio y overflow horizontal;
`report-ui-table` se aplica a HTML `<table>` semántico. Las cabeceras son
compactas y las celdas conservan separadores horizontales. Usar:

- `report-ui-table__numeric` para alineación derecha y números tabulares.
- `report-ui-table-row--highlight` para un único énfasis arena neutral.
- `report-ui-table-row--summary` para filas TOTAL/resumen neutrales.
- `report-ui-table--sticky-header` solo cuando el consumidor solicite sticky.

Highlight significa énfasis visual: no es estado, alerta o selección automática
y no sustituye `report-ui-status`. Sticky no se activa globalmente, no fija la
primera columna y no impone max-height. Si el contenedor de scroll es focalizable,
debe recibir un nombre accesible apropiado. Mantener `<thead>`, `<tbody>`, `<th>`,
`<td>` y los atributos `scope` correspondientes; responsive se resuelve con
overflow, nunca convirtiendo filas y celdas en bloques.

### Tabs analíticas

`report-ui-tabs` y `report-ui-tab` presentan texto con línea inferior activa,
sin pills ni fondo navy completo. Admiten `.is-active`, `aria-current="page"` y
`aria-selected="true"`, focus visible y overflow horizontal sin comprimir texto.
Para navegación se usa `<nav>` con enlaces y `aria-current`. Un widget tablist
real debe implementar en su módulo roles, `aria-controls`, selección y teclado;
el Design System no añade roles ni JavaScript por sí solo.

### Filter bar

`report-ui-filter-bar`, `__fields` y `__actions` componen un panel responsive de
filtros. Se reutilizan `report-ui-field`, label, input, select y button; no se
duplican controles ni se implementan autocomplete, selects dependientes, reset,
carga remota o comportamiento JavaScript.

### Source status

`<x-reports.ui.source-status>` representa título de fuente, detalle y metadata
opcionales, más un slot `indicator`. No clasifica la fuente ni define estados:
el consumidor aporta badge, `x-reports.ui.status` o texto neutral según una regla
real. El estado nunca debe comunicarse solo con un punto de color.

### Responsive y densidad

KPI strips y campos usan grids de cardinalidad variable; tabs y tablas conservan
una fila legible mediante scroll horizontal; headers, filter bars y source status
se apilan en móvil. Los controles mantienen 42 px y los patrones se apoyan en
background, borde y spacing, sin sombras grandes, blur, glows o gradientes
decorativos.

### Uso incorrecto

- No inventar KPI, fuentes, estados o tabs para demostrar los patrones.
- No convertir KPI items o filas en cards flotantes.
- No usar highlight como estado analítico.
- No declarar semántica ARIA de tablist sin comportamiento de teclado completo.
- No aplicar estas clases a dashboards legacy sin un lote de migración propio.

## Accesibilidad

- Los estados combinan texto, icono y color; los iconos son decorativos y usan
  `aria-hidden="true"`.
- `--report-ui-text-muted` mantiene contraste WCAG AA para texto normal sobre
  las superficies compartidas; `--report-ui-control-border` garantiza el
  contraste no textual del límite normal de inputs, selects y textareas.
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
