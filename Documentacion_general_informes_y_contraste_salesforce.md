# Documentacion general de informes y contraste con Salesforce

Version: 2026-06-24  
Proyecto: `informes-app-hrmotor`

## 1. Objetivo

Este documento resume la logica funcional vigente de:

- Leads
- Reservas / Ventas
- Llamadas
- Campanas
- Comisiones Comerciales

Tambien deja un metodo simple para cotejar cifras del dashboard con Salesforce sin mezclar universos distintos.

## 2. Reglas globales de contraste

Antes de comparar cualquier KPI, fijar siempre estas cuatro cosas:

1. Que fecha pivota el informe.
2. Que objeto pivota el informe.
3. Que filtros funcionales reales usa el dashboard.
4. Si el dato es global del negocio o solo atribuible a campanas/leads concretos.

Si una de esas cuatro cosas cambia, la cifra ya no es comparable.

## 3. Reglas de fecha por informe

### Leads

- pivota por `Lead.CreatedDate`

### Reservas / Ventas

- pivota por el `date_criterion` seleccionado:
  - `created_date`
  - `reservation_date`
  - `cv_signed_date`

### Llamadas

- pivota por `created_date` del dato sincronizado

### Campanas

- pivota por fecha de creacion del lead
- el universo base son los leads del periodo

### Comisiones Comerciales

- pivota por `cv_signed_date`
- solo usa operaciones firmadas del mes cerrado seleccionado

## 4. Metodo rapido de contraste

Cuando una cifra no cuadre:

1. Anotar informe, periodo, filtros y KPI exacto.
2. Confirmar primero el conteo total con la query base correcta.
3. Si no cuadra, bajar a IDs concretos.
4. Comparar siempre con el mismo pivote temporal que usa el dashboard.

Regla practica:

- para `Campanas`, primero validar leads
- luego cuentas convertidas
- luego oportunidades
- y al final ventas o compras

## 5. Leads

### Fuentes

- `salesforce_leads`
- `salesforce_lead_activity_summaries`
- `salesforce_users`

### Servicio

- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`

### KPIs principales

- `Leads totales`
- `Convertidos`
- `Descartados`
- `Potenciales`
- `Potenciales sin trabajar`
- `Leads sin asignar`
- `Gestionados`
- `Llamadas`
- `Formularios`

### Query base de contraste

```sql
SELECT Id, Name, CreatedDate, Status, RecordType.Name, OwnerId, Owner.Name
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate ASC
```

### Auditoria KPI

- JSON: `/informes/leads/data/kpi-audit`
- CSV: `/informes/leads/export/kpi-audit.csv`

## 6. Reservas / Ventas

### Fuente

- `salesforce_opportunities`

### Servicios

- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`
- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`

### Reglas principales

- `Venta` incluye `Venta` y `Cambio`
- `Tasacion` usa `RecordType.Name = 'Tasacion'` o `Tasacion`
- `Reserva viva` = reserva true, CV firmado false y no cerrada perdida
- `Caida` = etapa `Cerrada Perdida`
- `CV firmado` = firmado y no cerrada perdida

### Query base para CV firmados de Venta

```sql
SELECT
    Id,
    Name,
    CreatedDate,
    StageName,
    RecordType.Name,
    AccountId,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c
FROM Opportunity
WHERE RecordType.Name IN ('Venta', 'Cambio')
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01T00:00:00Z
  AND Fecha_firma_contrato__c < 2026-07-01T00:00:00Z
  AND Fecha_firma_contrato__c != null
  AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

### Query base para CV firmados de Tasacion

```sql
SELECT
    Id,
    Name,
    CreatedDate,
    StageName,
    RecordType.Name,
    AccountId,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c
FROM Opportunity
WHERE RecordType.Name = 'Tasacion'
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01T00:00:00Z
  AND Fecha_firma_contrato__c < 2026-07-01T00:00:00Z
  AND Fecha_firma_contrato__c != null
  AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

### Auditoria KPI

- JSON: `/informes/reservas-ventas/data/kpi-audit`
- CSV: `/informes/reservas-ventas/export/kpi-audit.csv`

## 7. Llamadas

### Fuente

- `salesforce_calls`

### Servicio

- `app/Services/Reports/Calls/CallDashboardDatasetService.php`

### KPIs principales

- `Total llamadas`
- `Atendidas`
- `No atendidas`
- `Desbordes`
- `Entrantes`
- `Salientes`
- `Tiempo medio conversacion`

### Consulta local de contraste

```sql
SELECT
    created_date,
    direction,
    call_status,
    call_origin,
    portal_resolved,
    delegation,
    zone,
    operational_user_name
FROM salesforce_calls
WHERE created_date >= '2026-06-01 00:00:00'
  AND created_date < '2026-07-01 00:00:00'
ORDER BY created_date ASC;
```

## 8. Campanas

### Fuentes

- `campaign_platform_daily_metrics`
- `campaign_lead_attributions`
- `salesforce_opportunities`

### Servicio

- `app/Services/Campaigns/CampaignDashboardDatasetService.php`

### Reglas estructurales

- pivota por fecha de lead
- el dashboard actual muestra:
  - `Todos`
  - `Venta`
  - `Tasacion`
- dentro de `Venta` existen subcategorias:
  - `Venta`
  - `Exposicion`
  - `Branding`
  - `Otros`

### Regla clave de contraste

`Campanas` no mide todas las oportunidades del negocio. Solo mide oportunidades atribuibles al universo de leads del periodo y con procedencia valida.

### Query base de compras atribuibles a campanas de tasacion

```sql
SELECT
    Id,
    Name,
    CreatedDate,
    CloseDate,
    StageName,
    RecordType.Name,
    AccountId,
    Account.Phone,
    Account.PersonEmail,
    Account.ACC_EMA_Email__c,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c
FROM Opportunity
WHERE
    AccountId IN (
        SELECT ConvertedAccountId
        FROM Lead
        WHERE CreatedDate >= 2026-06-01T00:00:00Z
          AND CreatedDate < 2026-07-01T00:00:00Z
          AND ConvertedAccountId != null
          AND (
              Campa_a_Adquirida__c = 'TASADOR_LANDING_SEARCH_1'
              OR Campa_a_Adquirida__c = 'TASADOR LANDING SEARCH 1'
              OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasacion'
              OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasacion_Nuevas Ubicaciones'
          )
    )
    AND RecordType.Name = 'Tasacion'
    AND OPO_CAS_Contrato_CV_firmado__c = true
    AND Fecha_firma_contrato__c != null
    AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

### Query base de ventas atribuibles a campanas de venta

```sql
SELECT
    Id,
    Name,
    CreatedDate,
    CloseDate,
    StageName,
    RecordType.Name,
    AccountId,
    Account.Phone,
    Account.PersonEmail,
    Account.ACC_EMA_Email__c,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c
FROM Opportunity
WHERE
    AccountId IN (
        SELECT ConvertedAccountId
        FROM Lead
        WHERE CreatedDate >= 2026-06-01T00:00:00Z
          AND CreatedDate < 2026-07-01T00:00:00Z
          AND ConvertedAccountId != null
          AND (
              Campa_a_Adquirida__c LIKE 'Expiey_Catalogo_Campaign'
              OR Campa_a_Adquirida__c = 'geolocalizacion'
              OR Campa_a_Adquirida__c = 'Expiey_Catalogo_Campaign2-11.25'
              OR Campa_a_Adquirida__c = 'ventas'
          )
    )
    AND RecordType.Name IN ('Venta', 'Cambio')
    AND OPO_CAS_Contrato_CV_firmado__c = true
    AND Fecha_firma_contrato__c != null
    AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

### Auditoria KPI

- JSON: `/informes/campanas/data/kpi-audit`
- CSV: `/informes/campanas/export/kpi-audit.csv`

## 9. Comisiones Comerciales

### Fuentes

- `salesforce_opportunities`
- `salesforce_reviews`

### Servicio

- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`

### Regla base

El informe usa solo oportunidades con:

- `cv_signed = true`
- `owner_is_active = true`
- `StageName != 'Cerrada perdida'`
- `RecordType.Name IN ('Venta', 'Cambio', 'Tasacion')`
- `cv_signed_date` dentro del mes seleccionado

Ademas aplica el filtro de gestion de venta:

- campo Salesforce: `Gestion_de_venta__c`
- columna local: `gestion_de_venta`
- las operaciones con `gestion_de_venta = true` quedan fuera del calculo

### Logica de calculo resumida

- `Venta` y `Cambio`:
  - cuentan como entrega
  - 60 EUR por entrega
- `Compra`:
  - se toma desde una oportunidad de `Tasacion` o `Cambio`
  - debe compartir matricula con una venta posterior
  - comision = `rentabilidad_compra * 0.018`
  - hoy el campo activo es `informe_rentabilidad`
- `Compartida`:
  - 30 EUR para el copropietario
- `Descuento`:
  - penalizacion del 5% sobre `OPO_DIV_Descuento__c`
- `Stock +150`:
  - 10 EUR si el vehiculo vendido llevaba `>= 150` dias en stock
- `Bonus +15`:
  - 30 EUR por cada entrega a partir de la numero 16
- `Tramo de entregas`:
  - `0-6 => 0%`
  - `7-11 => 80%`
  - `12+ => 100%`
- `Penalizacion por garantias`:
  - si `Garant_a_Total__c < 3500`, resta 10% de la prima ajustada
- `Penalizacion por resenas`:
  - si `% resenas < 30`, resta 50%
  - si `% resenas >= 30` y `% resenas < 50`, resta 10%
  - si `% resenas >= 50`, no penaliza
- `Penalizacion por financiacion`:
  - base = `Venta + Cambio + Tasacion`
  - si `% financiacion < 40%`, resta 10%
- `Producto financiacion`:
  - tramo porcentual sobre `Beneficio_financiacion_comercial__c`
- `Producto garantias`:
  - tramo porcentual sobre `Garant_a_Total__c`

### Como auditarlo hoy

El informe no tiene aun endpoint exportable de auditoria KPI como Leads, Reservas/Ventas o Campanas.

La auditoria actual se hace dentro de la propia vista:

- tab `Detalle auditable`
- buscador de comercial
- tablas de:
  - entregas
  - compras liquidadas
  - compartidas
  - stock +150
  - resenas del mes

Para cargar historico completo de resenas en local:

```bash
php artisan salesforce:sync-commercial-reviews --all --fresh
```

## 10. Recomendacion operativa

Para dejar evidencia de una cifra:

1. Capturar filtros del informe.
2. Ejecutar la query base correcta.
3. Guardar conteo total.
4. Guardar IDs devueltos.
5. Si es un dato de campanas, documentar por separado:
   - universo de leads
   - cuentas convertidas
   - oportunidades
   - KPI final
