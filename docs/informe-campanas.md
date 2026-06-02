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

```bash
cd /var/www && php -d memory_limit=512M artisan salesforce:sync-campaign-leads --days=90 --fresh -vvv && php -d memory_limit=512M artisan salesforce:sync-opportunities --days=90 --fresh -vvv && php artisan campaigns:sync-meta --days=90 && php artisan campaigns:sync-google --days=90 && php -d memory_limit=512M artisan campaigns:build-attribution --days=90 --window=30 && php artisan reports:refresh-campaigns --days=90 --window=30 --store && php artisan cache:clear
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
