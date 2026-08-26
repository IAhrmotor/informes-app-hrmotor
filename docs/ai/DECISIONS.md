# Decisiones técnicas

## 2026-08-25 - Actividad mensual y hechos históricos verificables

La pestaña Rendimiento comercial se separa del dataset de cohorte existente. Un
hito pertenece al mes de su propia fecha y los ratios pueden superar el 100 %;
no se intenta vincular reservas/ventas al Lead del mismo mes ni crear scoring.

La separación de datasets no implica duplicar filtros: el bloque superior es la
única fuente DOM para zona, delegación y comercial. El modo de la pestaña decide
qué controles adicionales son visibles y si un cambio ejecuta el dataset legacy
o el mensual. Una selección ajena al nuevo universo se elimina antes de renderizar
y provoca una sola recarga correctiva.

La fecha de cancelación procede únicamente de una transición demostrable en
`OpportunityHistory`. `OpportunityFieldHistory` no aportó filas. Se conserva el
ID del historial, etapa previa, etapa nueva y timestamp; candidatos sin etapa
previa quedan no evaluables. `CloseDate` y `LastModifiedDate` se rechazan como
aproximaciones funcionales, aunque el segundo se usa para descubrir registros
antiguos modificados en la ingesta incremental.

`Delegacion_del_propietario__c` es una fórmula de la delegación actual del owner
y no acredita historia. Como tampoco existe `UserFieldHistory`, se adoptan
intervalos append-only observados desde la implantación. No se retrodata el
primer intervalo: actividad anterior queda `Histórico no certificable`, sin
media de equipo ni ranking. Esta pérdida de presentación es preferible a
reescribir historia laboral con datos actuales.

La unidad mensual se fija en Salesforce User ID. La delegación no forma parte de
la clave: cobertura incompleta o más de una delegación en el mes deshabilita
media/ranking, pero conserva una única fila y objetivo. Un intervalo abierto
certifica el corte transcurrido del mes actual; meses cerrados exigen cobertura
continua completa. El roster cero solo nace de esa cobertura demostrable.

El objetivo efectivo se materializa al primer acceso con `is_explicit=false`.
Esto congela el default vigente sin impedir una edición posterior auditada.
El cumplimiento agregado divide las reservas operativas incluidas por la suma
de objetivos de las filas comerciales incluidas. Las incidencias no son personas
comerciales y por ello tienen objetivo, cumplimiento, semáforo y ranking nulos.

Los identificadores de esquema largos no se dejan a la convención de Laravel:
la FK del actualizador se denomina `commercial_perf_target_updated_user_fk` y el
UNIQUE del historial `sf_opp_stage_history_uq`. Así se conserva la semántica y se
cumple el máximo de 64 caracteres de MySQL sin renombrar tablas o columnas.

Una consulta de `OpportunityHistory` solo acredita cobertura después de que
consultas y persistencia local terminan correctamente. Intervalos solapados se
unen al leer; cualquier hueco produce N/A, no cero. Las transiciones con reserva
posterior se conservan como `reservation_after_transition` para auditoría y no
cuentan. La captura de delegaciones pertenece únicamente al sync mensual y una
clave única `(salesforce_user_id, open_marker)` impide dos intervalos abiertos.
Para el mes actual el horizonte funcional termina en el último cutoff del sync,
no en `now()`. El sync captura el cutoff al iniciar, limita consultas e intervalos
a ese instante y la UI publica el corte persistido; el reloj no lo amplía.
Un intervalo con `opportunity_not_local` persiste la incidencia y se marca no
apto para KPI hasta que un backfill solucione la dependencia y el rango se repita.
Una candidata Cerrada Perdida sin etapa previa demostrable se persiste como
`previous_stage_not_demonstrated` y bloquea igualmente; permanecer en Cerrada
Perdida desde una fila anterior no es una transición y no bloquea.

Las dependencias visibles se derivan del estado actual de las transiciones, no
de sumar intervalos históricos solapados. Una reejecución que actualiza la
calidad y aporta cobertura certificada elimina la deuda ya resuelta sin ocultar
incidencias que continúen pendientes.

El filtro principal de usuarios sigue limitado al universo existente. Para
detectar una salida de perfil sin falsear `IsActive`, se refrescan por Salesforce
ID únicamente usuarios locales relevantes o con snapshot abierto. El servicio
de snapshots cierra el intervalo si el usuario está inactivo o ya no pertenece a
los perfiles comerciales; las reglas de Leads, Comisiones, Llamadas y Area
Manager no cambian.
Para reconstruir meses históricos, la identidad también se carga si existe un
snapshot solapado aunque el perfil actual sea no comercial. El snapshot acredita
el pasado; el perfil actual limita únicamente pertenencia presente y futura.
Leads, Opportunities, reservas, ventas y cancelaciones comparten esa misma
elegibilidad: un responsable no presente en el universo verificable se agrega a
incidencia y queda auditado como `non_commercial_responsible`, sin contaminar
objetivos ni comparaciones de equipo.

El sync diario de Opportunities se sitúa a las 07:10: queda después de Campañas,
refresco, Stock —que también escribe Opportunities— y el bloque SEO, evitando
una fotografía concurrente conocida sin alterar reglas de otros módulos.

## 2026-08-24 - Responsable financiero por dimension de zona

El responsable financiero no se deriva de `Opportunity.OwnerId`, porque ese ID
pertenece al comercial de la operacion. Se adopta una clave tecnica estable por
zona financiera y la delegacion normalizada como segundo nivel auditable. Esto
permite agregar distintos owners comerciales sin nombres humanos como clave y
mantiene una sola coleccion para resumen, delegaciones y trazabilidad.

Las reglas de Irene y Nuria se asocian a `zona_irene` y `zona_nuria` desde
2026-06 y sustituyen por completo los bloques estandar con comision neta por
`0.005`. El redondeo se reconcilia a centimos en el ultimo agregado de
delegacion para que su suma coincida con el total del responsable; las bases
economicas y la comision financiera nunca se ajustan. El ajuste distinto de cero
se expone en la fila y en el diagnostico administrativo.

Una zona financiera explicita desconocida nunca cae al mapping de delegacion. Si
contiene comision o descuento, el payload queda `ready=false` y las exportaciones
se bloquean; si no tiene impacto economico permanece excluida y auditable. Esta
politica evita asignaciones silenciosas sin ampliar el catalogo por aproximacion.

La sincronizacion puede reintentar una sola vez sin un campo opcional de Account
si la primera SOQL es rechazada. Un segundo fallo se propaga. Se prefiere este
fallback acotado frente a impedir la actualizacion de todos los campos
financieros por una dependencia no funcional del modulo.

## 2026-08-21 — Correo ejecutivo SEO congelado e idempotente

El resumen ejecutivo SEO se envía siempre a las 08:00 Europe/Madrid y consume
las evaluaciones versionadas existentes; no tiene scoring, thresholds ni motor
analítico propio. Los destinatarios se configuran en BD exclusivamente por
Administrador/Director, mientras transporte y secretos SMTP permanecen en el
entorno.

El contenido se congela por fecha antes del primer envío y cada destinatario
recibe un mensaje individual. Un ledger con unique fecha+destinatario y claim
atómico evita duplicados y permite reintentar solo fallos. Las entregas que
quedan `sending` no se reintentan automáticamente porque el SMTP pudo aceptar el
mensaje antes de que el proceso cayera. El fallo SMTP y la confirmación local se
tratan como fases separadas: después de un retorno correcto del transporte, una
confirmación local fallida o de cero filas permanece `sending` salvo que la BD
demuestre que ya está `sent`; nunca vuelve automáticamente a `failed`.

## 2026-08-11 — Hardening transversal y recuperabilidad

Se conserva Basic Auth de Comisiones por compatibilidad, pero las credenciales
se modelan en configuración de entorno como entradas con `integration`,
`credential_id`, `username`, `password` y revocación. Esto permite coexistencia
durante rotación, identificación auditable y rate limit por integración sin
almacenar secretos en base de datos.

Las alertas técnicas se representan mediante `operational_alerts`, deduplicadas
por fingerprint y accesibles solo a Administrador. El estado de Stock existente
se conserva como entidad de dominio, pero deja de generar email y escrituras en
Salesforce. Los fallos del scheduler abren alertas y los éxitos posteriores las
resuelven.

La retención pone a `NULL` únicamente los ocho `raw_payload` sin lectores
funcionales, en vez de borrar la fila normalizada. Cinco payloads aún leídos por
informes/reconstrucciones quedan bloqueados hasta materializar esas dependencias.
Las ejecuciones y alertas resueltas sí se borran por chunks. Snapshots, cierres,
atribuciones e historiales quedan fuera. La migración añade índices exactamente
para estas consultas; por su coste DDL se despliega en ventana controlada y no
se considera reversible cuando `operational_alerts` ya contenga datos.

El rollback operativo preferido es código compatible o forward fix. Se prohíbe
documentar `migrate:rollback --step=N` como mecanismo genérico.

## 2026-08-06 — Cohorte única y auditoría minimizada

Se adopta una única resolución de cohorte por informe para alimentar KPI y
auditoría de Reservas/Ventas. Los decoradores de auditoría pueden enriquecer la
misma fila, pero no aplicar filtros funcionales adicionales.

La exportación estándar se considera un artefacto de conciliación, no una
extracción CRM: se retiran `Opportunity.Name`, `Account.Name` y datos de
contacto porque no son necesarios para reconstruir KPI o duplicados. Cualquier
consumidor futuro de PII requerirá un flujo distinto y aprobación explícita.

Para Llamadas, los valores estructurados se representan mediante JSON explícito
y la cardinalidad se define por `Task.Id`, incluidas las Tasks excluidas de KPI.

## 2026-08-07 — Tipo Venta y contenido descriptivo

La clave funcional Venta agrupa Venta, Venta con cambio, Lead y Ayvens. Como el
tipo normalizado está materializado en `salesforce_leads`, la modificación se
acompaña de un comando de reproceso idempotente y con modo simulación; no se
reconstruyen automáticamente los snapshots de Campañas.

Reservas/Ventas no utiliza IA ni un contrato de recomendaciones. Sus
comparativas y porcentajes se limitan a información descriptiva de cohortes.

## 2026-08-07 — Clasificación operativa de Llamadas

La exclusión de `Pruebas comunidad comercial` se materializa con el perfil
Salesforce verificable de la identidad operativa y el motivo estable
`excluded_test_profile`; `missing_call_object` conserva prioridad. La regla de
duración 5/10 se centraliza en `CallClassificationRules` y la ausencia de equipo
se representa como `unassigned`, sin convertir equipos en geografía.
## 2026-08-07 — Cierre mensual de inversión de Campañas

El cierre congela exclusivamente inversión agregada por campaña y conserva un
snapshot versionado. Reabrir exige motivo sin borrar snapshots. Las atribuciones
ambiguas no se degradan a Salesforce-only: se auditan y no entran en KPI.
## 2026-08-07 — Métricas Salesforce-only de Campañas

Salesforce-only puede contribuir Leads, Oportunidades y su ratio derivado. Las
métricas de resultados comerciales, importes y economía se consideran no
aplicables y no se agregan en KPIs de Campañas.
## 2026-08-07 — Simulación no persistente de atribución de Campañas

El dry-run usa `CampaignAttributionBuilderService`, no un motor paralelo. No
borra, inserta, actualiza ni invalida caché; exige conciliación de particiones
y unicidad de atribución KPI antes de permitir aprobar una reconstrucción.

## 2026-08-13 - Shell comun y modulos estrategicos fijos

Resumen y SEO y Analytics se modelan separadamente de los seis informes con
minimo configurable. Su autorizacion es fija en servidor para Administrador y
Director; las filas historicas de `report_access_settings` no participan en
esa decision y la pantalla de permisos no los edita. `/informes` renderiza el
Resumen para esos dos roles y conserva la redireccion al primer informe
operativo autorizado para el resto.

La estructura exterior se implementa como componente Blade anonimo, CSS y
JavaScript propios, sin introducir un framework frontend. La preferencia de
sidebar es local al navegador y no forma parte del modelo de usuario. Esta
separacion permite incorporar paginas futuras aportando solo metadatos, assets
y contenido, sin duplicar autenticacion, branding o navegacion.

## 2026-08-14 - Design System namespaced y migracion progresiva

Los nuevos contratos visuales usan tokens `--report-ui-*`, clases
`report-ui-*` y componentes Blade presentacionales. No se modifican selectores
legacy de forma transversal: cada dashboard se migrara en un lote separado con
validacion funcional y visual. Resumen y SEO/Analytics validan la capa comun sin
datos ficticios. Los estados analiticos tienen cinco claves cerradas y cualquier
valor desconocido se representa como `not-evaluable`. Superficies y controles
usan radios discretos; los pills se reservan a badges, estados y chips con esa
semantica. Esta politica no se aplica globalmente al CSS de dashboards legacy,
que se migraran progresivamente. Los KPI analiticos se agrupan preferentemente
en strips continuos, las tabs usan texto y linea activa, y las tablas densas son
el patron principal para datos detallados. Referencias externas solo orientan
estructura y densidad; colores, marca e identidad permanecen HR Motor.

## 2026-08-17 - Readiness e ingesta SEO desacoplados del render

La pantalla SEO solo interpreta configuración local y no llama proveedores. La
verificación real y la ingesta son procesos CLI/scheduler read-only y
sanitizados. Search
Console y GA4 comparten únicamente el flujo técnico OAuth; sus credenciales,
scopes y properties son independientes y no reutilizan Google Ads. Salesforce
Lead orgánico y GA4 Key Events conservan cardinalidades separadas.

Los agregados diarios exactos de Search Console se almacenan separados de sus
rankings top, porque estos últimos no son exhaustivos y no pueden alimentar KPI
ni ceros. SEO preserva la semántica legacy
`LEA_SEL_Medio_Origen__c -> salesforce_leads.medio_origen`: su métrica orgánica
vive en una proyección propia basada en `Medio_origen__c = 'Orgánico'`. Cada
fuente conserva cutoff y timezone. El dataset publica únicamente cutoffs de
ejecuciones completadas y solo habilita KPI con cobertura diaria local completa;
el resumen conjunto usa el menor cutoff y los rankings conservan el periodo
propio de Search Console.

GA4 define Conversiones web orgánicas exclusivamente como `keyEvents` con
`defaultChannelGroup = Organic Search` y `platform = web`; España usa
`countryId = ES`. Conserva crédito decimal y permanece separado de Lead orgánico
Salesforce, sin suma ni deduplicación. Su cutoff es operativo, basado en timezone
de property y lag configurable, y se refresca mediante ventana móvil: no se
declara final como Search Console.

## 2026-08-18 - Salud técnica SEO acotada y factual

Salud técnica no es un crawler: monitoriza únicamente Home, URLs estratégicas
configuradas y páginas del ranking local Search Console. Sitemap se utiliza para
infraestructura y membership de esos candidatos, nunca para rastrear todo el
sitio. No se infieren URLs de Stock mientras no exista un mapping público
verificable.

Las comprobaciones HTTP persisten hechos técnicos (respuesta, redirects,
noindex, canonical y sitemap). La clasificación de severidad y las alertas
analíticas pertenecen a un motor posterior. Todo fetch pasa por allowlist exacta
de hosts, validación DNS pública y redirects manuales; el dashboard nunca accede
a la red.

## 2026-08-19 - Comparación factual transversal mediante snapshots

Se adopta un motor transversal sin Eloquent ni conocimiento de SEO. La versión
`same_weekday_v1` compara el día cerrado con D-7/D-14/D-21/D-28 exactos, exige
3/4 referencias y conserva D-364 como dato opcional. Cada métrica usa el cutoff
cerrado de su propia fuente; una ausencia nunca se convierte en cero y una
referencia cero nunca produce una variación infinita.

Los resultados se materializan en snapshots idempotentes separados de scoring,
thresholds y severidad. SEO es el primer adaptador y aísla properties mediante
identidades técnicas no secretas y SHA-256. El rolling rebuild no borra
históricos. No se aplica pruning a snapshots hasta aprobar una retención
específica, ni se extiende esta decisión a CSS o lógica de dashboards legacy.

## 2026-08-21 - Evaluaciones analíticas separadas y versionadas

Los snapshots factuales no incorporan estado ni severidad. La interpretación se
persiste aparte, vinculada a una versión inmutable de reglas. SEO inicia este
contrato con 10/20/35 % más puertas de materialidad para volúmenes; CTR y
Posición se evalúan por unidades absolutas. Las mejoras materiales se señalan
como oportunidad favorable, nunca automáticamente como Crítico.

Administrador y Director pueden crear una nueva versión desde la pantalla SEO,
con motivo, actor y protección frente a edición concurrente. No se actualiza ni
reactiva una versión histórica y un cambio no reinterpreta automáticamente el
histórico: solo reevalúa los snapshots actuales. El ID del actor queda como
referencia histórica sin FK para respetar la eliminación física de usuarios.

Los snapshots son proyecciones rolling y pueden revisarse mediante upsert. Por
ello cada evaluación captura los inputs factuales usados y su SHA-256 canónico.
La UI solo considera vigente una evaluación cuyo fingerprint coincide con la
proyección actual; mientras no coincida muestra un estado pendiente neutral. El
histórico presenta los valores capturados por la evaluación, evitando mezclar
hechos revisados con una clasificación anterior. D-364 y timestamps se excluyen
del hash porque no alteran la regla v1.
