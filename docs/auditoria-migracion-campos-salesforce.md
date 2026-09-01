# Auditoría preparatoria de procedencia y atribución Salesforce

Actualizado: 2026-09-01.

## Alcance y línea base

Este documento congela el comportamiento observado antes de migrar procedencia,
canal, medio, delegación de entrada y UTM. No define la regla futura ni valida
API Names contra la metadata de Salesforce.

- Rama auditada: `main`.
- Commit auditado: `4a4037a1036f0ce32800e003abdb571289cd10a1`.
- Estado inicial: limpio; `origin/main` apuntaba al mismo commit.
- Referencia solicitada: `a6b7ef768d0c261e18508e902b846abfffc12707`.
  Ese objeto no está disponible en el repositorio local, por lo que Git no pudo
  calcular ahead/behind ni merge-base. No se hizo fetch, reset ni checkout; el
  estado real actual se tomó como fuente de verdad.
- No se consultó metadata de Salesforce ni se ejecutó ninguna escritura remota.

## Hallazgos ejecutivos

1. `salesforce_leads.fuente_origen` significa actualmente
   `Lead.LEA_SEL_Fuente_Origen__c`; no significa `Lead.Fuente_origen__c`.
   `medio_origen` tiene la misma colisión con
   `LEA_SEL_Medio_Origen__c` frente a `Medio_origen__c`.
2. `LeadPortalResolver` deriva el canal solo de `Medio_Nuevo__c`. Una llamada
   prioriza `Fuente_Nuevo__c`; cualquier otro canal se trata como Formulario y
   prioriza `Portal_Text__c`.
3. Campañas sincroniza el universo SOQL mediante cinco campos legacy, pero el
   attribution builder no considera automáticamente candidato todo lo
   sincronizado: requiere campaña válida o la regla vigente de formulario
   directo Meta.
4. `CampaignLeadSyncService` escribe el mismo Lead en
   `campaign_salesforce_leads` y, si existe, en `salesforce_leads`. La segunda
   escritura es parcial y puede reemplazar por `null` columnas de procedencia o
   contacto obtenidas por el sincronizador mensual.
5. `Medio_origen__c` ya existe en una integración aislada de SEO para contar
   Leads orgánicos. No alimenta `salesforce_leads.medio_origen` ni los informes
   de Leads/Campañas. Ningún otro campo candidato nuevo aparece en PHP
   productivo.
6. Hay lógicas legacy paralelas con prioridades diferentes: el dashboard
   Salesforce usa `LeadPortalResolver`; la canalización histórica
   `leads_raw`/`leads_normalized` usa `LeadNormalizationService`; Llamadas y
   Reservas/Ventas aplican sus propios fallbacks de portal del Lead.

## Matriz técnica

`Impacta universo` indica si cambiar el uso puede alterar qué registros entran,
no solo su etiqueta.

| Campo/API | Tipo de uso | Archivo / contexto | Persistencia local | Informe afectado | Impacta universo | Riesgo | Cambio futuro |
|---|---|---|---|---|---|---|---|
| `Medio_Nuevo__c` | SELECT, mapper, resolución de canal | `SalesforceMonthlyLeadsSyncService::leadSoql/persistPages`, `SalesforceLeadMapper`, `SalesforceLeadCsvMapper`, `LeadPortalResolver::channel` | `salesforce_leads.medio_nuevo`, `leads_raw.medio_nuevo`, `salesforce_leads.resolved_channel` | Leads, Comercial mensual | No en sync general; sí en clasificaciones/filtros posteriores | Alto | Mantener como fallback hasta aprobar autoridad de `Canal__c` |
| `Medio_Nuevo__c` | SELECT auxiliar | `SalesforceCallSyncService::relatedLeadMatches` | No se persiste desde la consulta auxiliar | Llamadas | No, el universo es Task | Medio | Decidir si la consulta auxiliar necesita canal nuevo; no ampliar universo |
| `Fuente_Nuevo__c` | SELECT, mapper, resolución | `SalesforceMonthlyLeadsSyncService`, `SalesforceLeadMapper`, `SalesforceLeadCsvMapper`, `LeadPortalResolver::resolve` | `salesforce_leads.fuente_nuevo`, `leads_raw.fuente_nuevo`, `resolved_portal`, `portal_resolution_source` | Leads, Comercial mensual | No | Alto | Añadir fuente nueva solo tras definir prioridad y validez |
| `Fuente_Nuevo__c` | fallback de portal relacionado | `SalesforceCallSyncService::leadPortal`, `SalesforceOpportunitySyncService::leadPortal/queryLeads` | `salesforce_calls.portal_resolved`; `salesforce_opportunities.portal_resolved` | Llamadas, Reservas/Ventas | No: Tasks/Oportunidades no cambian | Alto | Cambiar únicamente la clasificación, preservando consultas base |
| `Portal_Text__c` | SELECT, portal primario de formulario | `SalesforceMonthlyLeadsSyncService`, `LeadPortalResolver`, `SalesforceLeadCsvMapper` | `salesforce_leads.portal_text`, `leads_raw.portal`, `resolved_portal` | Leads, Comercial mensual | No | Alto | Conservar como fallback explícito |
| `Portal_Text__c` | portal primario del Lead relacionado | `SalesforceCallSyncService::leadPortal`, `SalesforceOpportunitySyncService::leadPortal` | portal resuelto de Call/Oportunidad | Llamadas, Reservas/Ventas | No | Alto | Proteger prioridad y trazabilidad del campo ganador |
| `Portal__c` | mapper de importación API legacy | `SalesforceLeadMapper::map` | `leads_raw.portal` | Canalización legacy de Leads | No | Medio | No confundir con `Portal_Text__c` |
| `Portal__c` | SELECT y clasificación propia de Opportunity | `SalesforceOpportunitySyncService::buildOpportunitySoql/resolvePortal` | `salesforce_opportunities.portal_original`, `portal_resolved`, `portal_resolution_source` | Reservas/Ventas, Campañas al enlazar oportunidades | No en universo | Alto | No reemplazar por campo de Lead; mantener precedencia de Opportunity |
| `LEA_SEL_Fuente_Origen__c` | SELECT, mapper, fallback de portal | sync mensual, mappers, `LeadPortalResolver`, consultas auxiliares | `salesforce_leads.fuente_origen`, `leads_raw.lea_sel_fuente_origen` | Leads, Llamadas, Reservas/Ventas | No salvo Campañas | Crítico | La columna local es legacy; no reutilizar para `Fuente_origen__c` |
| `LEA_SEL_Fuente_Origen__c` | OR de filtro, SELECT, criterio PHP | `CampaignLeadSyncService::leadSoql/countMappedRecord`; `SalesforceMonthlyLeadsSyncService` en `campaignOnly` | ambas tablas de Lead | Campañas | Sí | Crítico | El cambio futuro del OR requiere decisión consciente y pruebas de conteo |
| `LEA_SEL_Medio_Origen__c` | SELECT, mapper, exposición | sync mensual, mappers, `MonthlyCommercialLeadEnricher` | `salesforce_leads.medio_origen`, `leads_raw.lea_sel_medio_origen` | Leads, Comercial mensual | No salvo Campañas | Crítico | No reutilizar columna para `Medio_origen__c` |
| `LEA_SEL_Medio_Origen__c` | OR de filtro, SELECT, criterio PHP | `CampaignLeadSyncService`; sync mensual `campaignOnly` | ambas tablas de Lead | Campañas | Sí | Crítico | Preservar universo hasta aprobar equivalencia/fallback |
| `Remitente_Lead__c` | SELECT, mapper y delegación de formulario legacy | sync mensual, mappers, `LeadNormalizationService::resolveFormDelegation` | `salesforce_leads.remitente_lead`, `leads_raw.remitente_lead` | Leads legacy / calidad | Puede alterar agrupación, no sync | Alto | No sustituir sin revisar mappings por portal/remitente |
| `Delegacion_Encargada_Text__c` | SELECT, mapper y fallback | sync mensual, mappers, sync Campañas | `delegacion_encargada_text` en ambas tablas | Leads, Comercial mensual, Campañas | No | Alto | Mantener su semántica de valor recibido/fallback |
| `Delegacion_Encargada_Text__c` | mapeo exacto por portal en llamadas legacy | `LeadNormalizationService::resolveCallDelegation` | `leads_normalized` | Canalización legacy de Leads | No | Alto | No equiparar automáticamente con procedencia |
| `Delegacion_Encargada_Bueno__c` | SELECT, mapper, prioridad 1 dashboard | sync mensual; `SalesforceLeadDashboardDatasetService::resolveLeadDelegation` | `salesforce_leads.delegacion_encargada_bueno` | Leads | No | Crítico | La prioridad futura está pendiente |
| `Delegacion_Encargada_Bueno__c` | fallback de formulario legacy | `LeadNormalizationService::resolveFallbackDelegation` | resultado normalizado legacy | Leads legacy | No | Alto | Conservar antes de `Delegacion_Encargada__c` |
| `Delegacion_Encargada__c` | SELECT, mapper, prioridad 2 dashboard | sync mensual y Campañas; dataset Leads | `salesforce_leads.delegacion_encargada`; `campaign_salesforce_leads.delegacion_encargada_id` | Leads, Campañas | No | Crítico | No cambiar prioridad sin decisión |
| `Delegacion__c` | mapper de Lead API legacy | `SalesforceLeadMapper::map`; `LeadNormalizationService` tercer fallback | `leads_raw.delegacion`; no se carga por CSV | Canalización legacy | No | Alto | API Name funcional no está verificado para el dashboard actual |
| `Owner.Delegacion__c` | delegación de owner en mapper legacy | `SalesforceLeadMapper::map` | `leads_raw.owner_delegation` | Leads legacy, Exposición | No | Alto | Solo Exposición permite este fallback |
| `Owner.USR_SEL_Delegacion__c` | SELECT de usuario/owner | sync mensual de Users, sync de Calls, sync de Opportunities | `salesforce_users.user_delegation`, `salesforce_opportunities.owner_delegation` | Leads (delegación comercial), Llamadas, Reservas/Ventas, Comisiones | No | Alto | Es delegación del comercial, no delegación de entrada |
| `Campa_a_Adquirida__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `campaign_acquired` en ambas tablas | Campañas y auditoría Leads | Sí en Campañas | Crítico | Definir equivalencia con `utm_campaign__c` antes de ampliar filtro |
| `Campa_a_Adquirida__c` | nombre y exclusión de campaña | `CampaignAttributionBuilderService::resolveCampaign` | atribuciones `campaign_acquired`, `source_campaign_name`, match trace | Campañas | Sí en candidatos del builder | Crítico | Mantener rule version y decidir conflicto nuevo/legacy |
| `Id_Adquirido__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `acquired_id` en ambas tablas | Campañas, auditoría Leads | Sí en sync Campañas | Crítico | No asumir que equivale a `utm_id__c` |
| `Id_Adquirido__c` | candidato de ID | attribution builder: ad, adset, ad group y campaign ID | `acquired_id`, `matched_source_field/value` | Campañas | No tras selección; cambia atribución | Crítico | Acordar significado y prioridad ID/nombre |
| `Contenido_Adquirido__c` | OR filtro, SELECT, mapper | ambos sync de Leads | `content_acquired` en ambas tablas | Campañas, auditoría Leads | Sí en sync Campañas | Crítico | No asumir equivalencia con `utm_content__c` |
| `Contenido_Adquirido__c` | segundo candidato de ID | attribution builder | `content_acquired`, traza de match | Campañas | No tras selección; cambia atribución | Alto | Definir si es contenido, anuncio u otro identificador |
| `Fuente_Adquirida__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Medio | Verificar API Name y equivalencia; no está seleccionado ni mapeado |
| `Medio_Adquirido__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Medio | Verificar API Name y equivalencia |
| `Fuente_origen__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Crítico | Verificar API Name; crear destino inequívoco en fase posterior |
| `Medio_origen__c` | filtro SOQL aislado | `SalesforceOrganicLeadSyncService::soql` | `seo_salesforce_organic_daily_metrics.lead_count` agregado diario | SEO/Analytics | Sí, solo universo SEO orgánico | Alto | No mezclar con `salesforce_leads.medio_origen` legacy |
| `Canal__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Alto | Pendiente definir autoridad y valores válidos |
| `Delegacion_procedencia__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Crítico | Pendiente prioridad, Exposición y aliases |
| `utm_campaign__c`, `utm_id__c`, `utm_source__c`, `utm_medium__c`, `utm_content__c` | sin referencia | búsqueda global | Ninguna | Ninguno observado | No hoy | Crítico | Verificar existencia, semántica, validez y fallback antes de consultar |
| campos locales `source_acquired`, `medium_acquired` | proyección de atribución | `CampaignAttributionBuilderService::buildAttributionRow` | tablas de atribución | Campañas/export | No | Alto | Proceden de `fuente_origen`/`medio_origen`, por tanto de `LEA_SEL_*`, no de campos `*_Adquirida__c` |

## Flujos de datos actuales

### Leads Salesforce y Comercial mensual

1. `SalesforceMonthlyLeadsSyncService` consulta `Lead` por `CreatedDate`,
   `Fecha_captador__c` o `LastModifiedDate`. Es el universo general; los campos
   de procedencia no deciden la entrada.
2. Los campos legacy se mapean a `salesforce_leads`. En el mismo paso,
   `LeadPortalResolver` materializa `resolved_channel`, `resolved_portal` y
   `portal_resolution_source`.
3. El canal actual normaliza trim, mayúsculas y acentos: solo `llamada` produce
   `Llamada`; `null`, vacío, whitespace, Formulario y desconocidos producen
   `Formulario`.
4. Prioridad de portal: Llamada = `Fuente_Nuevo__c` → `Portal_Text__c` →
   `LEA_SEL_Fuente_Origen__c`; Formulario = `Portal_Text__c` →
   `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`. Los candidatos se recortan;
   vacío/whitespace no gana. Sin candidato: `Sin clasificar`, source `fallback`.
5. `SalesforceLeadDashboardDatasetService` reutiliza lo materializado y solo
   recalcula si falta alguno de los tres valores. No modifica el universo de
   Leads válidos por procedencia.
6. El CSV de auditoría de Leads expone portal bruto y resuelto, campo ganador,
   canal, delegaciones, `LEA_SEL_Fuente_Origen__c`, medio local y adquisición.
7. `MonthlyCommercialLeadEnricher` proyecta `fuente_original` desde
   `fuente_origen`, `medio` desde `medio_origen` y tanto `portal` como `fuente`
   desde `portal_text`. Su delegación usa únicamente
   `Delegacion_Encargada_Text__c` y una comparación con `master_delegations`.

Existe además una canalización histórica `SalesforceLeadMapper` / CSV →
`leads_raw` → `LeadNormalizationService` → `leads_normalized`. No comparte todas
las prioridades del dashboard actual: para Llamada, `resolvePortalOriginal`
usa solo `fuente_nuevo` o `Sin portal`; para Formulario usa `portal`, fuente
legacy y fuente nueva. Esta divergencia debe tratarse conscientemente.

### Delegación de Lead

- Dashboard Salesforce: `Delegacion_Encargada_Bueno__c` →
  `Delegacion_Encargada__c` → `delegacion_encargada_text` persistido legacy.
- Solo RecordType funcional Exposición, y únicamente si lo anterior está vacío,
  permite persona que trabajó → owner → usuario comercial efectivo.
- Otros tipos no usan owner como fallback de delegación del Lead.
- `LeadDelegationNormalizer` limpia mayúsculas, acentos, puntuación, guiones,
  barras y espacios; para emails elimina espacios. Después aplica aliases
  controlados y devuelve delegación, grupo y zona. Desconocido/null/vacío queda
  `Sin clasificar` / `Sin grupo`.
- La delegación comercial es otro eje: procede del usuario efectivo y de
  `USR_SEL_Delegacion__c`. No debe confundirse con delegación de entrada.
- Canalización legacy: llamadas usan mapping portal +
  `Delegacion_Encargada_Text__c`; formularios priorizan mapping portal +
  remitente + valor, luego Bueno → Encargada → `Delegacion__c`; Exposición
  puede usar owner.

### Campañas

`CampaignLeadSyncService`:

- WHERE actual: `IsDeleted = false`, rango semiabierto de `CreatedDate` y OR de
  `Campa_a_Adquirida__c`, `Id_Adquirido__c`, `Contenido_Adquirido__c`,
  `LEA_SEL_Fuente_Origen__c`, `LEA_SEL_Medio_Origen__c` no null.
- SELECT: identidad, contacto, conversión, esos cinco campos, vehículo y tres
  campos de delegación. No selecciona campos UTM candidatos, `Fuente_Adquirida`
  ni `Medio_Adquirido`.
- Mapeo: los cinco campos escriben `campaign_acquired`, `acquired_id`,
  `content_acquired`, `fuente_origen`, `medio_origen`.
- Aunque Salesforce devuelva una cadena vacía, el filtro PHP exige `filled` tras
  trim. Un registro sin ninguno de los cinco se cuenta en
  `without_acquisition` y no se guarda.
- Estadísticas: tabla, borrados, consultados, guardados, contador por cada uno de
  los cinco campos, sin adquisición, warnings y `dry_run`.
- `fresh`: elimina antes del sync solo filas de `campaign_salesforce_leads` cuyo
  `created_date` cae en el rango; no elimina `salesforce_leads`. No es
  transaccional con la consulta remota: un fallo posterior puede dejar vacío el
  tramo especializado.
- `dryRun`: consulta, mapea y cuenta, pero no elimina ni hace upsert. El comando
  prohíbe combinar explícitamente `--fresh` con `--dry-run`.
- Si la query filtrada lanza `RuntimeException`, reintenta una query por rango
  con el mismo SELECT y sin OR de adquisición; después aplica el mismo filtro
  PHP. El warning incluye el error de cliente ya sanitizado por la capa
  Salesforce.
- Persistencia: upsert por chunks, ordenado por Salesforce ID y con reintentos
  de deadlock. Escribe primero `campaign_salesforce_leads` y después, si existe,
  `salesforce_leads`; no hay transacción común entre ambas tablas.

`CampaignAttributionBuilderService`:

1. Prefiere `salesforce_leads` si hay cualquier fila en el rango; solo cae a
   `campaign_salesforce_leads` si la tabla general no tiene filas en ese rango.
   Por ello no mezcla ambas fuentes ni completa huecos por Lead.
2. Recupera campos vacíos desde `raw_payload` usando exclusivamente API Names
   legacy.
3. Considera candidato un Lead con `campaign_acquired` válido o que cumpla la
   regla vigente de formulario directo Meta. Un Lead sincronizado únicamente
   por fuente/medio o ID no es automáticamente candidato fuera de esa regla.
4. Prueba `acquired_id` y `content_acquired` contra ID de anuncio, adset,
   ad group y campaña; conflictos producen atribución ambigua. Si no gana un ID,
   prueba nombre de campaña exacto y flexible. Después conserva atribución solo
   Salesforce cuando aplica.
5. Persiste bruto, resultado, método, confianza, campo/valor ganador,
   candidatos, first touch y versión de regla. Los exports muestran esa traza.

#### Riesgo de doble escritura

- Los sincronizadores mensual y de Campañas pueden actualizar el mismo
  `salesforce_leads.salesforce_id` con ventanas y tiempos distintos. No existe
  versionado por `LastModifiedDate` ni compare-and-swap entre servicios.
- El sync de Campañas no selecciona RecordType, resolución de portal, campos de
  citas ni metadatos de borrado. El upsert no actualiza esas columnas, por lo que
  permanecen, pero sí actualiza nombre, estado, owner, contacto, conversión,
  procedencia, adquisición, delegación y `raw_payload`.
- Si su respuesta parcial omite fuente/medio/contacto/delegación, los mapea a
  `null` y puede borrar el valor general existente. El test de caracterización
  congela expresamente este comportamiento peligroso sin corregirlo.
- El `raw_payload` de `salesforce_leads` pasa a ser el payload parcial del sync
  de Campañas. Un attribution builder posterior puede perder campos disponibles
  en el payload mensual.
- Un fallo entre ambos upserts deja tablas divergentes. Carreras concurrentes
  son last-write-wins. `fresh` solo afecta la tabla especializada.

### Reservas/Ventas

El universo nace de Opportunity y sus fechas/reglas; no depende de Lead. El
portal se resuelve así: `Opportunity.Portal__c` conclusivo → Lead relacionado
por email/teléfono (más reciente y con portal válido) →
`Opportunity.Fuente_de_Origen__c` útil → fallbacks Exposición/Web → Sin
clasificar. En el Lead relacionado la prioridad es `Portal_Text__c` →
`LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`. Cambiar procedencia futura solo
debe cambiar `portal_resolved` y su traza, nunca las Opportunities consultadas,
reservas, contratos ni conteos.

Si la consulta de Leads no devuelve filas, existe fallback local a `leads_raw`.
La consulta Opportunity solo reintenta sin email de empresa cuando Salesforce
rechaza ese campo opcional; no es el fallback de query filtrada de Campañas.

### Llamadas

El universo nace de Task de llamada y `CallObject`; no depende del Lead. Primero
normaliza `Task.Portales__c`. Solo si estaba informado pero queda Sin clasificar
busca el Lead de `WhoId` y aplica `Portal_Text__c` → fuente legacy → fuente
nueva. La consulta auxiliar selecciona también canal y delegaciones de Lead,
pero la resolución observada solo consume el portal. Cambiar procedencia futura
no debe alterar las Tasks, estados, duraciones, atendidas o desbordamientos.

### SEO/Analytics

`SalesforceOrganicLeadSyncService` consulta directamente
`Medio_origen__c = 'Orgánico'` y materializa únicamente conteos diarios en
`seo_salesforce_organic_daily_metrics`. Es una proyección separada y ya existente;
no constituye precedente para sobrescribir `salesforce_leads.medio_origen`.
`SalesforceLeadMediumFieldResolver` inspecciona describe y candidatos con label
“medio”, pero el sync orgánico tiene hoy el API Name literal.

### Otros consumidores y trazabilidad

- `salesforce_leads` alimenta Leads, Comercial mensual, Campañas, comisiones de
  Contact Center y auditorías. Cambiar columnas compartidas tiene radio amplio.
- `campaign_salesforce_leads` alimenta el attribution builder solo como fallback
  por rango y comandos de Campañas.
- Leads exporta bruto/resuelto/campo ganador. Campañas exporta valores adquiridos,
  resultado, IDs, método, campo y valor de match, ambigüedad y rule version.
- Calls y Opportunities persisten portal bruto, resuelto, source y debug/Lead ID,
  lo que permite comparar antes y después sin cambiar su universo.
- No se halló uso de estos campos en Stock. Las referencias “procedencia de
  compra” de vehículo pertenecen a otra semántica y quedan fuera de esta
  migración.

## Casos límite congelados o documentados

- `null`, vacío y whitespace no son candidatos válidos en resolvers/sync PHP.
- El canal solo reconoce Llamada tras trim, lowercase y eliminación de acentos;
  cualquier desconocido es Formulario.
- Los valores de portal se conservan recortados, sin normalizar mayúsculas ni
  aliases en `LeadPortalResolver`; los normalizadores posteriores sí pueden
  agruparlos.
- Campos legacy contradictorios: gana la prioridad específica del consumidor;
  no se detecta conflicto en Leads, Calls u Opportunities.
- Datos adquiridos parciales: cualquiera de los cinco campos introduce el Lead
  en el sync especializado, pero no garantiza candidatura de atribución.
- Lead convertido: se persisten flags e IDs; no cambia el criterio de sync por
  adquisición.
- Delegación desconocida conserva raw y queda Sin clasificar.
- La disponibilidad de campos opcionales en el sync mensual puede provocar
  reintento con un SELECT reducido. No valida campos candidatos nuevos.

## Riesgos

### CRÍTICO

- Colisión de nombres local/API en `fuente_origen` y `medio_origen`.
- Ampliar el OR de Campañas aumenta el universo, volumen, escrituras y conteos.
- Doble upsert no transaccional y last-write-wins sobre `salesforce_leads`, con
  posibilidad demostrada de reemplazar valores por `null`.
- Cambiar significado/prioridad de IDs puede reatribuir campañas históricas y
  ventas, alterando KPIs económicos sin cambiar Leads base.

### ALTO

- Prioridades divergentes entre dashboard, canalización legacy, Calls y
  Opportunities.
- Exposición tiene fallback de owner exclusivo y dependiente de RecordType.
- Añadir campos al SELECT puede fallar por API Name, permisos/FLS o tamaño de
  respuesta; los fallbacks actuales no son equivalentes entre servicios.
- Un backfill o reatribución masiva puede elevar tiempos, locks, deadlocks y
  escritura; debe diseñarse por lotes, reanudable y auditable.
- `fresh` borra antes de consultar y puede dejar un rango vacío tras fallo.

### MEDIO

- Alias, acentos, mayúsculas y valores desconocidos no se normalizan igual en
  todos los servicios.
- El builder elige una sola tabla por rango, por lo que una tabla general parcial
  puede ocultar Leads presentes solo en la especializada.
- Crecer SELECT, OR y volumen aumenta consumo de API, red, PHP y base de datos.

### BAJO

- Etiquetas de exports como “Medio origen” no explicitan que hoy es legacy;
  la traza técnica sí conserva el dato.
- Los API Names candidatos ausentes del código siguen sin verificar.

## Decisiones cerradas y validaciones técnicas pendientes

Dirección cerró la prioridad absoluta del campo nuevo informado, la validez
basada solo en null/vacío/whitespace, la resolución independiente, la prioridad
de delegación incluida Exposición, la política de conflictos y las cinco parejas
UTM. `utm_campaign__c` decide el nombre y `utm_id__c` permanece independiente.

Ya no son bloqueos de negocio. Siguen pendientes para fases posteriores la
semántica real de `utm_id__c` por plataforma, el análisis de casos Google/Meta,
la medición de Leads UTM-only fuera del universo legacy y el diseño/ejecución
controlada del backfill histórico.

## Pruebas de caracterización

- `LeadPortalResolverCharacterizationTest`: canal, normalización actual,
  prioridades, source, vacíos y fallback.
- `CampaignLeadSyncCharacterizationTest`: SELECT/WHERE legacy exactos, ausencia
  de candidatos nuevos, cinco formas de entrada, exclusión, whitespace,
  `dryRun`, fallback a rango y doble escritura parcial.
- `OpportunityPortalResolutionTest`: prioridad del Lead relacionado.
- `SalesforceCallSyncServiceTest`: SELECT auxiliar y prioridad del Lead cuando
  `Task.Portales__c` no clasifica.
- Cobertura previa reutilizada: normalización/prioridad de delegación, Exposición,
  sync mensual, attribution builder, Opportunities y Calls.

## Confirmación de no regresión de esta fase

- ¿Se modificó algún universo? NO.
- ¿Se modificó algún conteo? NO.
- ¿Se modificó alguna regla de negocio? NO.
- ¿Se modificó alguna prioridad de campos? NO.
- ¿Se integró algún campo Salesforce nuevo? NO.
- ¿Se modificó algún SOQL de producción para añadir campos nuevos? NO.
- ¿Se realizó algún backfill? NO.
- ¿Se modificó producción? NO.

## Implementación técnica de la fase 2 (2026-09-01)

### Metadata Lead validada en Salesforce (solo lectura)

Se utilizó `SalesforceClient::describe('Lead')` y una consulta `SELECT ... LIMIT
1` que solo informó del número de campos/filas, sin imprimir datos de Lead,
credenciales ni endpoints. Los 14 API Names existen y son consultables por la
integración actual.

| API Name | Label | Tipo | Longitud | Picklist | Nillable |
|---|---|---|---:|---|---|
| `Fuente_origen__c` | Fuente de Origen | string | 255 | Sí | Sí |
| `Medio_origen__c` | Medio de origen | string | 255 | Sí | Sí |
| `Canal__c` | Canal | string | 255 | Sí | Sí |
| `Delegacion_procedencia__c` | Delegación de Procedencia | string | 255 | Sí | Sí |
| `utm_campaign__c` | UTM Campaña | string | 70 | No | Sí |
| `utm_id__c` | UTM Id | string | 70 | No | Sí |
| `utm_source__c` | UTM Fuente | string | 70 | No | Sí |
| `utm_medium__c` | UTM Medio | string | 70 | No | Sí |
| `utm_content__c` | UTM Contenido | string | 70 | No | Sí |
| `Campa_a_Adquirida__c` | Campaña Adquirida | string | 255 | No | Sí |
| `Id_Adquirido__c` | Id Adquirido | string | 255 | No | Sí |
| `Fuente_Adquirida__c` | Fuente Adquirida | string | 255 | No | Sí |
| `Medio_Adquirido__c` | Medio Adquirido | string | 255 | No | Sí |
| `Contenido_Adquirido__c` | Contenido Adquirido | string | 255 | No | Sí |

Valores activos observados: Canal incluye `No identificado`, `Chat`, `Chatbot`,
`Email`, `Formulario`, `Llamada`, `Manual`, `Otro` y `Whatsapp`; Medio incluye
`No identificado`, `CPC`, `Orgánico` y `Referral`. Fuente y Delegación exponen
catálogos activos amplios. La aplicación conserva cualquier valor no vacío y no
replica esos catálogos como validación local.

### Mapeo local aditivo

Las columnas se añaden tanto a `salesforce_leads` como a
`campaign_salesforce_leads`, sin índices nuevos:

| Salesforce | Columna local | Semántica | Escritura autoritativa |
|---|---|---|---|
| `LEA_SEL_Fuente_Origen__c` | `fuente_origen` | Legacy, sin cambio | Sync general; tabla especializada de Campañas |
| `LEA_SEL_Medio_Origen__c` | `medio_origen` | Legacy, sin cambio | Sync general; tabla especializada de Campañas |
| `Fuente_origen__c` | `source_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Medio_origen__c` | `medium_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Canal__c` | `channel_new` | Nuevo raw | Sync general; tabla especializada |
| `Delegacion_procedencia__c` | `delegation_origin_new` | Nuevo raw | Sync general; tabla especializada |
| `Campa_a_Adquirida__c` | `campaign_acquired` | Legacy | Sync general y Campañas |
| `Id_Adquirido__c` | `acquired_id` | Legacy | Sync general y Campañas |
| `Fuente_Adquirida__c` | `acquired_source_legacy` | Legacy nuevo en almacenamiento | Sync general y Campañas |
| `Medio_Adquirido__c` | `acquired_medium_legacy` | Legacy nuevo en almacenamiento | Sync general y Campañas |
| `Contenido_Adquirido__c` | `content_acquired` | Legacy | Sync general y Campañas |
| `utm_campaign__c` | `utm_campaign_new` | Nuevo raw | Sync general y Campañas |
| `utm_id__c` | `utm_id_new` | Nuevo raw independiente | Sync general y Campañas |
| `utm_source__c` | `utm_source_new` | Nuevo raw | Sync general y Campañas |
| `utm_medium__c` | `utm_medium_new` | Nuevo raw | Sync general y Campañas |
| `utm_content__c` | `utm_content_new` | Nuevo raw | Sync general y Campañas |
| resolución estructurada | `field_resolution` | efectivo, campo, fallback, conflicto y raw por dimensión | Sync general; tabla especializada |

### Resolución y alcance funcional

`SalesforceLeadFieldResolver` aplica una única regla pura: trim vacío hace
fallback; cualquier otro valor nuevo gana. Compara los valores recortados para
marcar conflicto y conserva ambos raw. Fuente recibe como fallback el resultado
de `LeadPortalResolver`; canal recibe su canal legacy; delegación puede recibir
el resultado legacy contextual; medio y cada UTM usan su pareja aprobada.

En esta fase la resolución se materializa para auditoría, pero no sustituye los
campos resueltos consumidos por dashboards. No se migran Llamadas,
Reservas/Ventas, `CampaignAttributionBuilderService`, first-touch ni matching
Google/Meta. Por ello no cambian universos, clasificación visible ni conteos.

### Propiedad de datos y doble escritura

- `SalesforceMonthlyLeadsSyncService` es autoritativo sobre la fila general y
  puede reflejar valores informados, modificados o vaciados por Salesforce. Si
  falla el SELECT opcional, la query reducida omite todas las columnas nuevas y
  `field_resolution`, conservando lo ya almacenado.
- `CampaignLeadSyncService` es autoritativo sobre toda la fila de
  `campaign_salesforce_leads`. En una fila general existente solo puede aportar
  adquisición legacy y UTM no vacíos; no modifica identidad, contacto,
  conversión, procedencia, delegación, resolución general ni `raw_payload`.
- La lectura de filas generales existentes se hace una vez por chunk y los
  upserts continúan siendo masivos. Las dos escrituras del chunk comparten una
  transacción corta, abierta después de la consulta remota, y conservan los
  reintentos de deadlock. El insert de un Lead general ausente permanece
  soportado y es seguro frente a una carrera mediante upsert.

El WHERE de Campañas conserva exactamente sus cinco condiciones legacy. Los
nuevos campos solo crecen el SELECT; un Lead con UTM nuevos pero sin ninguno de
los cinco criterios legacy continúa fuera del universo.
