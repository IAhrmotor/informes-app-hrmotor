# SEO/Analytics

## Alcance operativo

El Lote 2 incorpora datos reales persistidos de Google Search Console y una
proyección diaria aislada de Leads orgánicos Salesforce. `GET
/informes/seo-analytics` consulta exclusivamente la base local y configuración:
no llama Google, Salesforce, GA4 ni SISTRIX.

Continúan pendientes GA4 diario, Key Events, salud técnica, crawler/sitemap,
SISTRIX AI Check, motor comparativo, scoring, anomalías y correo.

## Persistencia y cierre

- `seo_search_console_daily_metrics`: agregados diarios exactos por property,
  fecha, ámbito (`ALL`/`ESP`) y segmento (`all`/`brand`/`non_brand`). Conserva
  histórico y solo admite datos finales. Timezone: `America/Los_Angeles`.
- `seo_search_console_dimension_metrics`: último ranking top para 7, 28 y 90
  días de query ESP, page ESP y country global. El conjunto se obtiene completo
  antes de sustituirse atómicamente. No es un dataset exhaustivo ni fuente de
  verdad para KPI, ceros o alertas.
- `seo_salesforce_organic_daily_metrics`: conteo diario de registros Lead cuya
  condición es exactamente `Medio_origen__c = 'Orgánico'`. Timezone:
  `Europe/Madrid`; ayer es el último día cerrado y los días cubiertos sin Leads
  son ceros reales.

Search Console determina `closed-through` consultando una ventana reciente con
`dataState=all` y dimensión `date`. Si existe `metadata.first_incomplete_date`,
el cutoff es el día anterior; si no existe, se usa la última fecha devuelta. Sin
una fecha fiable, la sync falla y no persiste días abiertos. Las cuatro series
exactas se consultan con `dataState=final`; una fecha cerrada ausente se guarda
como `0 clicks`, `0 impressions`, `ctr=null`, `position=null`.

## Marca y detalle

Las únicas variantes son las de `seo_analytics.brand_variants` (por defecto
`hr motor`, `hrmotor`, `hr-motor`, `hrmotor.com`). Una única capa genera regex
RE2 case-insensitive para `includingRegex`/`excludingRegex` y clasifica las
query rows locales. Una expresión vacía o superior al límite falla; nunca se
trunca ni se inventan errores ortográficos, fabricantes o competidores.

`ALL / all` y `ESP / all` son los totales exactos de referencia. `ESP / brand`
y `ESP / non_brand` son subconjuntos observables obtenidos mediante filtros de
consulta. Search Console excluye consultas anonimizadas al aplicar esos filtros,
por lo que ambos segmentos pueden sumar menos que `ESP / all`; la diferencia no
se interpreta como error, cero ni tercer segmento.

Por ejecución se realizan 14 consultas Search Analytics: una de cutoff, cuatro
series diarias exactas y nueve rankings (tres dimensiones por tres periodos),
además del refresh OAuth cuando proceda. Límites: 1.000 queries, 1.000 pages y
100 countries por periodo; el dashboard muestra como máximo 50 queries y 50
pages.

## Salesforce y compatibilidad legacy

La consulta SEO selecciona únicamente `Id, CreatedDate` de `Lead`, filtra
`IsDeleted = false` y `Medio_origen__c = 'Orgánico'`, y pagina una única ventana.
Cada registro cuenta; no se deduplican personas ni se filtra conversión, status
o record type. `CreatedDate` se convierte a Madrid antes de agrupar.

La columna legacy `salesforce_leads.medio_origen` conserva su contrato:
`LEA_SEL_Medio_Origen__c -> medio_origen`. El Lote 2 no modifica esa columna,
su sincronizador ni consumidores de Leads/Campañas. Lead orgánico Salesforce y
conversiones web GA4 son métricas independientes y no se suman.

## Dashboard

Rangos cerrados permitidos: los valores textuales exactos `7`, `28` y `90`;
default 28 para cualquier otro valor. El cutoff publicado de cada fuente procede
del último `ReportSyncRun` completado con `source_cutoff_at`; un run fallido o en
curso posterior no invalida datos completados anteriores. Search Console exige
además que la property del run coincida con la configurada.

Un KPI solo está disponible si las filas locales cubren todos los días desde el
inicio hasta el cutoff, sin huecos. Las filas sincronizadas con cero son ceros
reales; una fila ausente significa cobertura incompleta y se representa como
ausencia. Resumen y Tráfico y conversión usan el menor cutoff común cuando
mezclan fuentes. Búsquedas y páginas usa exclusivamente el periodo propio de
Search Console, por lo que puede terminar en una fecha distinta. Si solo existe
una fuente, sus métricas disponibles no quedan bloqueadas por la ausente. Las
secciones son Resumen, Tráfico y conversión, Búsquedas y páginas, Salud técnica
y GEO/IA.

- KPI España: clicks, impressions, CTR, posición ponderada y Lead orgánico
  Salesforce.
- `CTR = SUM(clicks) / SUM(impressions)`; posición = suma de
  `daily_position * daily_impressions` dividida por impressions.
- Resto del mundo resta solo magnitudes aditivas y recalcula ratios; la posición
  solo se muestra si puede ponderarse válidamente.
- `—` significa ausencia; `0` se conserva cuando la fuente cubrió realmente el
  día.
- Readiness es estado técnico neutral, no uno de los cinco estados analíticos.

## Operación y seguridad

```bash
php artisan seo:diagnose-integrations
php artisan seo:diagnose-integrations --live
php artisan seo:sync-search-console --days=120
php artisan seo:sync-salesforce-organic --days=120
```

`--days` acepta 1–480; el scheduler usa 120 días a las 05:15 y 05:30
Europe/Madrid, con `withoutOverlapping(120)` y alertas técnicas administrativas.
Una fuente sin configurar devuelve `SKIPPED` exitoso y no inventa datos.
Fallos configurados devuelven error, quedan en `ReportSyncRun` sanitizado y no
vacían rankings anteriores. No se persisten tokens, secretos ni payloads raw.
