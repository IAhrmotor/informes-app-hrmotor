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

- Meta: 01:30;
- Google: 01:45;
- atribución: 02:15;
- snapshot del informe: 03:15.

```bash
php artisan campaigns:sync-meta --days=120
php artisan campaigns:sync-google --days=120
php -d memory_limit=512M artisan campaigns:build-attribution --days=120
php artisan reports:refresh-campaigns --days=120 --store
```

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
