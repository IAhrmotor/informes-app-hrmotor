# Documentación general de informes y contraste con Salesforce

Versión: 2026-06-23  
Proyecto: `informes-app-hrmotor`

## 1. Objetivo del documento

Este documento resume la lógica funcional vigente de los informes:

- Leads
- Reservas / Ventas
- Llamadas
- Campañas

La pestaña `Comisiones Comerciales` todavía no se documenta de forma funcional completa porque sigue en fase de desarrollo.

Además, este documento deja un método sencillo para cotejar datos del informe con Salesforce y poder demostrar por qué una cifra del dashboard es correcta o por qué no debe compararse con una query distinta.

## 2. Reglas globales de contraste

Antes de comparar una cifra del informe con Salesforce, hay que fijar estas cuatro cosas:

1. Qué fecha pivota el informe.
2. Qué objeto pivota el informe.
3. Qué filtros funcionales usa realmente el dashboard.
4. Si la comparación es contra datos globales del informe o contra datos de campañas atribuidas.

Si una de estas cuatro cosas cambia, la cifra ya no es comparable.

## 3. Reglas generales de fecha

### 3.1. Campañas

- Pivota por fecha de creación del lead.
- Internamente trabaja en horario `Europe/Madrid`.
- En sync y build, `--to` es exclusivo.
- En web, el usuario trabaja con `start_date` y `end_date` visibles en local.

Consecuencia:

- Si el informe de campañas se está leyendo para junio 2026, el universo base son los leads creados en junio 2026.
- Las ventas, compras u oportunidades de campañas se consideran solo si pertenecen a esos leads atribuibles.

### 3.2. Reservas / Ventas

- Pivota por el `date_criterion` seleccionado.
- Puede comparar por:
  - `created_date`
  - `reservation_date`
  - `cv_signed_date`

Consecuencia:

- Una misma oportunidad puede entrar o salir del informe según el criterio de fecha.

### 3.3. Leads

- Pivota por `Lead.CreatedDate`.

### 3.4. Llamadas

- Pivota por `created_date` del dato sincronizado en `salesforce_calls`.

## 4. Método rápido de contraste

Cuando una cifra no cuadra, usar siempre este orden:

1. Confirmar el número en el propio informe y anotar:
   - pestaña
   - periodo
   - filtros
   - KPI exacto
2. Ejecutar la query base de Salesforce o la consulta local equivalente.
3. Comparar primero el conteo total.
4. Si no cuadra, comparar IDs concretos:
   - `Lead.Id`
   - `Opportunity.Id`
   - `AccountId`
5. Si sigue sin cuadrar, bajar un nivel:
   - leads
   - cuentas convertidas
   - oportunidades
   - ventas o compras

Regla práctica:

- si quieres validar `Campañas`, primero valida el universo de `Leads`
- después valida las `Opportunity`
- y solo al final la métrica final de `Ventas` o `Compras`

## 5. Informe de Leads

## 5.1. Fuente y lógica

Fuente local:

- `salesforce_leads`
- `salesforce_lead_activity_summaries`
- `salesforce_users`

Servicio:

- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`

El informe cuenta leads creados en el periodo y los clasifica por:

- estado
- portal
- delegación del lead
- delegación comercial
- zona
- comercial
- exposición incluida o excluida

KPIs principales:

- `Leads totales`
- `Convertidos`
- `Descartados`
- `Potenciales`
- `Potenciales sin trabajar`
- `Leads sin asignar`
- `Gestionados`
- `Llamadas`
- `Formularios`

Lógicas especiales:

- `Leads sin asignar` no es un campo nativo de Salesforce.
- Se calcula cuando el lead está en `Potencial` y el owner es técnico.
- `Potenciales sin trabajar` usa el resumen de actividad y ventana reciente.
- `Venta` como tipo de lead incluye `Venta` y `Venta con cambio`.

## 5.2. Qué sí cuenta

- Leads con `CreatedDate` dentro del periodo.
- Leads clasificados aunque parte de la delegación quede `Sin clasificar`.

## 5.3. Qué no cuenta

- Leads fuera del periodo.
- Leads de exposición si el filtro `exposition_mode` está en excluir.

## 5.4. Queries base de contraste en Salesforce

### Leads totales de un mes

```sql
SELECT Id, Name, CreatedDate, Status, RecordType.Name, OwnerId, Owner.Name
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate ASC
```

### Leads convertidos de un mes

```sql
SELECT Id, Name, CreatedDate, Status, ConvertedAccountId, ConvertedOpportunityId
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND Status = 'Convertido'
ORDER BY CreatedDate ASC
```

### Leads descartados de un mes

```sql
SELECT Id, Name, CreatedDate, Status
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND Status = 'Descartado'
ORDER BY CreatedDate ASC
```

### Leads potenciales de un mes

```sql
SELECT Id, Name, CreatedDate, Status, OwnerId, Owner.Name
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND Status = 'Potencial'
ORDER BY CreatedDate ASC
```

Nota:

- `Leads sin asignar` y `Potenciales sin trabajar` no se pueden contrastar con una sola query SOQL simple porque dependen de reglas internas adicionales y del resumen de actividad sincronizado.

## 5.5. Método de revisión recomendado

1. Contrastar `Leads totales`.
2. Contrastar `Convertidos`, `Descartados` y `Potenciales`.
3. Si falla `Leads sin asignar`, revisar owners técnicos.
4. Si falla `Potenciales sin trabajar`, revisar actividad sincronizada.

## 6. Informe de Reservas / Ventas

## 6.1. Fuente y lógica

Fuente local:

- `salesforce_opportunities`

Servicio:

- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`

El informe pivota por `Opportunity` y usa tres criterios de fecha posibles:

- `created_date`
- `reservation_date`
- `cv_signed_date`

Filtro `Tipo de oportunidad`:

- `Tasación` => solo `RecordType.Name = 'Tasacion'`
- `Venta` => `RecordType.Name IN ('Venta', 'Cambio')`

KPIs principales:

- `Oportunidades totales`
- `Reservas vivas`
- `Oportunidades caídas`
- `CV firmados`

Lógicas especiales:

- `Reserva viva` = `reservation = true`, `cv_signed = false` y no `Cerrada perdida`
- `Caída` = etapa `Cerrada Perdida`
- `CV firmado` = `cv_signed = true` y no `Cerrada Perdida`

## 6.2. Qué sí cuenta

- Oportunidades cuya fecha del criterio activo cae dentro del periodo.

## 6.3. Qué no cuenta

- Oportunidades fuera del criterio temporal seleccionado.
- Ventas sin `Cambio` cuando el filtro es `Venta`, porque `Cambio` sí forma parte del grupo.

## 6.4. Query base para CV firmados de Tasación

Ejemplo: junio 2026.

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
WHERE RecordType.Name = 'Tasación'
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01T00:00:00Z
  AND Fecha_firma_contrato__c < 2026-07-01T00:00:00Z
  AND Fecha_firma_contrato__c != null
  AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

## 6.5. Query base para CV firmados de Venta

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

## 6.6. Cómo usar Reservas / Ventas para contrastar Campañas

Este es el punto importante:

- `Reservas / Ventas` mide oportunidades directamente.
- `Campañas` mide solo oportunidades atribuibles a leads del periodo y con procedencia válida.

Por eso, el contraste correcto se hace en dos pasos:

1. confirmar el total global en `Reservas / Ventas`
2. confirmar el subconjunto atribuible a campañas

## 6.7. Query de Tasación atribuible a campañas

Ejemplo de lógica: compras de tasación cuyas cuentas vienen de leads creados en junio 2026 y pertenecen a campañas de tasación.

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
              OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasación'
              OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones'
          )
    )
    AND RecordType.Name = 'Tasación'
    AND OPO_CAS_Contrato_CV_firmado__c = true
    AND Fecha_firma_contrato__c != null
    AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

Esta query no sirve para contrastar el total global de `Reservas / Ventas`.

Sí sirve para contrastar el subconjunto de `Campañas > Tasación`.

## 6.8. Query de Venta atribuible a campañas

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
              Campa_a_Adquirida__c LIKE 'Expiey_Catálogo_Campaign'
              OR Campa_a_Adquirida__c = 'geolocalizacion'
              OR Campa_a_Adquirida__c = 'Expiey_Catálogo_Campaign2-11.25'
              OR Campa_a_Adquirida__c = 'ventas'
          )
    )
    AND RecordType.Name IN ('Venta', 'Cambio')
    AND OPO_CAS_Contrato_CV_firmado__c = true
    AND Fecha_firma_contrato__c != null
    AND StageName != 'Cerrada perdida'
ORDER BY Fecha_firma_contrato__c ASC
```

## 6.9. Método de revisión recomendado

1. Si revisas `Reservas / Ventas`, usa query global de oportunidades.
2. Si revisas `Campañas`, usa query cruzada por leads convertidos del periodo.
3. No mezclar ambos conteos porque miden universos distintos.

## 7. Informe de Llamadas

## 7.1. Fuente y lógica

Fuente local:

- `salesforce_calls`

Servicio:

- `app/Services/Reports/Calls/CallDashboardDatasetService.php`

Filtros funcionales:

- periodo
- dirección
- estado
- portal
- equipo
- origen
- delegación
- zona
- usuario

KPIs principales:

- `Total llamadas`
- `Atendidas`
- `No atendidas`
- `Desbordes`
- `Entrantes`
- `Salientes`
- `Tiempo medio conversación`

Lógicas especiales:

- el informe distingue `commercial_direct` frente a `portal`
- `switchboard` se normaliza a `commercial_direct`
- las agrupaciones por equipo, usuario, delegación y zona salen del dato sincronizado y reglas de clasificación

## 7.2. Contraste recomendado

Aquí no se debe inventar una SOQL sin confirmar el objeto API real de origen.

Mientras no se documente ese objeto de Salesforce, el contraste fiable es:

- revisar la tabla local sincronizada `salesforce_calls`
- y contrastar contra el sistema de origen de llamadas usado por el equipo

## 7.3. Consulta local de contraste

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

## 7.4. Método de revisión recomendado

1. Validar volumen total por periodo.
2. Validar atendidas y no atendidas.
3. Validar desbordes.
4. Validar distribución por portal y por equipo.

## 8. Informe de Campañas

## 8.1. Fuente y lógica

Fuentes:

- `campaign_platform_daily_metrics`
- `campaign_lead_attributions`
- `salesforce_opportunities`

Servicio:

- `app/Services/Campaigns/CampaignDashboardDatasetService.php`

Regla estructural principal:

- el informe pivota por fecha de lead

Eso significa:

- primero entra el lead del periodo
- luego se busca su atribución
- después se cruzan las oportunidades asociadas
- y al final se calculan ventas, compras, oportunidades o leads según el contexto

## 8.2. Contextos actuales de UI

La UI principal ahora trabaja con:

- `Todos`
- `Venta`
- `Tasación`

Dentro de `Venta`, como subcategorías:

- `Venta`
- `Exposición`
- `Branding`
- `Otros`

Nota:

- el backend sigue entendiendo esos contextos de forma individual
- el frontend solo los ha reordenado para simplificar lectura

## 8.3. KPIs y significado por contexto

### Todos

- mezcla ventas y compras
- `result_count` = `sales + purchases`

### Venta

- resultado principal = `Ventas`

### Tasación

- resultado principal = `Compras`

### Exposición

- resultado principal = `Oportunidades`

### Branding

- resultado principal = `Leads`

### Otros

- resultado principal = `Resultados` agregados según la lógica del dashboard

## 8.4. Qué sí cuenta

- campañas con inversión del periodo seleccionado
- campañas Salesforce sin inversión si existen atribuciones válidas
- oportunidades atribuibles a leads del periodo

## 8.5. Qué no cuenta

- oportunidades no atribuibles al universo de leads del periodo
- ventas de campañas de tasación
- compras de campañas de venta

## 8.6. Query base de leads de campañas

Ejemplo: leads de junio 2026 para campañas de tasación.

```sql
SELECT
    Id,
    Name,
    CreatedDate,
    Status,
    ConvertedAccountId,
    ConvertedOpportunityId,
    Campa_a_Adquirida__c,
    Fuente_Nuevo__c,
    Portal__c
FROM Lead
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND (
      Campa_a_Adquirida__c = 'TASADOR_LANDING_SEARCH_1'
      OR Campa_a_Adquirida__c = 'TASADOR LANDING SEARCH 1'
      OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasación'
      OR Campa_a_Adquirida__c = 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones'
  )
ORDER BY CreatedDate ASC
```

## 8.7. Query base de compras de campañas de tasación

Usar la query del punto `6.7`.

Esa es la query adecuada para contrastar:

- `Campañas > Tasación > Compras`

## 8.8. Query base de ventas de campañas de venta

Usar la query del punto `6.8`.

Esa es la query adecuada para contrastar:

- `Campañas > Venta > Ventas`

## 8.9. Método de revisión cuando no cuadra una cifra

### Caso A. No cuadra `Leads Salesforce`

1. Ejecutar la query de leads del periodo y campaña.
2. Comparar número de leads.
3. Si no cuadra, revisar nombres reales de campaña y variantes.

### Caso B. No cuadra `Oportunidades`

1. Tomar los `ConvertedAccountId` del universo de leads.
2. Consultar oportunidades de esas cuentas.
3. Comparar IDs de oportunidad.

### Caso C. No cuadra `Compras`

1. Partir de las oportunidades atribuibles.
2. Filtrar solo `Tasación`.
3. Exigir:
   - `OPO_CAS_Contrato_CV_firmado__c = true`
   - `Fecha_firma_contrato__c != null`
   - `StageName != 'Cerrada perdida'`

### Caso D. No cuadra `Ventas`

1. Partir de las oportunidades atribuibles.
2. Filtrar `Venta` y `Cambio`.
3. Exigir:
   - `OPO_CAS_Contrato_CV_firmado__c = true`
   - `Fecha_firma_contrato__c != null`
   - `StageName != 'Cerrada perdida'`

## 8.10. Ejemplo práctico: “el informe dice 29 CV firmados de tasación en junio”

Si ese `29` se quiere usar para contrastar campañas:

1. Primero validar que realmente existen `29` oportunidades de tasación firmadas con la query global de `Reservas / Ventas`.
2. Después filtrar solo el subconjunto atribuible a leads de campañas del mes.
3. Ese subconjunto es el que debe cuadrar con `Campañas > Tasación`.

Conclusión:

- `29` puede ser correcto en `Reservas / Ventas`
- y un número menor puede ser correcto en `Campañas`
- porque `Campañas` no representa todo el negocio, sino solo lo atribuible al universo de leads del periodo y procedencias válidas

## 8.11. Endpoint y export de auditoría KPI

Se ha dejado un mecanismo nativo para auditar KPIs de `Campañas` sin tener que reconstruir manualmente la lógica en Salesforce.

### Endpoint JSON

```text
/informes/campanas/data/kpi-audit
```

### Export CSV

```text
/informes/campanas/export/kpi-audit.csv
```

### Parámetros

Usa los mismos filtros del dashboard de campañas y añade:

- `metric`

Valores soportados:

- `leads_salesforce`
- `opportunities`
- `reservations`
- `live_reservations`
- `fallen_reservations`
- `sales`
- `appraisals_generated`
- `purchases`
- `result_count`

### Ejemplo para compras de tasación en junio

```text
/informes/campanas/export/kpi-audit.csv?start_date=2026-06-01&end_date=2026-06-30&context=tasacion&campaign_status=active&metric=purchases
```

Qué devuelve:

- una fila por entidad deduplicada del KPI
- en `Compras`, una fila por oportunidad de compra
- con:
  - lead o leads asociados
  - oportunidad
  - campañas relacionadas
  - fuente y medio
  - portal
  - comercial asociado
  - owner de oportunidad
  - importes si existen

Importante:

- este export usa el mismo universo deduplicado que el KPI del dashboard
- por eso, si el KPI dice `7 compras`, el export debe devolver `7 filas`
- si una misma oportunidad llega por varios leads o campañas, seguirá siendo una sola fila de auditoría, pero conservará el detalle agregado de leads y campañas implicadas

## 9. Comisiones Comerciales

Estado actual:

- la pestaña existe
- solo la ve `admin`
- ya tiene base técnica, migraciones y diagnóstico
- todavía no debe incorporarse a la documentación funcional cerrada

Se añadirá a este documento cuando queden cerradas:

- reglas exactas de cálculo
- campo final de rentabilidad operativa
- filtros de gestión de venta

## 10. Recomendación operativa final

Para revisar cualquier cifra y dejar evidencia:

1. Hacer captura del filtro del informe.
2. Copiar la query base correspondiente de este documento.
3. Guardar:
   - conteo total
   - IDs devueltos
   - uno o dos ejemplos representativos
4. Si el dato es de campañas, documentar siempre:
   - universo de leads
   - universo de cuentas convertidas
   - universo de oportunidades
   - KPI final

Ese orden evita discutir cifras comparadas con universos distintos.

## 11. AuditorÃ­a KPI nativa en el front

Desde 2026-06-23 los informes de `Leads`, `Reservas / Ventas` y `CampaÃ±as` disponen de auditorÃ­a KPI conectada al frontend.

Comportamiento:

- si el usuario tiene permiso de exportaciÃ³n, cada KPI auditable muestra botÃ³n `Auditar KPI`
- el botÃ³n reutiliza exactamente los filtros activos en pantalla
- el resultado se descarga como CSV para cotejo con Salesforce o revisiÃ³n interna

### 11.1. Leads

Endpoint JSON:

```text
/informes/leads/data/kpi-audit
```

Export CSV:

```text
/informes/leads/export/kpi-audit.csv
```

MÃ©tricas soportadas:

- `leads_totales`
- `convertidos`
- `descartados`
- `potenciales`
- `potenciales_sin_trabajar`
- `leads_unassigned`
- `gestionados`
- `llamadas`
- `formularios`

El export devuelve, por lead:

- `Lead ID`
- nombre
- fecha de alta
- estado y tipo
- portal y canal
- delegaciones y zonas
- owner
- comercial resuelto
- campaign acquired
- cuenta convertida
- oportunidad convertida

Ejemplo:

```text
/informes/leads/export/kpi-audit.csv?period=current_month&lead_type=Venta&metric=convertidos
```

### 11.2. Reservas / Ventas

Endpoint JSON:

```text
/informes/reservas-ventas/data/kpi-audit
```

Export CSV:

```text
/informes/reservas-ventas/export/kpi-audit.csv
```

MÃ©tricas soportadas:

- `oportunidades_totales`
- `reservas_vivas`
- `reservas_vivas_actuales_salesforce`
- `oportunidades_caidas`
- `cv_firmados`

El export devuelve, por oportunidad:

- `Opportunity ID`
- nombre
- fechas clave
- record type
- etapa
- owner
- delegaciÃ³n y zona
- cuenta y contacto
- portal original y resuelto
- fuente raw y normalizada

Ejemplo:

```text
/informes/reservas-ventas/export/kpi-audit.csv?period=custom&date_criterion=cv_signed_date&current_start=2026-06-01&current_end=2026-06-30&opportunity_type=Tasaci%C3%B3n&metric=cv_firmados
```

### 11.3. CampaÃ±as

Ya estaba disponible la auditorÃ­a KPI en backend y ahora tambiÃ©n queda conectada al frontend mediante botÃ³n por KPI auditable.

Importante:

- no todos los KPIs de `CampaÃ±as` son auditables contra Salesforce
- el botÃ³n solo aparece en los KPIs cuyo detalle depende de leads u oportunidades Salesforce
- los KPIs puros de plataforma como `InversiÃ³n`, `Impresiones` o `Clicks` no usan este export
