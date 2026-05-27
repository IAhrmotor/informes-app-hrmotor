# Informe de campanas digitales

URL: `/informes/campanas`

La V1 cruza inversion cacheada de Meta Ads / Google Ads con leads Salesforce atribuidos y oportunidades locales. Si no hay credenciales de Ads, los comandos no fallan y el informe carga con datos Salesforce y avisos internos.

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
cd /var/www && php -d memory_limit=512M artisan salesforce:sync-monthly-commercial --days=60 --fresh -vvv && php -d memory_limit=512M artisan reports:refresh-monthly-commercial --days=30 --store --show-summary && php -d memory_limit=512M artisan salesforce:sync-opportunities --days=60 --fresh -vvv && php -d memory_limit=512M artisan salesforce:sync-calls --days=60 --fresh -vvv && php artisan campaigns:sync-meta --days=60 && php artisan campaigns:sync-google --days=60 && php artisan campaigns:build-attribution --days=60 --window=30 && php artisan reports:refresh-campaigns --days=30 --window=30 --store && php artisan cache:clear
```

Backfill inicial opcional:

```bash
php artisan campaigns:sync-meta --days=180
php artisan campaigns:sync-google --days=180
php artisan campaigns:build-attribution --days=180 --window=30
php artisan reports:refresh-campaigns --days=180 --window=30 --store
```

## Nota de importe vendido

El informe actual de Reservas/Ventas no persiste un campo local de importe vendido. Por eso `sale_amount`, ROAS y ROI quedan en `null` hasta mapear el API name correcto y reutilizarlo de forma centralizada.
