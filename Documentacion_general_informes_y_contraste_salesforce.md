# Documentación general de informes y contraste con Salesforce

Versión: 2026-07-30
Proyecto: `informes-app-hrmotor`

## 1. Propósito y criterio de verdad

Este documento describe el funcionamiento vigente de los informes:

- Leads
- Reservas / Ventas
- Llamadas
- Campañas
- Comisiones comerciales
- Stock

La fuente de verdad de esta versión es el código actual del proyecto, incluidas las
migraciones y pruebas presentes en el árbol de trabajo. Los documentos históricos se
han usado como contexto, pero no prevalecen sobre la implementación.

Para cualquier contraste hay que fijar antes:

1. zona horaria y límites del período;
2. objeto y fecha pivote;
3. filtros funcionales;
4. nivel de deduplicación;
5. si el KPI es del período o una foto global;
6. si la cifra procede directamente de Salesforce o de una transformación local.

Las consultas de este documento son plantillas SOQL. Sustituir los valores
`2026-06-01` y `2026-07-01` por el intervalo que se quiera auditar. Los límites
superiores son exclusivos.

## 2. Arquitectura común

### 2.1 Fuentes externas

- Salesforce REST API:
  - `Lead`
  - `Task`
  - `Event`
  - `User`
  - `Opportunity`
  - `Product2`
  - `Resena__c`
  - `Tasacion__c`
  - `Logistica__c`
- Meta Marketing API.
- Google Ads API.
- endpoint interno de reseñas de delegaciones.
- CSV/XLSX de capacidades de stock.
- XLSX de penalizaciones por cancelación de financiación.

### 2.2 Persistencia local

La aplicación no consulta Salesforce al renderizar cada pantalla. Primero sincroniza
objetos a tablas locales y los dashboards agregan esas tablas. Esto permite cache,
normalización, auditoría y conservación histórica.

Principales tablas por dominio:

| Dominio | Tablas locales principales |
|---|---|
| Leads | `salesforce_leads`, `salesforce_activities`, `salesforce_lead_activity_summaries`, `salesforce_users` |
| Reservas / Ventas | `salesforce_opportunities`, `leads_raw` como fallback de portal |
| Llamadas | `salesforce_calls`, `salesforce_users`, `call_agent_mappings` |
| Campañas | `campaign_platform_daily_metrics`, `campaign_lead_attributions`, `campaign_salesforce_leads`, `salesforce_opportunities` |
| Comisiones | `salesforce_opportunities`, `salesforce_users`, `salesforce_reviews`, `salesforce_tasaciones`, `commercial_commission_month_settings`, tablas de penalizaciones |
| Stock | `salesforce_vehicles`, `stock_delegations`, `stock_daily_snapshots`, `salesforce_sale_snapshots`, `salesforce_logistics`, `stock_availability_alerts` |

### 2.3 Fechas pivote

| Informe / bloque | Fecha pivote |
|---|---|
| Leads | `Lead.CreatedDate` |
| Reservas / Ventas | seleccionable: creación, reserva o firma |
| Llamadas | `Task.CreatedDate` |
| Campañas | creación del lead para resultados; fecha publicitaria para inversión |
| Comisiones comerciales | `Opportunity.Fecha_firma_contrato__c` y mes cerrado seleccionado |
| Stock actual | foto vigente de `Product2` |
| Ventas de Stock | `Opportunity.Fecha_firma_contrato__c` congelada en snapshot |
| Histórico de Stock | `stock_daily_snapshots.snapshot_date` |

## 3. Leads

### 3.1 Objetos y campos consultados

`Lead`:

- identidad y fechas: `Id`, `Name`, `CreatedDate`, `LastActivityDate`,
  `Fecha_Asignacion__c`;
- estado y tipo: `Status`, `RecordType.Name`;
- responsables: `OwnerId`, `Owner.Name`, `Persona_que_trabaj__c`,
  `Persona_que_trabaj__r.Name`, `Propietario_cuando_se_descarto__c`,
  `Propietario_cuando_se_descarto__r.Name`;
- procedencia: `LEA_SEL_Fuente_Origen__c`, `LEA_SEL_Medio_Origen__c`,
  `Medio_Nuevo__c`, `Fuente_Nuevo__c`, `Portal_Text__c`,
  `Remitente_Lead__c`;
- delegación: `Delegacion_Encargada_Text__c`,
  `Delegacion_Encargada__c`, `Delegacion_Encargada_Bueno__c`;
- campaña y vehículo: `Campa_a_Adquirida__c`, `Id_Adquirido__c`,
  `Contenido_Adquirido__c`, `LEA_BUS_Vehiculo_de_interes__c`;
- contacto y conversión: `Phone`, `MobilePhone`, `Email`, `IsConverted`,
  `ConvertedDate`, `ConvertedAccountId`, `ConvertedContactId`,
  `ConvertedOpportunityId`;
- Contact Center: `Captador_de_cita__c`, `Captador_de_cita__r.Name`,
  `Fecha_captador__c`, `Cita_llamada__c`, `Cita_Tienda__c`,
  `Acudi_a_la_cita__c`, `Comercial_que_atiende_en_tienda__c`,
  `Comercial_que_atiende_en_tienda__r.Name`,
  `Estado_del_candidato_formula__c`.

`Task` y `Event` aportan la actividad del lead. `User` aporta perfil, delegación,
estado, email y marca de tasador.

### 3.2 Query base de Leads

```sql
SELECT
    Id, Name, CreatedDate, LastActivityDate, Status, RecordType.Name,
    OwnerId, Owner.Name,
    Persona_que_trabaj__c, Persona_que_trabaj__r.Name,
    Propietario_cuando_se_descarto__c,
    Propietario_cuando_se_descarto__r.Name,
    Fecha_Asignacion__c,
    LEA_SEL_Fuente_Origen__c, LEA_SEL_Medio_Origen__c,
    Medio_Nuevo__c, Fuente_Nuevo__c, Portal_Text__c,
    Remitente_Lead__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c,
    Campa_a_Adquirida__c, Id_Adquirido__c, Contenido_Adquirido__c,
    LEA_BUS_Vehiculo_de_interes__c,
    Phone, MobilePhone, Email,
    IsConverted, ConvertedDate, ConvertedAccountId,
    ConvertedContactId, ConvertedOpportunityId,
    Captador_de_cita__c, Captador_de_cita__r.Name,
    Fecha_captador__c, Cita_llamada__c, Cita_Tienda__c,
    Acudi_a_la_cita__c,
    Comercial_que_atiende_en_tienda__c,
    Comercial_que_atiende_en_tienda__r.Name,
    Estado_del_candidato_formula__c
FROM Lead
WHERE IsDeleted = false
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate, Id
```

El sincronizador ampliado usa esta condición para no perder citas del Contact
Center creadas sobre leads antiguos:

```sql
AND (
    (CreatedDate >= 2026-06-01T00:00:00Z
     AND CreatedDate < 2026-07-01T00:00:00Z)
    OR
    (Fecha_captador__c >= 2026-06-01
     AND Fecha_captador__c < 2026-07-01)
)
```

Esta ampliación solo afecta a la extracción. El dashboard de Leads sigue filtrando
por `salesforce_leads.created_date`, es decir, por `Lead.CreatedDate`.

### 3.3 Queries de actividad

```sql
SELECT
    Id, WhoId, OwnerId, Owner.Name, CreatedById, CreatedBy.Name,
    CreatedDate, ActivityDate, Subject, Type, Status
FROM Task
WHERE WhoId IN (
    SELECT Id FROM Lead
    WHERE IsDeleted = false
      AND CreatedDate >= 2026-06-01T00:00:00Z
      AND CreatedDate < 2026-07-01T00:00:00Z
)
AND CreatedDate >= 2026-06-01T00:00:00Z
AND CreatedDate < 2026-07-01T00:00:00Z
```

```sql
SELECT
    Id, WhoId, OwnerId, Owner.Name, CreatedById, CreatedBy.Name,
    CreatedDate, ActivityDate, Subject, Type
FROM Event
WHERE WhoId IN (
    SELECT Id FROM Lead
    WHERE IsDeleted = false
      AND CreatedDate >= 2026-06-01T00:00:00Z
      AND CreatedDate < 2026-07-01T00:00:00Z
)
AND CreatedDate >= 2026-06-01T00:00:00Z
AND CreatedDate < 2026-07-01T00:00:00Z
```

### 3.4 Lógicas y cálculos

- Canal:
  - `Medio_Nuevo__c = Llamada` normalizado → `Llamada`;
  - cualquier otro valor → `Formulario`.
- Portal:
  - llamada: `Fuente_Nuevo__c` → `Portal_Text__c` →
    `LEA_SEL_Fuente_Origen__c` → `Sin clasificar`;
  - formulario: `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` →
    `Fuente_Nuevo__c` → `Sin clasificar`.
- Delegación del lead:
  `Delegacion_Encargada_Text__c` → `Delegacion_Encargada__c` →
  `Delegacion_Encargada_Bueno__c`; después se aplica el catálogo de alias de
  `LeadDelegationNormalizer`.
- Comercial responsable:
  - convertido: persona que trabajó → owner;
  - descartado: propietario al descarte → persona que trabajó → owner;
  - resto: owner.
- Solo se consideran comerciales activos con perfiles `Compra/Venta` o
  `Comerciales Partner Community`.
- `Convertido`: `Status = Convertido`.
- `Descartado`: `Status = Descartado`.
- `Potencial`: `Status = Potencial`.
- `Lead sin asignar`: potencial cuyo owner es una identidad técnica configurada.
- `Potencial sin trabajar`: potencial, no sin asignar, y sin actividad o sin
  actividad en los tres días anteriores al fin del período.
- `Gestionado`: convertido, descartado o potencial con actividad en esos tres días.
- Los no clasificados sí se incluyen en los KPIs generales.
- Porcentajes generales: `métrica / leads_totales * 100`.
- En filtro `Venta` se incluyen tipos `Venta` y `Venta con cambio`.
- Exposición:
  - `Con`: todos;
  - `Sin`: excluye portal `Exposición`;
  - la propiedad `is_exposicion` se obtiene del portal resuelto.

### 3.5 Filtros, salidas y auditoría

Filtros: período, fechas personalizadas, portal, delegación del lead, tipo de lead,
delegación comercial, zona, comercial y modo de exposición.

Endpoints:

- `/informes/leads/data/resumen`
- `/informes/leads/data/kpis`
- `/informes/leads/data/portales`
- `/informes/leads/data/delegaciones`
- `/informes/leads/data/comerciales`
- `/informes/leads/data/comparativa`
- `/informes/leads/data/calidad-dato`
- `/informes/leads/data/kpi-audit`
- `/informes/leads/export/kpi-audit.csv`

Comando de sincronización:

```bash
php artisan salesforce:sync-monthly-commercial --days=120 --debug-soql
```

Código fuente principal:

- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyLeadsSyncService.php`
- `app/Services/Reports/MonthlyCommercial/Sync/SalesforceMonthlyActivitiesSyncService.php`
- `app/Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php`
- `app/Services/Reports/Leads/LeadDelegationNormalizer.php`

## 4. Reservas / Ventas

### 4.1 Objetos y fuentes

Objeto pivote: `Opportunity`. Se consultan también `Account`, `Owner`, `RecordType`
y relaciones a `Product2`. Para resolver procedencia se consulta `Lead` por email o
teléfono; si Salesforce no devuelve coincidencia, se usa `leads_raw`.

Campos funcionales principales:

- fechas: `CreatedDate`, `CloseDate`, `OPO_FEC_Fecha_de_reserva__c`,
  `Fecha_firma_contrato__c`;
- clasificación: `StageName`, `RecordType.Name`;
- flags: `OPO_CAS_Reserva__c`,
  `OPO_CAS_Contrato_CV_firmado__c`;
- responsable: `OwnerId`, `Owner.Name`, `Owner.IsActive`,
  `Owner.USR_SEL_Delegacion__c`;
- cliente: `AccountId`, `Account.Name`, `Account.Phone`,
  `Account.PersonEmail`, `Account.AC_C_EMA_email__c`;
- procedencia: `Portal__c`, `Fuente_de_Origen__c`;
- importes: `Amount`, `OPO_FOR_Importe_total__c`,
  `OPO_FOR_Importe_vehiculo_venta__c`,
  `OPO_FOR_Importe_vehiculo_a_cambio__c`;
- campos de comisiones y vehículo descritos en la sección 7.

### 4.2 Query base de sincronización y contraste

```sql
SELECT
    Id, Name, CreatedDate, CloseDate, Amount,
    OPO_FOR_Importe_total__c,
    StageName, RecordType.Name,
    OwnerId, Owner.Name, Owner.IsActive, Owner.USR_SEL_Delegacion__c,
    AccountId, Account.Name, Account.Phone, Account.PersonEmail,
    Portal__c, Fuente_de_Origen__c,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Tienda_de_entrega__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c
FROM Opportunity
WHERE IsDeleted = false
  AND (
      (CreatedDate >= 2026-06-01T00:00:00Z
       AND CreatedDate < 2026-07-01T00:00:00Z)
      OR
      (OPO_FEC_Fecha_de_reserva__c >= 2026-06-01
       AND OPO_FEC_Fecha_de_reserva__c < 2026-07-01)
      OR
      (Fecha_firma_contrato__c >= 2026-06-01
       AND Fecha_firma_contrato__c < 2026-07-01)
  )
ORDER BY CreatedDate, Id
```

Query para CV firmados:

```sql
SELECT
    Id, Name, StageName, RecordType.Name, OwnerId, Owner.Name,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c, Tienda_de_entrega__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01
  AND Fecha_firma_contrato__c < 2026-07-01
  AND StageName != 'Cerrada Perdida'
  AND RecordType.Name IN ('Venta', 'Cambio', 'Tasacion')
ORDER BY Fecha_firma_contrato__c, Id
```

Query de reservas vivas actuales, sin fecha:

```sql
SELECT
    Id, Name, StageName, RecordType.Name,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Reserva__c = true
  AND OPO_CAS_Contrato_CV_firmado__c = false
  AND StageName != 'Cerrada Perdida'
ORDER BY OPO_FEC_Fecha_de_reserva__c, Id
```

### 4.3 Lógicas y cálculos

- Criterio temporal seleccionable:
  - `created_date` → `Opportunity.CreatedDate`;
  - `reservation_date` → `OPO_FEC_Fecha_de_reserva__c`;
  - `cv_signed_date` → `Fecha_firma_contrato__c`.
- Tipo `Venta` del dashboard = `RecordType.Name IN (Venta, Cambio)`.
- Tipo `Tasación` acepta el valor local `Tasacion`.
- `Oportunidades totales`: todas las filas del tipo y período seleccionados.
- `Reserva viva del período`: reserva true, CV false y etapa distinta de
  `Cerrada Perdida`, dentro del criterio temporal seleccionado.
- `Reservas vivas actuales Salesforce`: la misma regla sin filtro temporal. Los
  filtros de tipo siguen aplicando; los filtros de comercial/delegación/zona se
  aplican después de decorar la fila.
- `Caída`: etapa `Cerrada Perdida`.
- `CV firmado`: flag true y etapa distinta de `Cerrada Perdida`.
- Porcentajes del resumen: KPI / oportunidades totales.
- En tablas por comercial, delegación o portal, los porcentajes son participación
  de columna: valor de la fila / total de ese KPI en todas las filas.
- Delegación y zona salen del owner y se normalizan con el mismo catálogo de Leads.
- Resolución de portal, por prioridad:
  1. `Opportunity.Portal__c` si es concluyente;
  2. lead relacionado por email/teléfono con portal válido;
  3. `Opportunity.Fuente_de_Origen__c` si es útil;
  4. fallback `Exposición`;
  5. fallback `Web`;
  6. `Sin clasificar`.

### 4.4 Auditoría

- `/informes/reservas-ventas/data/kpi-audit`
- `/informes/reservas-ventas/export/kpi-audit.csv`
- `php artisan reports:debug-reservas-ventas --unclassified-portals`
- `php artisan reports:reprocess-opportunity-portals`

Sincronización:

```bash
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01 --debug-soql
```

Código fuente:

- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`
- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`
- `app/Services/Reports/ReservasVentas/OpportunityPortalNormalizer.php`

## 5. Llamadas

### 5.1 Objetos y campos

Objeto pivote: `Task`:

- `Id`, `Subject`, `Description`, `Type`, `Status`, `Priority`;
- `ActivityDate`, `CreatedDate`, `LastModifiedDate`;
- `OwnerId`, `Owner.Name`, `Owner.Profile.Name`;
- `WhoId`, `WhatId`, `CallObject`;
- `CallDurationInSeconds`, `CallType`, `Portales__c`.

También se consulta `User` para perfil/delegación y `Lead` cuando `WhoId` empieza
por `00Q`, para recuperar portal.

### 5.2 Query base

```sql
SELECT
    Id, Subject, Description, Type, Status, Priority,
    ActivityDate, CreatedDate, LastModifiedDate,
    OwnerId, Owner.Name, Owner.Profile.Name,
    WhoId, WhatId, CallObject,
    CallDurationInSeconds, CallType, Portales__c
FROM Task
WHERE IsDeleted = false
  AND Type = 'Call'
  AND CallObject != null
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
ORDER BY CreatedDate DESC
```

Query auxiliar de leads:

```sql
SELECT
    Id, Name, CreatedDate, Portal_Text__c,
    LEA_SEL_Fuente_Origen__c, Fuente_Nuevo__c, Medio_Nuevo__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE Id IN ('00Q...')
```

### 5.3 Parsing, clasificación y KPIs

De `Task.Description` se extraen: resultado, tipo, teléfonos, comercial destino,
código/nombre de agente, cola, opción de teclado, duración, UID/PUID e inicio/fin.

- Atendida: `Resultado = ANSWERED`; también se infiere atendida si existe
  “Respondido por”.
- No atendida: cualquier otra clasificación.
- `ABANDONED` siempre se trata como perdida y nunca como desborde.
- Dirección:
  - contiene `inbound` o `entrante` → entrante;
  - contiene `outbound` o `saliente` → saliente;
  - resto → desconocida.
- Duración base: `CallDurationInSeconds`; fallback a duración parseada.
- Duración ajustada:
  - llamada directa comercial: `max(duración - 5, 0)`;
  - portal: `max(duración - 10, 0)`.
- Origen efectivo:
  - `Portales__c` nulo, “Llamada directa” o centralita → `commercial_direct`;
  - resto → `portal`.
- Desborde:
  - origen portal;
  - atendida;
  - equipo `Contact Center` o `Atención al Cliente`;
  - portal distinto de Web/Google Maps, o en esos portales opción de teclado
    nula, vacía, 1 o 2.
- Equipos: comercial, atención al cliente, contact center y tasadores. Hay alias
  forzados y se excluyen identidades de sistema/administrador.
- `Total llamadas`: `COUNT(*)`.
- `Atendidas` y `No atendidas`: reglas anteriores, excluyendo `ABANDONED` de
  atendidas.
- `Tiempo medio conversación`: promedio de `adjusted_duration_seconds` solo en
  atendidas.
- `Ratio atención`: atendidas / total.
- `Ratio desborde`: desbordes / denominador elegible de portal atendido.

Filtros: período, dirección, estado, origen, portal, equipo, delegación, zona y
usuario operativo.

No existe actualmente endpoint específico de auditoría KPI; se contrasta con la
tabla local y los comandos:

```bash
php artisan salesforce:sync-calls --days=120 --debug-soql
php artisan reports:debug-calls
php artisan reports:reprocess-calls-classification
```

Código fuente:

- `app/Services/Reports/Calls/SalesforceCallSyncService.php`
- `app/Services/Reports/Calls/CallDescriptionParser.php`
- `app/Services/Reports/Calls/CallClassificationRules.php`
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`

## 6. Campañas

### 6.1 Fuentes

- Salesforce `Lead` y `Opportunity`.
- `campaign_platform_daily_metrics`:
  - Google Ads: coste, impresiones, clics, conversiones y estado;
  - Meta Ads: gasto, impresiones, clics, leads y estado efectivo.
- `campaign_lead_attributions`: resultado local del cruce.
- `campaign_type_mappings`: overrides editables de clasificación.

La inversión se filtra por `metric_date`. Leads y resultados se filtran por
`Lead.CreatedDate`; reservas, ventas y compras pueden ocurrir después.
La opción legacy `--window` no tiene efecto.

### 6.2 Query de leads de campaña

```sql
SELECT
    Id, CreatedDate, Name, Status, OwnerId, Owner.Name,
    Phone, MobilePhone, Email,
    IsConverted, ConvertedDate, ConvertedAccountId,
    ConvertedContactId, ConvertedOpportunityId,
    LEA_SEL_Fuente_Origen__c, LEA_SEL_Medio_Origen__c,
    Campa_a_Adquirida__c, Id_Adquirido__c,
    Contenido_Adquirido__c, LEA_BUS_Vehiculo_de_interes__c,
    Delegacion_Encargada_Text__c,
    Delegacion_Encargada__c,
    Delegacion_Encargada_Bueno__c
FROM Lead
WHERE IsDeleted = false
  AND CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
  AND (
      Campa_a_Adquirida__c != null
      OR Id_Adquirido__c != null
      OR Contenido_Adquirido__c != null
      OR LEA_SEL_Fuente_Origen__c != null
      OR LEA_SEL_Medio_Origen__c != null
  )
ORDER BY CreatedDate, Id
```

### 6.3 Query de oportunidades atribuibles

Para un contraste reproducible se recomienda exportar primero
`ConvertedOpportunityId` y `ConvertedAccountId` de la query anterior y consultar:

```sql
SELECT
    Id, Name, CreatedDate, StageName, RecordType.Name,
    AccountId, Account.Phone, Account.PersonEmail,
    OPO_CAS_Reserva__c, OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    OPO_FOR_Importe_total__c
FROM Opportunity
WHERE Id IN ('006...')
   OR AccountId IN ('001...')
ORDER BY CreatedDate, Id
```

El resultado final no se reproduce contando todas las oportunidades del mes:
solo entran las enlazadas a los leads del período.

### 6.4 Atribución

1. Se descartan campañas vacías y nombres `Tasador` exacto, `ren2click` o
   `hrrenting`.
2. Los leads de formulario directo Meta se consolidan como
   `Formulario Directo Meta`.
3. Cruce lead → campaña, por prioridad:
   - ID de anuncio;
   - ID de adset/ad group;
   - ID de campaña;
   - nombre exacto;
   - nombre flexible;
   - campaña solo Salesforce, sin inversión asociada.
4. Cruce lead → oportunidad:
   - `ConvertedOpportunityId`;
   - después cuentas convertidas y señales normalizadas de email/teléfono;
   - una oportunidad se reclama una sola vez para evitar doble atribución;
   - se prioriza el lead con señal de campaña más fuerte y fecha adecuada.
5. Tipos:
   - tasación por mapping o nombre de tasación;
   - venta por nombre;
   - exposición por visita a tienda/PMax;
   - branding por YouTube, vídeo, shorts o display;
   - otros para catálogo/instant forms y resto;
   - exposición, branding y otros son subcategorías de `Venta`.

### 6.5 Resultados y fórmulas

- `Leads Salesforce`: leads distintos.
- `Oportunidades`: oportunidades distintas con match.
- `Reservas`: flag reserva.
- `Venta`:
  - CV firmado;
  - fecha de firma informada;
  - no cerrada perdida;
  - tipo Venta; Cambio cuenta como venta solo si tiene importe positivo.
- `Compra`: contrato firmado válido y tipo Tasación.
- Una campaña fuente de tasación no recibe ventas; una fuente de venta no recibe
  compras.
- Importe vendido: primer valor positivo de
  `OPO_FOR_Importe_total__c`; `Amount` y otros campos compatibles son fallback.
- Importe de compra: valor absoluto de `OPO_FOR_Importe_total__c`.
- `CTR = clicks / impressions`.
- `CPC = spend / clicks`.
- `Coste por lead/oportunidad/reserva/venta/compra = spend / resultado`.
- `ROAS = sale_amount / spend`.
- `ROI estimado = (sale_amount - spend) / spend`.
- Ratios de funnel:
  lead→oportunidad, oportunidad→reserva, reserva→venta, lead→venta,
  lead→compra y oportunidad→compra.

Clasificación automática:

- tracking: inversión > 0 y cero leads;
- revisar inversión/tracking: leads > 0 y gasto cero;
- potenciar: ventas con coste/ROAS favorable frente al benchmark;
- parar: gasto ≥ 500, con leads, sin venta ni reserva;
- revisar: gasto ≥ 150 y resultado insuficiente;
- sin datos suficientes: gasto < 50 y menos de 5 leads.

### 6.6 Sincronización y auditoría

```bash
php artisan salesforce:sync-campaign-leads --from=2026-06-01 --to=2026-07-01 --fresh --debug-soql
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01
php artisan campaigns:sync-meta --from=2026-06-01 --to=2026-07-01
php artisan campaigns:sync-google --from=2026-06-01 --to=2026-07-01
php artisan campaigns:build-attribution --from=2026-06-01 --to=2026-07-01
php artisan reports:refresh-campaigns --from=2026-06-01 --store
```

Auditoría:

- `/informes/campanas/data/kpi-audit`
- `/informes/campanas/export/kpi-audit.csv`
- `/informes/campanas/export/campaigns.csv`
- `php artisan campaigns:debug-attribution`
- `php artisan campaigns:debug-google-spend`

Código fuente:

- `app/Services/Campaigns/CampaignLeadSyncService.php`
- `app/Services/Campaigns/CampaignAttributionBuilderService.php`
- `app/Services/Campaigns/CampaignDashboardDatasetService.php`
- `app/Services/Campaigns/CampaignTypeResolver.php`
- `app/Services/Campaigns/CampaignPerformanceClassifier.php`

## 7. Comisiones comerciales

Esta sección resume las fuentes y universos. La especificación económica completa
está en `Calculo_comisiones_comerciales.txt`.

### 7.1 Objetos consultados

- `Opportunity`: operaciones, importes, productos, propietarios, tiendas,
  financiación y contratos.
- `User`: identidad, email, perfil, delegación, activo y
  `Comision_Tasador__c`.
- `Resena__c`: reseñas por oportunidad/comercial.
- `Tasacion__c`: negociaciones German.
- Endpoint interno de reseñas: bonus/penalización de delegaciones.
- XLSX: cancelaciones de financiación.

Campos de `Opportunity` usados, además de los de Reservas/Ventas:

- `Entrega_Compartida__c`, `Entrega_Compartida__r.Name`;
- `Tienda_de_entrega__c`, `Delegacion_del_propietario__c`;
- `Garant_a_Total__c`, `Beneficio_financiacion_comercial__c`,
  `Importe_financiado__c`, `Gestion_de_venta__c`,
  `OPO_DIV_Descuento__c`;
- `Comisi_n_Financiera__c`, `OPO_DIV_Descuento_financiera__c`,
  `Inter_s_elegido__c`, `zona_financiera__c`,
  `Tipo_de_registro_oportunidad__c`;
- `Captador__c`, `Comisi_n_Captador__c`,
  `Captador_de_cita__c`, `Fecha_captador__c`;
- relaciones del vehículo de interés: precios, matrícula, entrada, procedencia,
  comprador, Plan Auto Plus y CAE.

### 7.2 Query base de comerciales

```sql
SELECT
    Id, Name, StageName, RecordType.Name,
    OwnerId, Owner.Name, Owner.IsActive,
    Owner.USR_SEL_Delegacion__c,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Gestion_de_venta__c,
    Entrega_Compartida__c, Entrega_Compartida__r.Name,
    Tienda_de_entrega__c, Delegacion_del_propietario__c,
    OPO_DIV_Descuento__c, Garant_a_Total__c,
    Beneficio_financiacion_comercial__c, Importe_financiado__c,
    OPO_FOR_Importe_total__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND Fecha_firma_contrato__c >= 2026-06-01
  AND Fecha_firma_contrato__c < 2026-07-01
  AND StageName != 'Cerrada Perdida'
  AND RecordType.Name IN ('Venta', 'Cambio', 'Tasacion')
  AND Owner.IsActive = true
  AND (Gestion_de_venta__c = false OR Gestion_de_venta__c = null)
ORDER BY Owner.Name, Fecha_firma_contrato__c, Id
```

Usuarios:

```sql
SELECT
    Id, Name, Email, Profile.Name, USR_SEL_Delegacion__c,
    Comision_Tasador__c, IsActive
FROM User
WHERE IsActive = true
  AND (
      Profile.Name = 'Comerciales Partner Community'
      OR Profile.Name = 'Compra/Venta'
      OR Comision_Tasador__c = true
  )
```

Reseñas:

```sql
SELECT
    Id, CreatedDate, OwnerId, Owner.Name,
    RES_BUS_Oportunidad__c, RES_BUS_Oportunidad__r.Name,
    RES_BUS_Oportunidad__r.OwnerId,
    RES_BUS_Oportunidad__r.Owner.Name,
    RES_BUS_Oportunidad__r.RecordType.Name,
    RES_BUS_Oportunidad__r.Fecha_firma_contrato__c
FROM Resena__c
WHERE CreatedDate >= 2026-06-01T00:00:00Z
  AND CreatedDate < 2026-07-01T00:00:00Z
```

### 7.3 Universos internos

- Comerciales:
  query base anterior; owner activo y gestión de venta excluida.
- Delegaciones, entregas:
  CV firmado, mes, no cerrada perdida, Venta/Cambio o nombre Facilitea; no
  filtra owner activo ni gestión de venta; asignación exclusivamente por
  `Tienda_de_entrega__c`.
- Delegaciones, rentabilidad total:
  fecha de firma en mes y etapa distinta de `Cerrada ganada` y
  `Cerrada perdida`; no exige CV ni limita Record Type; asignación por tienda.
- Ratios financieros de Delegaciones:
  conjunto de entregas, agrupado por
  `Delegacion_del_propietario__c`, con fallback al owner histórico/local.
- Area Managers:
  entregas Venta/Cambio y compras Tasación/Cambio con CV firmado, mes y no
  cerrada perdida; agrupa por delegación actual del owner. Facilitea con owner
  no operativo cae a tienda.
- Financieros:
  firma en mes, no cerrada perdida, tipo fórmula Venta/Cambio, zona financiera
  válida.
- Call Center:
  oportunidades firmadas del rango; Tasación, Venta y Cambio con captador y sin
  gestión de venta; German desde `Tasacion__c`; Facilitea por regla específica.
- Contact Center:
  citas del mes en Leads; oportunidades hasta el día 10 del mes siguiente;
  ventas firmadas dentro del mes.

### 7.4 Auditoría y comandos

- vista `/informes/comisiones-comerciales`;
- XLSX `/informes/comisiones-comerciales/export/comisiones.xlsx`;
- CSV de entregas de delegación:
  `/informes/comisiones-comerciales/export/delegation-deliveries.csv`;
- CSV de Call Center sin captador:
  `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`;
- API Basic Auth:
  `/api/comisiones_comercial?salesforce_id={ID}`;
- configuración mensual: `/informes/configuracion-comisiones`;
- penalizaciones: `/informes/penalizaciones-financiacion`.

```bash
php artisan salesforce:sync-opportunities --all-history
php artisan salesforce:sync-commercial-reviews --all
php artisan salesforce:sync-tasaciones --all
```

Código fuente:

- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`
- `app/Services/Reports/CallCenterCommissions/CallCenterCommissionDashboardService.php`
- `app/Services/Reports/ContactCenterCommissions/ContactCenterCommissionDashboardService.php`
- `app/Services/Reports/AreaManagerCommissions/AreaManagerCommissionDashboardService.php`
- `app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php`
- `app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php`

## 8. Stock

### 8.1 Fuentes y propósito

Stock cruza:

1. inventario vigente de `Product2`;
2. ventas firmadas de `Opportunity`;
3. logística de `Logistica__c`;
4. capacidades por tienda importadas desde CSV/XLSX;
5. fotografías diarias y snapshots inmutables de venta.

El dashboard no calcula ventas históricas leyendo el Product2 actual: cuando detecta
una nueva venta crea `salesforce_sale_snapshots` y conserva los importes y atributos
de ese momento.

### 8.2 Query de inventario

```sql
SELECT
    Id, Name, StockKeepingUnit,
    PRO_TEX_Matricula__c,
    PRO_SEL_Marca__c, PRO_TEX_Modelo__c, PRO_TEX_Version__c,
    Segmento__c, PRO_SEL_Combustible__c, PRO_SEL_Carroceria__c,
    PRO_NUM_Kilometraje__c, PRO_SEL_Estado__c,
    PRO_BUS_Delegacion__c, PRO_BUS_Delegacion__r.Name,
    PRO_DIV_Precio_de_compra__c,
    PRO_DIV_Precio_de_venta__c,
    PRO_DIV_Precio_venta_financiado__c,
    PRO_FEC_Fecha_entrada__c,
    Comprador_oportunidad__c, Comprador_oportunidad__r.Name,
    Procedencia_de_compra__c
FROM Product2
WHERE PRO_SEL_Estado__c IN ('Disponible', 'Reservado', 'Bloqueado')
ORDER BY PRO_BUS_Delegacion__r.Name, PRO_FEC_Fecha_entrada__c, Id
```

Cada sincronización marca primero como fuera de stock todos los vehículos locales
que estaban activos y vuelve a activar únicamente los IDs devueltos por esta query.

### 8.3 Query de ventas firmadas

```sql
SELECT
    Id, Name, RecordType.Name,
    OPO_CAS_Contrato_CV_firmado__c, Fecha_firma_contrato__c,
    Tienda_de_entrega__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Marca__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Modelo__c,
    OPP_BUS_Vehiculo_de_interes__r.Segmento__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Combustible__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_SEL_Carroceria__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_NUM_Kilometraje__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c,
    OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name,
    OPO_BUS_Vehiculo_a_tasar__c,
    OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c,
    OPO_FOR_Importe_vehiculo_venta__c,
    OPO_FOR_Importe_vehiculo_a_cambio__c,
    Gestion_de_venta__c,
    Costes_de_gestion__c,
    Costes_de_Logistica_Incluido__c,
    OPO_DIV_Coste_Traslado__c,
    Garant_a_Total__c, OPO_DIV_Descuento__c,
    OPO_DIV_Descuento_financiera__c,
    Descuento_Logistica__c, OPO_FOR_Importe_total__c
FROM Opportunity
WHERE IsDeleted = false
  AND OPO_CAS_Contrato_CV_firmado__c = true
  AND RecordType.Name IN ('Venta', 'Cambio')
  AND Fecha_firma_contrato__c >= 2026-06-01
  AND Fecha_firma_contrato__c < 2026-07-01
ORDER BY Fecha_firma_contrato__c, Id
```

El sincronizador incluye además oportunidades sin fecha de firma modificadas en la
ventana para diagnosticarlas; no entrarán en ventas por período hasta tener fecha.

### 8.4 Query logística

```sql
SELECT
    Id, Name, LastModifiedDate,
    LOG_BUS_Vehiculo_a_transportar__c,
    LOG_BUS_Vehiculo_a_transportar__r.Name,
    LOG_BUS_Delegacion_Origen__c,
    LOG_BUS_Delegacion_Origen__r.Name,
    LOG_BUS_Delegacion_Destino__c,
    LOG_BUS_Delegacion_Destino__r.Name,
    LOG_SEL_Estado__c,
    LOG_FEC_Fecha_de_transporte__c,
    LOG_FEC_Fecha_recepcion__c,
    Fecha_en_destino__c
FROM Logistica__c
WHERE IsDeleted = false
  AND LastModifiedDate >= 2026-06-01T00:00:00Z
ORDER BY LastModifiedDate, Id
```

Actualmente Logística se sincroniza y conserva para trazabilidad; los KPIs
principales del dashboard se calculan con stock, ventas y capacidades.

### 8.5 KPIs y fórmulas

Stock actual:

- total = vehículos `is_in_stock = true`;
- disponible/reservado/bloqueado por `PRO_SEL_Estado__c`;
- valor de compra = suma de precios de compra;
- valor de venta = suma de precios de venta;
- margen potencial = valor venta − valor compra;
- precio medio = suma / unidades;
- antigüedad = hoy − fecha de entrada;
- umbrales: ≥60, ≥90, ≥120 y ≥180 días.

Ventas:

- universo: snapshots con `signed_date` dentro del rango;
- rotación = fecha firma − fecha entrada, solo si entrada ≤ firma;
- margen bruto mostrado = precio contractual de venta − precio de compra;
- la tabla expone además cambio, gestión, logística, traslado, garantía, Plan Auto
  Plus, CAE, descuentos e importe total, sin combinarlos en un único margen neto.

Capacidad:

- capacidad libre = plazas totales − stock total;
- ocupación = stock total / plazas totales × 100;
- ventas/stock por delegación = ventas del rango / stock actual.

Ratio ventas/stock general:

- si el histórico diario cubre al menos el 80% de los días:
  `ventas / promedio diario de disponibles`;
- si no, fallback aproximado:
  `ventas / disponibles actuales`;
- el payload marca si el resultado es aproximado.

Histórico:

- cobertura = días fotografiados / días esperados;
- suficiente si cobertura ≥ 80%;
- serie diaria separa disponible, reservado y bloqueado.

Rankings:

- dimensiones: marca, modelo, segmento, combustible, tramo de precio, carrocería,
  procedencia, kilometraje y antigüedad/rotación;
- rendimiento = ventas / stock actual;
- si no hay stock pero sí ventas, rendimiento = ventas.

### 8.6 Motor de recomendaciones

Usa ventas de los últimos 120 días y stock actual de cada delegación comercial con
capacidad informada.

Puntuación de destino:

```text
score =
  ventas_modelo * 9
  + ventas_marca * 2,5
  + ventas_segmento * 1,6
  + ventas_combustible * 1,2
  + ventas_tramo_precio * 1,8
  + min(capacidad_libre, 20) * 1,2
  - stock_mismo_modelo * 15
  - stock_antiguo_mismo_modelo * 10
  - stock_similar * 1
  + bonus_rotacion_rapida
  - 30 si no existe histórico comparable
```

`bonus_rotacion_rapida = max(0, (120 - min(rotación_media, 120)) / 120) * 12`.

Se devuelven los tres mejores destinos con capacidad libre. Un vehículo queda:

- `review` desde 60 días;
- `priority` desde 90 días;
- `priority` con 3 o más unidades del mismo modelo en la tienda;
- `priority` si el mejor destino supera al actual en 40 puntos.

Los tramos de precio son bloques de 5.000 EUR. Stock similar significa misma
combinación de segmento, combustible y tramo de precio.

### 8.7 Capacidades, alertas y calidad

- Capacidades:
  - importación CSV/XLSX;
  - se localiza cabecera `Plazas totales`;
  - si un nombre se repite se conserva la mayor capacidad;
  - se normalizan alias de delegación.
- No comerciales: mantenimiento, pendiente de entrar, fuera de stock y clave de
  prueba configurada.
- Alerta:
  - solo delegaciones comerciales con capacidad;
  - cero vehículos `Disponible` abre alerta;
  - crea `Task` prioritaria en Salesforce y envía email a `STOCK_ALERT_EMAIL`;
  - cuando vuelve a haber disponibles, resuelve la alerta y completa la Task.
- Calidad:
  - stock sin entrada/delegación/marca/modelo/segmento/combustible;
  - entregados que aún aparecen en stock;
  - tiendas comerciales sin zona;
  - ventas sin firma/tienda/entrada/precio.

Exportación:

- `/informes/stock/exportar/calidad-dato.xlsx`

### 8.8 Operación

```bash
php artisan stock:import-capacities ruta/capacidades.xlsx
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Programación vigente:

```text
03:30 Europe/Madrid
stock:sync-daily --sales-days=14 --logistics-days=30
sin solapamiento durante 120 minutos
```

Código fuente:

- `app/Services/Reports/Stock/SalesforceVehicleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSignedSaleSyncService.php`
- `app/Services/Reports/Stock/SalesforceSaleSnapshotService.php`
- `app/Services/Reports/Stock/SalesforceLogisticsSyncService.php`
- `app/Services/Reports/Stock/StockDailySnapshotService.php`
- `app/Services/Reports/Stock/StockDashboardDatasetService.php`
- `app/Services/Reports/Stock/StockRecommendationService.php`
- `app/Services/Reports/Stock/StockAvailabilityAlertService.php`

## 9. Método de conciliación recomendado

1. Guardar captura de filtros, período y rol.
2. Identificar la fecha pivote exacta.
3. Ejecutar primero `COUNT()` o la query base.
4. Exportar IDs, no solo totales.
5. Comparar con el endpoint/CSV auditable cuando exista.
6. Revisar normalizadores, alias y filtros locales.
7. Confirmar que la sincronización cubre todo el intervalo.
8. En Campañas, conciliar en orden:
   lead → campaña → cuenta/oportunidad → reserva → venta/compra → importe.
9. En Comisiones, conciliar cada pestaña por separado: sus universos no son
   intercambiables.
10. En Stock, distinguir siempre inventario vivo, snapshot diario y snapshot de
    venta.

## 10. Seguridad documental

La documentación solo debe nombrar variables de entorno. No debe incluir tokens,
contraseñas, secretos de Basic Auth ni credenciales de Salesforce, Meta, Google o
del endpoint interno.

Durante esta revisión se ha detectado que `config/services.php` aún contiene
valores fallback sensibles para el endpoint interno de reseñas. Deben retirarse,
rotarse y proporcionarse únicamente mediante `INTERNAL_REVIEWS_ENDPOINT`,
`INTERNAL_REVIEWS_USER` e `INTERNAL_REVIEWS_PASSWORD`. Este hallazgo no se
resuelve copiando los valores a otro documento.
