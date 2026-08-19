# SEO/Analytics

## Alcance operativo

Los Lotes 2, 3 y 4 incorporan datos persistidos de Google Search Console, una
proyección diaria aislada de Leads orgánicos Salesforce y Conversiones web
orgánicas GA4. `GET
/informes/seo-analytics` consulta exclusivamente la base local y configuración:
no llama Google, Salesforce, GA4 ni SISTRIX.

Continúan pendientes el crawler general, SISTRIX AI Check, motor comparativo,
scoring, anomalías y correo. Salud técnica ya monitoriza un conjunto acotado,
incluidos robots/sitemaps, sin convertirse en crawler.

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
- `seo_ga4_organic_daily_metrics`: total diario GA4 por property, fecha y
  ámbito (`ALL`/`ESP`), con `key_events DECIMAL(18,6)`.
- `seo_ga4_organic_key_event_daily_metrics`: detalle España por fecha y
  `event_name`, identificado mediante SHA-256 sin sustituir el nombre original.

Search Console determina `closed-through` consultando una ventana reciente con
`dataState=all` y dimensión `date`. Si existe `metadata.first_incomplete_date`,
el cutoff es el día anterior; si no existe, se usa la última fecha devuelta. Sin
una fecha fiable, la sync falla y no persiste días abiertos. Las cuatro series
exactas se consultan con `dataState=final`; una fecha cerrada ausente se guarda
como `0 clicks`, `0 impressions`, `ctr=null`, `position=null`.

## Conversiones web orgánicas GA4

La definición cerrada es `keyEvents` con atribución event-scoped
`defaultChannelGroup = "Organic Search"` y `platform = "web"`. España añade
`countryId = "ES"`; Search Console conserva independientemente `ESP`. No se usa
`sessionDefaultChannelGroup`, `sessionMedium` ni Organic Social. Los totales
global y España proceden de reportes agregados propios; nunca se derivan del
detalle por evento.

GA4 conserva crédito decimal/fraccional y solo redondea a dos decimales en la
vista. `Lead orgánico Salesforce` y `Conversiones web orgánicas (GA4)` son
cardinalidades distintas: no se suman, deduplican ni sustituyen. La sync verifica
property, timezone, al menos un web stream y al menos un Key Event configurado.
Lista Data Streams y Key Events mediante Admin API read-only; nunca consulta
Measurement Protocol secrets.

GA4 no ofrece el `dataState=final` de Search Console. El cutoff es operativo:
fecha actual en la timezone real de la property menos
`SEO_GA4_REPORTING_LAG_DAYS` (default 3; rango seguro 2–7). La ventana móvil se
resincroniza completa para absorber revisiones posteriores de procesamiento y
atribución. Cada página `runReport` se rechaza antes de interpretar sus filas si
GA4 indica `subjectToThresholding`, `dataLossFromOtherRow` o
`samplingMetadatas` no vacío. Solo una respuesta sin esas señales puede
rellenar con cero las fechas cubiertas ausentes; el detalle no fabrica ceros. El
detalle del rango se reemplaza solo dentro de una transacción después de
descargar y validar todos los reportes. Una incidencia de calidad conserva los
datos anteriores y finaliza el `ReportSyncRun` como fallo técnico.

## Salud técnica SEO

Salud técnica monitoriza un conjunto deliberadamente acotado; no es un crawler.
Los candidatos se seleccionan en este orden: raíz de
`SEO_TECHNICAL_SITE_URL`, URLs de `SEO_TECHNICAL_STRATEGIC_URLS` y ranking local
Search Console de páginas España/90 días para la property configurada. Home
siempre es estratégica. No se recorren enlaces HTML, no se descargan assets, no
se ejecuta JavaScript y el sitemap no incorpora automáticamente todas sus URLs.
Stock no aporta candidatos directos porque `SalesforceVehicle` carece de una URL
pública verificable; una ficha solo entra si está configurada o aparece en el
ranking local de Search Console.

Toda petición usa `HRMotor-SEO-Health/1.0`, timeouts acotados y un guard SSRF
único. Solo admite HTTP/HTTPS, puertos 80/443 y hosts DNS ASCII canónicos y
exactos del sitio o de `SEO_TECHNICAL_ALLOWED_HOSTS`; rechaza userinfo, IP
literal o numérica ambigua, percent escapes, trailing dot, localhost y toda
resolución que no sea global. Si una sola A/AAAA no es global, se rechaza el
host completo. Cada redirect se resuelve y valida antes de seguirlo, con
redirects automáticos desactivados y máximo 5.

El transporte usa Guzzle `>=7.15.2,<8` (lock 7.15.3), desactiva explícitamente
el proxy de entorno, conserva TLS/hostname verification y fija mediante
`CURLOPT_RESOLVE` la IP global validada para evitar DNS rebinding. No se envían
cookies, Proxy-Authorization ni Authorization, y no se persisten bodies o
headers completos.

`robots.txt` se construye desde el origen y solo aporta su estado HTTP y
directivas `Sitemap:`; no se interpretan `Allow`/`Disallow`. Los sitemaps de
robots se combinan con `SEO_TECHNICAL_SITEMAP_URLS`, se deduplican y soportan
`urlset`, `sitemapindex`, referencias recursivas y gzip acotado. El XML usa
`LIBXML_NONET`, nunca expansión de entidades. Límites duros/configurados: 50
documentos, 100.000 URLs escaneadas y 10 MiB por documento/descompresión. Una
URL encontrada queda `in_sitemap=true`; una ausencia solo es `false` si todo el
scan terminó, y es `null` si no existe sitemap, falla o queda parcial.

`sitemap_scan_complete=true` exige simultáneamente un descubrimiento concluyente
de fuentes y el procesamiento completo de sus documentos. `robots.txt` 2xx solo
es concluyente si el body se leyó íntegramente y todas sus directivas `Sitemap:`
fueron válidas; 404/410 acredita que no aporta directivas. Cualquier otro estado,
error, truncamiento o directiva rechazada por seguridad deja el scan parcial sin
solicitar destinos bloqueados. En un scan parcial, una pertenencia encontrada
conserva `true`, pero una ausencia permanece `null`.

Cada URL recibe un único GET más redirects válidos. En HTML 2xx se observan
`meta robots`/`googlebot`, `X-Robots-Tag`, canonical y coincidencia con la URL
final; otros content types no se parsean. El body se limita a 512 KiB y se
descarta. La lectura streaming itera hasta EOF o límite+1, usa `read_timeout` y
cierra siempre el stream. Un fallo posterior a headers produce
`body_read_error` para esa URL y no aborta las demás. En un body truncado, una
señal positiva noindex o canonical múltiple se conserva; la ausencia de
noindex/canonical o la unicidad/coincidencia canonical quedan no evaluables.
HTTP 4xx/5xx, noindex, redirects y errores de red se persisten como hechos
técnicos, no como estados analíticos ni `OperationalAlert` individuales.

`seo_technical_urls` conserva el registro activo/inactivo y metadata de origen;
`seo_technical_url_checks` conserva un check idempotente por URL/día. El comando
`seo:sync-technical-health` hace toda la red antes de la transacción corta de
persistencia y publica `ReportSyncRun` (`seo_technical_health`,
`public_website`). Se programa a las 06:00 Europe/Madrid con lock de 120 minutos.
El panel Salud técnica lee exclusivamente BD/config, muestra el último run
completado aunque exista uno fallido/en curso posterior, y no participa en el
common cutoff ni en el selector 7/28/90. Límites: 200 candidatos por defecto,
500 absoluto, 150 automáticos de Search Console y 100 filas visibles.

Quedan expresamente fuera: indexabilidad completa, evaluación RFC de robots,
crawler general, Core Web Vitals/Lighthouse, render JavaScript, schema.org,
scoring, severidad, recomendaciones y alertas analíticas. La retención del nuevo
histórico no se inventa en este lote. También queda pendiente un mapping
verificable Stock -> URL pública.

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
ausencia. Resumen y Tráfico y conversión usan el menor cutoff común de Search
Console, Salesforce y GA4 disponibles. Búsquedas y páginas usa exclusivamente el periodo propio de
Search Console, por lo que puede terminar en una fecha distinta. Si solo existe
una fuente, sus métricas disponibles no quedan bloqueadas por la ausente. Las
secciones son Resumen, Tráfico y conversión, Búsquedas y páginas, Salud técnica
y GEO/IA.

- KPI España: clicks, impressions, CTR, posición ponderada, Lead orgánico
  Salesforce y Conversiones web orgánicas GA4 como métricas separadas.
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
php artisan seo:sync-ga4-organic --days=120
```

`--days` acepta 1–480; el scheduler usa 120 días a las 05:15, 05:30 y 05:45
Europe/Madrid, con `withoutOverlapping(120)` y alertas técnicas administrativas.
Una fuente sin configurar devuelve `SKIPPED` exitoso y no inventa datos.
Fallos configurados devuelven error, quedan en `ReportSyncRun` sanitizado y no
vacían rankings anteriores. No se persisten tokens, secretos ni payloads raw.
