# Informe de campanas digitales

URL: `/informes/campanas`

La V1 cruza inversion cacheada de Meta Ads / Google Ads con leads Salesforce atribuidos y oportunidades locales. La vista de Direccion pivota sobre el lead: inversion, impresiones y clicks se filtran por fecha publicitaria; los leads se filtran por fecha de creacion; oportunidades, reservas, ventas y compras se cuentan como resultados posteriores de esos leads dentro de la ventana de atribucion seleccionada. Si no hay credenciales de Ads, los comandos no fallan y el informe carga con datos disponibles y avisos internos para admin.

## Variables de entorno

No se deben hardcodear credenciales. Configurar en `.env`:

```dotenv
INFORMES_AUTH_ENABLED=true
INFORMES_AUTH_EMAIL=admin@hrmotor.com
INFORMES_AUTH_PASSWORD=definir_password_seguro
INFORMES_AUTH_REMEMBER_DAYS=30

META_API_VERSION=v22.0
META_ACCESS_TOKEN=
META_AD_ACCOUNT_IDS=
META_APP_ID=
META_APP_SECRET=

GOOGLE_ADS_API_VERSION=v22
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_CLIENT_ID=
GOOGLE_ADS_CLIENT_SECRET=
GOOGLE_ADS_REFRESH_TOKEN=
GOOGLE_ADS_CUSTOMER_IDS=
GOOGLE_ADS_LOGIN_CUSTOMER_ID=
```

## Comandos diarios

El scheduler ejecuta Meta a las 01:30, Google a las 01:45, construye la
atribucion a las 02:15 y genera el snapshot a las 03:15 (`Europe/Madrid`). El
servidor solo necesita `php artisan schedule:run` cada minuto.

Secuencia manual recomendada:

```bash
php artisan campaigns:sync-meta --days=120
php artisan campaigns:sync-google --days=120
php -d memory_limit=512M artisan campaigns:build-attribution --days=120
php artisan reports:refresh-campaigns --days=120 --store
```

Backfill 12 meses bajo demanda:

```bash
php -d memory_limit=512M artisan salesforce:sync-campaign-leads --months=12 --fresh -vvv
php -d memory_limit=512M artisan salesforce:sync-opportunities --months=12 --fresh -vvv
php artisan campaigns:sync-meta --months=12
php artisan campaigns:sync-google --months=12
php -d memory_limit=512M artisan campaigns:build-attribution --months=12 --window=30
php artisan reports:refresh-campaigns --months=12 --window=30 --store
```

## Nota de importe vendido

El campo funcional confirmado es `Opportunity.OPO_FOR_Importe_total__c`, sincronizado localmente como `salesforce_opportunities.opo_for_importe_total`. Campanas usa este campo como importe principal y `amount` solo como fallback positivo. Si no hay importes sincronizados o no cruzan con oportunidades locales, el aviso tecnico se muestra solo a usuarios admin.

## Semantica y trazabilidad

`Tipo de campana` y `Tipo del Lead` son filtros independientes. Venta en el eje
de Campanas clasifica la campana; no implica que el RecordType del Lead sea
Venta.

La normalizacion limpia mayusculas, tildes, espacios y guiones bajos.
`VENTAS 1`, `VENTAS_1` y `ventas` son comparables, pero siempre se conserva el
nombre bruto. Los metodos auditables son:

- `ad_id_match`;
- `adset_or_adgroup_id_match`;
- `campaign_id_match`;
- `campaign_name_exact_match`;
- `campaign_name_flexible_match`;
- `salesforce_only`.

Cada atribucion guarda `matched_source_field/value`,
`matched_platform_field/value` y `match_candidate_count`. Si una clave devuelve
varias campanas, no se elige la primera: queda como ambigua y sin cruce.

Meta y Google mantienen el inventario de IDs de anuncio, adset/ad group y
campana en `campaign_platform_identifiers`; la inversion sigue agregada por
campana para no duplicar importes. El builder excluye Leads eliminados y
reemplaza el periodo dentro de una transaccion.

La exportacion de atribuciones muestra por Lead.Id nombres bruto/final, IDs,
metodo, campos y valores del match, RecordType, Opportunity y fechas. La
cabecera separa sincronizacion de Salesforce, Meta y Google, construccion,
generacion y corte. Si las fuentes son posteriores al builder, el panel solicita
reconstruir atribuciones.

La sección `Conciliación por origen` reconstruye por entidades distintas:

- atribuciones procedentes de Google/Meta;
- atribuciones `Salesforce-only`;
- total distinto;
- solapamientos entre ambos universos.

Las campañas a revisar se ordenan por inversión, de forma que una campaña con
gasto alto y atribución cero no quede detrás de incidencias de poco impacto.
