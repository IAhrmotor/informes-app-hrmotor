# Informe de Campañas

Actualizado: 2026-08-06. URL: `/informes/campanas`.

## Fuentes y períodos

- Google Ads y Meta Ads: inversión, impresiones, clics y métricas diarias.
- Salesforce: Leads, Opportunities y campaña original.
- Tablas principales: `campaign_platform_daily_metrics`,
  `campaign_platform_identifiers`, `campaign_salesforce_leads`,
  `campaign_lead_attributions`, `campaign_unresolved_attributions` y
  `campaign_operational_classifications`.

Se muestran por separado:

- período de gasto publicitario;
- fecha de creación del Lead;
- fecha del resultado posterior;
- sincronización de cada fuente;
- construcción de la atribución;
- generación y corte del dataset.

No se aplica una ventana de conversión de 30, 60 o 90 días. `--window` se
mantiene únicamente como opción legacy sin efecto.

## Universos

El dataset distingue:

- Google Ads / Meta Ads;
- Salesforce-only;
- campañas de prueba;
- pendientes de revisar;
- atribuciones ambiguas;
- registros sin atribuir.

Salesforce-only suma en la actividad de Salesforce, pero no en el rendimiento
de pago cuando no existe inversión asociada. La conciliación interna por origen
se muestra únicamente a Administrador/IT.

`Tipo/objetivo de campaña` y `RecordType real del Lead` son filtros diferentes.
Una campaña clasificada como Venta puede contener Leads cuyo RecordType sea
Tasación; el filtro de campaña no reescribe el tipo real del Lead.

## Normalización y clasificación operativa

La comparación de nombres normaliza mayúsculas, tildes, espacios y guiones
bajos. Por tanto, `VENTAS 1`, `VENTAS_1` y `ventas` pueden ser comparables, pero
se conserva el nombre bruto y el motivo exacto del match.

Cada campaña se clasifica por plataforma, cuenta e ID como:

- `real`;
- `test`;
- `pending_review`.

Que el nombre contenga `prueba` o `test` solo genera una sugerencia. La campaña
queda fuera de KPIs ejecutivos, rankings y recomendaciones únicamente cuando
Dirección o Administrador/IT guarda una clasificación explícita `test`, con
motivo, usuario y fecha. Las campañas pendientes deben revisarse por ID.

## First touch y atribución

Precedencia vigente:

1. relación explícita del Lead convertido con Opportunity;
2. coincidencia inequívoca por identificadores publicitarios;
3. campaña original inequívoca del Lead;
4. primera campaña inequívoca conocida de la cuenta;
5. Salesforce-only;
6. ambiguo o sin atribuir.

Los métodos de match auditables incluyen:

- `ad_id_match`;
- `adset_or_adgroup_id_match`;
- `campaign_id_match`;
- `campaign_name_exact_match`;
- `campaign_name_flexible_match`;
- `salesforce_only`.

No se sustituye el first touch por remarketing posterior. Cada Lead, cuenta,
Opportunity o resultado se reclama una sola vez. Si varias campañas tienen la
misma precedencia sin una señal inequívoca, el registro queda ambiguo, no se
duplica y no se atribuye al rendimiento de una campaña concreta.

La traza conserva campaña elegida, candidatos, motivo, confianza, primer
contacto, IDs utilizados, campos/valores del match, ambigüedad y versión de
reglas. El builder excluye Leads eliminados y sustituye el período dentro de una
transacción.

## Métricas y alertas

- Leads, Opportunities y resultados se cuentan como entidades distintas.
- Inversión no se duplica por almacenar inventario de anuncio, adset/ad group y
  campaña.
- Importe vendido usa `Opportunity.OPO_FOR_Importe_total__c`, almacenado como
  `opo_for_importe_total`; `Amount` solo es fallback positivo.
- CTR, CPC, CPL, costes por etapa, ROAS y ROI usan denominadores visibles y
  devuelven nulo cuando no existe denominador válido.

Prioridad de `Campañas a revisar`:

1. inversión con posible fallo de medición o cero Leads;
2. mayor inversión con cero resultados;
3. coste por resultado fuera del benchmark y muestra suficiente;
4. caída relevante del funnel.

Dentro de cada nivel se ordena por impacto económico, principalmente inversión.
Los umbrales se muestran y cualquier cambio futuro deberá versionarse.

## Auditoría

- JSON KPI: `/informes/campanas/data/kpi-audit`.
- CSV KPI: `/informes/campanas/export/kpi-audit.csv`.
- CSV campañas: `/informes/campanas/export/campaigns.csv`.
- CSV atribuciones: `/informes/campanas/export/attributions.csv`.

La exportación de atribuciones incluye Lead ID, Opportunity ID, campaña final y
bruta, plataforma, IDs publicitarios, método, confianza, candidatos,
ambigüedad, RecordType, fechas, versión, construcción y sincronización.

## Operación

El scheduler ejecuta en `Europe/Madrid`:

- Leads Salesforce de Campañas: 01:15;
- Meta: 01:30;
- Google: 01:45;
- atribución: 02:15;
- snapshot del informe: 03:15.

```bash
php artisan salesforce:sync-campaign-leads --days=120
php artisan campaigns:sync-meta --days=120
php artisan campaigns:sync-google --days=120
php -d memory_limit=512M artisan campaigns:build-attribution --days=120
php artisan reports:refresh-campaigns --days=120 --store
```

La sincronización diaria de Leads Salesforce usa upsert incremental, sin
`--fresh`, se ejecuta antes de reconstruir la atribución y está monitorizada por
la infraestructura común de alertas operativas. Un fallo abre una alerta y el
primer éxito posterior la resuelve. No cambia el WHERE legacy ni amplía el
universo de Campañas.

Para un backfill debe usarse un rango explícito, probar primero sobre copia y
conciliar por Lead/Opportunity ID. Reconstruir first touch puede cambiar
históricos; no afecta cierres económicos ya congelados.

Credenciales de Meta y Google se configuran exclusivamente mediante variables
de entorno. No deben aparecer en documentación, logs ni respuestas del
dashboard.

Archivos principales:

- `app/Services/Campaigns/CampaignAttributionBuilderService.php`;
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`;
- `app/Models/CampaignOperationalClassification.php`;
- `app/Console/Commands/BuildCampaignAttributionCommand.php`.

## Cierre de inversión y auditoría de atribución

- Una coincidencia múltiple en un nivel de primer toque queda ambigua y no entra
  en KPI, pero permanece auditable.
- `tasador`, `ren2click` y `hrrenting` se excluyen solo por coincidencia exacta;
  se conservan en auditoría con su motivo.
- Salesforce-only conserva volumen y trazabilidad sin presentar costes como cero.
- Administrador/IT puede cerrar inversión mensual mediante snapshot versionado.
  Los resultados comerciales siguen abiertos; reabrir exige motivo y no borra
  snapshots.
## Correctivo Salesforce-only (2026-08-07)

Salesforce-only solo aporta Leads, Oportunidades y `lead_to_opportunity`.
Reservas, ventas, compras, importes y métricas de inversión son no aplicables
(`null`) por fila y no contaminan los totales de Campañas.

`platform_leads` procede de acciones nativas de Meta y `platform_conversions`
de métricas nativas de Google Ads; no contienen resultados comerciales
Salesforce y pueden conservarse en snapshots económicos.

No se verificaron identificadores persistentes de Tasador, ren2click ni
hrrenting en el repositorio. Se conserva el fallback exacto por nombre y queda
auditado como `exact_name` con motivo estable.
## Simulación histórica de Campaign Leads y atribución

La secuencia de simulación no escribe tablas ni invalida cachés:

```bash
php artisan reports:reprocess-lead-record-types --dry-run --from=YYYY-MM-DD --to=YYYY-MM-DD
php artisan salesforce:sync-campaign-leads --dry-run --from=YYYY-MM-DD --to=YYYY-MM-DD
php artisan campaigns:build-attribution --dry-run --from=YYYY-MM-DD --to=YYYY-MM-DD
```

El último comando reutiliza el builder real, compara IDs actuales y simulados,
informa cambios, ambigüedades, exclusiones y Salesforce-only, y aborta si la
partición del universo no concilia. Para escritura histórica con `--from` se
requiere `--reason`; no ejecutar sin aprobación tras la conciliación.
## Diagnóstico de tipos nulos en simulación

El resumen de dry-run representa un tipo no normalizable como `null` solo para
conteo técnico; no cambia la clasificación funcional ni excluye el Lead del
universo. El reproceso de tipos detalla transiciones raw/actual/calculada y una
muestra acotada de Salesforce Lead IDs, sin PII.
## Precedencia de IDs frente a Meta Instant Forms

Los IDs originales `Id_Adquirido__c` y `Contenido_Adquirido__c` se resuelven
antes de inferir Meta Direct Form por portal/origen. Meta se usa solo cuando no
existe match publicitario; IDs que resuelven campañas distintas en el mismo
nivel quedan ambiguos y fuera de KPI.
## Regresión Meta Direct Form: candidatos sin campaña materializada

Un Lead con `portal_text = Meta` y `fuente_origen = Facebook` es candidato a Formulario Directo Meta aunque `campaign_acquired` sea nulo, vacío o no utilizable. Ese caso no se contabiliza como valor de adquisición inválido y llega al resolver, donde los IDs originales se contrastan primero. La inferencia Meta se aplica solo si esos IDs no resuelven una campaña; los Leads no Meta sin una campaña válida siguen descartándose como hasta ahora.

## Conflictos entre identificadores publicitarios

La atribución recopila los matches de `Id_Adquirido__c` y
`Contenido_Adquirido__c` contra ad, adset, ad group y campaign ID antes de
elegir. Se agrupan por identidad `platform + campaign_id` (o nombre normalizado
sin ID); identidades distintas quedan ambiguas. Para una misma campaña se usa
como traza el match más específico: ad, adset, ad group y campaign ID.

## Procedencia Salesforce efectiva (Fase 6)

El universo de Campañas no cambia: la sincronización y el builder conservan sus
señales legacy de admisión, incluida la excepción Meta Direct Form. Los campos
UTM nuevos nunca crean candidatos por sí solos.

Para cada Lead ya admitido, la atribución usa estas parejas independientes:

- `utm_campaign__c` → `Campa_a_Adquirida__c`;
- `utm_id__c` → `Id_Adquirido__c`;
- `utm_source__c` → `Fuente_Adquirida__c`;
- `utm_medium__c` → `Medio_Adquirido__c`;
- `utm_content__c` → `Contenido_Adquirido__c`.

Solo null, vacío o whitespace permiten fallback. El matching, first touch,
deduplicación y ambigüedad conservan su precedencia; trabajan con el valor
efectivo y trazan el API Name ganador. La versión de atribución es
`2026-09-03.1`. El dry-run sigue sin escribir ni invalidar caché y expone un
recuento agregado de fuentes ganadoras por dimensión.
