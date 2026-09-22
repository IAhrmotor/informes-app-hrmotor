# Roadmap controlado de implementación

Actualizado: 2026-09-22.

Este documento es la **fuente única de verdad del trabajo pendiente**. El
histórico de trabajo ya entregado y sus validaciones permanece en
[`HANDOFF.md`](HANDOFF.md); los contratos estables del sistema permanecen en
[`PROJECT_CONTEXT.md`](PROJECT_CONTEXT.md) y las decisiones aprobadas que deben
persistir, en [`DECISIONS.md`](DECISIONS.md).

## Reglas de mantenimiento

- Cada trabajo pendiente debe tener un ID único y una ficha en este documento.
- Al activar una tarea se deben completar su rama y SHA base, cambiar su estado
  a `en_progreso` y registrar un punto de reanudación verificable.
- Una tarea no se considera terminada por estar implementada: pasa por
  `en_revision`, `aprobada` y finalmente `cerrada` cuando también se han
  completado merge, operación y validación aplicables.
- El roadmap no duplica el historial de entregas cerradas. Al cerrar una tarea,
  se conserva solo la información mínima necesaria para entender dependencias;
  el detalle de ejecución se traslada a `HANDOFF.md`.
- No se cambian fórmulas, contratos JSON ni semánticas existentes fuera de una
  tarea que lo autorice expresamente.

### Estados

| Estado | Uso |
|---|---|
| `pendiente` | Aprobada para planificar, todavía sin rama activa ni cambios. |
| `en_progreso` | Existe una única rama funcional activa y trabajo en curso. |
| `bloqueada` | No puede continuar; la ficha identifica el bloqueo y el punto exacto de reanudación. |
| `en_revision` | Implementación, pruebas y documentación terminadas; rama pendiente de revisión previa al PR o revisión en curso. |
| `aprobada` | Revisión completada y autorizada para PR/merge, todavía no cerrada operacionalmente. |
| `cerrada` | Merge y, cuando corresponda, validación operacional completados. |

## Protocolo de desarrollo y revisión

1. Cada tarea comienza desde `main` limpio y actualizado con `git fetch origin`,
   `git switch main` y `git pull --ff-only origin main`.
2. Se crea una rama funcional por tarea o lote, nacida del nuevo `main`.
3. El WIP funcional máximo es una rama.
4. Solo se admite una segunda rama simultánea para una incidencia urgente y
   expresamente identificada como tal.
5. Codex implementa, prueba, documenta, hace commit y publica la rama.
6. Codex no crea el PR inicialmente.
7. La rama se revisa antes de abrir el PR.
8. Las correcciones posteriores a la primera revisión se publican como commits
   nuevos; no se usa `amend` ni `force-push` salvo instrucción expresa.
9. Tras la aprobación de la rama se abre el PR.
10. La CI debe estar completamente verde antes del merge.
11. Tras el merge se elimina la rama funcional.
12. La siguiente tarea nace del `main` resultante del merge anterior.

## Línea base y límites actuales

- Rama base de este roadmap: `main`.
- SHA base documental: `0355dd688a352e9b5d3bc34e7ab98a308cec0498`.
- Rama documental: `docs/roadmap-executive-v1`.
- El PR #53 de Reservas/Ventas ya está fusionado. No es trabajo pendiente.
- No hay una rama funcional activa para los lotes descritos abajo.
- Las fichas con rama o SHA `por asignar` no autorizan iniciar trabajo: deben
  completarse al activar formalmente la tarea.

## Orden ejecutivo

| Orden | ID | Lote | Prioridad | Estado | Dependencia principal |
|---:|---|---|---|---|---|
| 1 | RV-3 | Validación histórica Reservas/Ventas | P0 | `pendiente` | PR #53 fusionado |
| 2 | RV-1 | Cierre ejecutivo de Rendimiento comercial | P0 | `pendiente` | RV-3 |
| 3 | RV-2 | Producción y períodos de Resumen Dirección | P0 | `pendiente` | RV-3 y RV-1 |
| 4 | SF-7A-OPS | Cierre operacional Salesforce Fase 7A | P0 | `pendiente` | RV-1 y RV-2 cerradas |
| 5 | SF-7B-OPS | Cierre operacional Salesforce Fase 7B | P0 | `pendiente` | SF-7A-OPS |
| 6 | EXE-1 | Motor ejecutivo V1 | P0 | `pendiente` | SF-7A-OPS y SF-7B-OPS |
| 7 | EXE-2 | Datos ejecutivos diarios | P0 | `pendiente` | EXE-1 |
| 8 | EXE-3 | Resumen Ejecutivo global | P0 | `pendiente` | EXE-2 |
| 9 | EXE-4 | Correo ejecutivo piloto | P0 | `pendiente` | EXE-3 |
| 10 | EXE-5 | Piloto y calibración | P0 | `pendiente` | EXE-4 |
| 11 | TRANS-1 | Correcciones transversales | P1 | `pendiente` | EXE-5 |
| 12 | UX-LEADS | UX Leads | P1 | `pendiente` | TRANS-1 |
| 13 | UX-CALLS | UX Llamadas | P1 | `pendiente` | TRANS-1 |
| 14 | UX-CAMPAIGNS | UX Campañas | P1 | `pendiente` | TRANS-1 |
| 15 | SEO-SIMPLIFY | Simplificación SEO | P2 | `pendiente` | EXE-5 |
| 16 | ANALYTICS-EXT | Ampliación del motor analítico | P2 | `pendiente` | EXE-5 |
| 17 | AI-LATER | IA posterior | P3 | `pendiente` | ANALYTICS-EXT |
| 18 | GEO-AI-LATER | GEO/IA posterior | P3 | `pendiente` | ANALYTICS-EXT |

## Lote previo: cierre definitivo de Reservas/Ventas

### RV-3 — Validación histórica

- **Fase/lote:** cierre definitivo de Reservas/Ventas.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** PR #53 fusionado; contratos y auditorías locales existentes.
- **Rama prevista o activa:** por asignar al activar; ninguna rama activa.
- **SHA base al activar:** por registrar desde `main` actualizado.
- **Bloqueos/decisiones de negocio:** la investigación debe ser de solo lectura.
  Ninguna métrica puede modificarse sin demostrar antes una discrepancia.
- **Punto exacto de reanudación:** iniciar inventario de cobertura local de
  `OpportunityHistory`, contratos y auditorías para julio y agosto, sin lanzar
  sincronizaciones ni escrituras.
- **Criterios de aceptación:**
  - explicar con evidencia por qué julio muestra cancelaciones `N/D`;
  - explicar con evidencia por qué agosto muestra cero ventas caídas;
  - usar primero contratos, auditorías y datos locales existentes;
  - si `N/D` procede de cobertura incompleta, documentarlo y hacerlo visible;
  - si el cero está demostrado, conservarlo como cero;
  - no convertir `null`/`N/D` en cero ni inferir datos ausentes;
  - registrar consultas, cobertura, límites y resultado sin PII ni escritura en
    Salesforce o producción.

### RV-1 — Cierre ejecutivo de Rendimiento comercial

- **Fase/lote:** cierre definitivo de Reservas/Ventas.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** RV-3 cerrada para cualquier ajuste relacionado con
  cancelaciones o ventas caídas.
- **Rama prevista o activa:** por asignar al activar; ninguna rama activa.
- **SHA base al activar:** por registrar desde `main` actualizado.
- **Bloqueos/decisiones de negocio:** no redefinir fórmulas ni extrapolar margen
  desconocido; los presets de columnas son condicionales a que no introduzcan
  complejidad estructural.
- **Punto exacto de reanudación:** revisar el contrato y la UI actuales de
  Rendimiento comercial contra los diez criterios siguientes, empezando por el
  cálculo visible de cumplimiento y la terminología.
- **Criterios de aceptación:**
  1. Mostrar `X reservas computables / Y de objetivo = Z %` usando, sin
     redefinir la fórmula, `global_reservations_valid_for_objective`,
     `global_target` y `global_fulfillment_pct`.
  2. Sustituir visualmente **Semáforo** por **Estado**.
  3. Sustituir **Conversiones** por **Ratios de actividad mensual** y explicar
     que cada hito usa su fecha propia y que los ratios pueden superar 100 %;
     no cambiar fórmulas.
  4. Ofrecer una vista ejecutiva simplificada por defecto y conservar la
     personalización de columnas. Añadir, solo si no exige complejidad
     estructural, presets **Resumen**, **Actividad** y **Rentabilidad**.
  5. Ocultar Salesforce User ID en la vista normal y mantener identificadores
     técnicos solo donde correspondan por auditoría o detalle autorizado.
  6. Fijar **Ranking**, **Estado** y **Comercial** durante el scroll horizontal.
  7. Aplicar formato español homogéneo, incluidos diferencias y puntos
     porcentuales: `10.234`, `1.036`, `28,2 %`, `11.851,50 €`.
  8. Mostrar la cobertura de margen cuando sea inferior a 100 % y no extrapolar
     margen desconocido.
  9. Explicar `N/D` de cancelaciones en Evolución mensual mediante el estado real
     de cobertura de `OpportunityHistory`; nunca convertir `null`/`N/D` en cero.
  10. No modificar la lógica de ventas caídas hasta reconciliar el cero. Un cero
      demostrado debe seguir siendo cero.

### RV-2 — Producción y períodos de Resumen Dirección

- **Fase/lote:** cierre definitivo de Reservas/Ventas.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** RV-3 y RV-1 cerradas.
- **Rama prevista o activa:** por asignar al activar; ninguna rama activa.
- **SHA base al activar:** por registrar desde `main` actualizado.
- **Bloqueos/decisiones de negocio:** preservar el contrato temporal `[start,
  end)` y no cambiar silenciosamente claves JSON existentes.
- **Punto exacto de reanudación:** inventariar presets, etiquetas y claves JSON
  actuales de Resumen Dirección; separar en el diseño producción del período y
  cohorte antes de cambiar presentación o payload.
- **Criterios de aceptación:**
  - los períodos se muestran con límites claros, verificables y sin apariencia
    de solapamiento; todos los presets conservan `[start, end)`;
  - **Producción del período** y **Cohorte de oportunidades creadas en el
    período** aparecen claramente separadas;
  - producción calcula reservas por `reservation_date` y ventas por
    `cv_signed_date`, reutilizando reglas, deduplicación y universos existentes;
  - producción es coherente con Rendimiento comercial y comisiones;
  - el análisis por `created_date`, si se conserva, se identifica explícitamente
    como cohorte secundaria;
  - cualquier contrato nuevo es aditivo o versionado; ninguna clave JSON cambia
    de significado silenciosamente.

## Salesforce: cierres operacionales pendientes

### SF-7A-OPS — Cierre operacional de Fase 7A

- **Fase/lote:** Salesforce 7A, backfill histórico de atribución Lead.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** cierre definitivo de RV-1 y RV-2; herramienta técnica ya
  implementada según `HANDOFF.md`.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** requiere runbook, rango, motivo y
  autorización operativa separados; no autoriza escritura Salesforce.
- **Punto exacto de reanudación:** conciliar migraciones y ejecutar primero el
  dry-run aprobado sobre el rango acordado.
- **Criterios de aceptación:** dry-run conciliado; apply local autorizado,
  auditable e idempotente; validación posterior sin PII; incidencias y punto de
  reanudación documentados; cero escrituras Salesforce.

### SF-7B-OPS — Cierre operacional de Fase 7B

- **Fase/lote:** Salesforce 7B, reproceso histórico de portales Opportunity.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** SF-7A-OPS cerrada y fechas locales necesarias conciliadas.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** requiere runbook, rango, motivo y
  autorización operativa separados; Salesforce solo puede usarse para lectura
  de Leads dentro del contrato existente.
- **Punto exacto de reanudación:** verificar `created_date`, migraciones y rango
  `[from, to)`; ejecutar primero dry-run productivo aprobado.
- **Criterios de aceptación:** dry-run conciliado; apply local autorizado,
  reanudable y auditable; contratos y precedencias preservados; validación
  posterior documentada; cero escrituras Salesforce.

## Resumen Ejecutivo V1

Las decisiones persistentes de esta sección están registradas también en
[`DECISIONS.md`](DECISIONS.md). Aquí se usan como límites de aceptación del
trabajo pendiente, no como afirmación de funcionalidad ya implementada.

### Contrato funcional aprobado

- Acceso V1 exclusivo para **Administrador** y **Dirección**, con visión global.
- La arquitectura debe admitir futuros scopes de Area Manager y Manager sin
  rehacer la lógica; esos accesos no se implementan ni conceden en V1.
- Las alertas evalúan el último día cerrado. El mes en curso hasta ese corte se
  presenta únicamente como contexto informativo.
- Métricas iniciales: Leads, Reservas y Ventas.
- Baseline principal: media de D-7, D-14, D-21 y D-28; V1 exige 4/4.
- D-364 es una referencia complementaria y nunca cambia por sí sola el estado.
- Estados: **Correcto**, **Atención**, **Desviación**, **Crítico** y **No
  evaluable**. Dirección: **Favorable**, **Desfavorable** y **Estable**. Salud
  del dato: **Actualizado**, **Parcial**, **Desactualizado** e **Incidencia**.
- Una incidencia de datos no genera una alerta de negocio.
- Como máximo se publican cinco alertas ejecutivas. Se ordenan primero por
  severidad; dentro de ella, las desfavorables preceden a las favorables; después
  se usa impacto económico solo cuando exista un dato fiable y, en su defecto,
  volumen absoluto. Nunca se inventa impacto económico.
- V1 no usa IA. Las acciones recomendadas son fijas, versionadas, con clave única
  y auditables.
- Una causa solo se presenta como confirmada cuando los datos la demuestran. En
  caso contrario, el texto debe ser **Posible causa a revisar**.
- SISTRIX y GEO/IA quedan fuera y ocultos en V1.
- El correo ejecutivo general se limita durante el piloto a
  `carlos.torres@hrmotor.es` y se programa a las 08:00 `Europe/Madrid`.
- El correo SEO existente continúa independiente mientras SEO no forme parte del
  correo ejecutivo general.

### Umbrales aprobados

La variación se calcula respecto a la media de los cuatro mismos días anteriores.
Un nivel de alerta solo se alcanza si se cumplen simultáneamente su banda
porcentual y su diferencia absoluta mínima. Si una puerta absoluta no se cumple,
no se puede asignar ese nivel. La especificación ejecutable deberá fijar con
pruebas todos los límites antes de implementar el motor.

| Estado | Variación porcentual |
|---|---:|
| Correcto | `< 15 %` |
| Atención | `>= 15 %` y `< 25 %` |
| Desviación | `>= 25 %` y `<= 40 %` |
| Crítico | `> 40 %` |

| Métrica | Baseline mínimo evaluable | Atención | Desviación | Crítico |
|---|---:|---:|---:|---:|
| Leads | 100 | 30 | 50 | 100 |
| Reservas | 5 | 3 | 5 | 8 |
| Ventas | 5 | 3 | 5 | 8 |

Reglas de evaluabilidad:

- si `current = 0` y el baseline alcanza el mínimo evaluable, el resultado es
  **Crítico** y **Desfavorable**;
- si falta D-7, D-14, D-21 o D-28, el día está incompleto o existe una incidencia
  de sincronización, el resultado es **No evaluable** y la salud nunca se
  presenta como actualizada; debe reflejar dato parcial o incidencia según la
  evidencia;
- un resultado no evaluable no genera alerta de negocio.

### EXE-1 — Motor ejecutivo V1

- **Fase/lote:** Resumen Ejecutivo, motor analítico.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** SF-7A-OPS y SF-7B-OPS cerradas.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** antes de código deben formalizarse pruebas
  de frontera para bandas, mínimos absolutos y dirección `Estable`, sin inventar
  tolerancias no aprobadas.
- **Punto exacto de reanudación:** diseñar un contrato de entrada/salida agnóstico
  de módulo y una matriz de pruebas de reglas V1.
- **Criterios de aceptación:** motor determinista, sin IO ni IA; 4/4 referencias;
  D-364 complementario; estados, dirección, salud y reason codes versionados;
  ausencia distinta de cero; acciones fijas con clave única; causas demostradas
  separadas de posibles causas; pruebas completas de límites y regla de cero.

### EXE-2 — Datos ejecutivos diarios Leads/Reservas/Ventas

- **Fase/lote:** Resumen Ejecutivo, adaptadores de datos.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** EXE-1.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** reutilizar universos canónicos y datos
  locales; no duplicar fórmulas ni consultar proveedores durante el render.
- **Punto exacto de reanudación:** inventariar el contrato canónico y el cutoff
  certificado de cada una de las tres métricas.
- **Criterios de aceptación:** series diarias reconciliables para Leads, Reservas
  y Ventas; último día cerrado explícito; 4/4 referencias; frescura y cobertura
  verificables; scopes preparados sin conceder acceso futuro; sin PII ni ceros
  inventados; rendimiento por lotes y sin N+1.

### EXE-3 — Resumen Ejecutivo global

- **Fase/lote:** Resumen Ejecutivo, dashboard.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** EXE-2.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** acceso V1 solo Administrador/Dirección;
  SISTRIX y GEO/IA ocultos.
- **Punto exacto de reanudación:** definir autorización server-side y jerarquía
  de la vista global conforme al Design System existente.
- **Criterios de aceptación:** visión global; máximo cinco alertas con orden
  aprobado; último día cerrado y contexto MTD claramente separados; salud del
  dato fuera de alertas de negocio; causas y acciones con trazabilidad; interfaz
  accesible, responsive y sin exposición de identificadores o datos sensibles.

### EXE-4 — Correo piloto 08:00

- **Fase/lote:** Resumen Ejecutivo, distribución piloto.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** EXE-3.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** único destinatario aprobado
  `carlos.torres@hrmotor.es`; el correo SEO existente no se sustituye.
- **Punto exacto de reanudación:** diseñar payload congelado, ledger idempotente
  y autorización de configuración reutilizando patrones aprobados donde encajen.
- **Criterios de aceptación:** envío a las 08:00 `Europe/Madrid`; destinatario
  único del piloto; contenido idéntico al estado ejecutivo congelado; reintentos
  sin duplicados; errores auditables sin secretos ni PII; correo SEO sin cambios.

### EXE-5 — Piloto y calibración

- **Fase/lote:** Resumen Ejecutivo, validación funcional.
- **Prioridad:** P0.
- **Estado:** `pendiente`.
- **Dependencias:** EXE-4.
- **Rama prevista o activa:** por asignar; ninguna rama activa.
- **SHA base al activar:** por registrar.
- **Bloqueos/decisiones de negocio:** cualquier ajuste de umbral o prioridad debe
  aprobarse y versionarse; no se reescribe el histórico.
- **Punto exacto de reanudación:** acordar ventana de piloto, responsables y
  plantilla de revisión de falsos positivos/negativos.
- **Criterios de aceptación:** ejecución piloto trazable; revisión de cobertura,
  utilidad y ruido; ajustes aprobados como nueva versión; incidencias resueltas o
  registradas con reanudación exacta; autorización expresa antes de ampliar
  destinatarios o roles.

## Trabajo posterior

Las tareas siguientes no están activas. Su detalle se completará cuando se
aproximen a ejecución, sin adelantar contratos ni inventar arquitectura.

### TRANS-1 — Correcciones transversales pendientes

- **Fase/lote:** Leads/Llamadas/Campañas.
- **Prioridad:** P1. **Estado:** `pendiente`.
- **Dependencias:** EXE-5. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** inventario y priorización pendientes.
- **Punto de reanudación:** consolidar incidencias abiertas verificadas de los
  tres módulos sin mezclar cambios funcionales no relacionados.
- **Aceptación:** lotes independientes, contratos preservados, seguridad y
  rendimiento verificados, pruebas y documentación completas.

### UX-LEADS — UX Leads

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Dependencias:** TRANS-1. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, sin cambios de métricas, migración compatible
  con Design System y validación responsive/accesible.

### UX-CALLS — UX Llamadas

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Dependencias:** TRANS-1. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, sin cambios silenciosos de clasificación,
  migración compatible con Design System y validación responsive/accesible.

### UX-CAMPAIGNS — UX Campañas

- **Fase/lote:** evolución UX. **Prioridad:** P1. **Estado:** `pendiente`.
- **Dependencias:** TRANS-1. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** alcance funcional pendiente de inventario.
- **Punto de reanudación:** auditoría de uso, accesibilidad y deuda visual.
- **Aceptación:** alcance aprobado, atribución preservada, migración compatible
  con Design System y validación responsive/accesible.

### SEO-SIMPLIFY — Simplificación SEO

- **Fase/lote:** SEO/Analytics. **Prioridad:** P2. **Estado:** `pendiente`.
- **Dependencias:** EXE-5. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** el correo SEO sigue independiente hasta decisión
  expresa de integración.
- **Punto de reanudación:** inventariar información esencial, secundaria y
  técnica del dashboard actual.
- **Aceptación:** simplificación aprobada sin perder trazabilidad, cobertura,
  contratos o correo existente.

### ANALYTICS-EXT — Ampliación del motor analítico

- **Fase/lote:** analítica transversal. **Prioridad:** P2. **Estado:** `pendiente`.
- **Dependencias:** EXE-5. **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** métricas y nuevos módulos todavía no aprobados.
- **Punto de reanudación:** evaluar el piloto y proponer extensiones basadas en
  casos de uso demostrados.
- **Aceptación:** contrato versionado y agnóstico de módulo, migración compatible,
  evaluación determinista y costes de datos/rendimiento medidos.

### AI-LATER — IA posterior

- **Fase/lote:** capacidades futuras. **Prioridad:** P3. **Estado:** `pendiente`.
- **Dependencias:** ANALYTICS-EXT y aprobación específica.
- **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** fuera de V1; sin caso de uso, gobernanza ni contrato
  aprobados.
- **Punto de reanudación:** ninguno hasta decisión formal posterior al motor
  analítico ampliado.
- **Aceptación:** por definir tras aprobación; no introducir IA antes.

### GEO-AI-LATER — GEO/IA posterior

- **Fase/lote:** capacidades futuras. **Prioridad:** P3. **Estado:** `pendiente`.
- **Dependencias:** ANALYTICS-EXT y aprobación específica.
- **Rama:** por asignar. **SHA base:** por registrar.
- **Bloqueos/decisiones:** GEO/IA y SISTRIX están fuera y ocultos en V1.
- **Punto de reanudación:** ninguno hasta decisión funcional expresa.
- **Aceptación:** por definir tras aprobación; no exponer módulos, datos ni
  navegación de GEO/IA en V1.

## Restricciones permanentes de seguridad y operación

- No registrar secretos, tokens, credenciales, PII ni datos productivos en el
  roadmap o sus evidencias.
- El único email aprobado para el piloto ejecutivo es
  `carlos.torres@hrmotor.es`; cualquier ampliación requiere una nueva decisión.
- Una tarea documental no autoriza conexiones externas, escrituras Salesforce,
  operaciones sobre producción, despliegues ni cambios de runtime.
- Los scopes se resuelven en servidor bajo mínimo privilegio; ocultar UI nunca
  sustituye autorización.
- `null`, ausencia, cobertura parcial e incidencia no se convierten en cero.
- El impacto económico solo se usa cuando un dato fiable lo acredita; nunca se
  estima ni extrapola para priorizar una alerta.
