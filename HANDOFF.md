# Handoff del proyecto

Fecha: 2026-07-31
Proyecto: `informes-app-hrmotor`

## 1. Estado general

Aplicación Laravel interna para HR Motor que sincroniza y consolida Salesforce,
Google Ads y Meta Ads en seis informes:

- Leads
- Reservas / Ventas
- Llamadas
- Campañas
- Comisiones Comerciales
- Stock

La descripción funcional y las consultas de contraste están centralizadas en
`Documentacion_general_informes_y_contraste_salesforce.md`. Las reglas económicas
de comisiones están en `Calculo_comisiones_comerciales.txt`.

No incluir secretos en este fichero. Las credenciales se configuran únicamente por
variables de entorno.

## 2. Stack y arquitectura

- Laravel 13
- PHP 8.4
- Blade
- JavaScript vanilla
- Vite
- PHPUnit/Pest
- Salesforce REST API
- Google Ads API
- Meta Marketing API

Patrón de datos:

```text
fuente externa
  -> comando/servicio de sincronización
  -> tablas locales
  -> normalización y atribución
  -> servicio de dataset
  -> controlador / Blade / exportación
```

Los dashboards no deben depender de una llamada a Salesforce durante el render.
La excepción es el recuento interno de reseñas por delegación, que se lee en vivo
con cache y está protegido frente a fallos.

## 3. Acceso

Los mínimos por defecto están en `App\Support\ReportUserAccess` y pueden
sobrescribirse en `report_access_settings`:

| Informe | Rol mínimo por defecto |
|---|---|
| Leads | viewer |
| Reservas / Ventas | viewer |
| Llamadas | viewer |
| Campañas | director |
| Comisiones Comerciales | director |
| Stock | admin |

`commission_auditor` es un rol aislado: solo accede a Comisiones Comerciales.
La gestión de usuarios, permisos, coeficientes, penalizaciones, capacidades y
exportaciones administrativas queda reservada a admin según cada controlador.

## 4. Informe Leads

### Fuente y pivote

- Salesforce: `Lead`, `Task`, `Event`, `User`.
- Local: `salesforce_leads`, `salesforce_activities`,
  `salesforce_lead_activity_summaries`, `salesforce_users`.
- Pivote del dashboard: `Lead.CreatedDate`.

El sincronizador también trae leads cuyo `Fecha_captador__c` cae en el rango para
Contact Center. Esto no cambia el pivote del dashboard de Leads.

### Reglas vigentes

- canal por `Medio_Nuevo__c`;
- portal por prioridad distinta para llamada y formulario;
- delegación con `LeadDelegationNormalizer`;
- convertido/descartado/potencial por `Status`;
- potencial sin trabajar: sin actividad o sin actividad reciente de tres días;
- sin asignar: potencial en owner técnico;
- gestionado: convertido, descartado o potencial con actividad reciente;
- `Venta` incluye `Venta con cambio`;
- los no clasificados entran en totales.

### Archivos y auditoría

- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`
- `app/Services/Reports/Leads/LeadDelegationNormalizer.php`
- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyLeadsSyncService.php`
- JSON: `/informes/leads/data/kpi-audit`
- CSV: `/informes/leads/export/kpi-audit.csv`

## 5. Informe Reservas / Ventas

### Fuente y pivote

- Salesforce: `Opportunity`, relaciones `Account`, `Owner`, `Product2`; `Lead`
  para resolver procedencia.
- Local: `salesforce_opportunities`, fallback `leads_raw`.
- Fecha seleccionable: creación, reserva o firma.

### Reglas vigentes

- `Venta` = Venta + Cambio.
- Reserva viva = reserva true, CV false y no cerrada perdida.
- Caída = `Cerrada Perdida`.
- CV firmado = flag true y no cerrada perdida.
- `Reservas vivas actuales Salesforce` no lleva filtro temporal.
- El portal se resuelve por Opportunity, lead relacionado, fuente, fallbacks y
  finalmente `Sin clasificar`.
- Los porcentajes de tablas agrupadas son participación sobre el total de cada
  columna, no porcentaje sobre el total de la fila.

### Archivos y auditoría

- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`
- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`
- JSON: `/informes/reservas-ventas/data/kpi-audit`
- CSV: `/informes/reservas-ventas/export/kpi-audit.csv`

## 6. Informe Llamadas

### Fuente y pivote

- Salesforce `Task` con `Type = Call` y `CallObject != null`.
- `User` para perfil/delegación.
- `Lead` para recuperar portal cuando `WhoId` es Lead.
- Local: `salesforce_calls`.
- Pivote: `Task.CreatedDate`.

### Reglas vigentes

- estado atendida por resultado `ANSWERED` o “Respondido por”;
- `ABANDONED` es perdida, nunca desborde;
- duración ajustada: -5 segundos en directa, -10 en portal;
- desborde: portal atendido por Contact Center/Atención al Cliente, con regla
  especial de Web/Google Maps y opción de teclado;
- clasificación de equipos y usuarios mediante alias canónicos;
- identidades de sistema no entran en vistas operativas.

No existe todavía exportación específica de auditoría KPI para Llamadas.

### Archivos

- `app/Services/Reports/Calls/SalesforceCallSyncService.php`
- `app/Services/Reports/Calls/CallDescriptionParser.php`
- `app/Services/Reports/Calls/CallClassificationRules.php`
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`

## 7. Informe Campañas

### Fuentes y pivote

- Salesforce `Lead` y `Opportunity`.
- Meta Ads y Google Ads.
- Local: `campaign_platform_daily_metrics`,
  `campaign_lead_attributions`.
- Inversión por fecha publicitaria.
- Resultados por `Lead.CreatedDate`.

### Reglas vigentes

- el flag legacy `--window` no tiene efecto;
- cruce de campaña por IDs, nombre exacto/flexible y fallback Salesforce;
- cruce a Opportunity primero por `ConvertedOpportunityId`, después por cuenta y
  señales de contacto;
- una Opportunity se atribuye una sola vez;
- contextos visibles: Todos, Venta y Tasación;
- Exposición, Branding y Otros son subcategorías de Venta;
- venta requiere contrato firmado, fecha y no cerrada perdida;
- compra requiere contrato firmado válido y tipo Tasación;
- importe vendido prioriza `OPO_FOR_Importe_total__c`;
- campañas `Tasador` exacto, `ren2click` y `hrrenting` se excluyen.

### Archivos y auditoría

- `app/Services/Campaigns/CampaignLeadSyncService.php`
- `app/Services/Campaigns/CampaignAttributionBuilderService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- JSON: `/informes/campanas/data/kpi-audit`
- CSV KPI: `/informes/campanas/export/kpi-audit.csv`
- CSV campañas: `/informes/campanas/export/campaigns.csv`

## 8. Informe Comisiones Comerciales

### Estado de la pantalla

Pestañas, en orden:

1. Comerciales
2. Delegaciones
3. Call Center
4. Contact Center
5. Area Manager
6. Financieros

El export `comisiones-YYYY-MM.xlsx` genera hojas para esos seis bloques. Cada hoja
contiene entidad y comisión final; Area Managers añade `Oscar` con el 40% de la
suma de managers.

### 8.1 Comerciales

Universo:

- CV firmado;
- fecha de firma dentro del mes;
- no cerrada perdida;
- Venta, Cambio o Tasación;
- owner activo;
- gestión de venta false/null;
- usuario elegible y no técnico.

Desde junio de 2026:

- comercial:
  - Venta 60 EUR;
  - Tasación 60 EUR;
  - Cambio 85 EUR;
  - compartida 30/30;
- tasador:
  - compras Tasación + Cambio por tramo mensual;
  - ventas 60 EUR;
  - financiación 3%;
  - rapidez <30 días 20 EUR, 30–60 días 10 EUR.

Hasta mayo de 2026 se conserva el cálculo histórico de compra liquidada al vender
el vehículo, con 1,8% de rentabilidad.

La fórmula completa, penalizaciones y productos adicionales están en
`Calculo_comisiones_comerciales.txt`.

### 8.2 Delegaciones

Hay tres universos deliberadamente diferentes:

- entregas:
  - CV firmado, mes, no cerrada perdida;
  - Venta/Cambio o nombre Facilitea;
  - sin filtro de owner activo ni gestión de venta;
  - asignación exclusiva por `Tienda_de_entrega__c`;
  - sin tienda, la operación queda fuera;
- rentabilidad total:
  - firma en mes;
  - etapa distinta de Cerrada ganada y Cerrada perdida;
  - sin exigir CV ni Record Type;
  - agrupación por tienda de entrega;
- porcentajes financieros:
  - conjunto de entregas;
  - agrupación por `Delegacion_del_propietario__c`;
  - fallback a delegación del owner solo por compatibilidad de filas antiguas.

No volver a introducir un fallback de owner para la entrega: rompería la
conciliación con tienda.

### 8.3 Call Center

- compras, ventas y cambios desde Opportunity;
- requiere `Captador__c`, sin gestión de venta;
- importe = suma de `Comisi_n_Captador__c`; vacío cuenta 0 y genera aviso;
- German desde `Tasacion__c`: seguimiento German y negociación 1 informada,
  5 EUR;
- Facilitea: regla específica de owner/fuente/nombre, 5 EUR.

### 8.4 Contact Center

- cita válida por `Fecha_captador__c`, captador de cita y flag llamada/tienda;
- citas con oportunidad: enlace por `ConvertedOpportunityId`, después teléfono;
- resultados permitidos hasta el día 10 inclusive del mes siguiente;
- ventas por firma dentro del mes y mejor cita previa;
- 5 EUR por cita con oportunidad;
- 12 EUR por venta;
- ratio ventas/citas > 3%: +2 EUR por venta;
- bonus no acumulativo: 10 = 100, 15 = 250, 20 = 500;
- Show es informativo.

### 8.5 Area Manager

- managers: David Baeza, Nicolas Fernandez, Kosta Plamenov y Luis Lopez;
- KPIs: entregas, beneficio, garantía premium y compras;
- delegación por owner actual sincronizado;
- Facilitea con owner no operativo cae a tienda de entrega;
- cumplimiento por delegación se redondea a entero;
- solo paga desde 85%;
- comisión pre-llave = base KPI × porcentaje usado;
- llave del manager se calcula con el promedio de cumplimientos por delegación;
- total = suma de los cuatro KPIs tras llave;
- objetivos y asignaciones se guardan por mes.

### 8.6 Financieros

- firma dentro del mes;
- tipo fórmula Venta/Cambio, fallback a Record Type;
- no cerrada perdida;
- agrupa por `zona_financiera__c`, fallback desde delegación;
- excluye General/Sin Zona.

Bloques:

1. porcentaje financiado sobre comisión neta;
2. rentabilidad sobre beneficio financiero válido;
3. garantía premium.

Tipos de interés vacíos o 3,99%, 4,99% y 5,99% quedan fuera del bloque 2. Desde
junio de 2026 Zona Nuria y Zona Irene usan 0,50% de comisión neta en lugar de los
tres bloques.

### 8.7 Penalizaciones de financiación

Formato operativo vigente:

- `Mes comision`;
- `Email comercial`;
- `descontar comercial 4%`;
- nombre e ID Salesforce opcionales.

El email normalizado es la clave principal. Los ficheros históricos por ID se
mantienen como compatibilidad. Una nueva importación sustituye las penalizaciones
activas de los meses incluidos y conserva historial.

### Archivos y endpoints

- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`
- `app/Services/Reports/CallCenterCommissions/CallCenterCommissionDashboardService.php`
- `app/Services/Reports/ContactCenterCommissions/ContactCenterCommissionDashboardService.php`
- `app/Services/Reports/AreaManagerCommissions/AreaManagerCommissionDashboardService.php`
- `app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php`
- `/informes/comisiones-comerciales/export/comisiones.xlsx`
- `/informes/comisiones-comerciales/export/delegation-deliveries.csv`
- `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`
- `/api/comisiones_comercial?salesforce_id={ID}`

## 9. Informe Stock

### Estado

Informe activo con pestañas:

- Resumen
- Delegaciones y ventas, que unifica capacidad, stock y rankings comerciales
- Recomendaciones
- Vehículos
- Capacidades, solo admin

Fuentes:

- `Product2` para stock vivo;
- `Opportunity` para ventas firmadas;
- `Logistica__c` para trazabilidad logística;
- CSV/XLSX para plazas;
- snapshots locales diarios y de venta.

### Reglas esenciales

- stock vivo = Disponible + Reservado + Bloqueado;
- cada sync desactiva el stock anterior y reactiva solo Product2 devueltos;
- `Solo_financiado__c` decide el precio efectivo: financiado con fallback al
  normal; ambos precios y el flag se conservan por separado;
- el snapshot económico se congela una vez por Opportunity, pero su validez se
  reconcilia en cada `stock:sync-daily`;
- una venta válida requiere contrato firmado, tipo Venta/Cambio, fase distinta
  de Cerrada perdida y vehículo de interés;
- si dos ventas base-válidas apuntan al mismo vehículo, ambas se invalidan como
  `duplicate_valid_vehicle`; no se elige una arbitrariamente;
- solo `salesforce_sale_snapshots.is_valid = true` entra en ventas, ratios,
  rankings, recomendaciones y detección de entregados aún en stock;
- rotación = firma − entrada;
- precio del snapshot = importe contractual y, si falta, precio efectivo del
  vehículo; margen bruto visible = precio snapshot − precio compra;
- ocupación = stock / capacidad;
- capacidad libre = capacidad − stock;
- la antigüedad se presenta en tramos excluyentes: 0–59, 60–89, 90–119,
  120–180 y más de 180 días; `Sin fecha de entrada` se separa para que la suma
  de todos los tramos coincida siempre con el stock total;
- el resumen alerta visualmente de ocupaciones superiores al 100% o inferiores
  al 80%, calculadas sobre el stock completo y no sobre los filtros analíticos;
- ratio ventas/stock usa promedio diario de disponibles si hay cobertura ≥80%,
  o disponibles actuales como fallback aproximado;
- recomendaciones usan 120 días de ventas, capacidad libre, rotación, stock
  duplicado y similitud de segmento/combustible/precio;
- solo se recomiendan vehículos operativos y `Disponible`; se excluyen Reservado,
  Bloqueado y catálogos con prueba/test/formación/fuera de stock;
- destinos: comerciales, con capacidad positiva y plaza libre, distintos de la
  tienda actual y no incluidos en `excluded_destination_keys`; Dos Hermanas está
  excluida por configuración;
- prioridad desde 90 días, desde 3 unidades del mismo modelo o si otro destino
  supera en 40 puntos;
- se calculan todos los candidatos antes de paginar: 150 por página; la vista de
  Vehículos limita el detalle a 250 filas;
- el barrido global de recomendaciones usa perfiles compactos; tras paginar se
  generan las explicaciones completas de las 150 filas visibles;
- rankings normalizan variantes de catálogo y se ordenan por unidades vendidas;
  permiten alternar más vendidos, menos vendidos y todos. Stock, rotación,
  antigüedad y ventas/stock se conservan como contexto, sin dirigir el orden;
- al pulsar una delegación se aplica como filtro conjunto de stock, ventas y
  rankings; las antiguas URLs `section=sales` y `section=rankings` abren esta
  vista unificada;
- el simulador filtra los modelos disponibles según la marca seleccionada;
- alerta al quedar una tienda comercial con cero disponibles: Task Salesforce +
  email; se resuelve al recuperar disponibilidad.

Calidad del dato:

- el resumen muestra 20 controles y el XLSX genera 20 hojas;
- se añadieron firma sin contrato, firmado en Cerrada perdida, venta duplicada
  por vehículo, fase firmada inesperada, entrada futura, tienda sin capacidad,
  catálogo duplicado y valores no operativos;
- fases esperadas para contrato firmado: `Contrato` y `Cerrada ganada`.
- los 20 controles se calculan únicamente al abrir Resumen y los recuentos de
  campos nulos se agrupan en consultas SQL condicionales; las demás pestañas no
  ejecutan este bloque.
- el dataset carga ventas solo para Resumen/Delegaciones; Capacidades no hidrata
  stock ni ventas y las consultas analíticas de ventas usan filas ligeras sin
  hidratación Eloquent.

### Operación

Programado en `routes/console.php`:

```text
03:30 Europe/Madrid
stock:sync-daily --sales-days=14 --logistics-days=30
withoutOverlapping(120)
```

Secuencia: Product2 → oportunidades firmadas/modificadas → snapshots nuevos →
reconciliación de validez → logística → snapshot diario → alertas. La
reconciliación se ejecuta incluso con `--skip-opportunities`, usando el estado
local disponible.

Ejecución completa manual:

```bash
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Importación de capacidades:

```bash
php artisan stock:import-capacities ruta/capacidades.xlsx
```

Calidad:

- `/informes/stock/exportar/calidad-dato.xlsx`

Archivos principales:

- `app/Services/Reports/Stock/StockDashboardDatasetService.php`
- `app/Services/Reports/Stock/StockRecommendationService.php`
- `app/Services/Reports/Stock/StockCatalogNormalizer.php`
- `app/Services/Reports/Stock/StockSaleValidityService.php`
- `app/Services/Reports/Stock/SalesforceVehicleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSignedSaleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSaleSnapshotService.php`
- `app/Services/Reports/Stock/SalesforceLogisticsSyncService.php`
- `app/Services/Reports/Stock/StockAvailabilityAlertService.php`
- `config/stock.php`

## 10. Comandos operativos

### Limpieza, migraciones y build

```bash
php artisan migrate
php artisan optimize:clear
npm run build
```

### Sincronizaciones

```bash
php artisan salesforce:sync-monthly-commercial --days=120
php artisan salesforce:sync-opportunities --days=120
php artisan salesforce:sync-calls --days=120
php artisan salesforce:sync-commercial-reviews --days=120
php artisan salesforce:sync-tasaciones --days=120
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Campañas:

```bash
php artisan salesforce:sync-campaign-leads --days=90 --fresh
php artisan campaigns:sync-meta --days=90
php artisan campaigns:sync-google --days=90
php artisan campaigns:build-attribution --days=90
php artisan reports:refresh-campaigns --days=90 --store
```

### Tests focalizados

```bash
php artisan test --filter=SalesforceLeadDashboardDatasetServiceTest
php artisan test --filter=OpportunityDashboardEndpointTest
php artisan test --filter=CallDashboard
php artisan test --filter=CampaignDashboardTest
php artisan test --filter=CommercialCommissionDashboardTest
php artisan test --filter=ContactCenterCommissionDashboardTest
php artisan test --filter=StockDashboardTest
php artisan test --filter=StockRecommendationServiceTest
php artisan test --filter=StockAvailabilityAlertServiceTest
php artisan test --filter=StockCatalogNormalizerTest
php artisan test --filter=StockSaleValidityServiceTest
php artisan test --filter=StockRecommendationCandidatePaginationTest
```

## 11. Migraciones que no deben olvidarse

Comisiones:

- campos comerciales, financieros, captadores, producto y delegación histórica en
  `salesforce_opportunities`;
- `salesforce_reviews`;
- `salesforce_tasaciones`;
- `commercial_commission_month_settings`;
- tablas de penalizaciones de financiación.

Stock:

- `2026_07_29_090000_create_stock_analysis_tables.php`
- `2026_07_29_100000_add_plan_auto_plus_and_cae_to_salesforce_opportunities.php`
- `2026_07_29_110000_normalize_stock_delegation_commercial_flags.php`
- `2026_07_29_120000_merge_normalized_stock_delegations.php`
- `2026_07_29_130000_add_vehicle_profile_to_sale_snapshots.php`
- `2026_07_29_140000_create_stock_availability_alerts_table.php`
- `2026_07_30_090000_ensure_badajoz_stock_delegation_exists.php`
- `2026_07_31_090000_add_sale_validity_and_financed_stock_price.php`

## 12. Variables de entorno relevantes

Solo nombres; nunca valores:

```env
SALESFORCE_AUTH_MODE
SALESFORCE_API_VERSION
SALESFORCE_TOKEN_URL
SALESFORCE_CLIENT_ID
SALESFORCE_CLIENT_SECRET
SALESFORCE_REFRESH_TOKEN
SALESFORCE_TIMEOUT

META_API_VERSION
META_ACCESS_TOKEN
META_AD_ACCOUNT_IDS
GOOGLE_ADS_API_VERSION
GOOGLE_ADS_DEVELOPER_TOKEN
GOOGLE_ADS_CLIENT_ID
GOOGLE_ADS_CLIENT_SECRET
GOOGLE_ADS_REFRESH_TOKEN
GOOGLE_ADS_CUSTOMER_IDS

INTERNAL_REVIEWS_ENDPOINT
INTERNAL_REVIEWS_USER
INTERNAL_REVIEWS_PASSWORD
INTERNAL_REVIEWS_TIMEOUT
INTERNAL_REVIEWS_CACHE_MINUTES
COMMISSIONS_API_USER
COMMISSIONS_API_PASSWORD

SALESFORCE_COMMISSIONS_PURCHASE_RENTABILITY_FIELD
SALESFORCE_COMMISSIONS_SALE_MANAGEMENT_FIELD
STOCK_ALERT_EMAIL
```

## 13. Pendientes y cautelas

- `config/services.php` conserva valores fallback del servicio interno de
  reseñas, incluida una credencial. Deben eliminarse del código, rotarse y
  configurarse exclusivamente mediante `INTERNAL_REVIEWS_*`. No copiar esos
  valores a documentación, incidencias ni commits nuevos.
- Llamadas no tiene aún exportación dedicada de auditoría KPI.
- El bloque 2 de Financieros depende de `Inter_s_elegido__c`. Si Salesforce lo
  devuelve vacío, queda a cero por diseño; no fijar importes manuales.
- Para diferencias de Delegaciones, comparar IDs del CSV auditable antes de
  cambiar reglas. Tienda de entrega y delegación del propietario son ejes
  distintos.
- Stock necesita varios días de snapshots para que el ratio ventas/stock deje de
  ser aproximado.
- `Logistica__c` ya se sincroniza, pero aún no participa en el score de
  recomendaciones ni en los KPIs principales.
- Los importes del snapshot económico son `firstOrCreate` y no se reescriben. Sí
  cambian los metadatos de validez (`current_stage_name`, `is_valid`, fechas y
  motivo) tras cada conciliación; el sincronizador también puede completar
  suplementos de catálogo que estaban nulos.
- La rama `LastModifiedDate` de la query Stock de Opportunity no tiene límite
  superior. Es intencionada para recoger cambios posteriores, pero puede traer
  más filas que el rango de firma y debe vigilarse si crece el volumen.
- Después de modificar normalizadores o fórmulas, limpiar cache y ejecutar los
  tests focalizados del informe afectado.
