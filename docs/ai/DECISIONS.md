# Decisiones técnicas

## 2026-08-11 — Hardening transversal y recuperabilidad

Se conserva Basic Auth de Comisiones por compatibilidad, pero las credenciales
se modelan en configuración de entorno como entradas con `integration`,
`credential_id`, `username`, `password` y revocación. Esto permite coexistencia
durante rotación, identificación auditable y rate limit por integración sin
almacenar secretos en base de datos.

Las alertas técnicas se representan mediante `operational_alerts`, deduplicadas
por fingerprint y accesibles solo a Administrador. El estado de Stock existente
se conserva como entidad de dominio, pero deja de generar email y escrituras en
Salesforce. Los fallos del scheduler abren alertas y los éxitos posteriores las
resuelven.

La retención pone a `NULL` únicamente los ocho `raw_payload` sin lectores
funcionales, en vez de borrar la fila normalizada. Cinco payloads aún leídos por
informes/reconstrucciones quedan bloqueados hasta materializar esas dependencias.
Las ejecuciones y alertas resueltas sí se borran por chunks. Snapshots, cierres,
atribuciones e historiales quedan fuera. La migración añade índices exactamente
para estas consultas; por su coste DDL se despliega en ventana controlada y no
se considera reversible cuando `operational_alerts` ya contenga datos.

El rollback operativo preferido es código compatible o forward fix. Se prohíbe
documentar `migrate:rollback --step=N` como mecanismo genérico.

## 2026-08-06 — Cohorte única y auditoría minimizada

Se adopta una única resolución de cohorte por informe para alimentar KPI y
auditoría de Reservas/Ventas. Los decoradores de auditoría pueden enriquecer la
misma fila, pero no aplicar filtros funcionales adicionales.

La exportación estándar se considera un artefacto de conciliación, no una
extracción CRM: se retiran `Opportunity.Name`, `Account.Name` y datos de
contacto porque no son necesarios para reconstruir KPI o duplicados. Cualquier
consumidor futuro de PII requerirá un flujo distinto y aprobación explícita.

Para Llamadas, los valores estructurados se representan mediante JSON explícito
y la cardinalidad se define por `Task.Id`, incluidas las Tasks excluidas de KPI.

## 2026-08-07 — Tipo Venta y contenido descriptivo

La clave funcional Venta agrupa Venta, Venta con cambio, Lead y Ayvens. Como el
tipo normalizado está materializado en `salesforce_leads`, la modificación se
acompaña de un comando de reproceso idempotente y con modo simulación; no se
reconstruyen automáticamente los snapshots de Campañas.

Reservas/Ventas no utiliza IA ni un contrato de recomendaciones. Sus
comparativas y porcentajes se limitan a información descriptiva de cohortes.

## 2026-08-07 — Clasificación operativa de Llamadas

La exclusión de `Pruebas comunidad comercial` se materializa con el perfil
Salesforce verificable de la identidad operativa y el motivo estable
`excluded_test_profile`; `missing_call_object` conserva prioridad. La regla de
duración 5/10 se centraliza en `CallClassificationRules` y la ausencia de equipo
se representa como `unassigned`, sin convertir equipos en geografía.
## 2026-08-07 — Cierre mensual de inversión de Campañas

El cierre congela exclusivamente inversión agregada por campaña y conserva un
snapshot versionado. Reabrir exige motivo sin borrar snapshots. Las atribuciones
ambiguas no se degradan a Salesforce-only: se auditan y no entran en KPI.
## 2026-08-07 — Métricas Salesforce-only de Campañas

Salesforce-only puede contribuir Leads, Oportunidades y su ratio derivado. Las
métricas de resultados comerciales, importes y economía se consideran no
aplicables y no se agregan en KPIs de Campañas.
## 2026-08-07 — Simulación no persistente de atribución de Campañas

El dry-run usa `CampaignAttributionBuilderService`, no un motor paralelo. No
borra, inserta, actualiza ni invalida caché; exige conciliación de particiones
y unicidad de atribución KPI antes de permitir aprobar una reconstrucción.

## 2026-08-13 - Shell comun y modulos estrategicos fijos

Resumen y SEO y Analytics se modelan separadamente de los seis informes con
minimo configurable. Su autorizacion es fija en servidor para Administrador y
Director; las filas historicas de `report_access_settings` no participan en
esa decision y la pantalla de permisos no los edita. `/informes` renderiza el
Resumen para esos dos roles y conserva la redireccion al primer informe
operativo autorizado para el resto.

La estructura exterior se implementa como componente Blade anonimo, CSS y
JavaScript propios, sin introducir un framework frontend. La preferencia de
sidebar es local al navegador y no forma parte del modelo de usuario. Esta
separacion permite incorporar paginas futuras aportando solo metadatos, assets
y contenido, sin duplicar autenticacion, branding o navegacion.

## 2026-08-14 - Design System namespaced y migracion progresiva

Los nuevos contratos visuales usan tokens `--report-ui-*`, clases
`report-ui-*` y componentes Blade presentacionales. No se modifican selectores
legacy de forma transversal: cada dashboard se migrara en un lote separado con
validacion funcional y visual. Resumen y SEO/Analytics validan la capa comun sin
datos ficticios. Los estados analiticos tienen cinco claves cerradas y cualquier
valor desconocido se representa como `not-evaluable`. Superficies y controles
usan radios discretos; los pills se reservan a badges, estados y chips con esa
semantica. Esta politica no se aplica globalmente al CSS de dashboards legacy,
que se migraran progresivamente. Los KPI analiticos se agrupan preferentemente
en strips continuos, las tabs usan texto y linea activa, y las tablas densas son
el patron principal para datos detallados. Referencias externas solo orientan
estructura y densidad; colores, marca e identidad permanecen HR Motor.

## 2026-08-17 - Readiness e ingesta SEO desacoplados del render

La pantalla SEO solo interpreta configuración local y no llama proveedores. La
verificación real y la ingesta son procesos CLI/scheduler read-only y
sanitizados. Search
Console y GA4 comparten únicamente el flujo técnico OAuth; sus credenciales,
scopes y properties son independientes y no reutilizan Google Ads. Salesforce
Lead orgánico y GA4 Key Events conservan cardinalidades separadas.

Los agregados diarios exactos de Search Console se almacenan separados de sus
rankings top, porque estos últimos no son exhaustivos y no pueden alimentar KPI
ni ceros. SEO preserva la semántica legacy
`LEA_SEL_Medio_Origen__c -> salesforce_leads.medio_origen`: su métrica orgánica
vive en una proyección propia basada en `Medio_origen__c = 'Orgánico'`. Cada
fuente conserva cutoff y timezone. El dataset publica únicamente cutoffs de
ejecuciones completadas y solo habilita KPI con cobertura diaria local completa;
el resumen conjunto usa el menor cutoff y los rankings conservan el periodo
propio de Search Console.

GA4 define Conversiones web orgánicas exclusivamente como `keyEvents` con
`defaultChannelGroup = Organic Search` y `platform = web`; España usa
`countryId = ES`. Conserva crédito decimal y permanece separado de Lead orgánico
Salesforce, sin suma ni deduplicación. Su cutoff es operativo, basado en timezone
de property y lag configurable, y se refresca mediante ventana móvil: no se
declara final como Search Console.

## 2026-08-18 - Salud técnica SEO acotada y factual

Salud técnica no es un crawler: monitoriza únicamente Home, URLs estratégicas
configuradas y páginas del ranking local Search Console. Sitemap se utiliza para
infraestructura y membership de esos candidatos, nunca para rastrear todo el
sitio. No se infieren URLs de Stock mientras no exista un mapping público
verificable.

Las comprobaciones HTTP persisten hechos técnicos (respuesta, redirects,
noindex, canonical y sitemap). La clasificación de severidad y las alertas
analíticas pertenecen a un motor posterior. Todo fetch pasa por allowlist exacta
de hosts, validación DNS pública y redirects manuales; el dashboard nunca accede
a la red.

## 2026-08-19 - Comparación factual transversal mediante snapshots

Se adopta un motor transversal sin Eloquent ni conocimiento de SEO. La versión
`same_weekday_v1` compara el día cerrado con D-7/D-14/D-21/D-28 exactos, exige
3/4 referencias y conserva D-364 como dato opcional. Cada métrica usa el cutoff
cerrado de su propia fuente; una ausencia nunca se convierte en cero y una
referencia cero nunca produce una variación infinita.

Los resultados se materializan en snapshots idempotentes separados de scoring,
thresholds y severidad. SEO es el primer adaptador y aísla properties mediante
identidades técnicas no secretas y SHA-256. El rolling rebuild no borra
históricos. No se aplica pruning a snapshots hasta aprobar una retención
específica, ni se extiende esta decisión a CSS o lógica de dashboards legacy.

## 2026-08-21 - Evaluaciones analíticas separadas y versionadas

Los snapshots factuales no incorporan estado ni severidad. La interpretación se
persiste aparte, vinculada a una versión inmutable de reglas. SEO inicia este
contrato con 10/20/35 % más puertas de materialidad para volúmenes; CTR y
Posición se evalúan por unidades absolutas. Las mejoras materiales se señalan
como oportunidad favorable, nunca automáticamente como Crítico.

Administrador y Director pueden crear una nueva versión desde la pantalla SEO,
con motivo, actor y protección frente a edición concurrente. No se actualiza ni
reactiva una versión histórica y un cambio no reinterpreta automáticamente el
histórico: solo reevalúa los snapshots actuales. El ID del actor queda como
referencia histórica sin FK para respetar la eliminación física de usuarios.

Los snapshots son proyecciones rolling y pueden revisarse mediante upsert. Por
ello cada evaluación captura los inputs factuales usados y su SHA-256 canónico.
La UI solo considera vigente una evaluación cuyo fingerprint coincide con la
proyección actual; mientras no coincida muestra un estado pendiente neutral. El
histórico presenta los valores capturados por la evaluación, evitando mezclar
hechos revisados con una clasificación anterior. D-364 y timestamps se excluyen
del hash porque no alteran la regla v1.
