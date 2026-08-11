# Informe de Comisiones

Actualizado: 2026-08-10.

## Mes compartido

`CommissionMonthResolver` fija un único mes para:

- Comerciales;
- Delegaciones;
- Call Center;
- Contact Center;
- Área Manager;
- Financieros;
- cabecera, textos, detalles y exportaciones.

El mes solicitado se conserva en URL y selector. El mes actual puede consultarse
y siempre se muestra como `Provisional`; ninguna pestaña cambia por su cuenta al
último mes cerrado.

## Cierre económico por bloque

Estados persistentes:

- `provisional`;
- `pending_approval`;
- `definitive`;
- `reopened`.

Solo pueden cerrarse `Comerciales`, `Delegaciones` y `Área Manager`. Cada cierre
tiene estado, aprobación, reapertura, eventos y versión de snapshot propios por
combinación `month + closure_scope`. Call Center, Contact Center y Financieros
siguen siendo operativos/provisionales y no se incluyen como requisito ni como
payload de los cierres económicos.

Un bloque solo puede pasar a definitivo cuando ha terminado el mes natural, están confirmados sus componentes y aprueba Dirección o Administrador/IT.

El cierre conserva mes, usuario y fecha de aprobación, corte, versión de
fórmulas, incidencias, usuario/fecha/motivo de reapertura y eventos de auditoría.
El snapshot reproducible contiene únicamente el universo del bloque: Comerciales
incluye su auditoría de reseñas; Delegaciones sus filas; Área Manager incluye las
variantes necesarias por zona. Los registros legacy existentes se conservan con
scope `legacy`; no se convierten automáticamente en cierres de los tres bloques.

Una modificación posterior de Salesforce no sobrescribe un cierre definitivo.
Por defecto se registra en el libro de ajustes del siguiente mes abierto. Para
recalcular el mes original hay que reabrirlo manualmente con motivo obligatorio.
El ajuste conserva operación, mes original, mes de aplicación, importe, motivo,
usuario, fecha y estado.

## Universos por pestaña

Las pestañas pueden tener universos diferentes. La reconciliación sigue:

```text
base común - exclusiones de la pestaña + inclusiones especiales = total mostrado
```

Los IDs y motivos permanecen en detalles y exportaciones. El cuadro interno de
conciliación del universo solo se muestra a Administrador/IT.

Resumen funcional:

- Comerciales: operaciones elegibles por Salesforce User ID y reglas económicas
  mensuales.
- Delegaciones: entregas y rentabilidad según sus reglas de tienda.
- Call Center: ventas/compras/cambios, German y Facilitea según captador y
  configuración.
- Contact Center: citas y resultados con su ventana funcional vigente.
- Área Manager: ámbito de zona y delegaciones normalizadas.
- Financieros: operaciones y productos financieros del mes seleccionado.

La identidad principal de una persona es Salesforce User ID; el nombre es solo
una etiqueta y no debe usarse para unir registros.

## Reseñas

La fórmula actual no se ha cambiado:

- numerador: reseñas creadas en el mes, atribuidas por `OwnerId`;
- denominador: operaciones elegibles del responsable en ese mes.

El ratio puede superar el 100 % porque los universos no son uno-a-uno y puede
haber varias reseñas por operación. La pantalla lo explica en `Cómo se calcula`
y `/informes/comisiones-comerciales/export/reviews-audit.csv` permite auditar
cada reseña y su procedencia.

Cambiar a una reseña máxima por Opportunity exigiría una nueva decisión
funcional; no se aplica implícitamente.

### Seguridad del endpoint interno de reseñas

`INTERNAL_REVIEWS_ENDPOINT`, `INTERNAL_REVIEWS_USER` e
`INTERNAL_REVIEWS_PASSWORD` se proporcionan exclusivamente mediante variables
de entorno y no tienen valores fallback. Si falta cualquiera de ellas, el
servicio queda `not_configured`, no construye una cabecera Basic Auth y mantiene
el cero previsto por la lógica funcional vigente. Los fallos de transporte o
remotos conservan un estado técnico separado sin incluir credenciales, cabeceras
ni cuerpos de respuesta en logs o excepciones.

Una incidencia `not_configured`, de transporte o remota deja reseñas en cero y
rating en nulo donde procede, sin romper el informe ni bloquear por sí sola el
cierre de un bloque. Debe revisarse mediante los avisos administrativos del
informe y los logs técnicos sanitizados.

## Excepciones personales

Las excepciones económicas personales se resuelven exclusivamente mediante el
Salesforce User ID materializado como `owner_id` de la Opportunity. Desde
`2026-06`, los IDs configurados de Nuria e Irene reciben el 0,50 % de la suma de
comisión neta, sustituyendo completamente los bloques 1, 2 y 3. Los nombres son
solo etiquetas visuales: ni el nombre, ni la zona, ni el email intervienen en la
decisión.

La regla histórica atribuida a Oscar del 40 % no aparece en la especificación
económica vigente; permanece desactivada hasta una aprobación funcional expresa
y no existe ninguna fila sintética basada en su nombre. Financieros es un bloque
operativo, por lo que no participa en los snapshots de cierre de Comerciales,
Delegaciones o Área Manager.

## Roles y datos sensibles

- Dirección y Administrador/IT: acceso completo al informe; ambos gestionan
  cierres.
- Área Manager: únicamente su zona.
- Responsable de delegación: únicamente su delegación; la delegación es
  obligatoria al asignar el rol.
- Financiero: pestaña Financieros y datos permitidos.
- Comercial: únicamente su Salesforce User ID.
- Auditor de comisiones: informe de Comisiones, búsqueda autorizada de managers
  y carga de Penalizaciones financieras.

El Auditor de comisiones no recibe administración de usuarios, configuración de
fórmulas ni cierres. Márgenes, penalizaciones y comisiones globales se filtran en
servidor; ocultar una pestaña en Blade no sustituye la autorización de endpoint,
exportación, fila y columna.

## Penalizaciones financieras

Ruta: `/informes/penalizaciones-financiacion`.

Las cargas XLSX se conservan como historial. Una carga nueva sustituye las filas
activas de los meses incluidos sin volver a sumar las versiones anteriores. Los
registros sin match con Salesforce se muestran para revisión y no se aplican de
forma silenciosa.

## Operación y auditoría

- Dashboard: `/informes/comisiones-comerciales`.
- XLSX: `/informes/comisiones-comerciales/export/comisiones.xlsx`.
- CSV entregas: `/informes/comisiones-comerciales/export/delegation-deliveries.csv`.
- CSV sin captador: `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`.
- CSV reseñas: `/informes/comisiones-comerciales/export/reviews-audit.csv`.
- Configuración: `/informes/configuracion-comisiones`.
- Penalizaciones: `/informes/penalizaciones-financiacion`.

```bash
php artisan salesforce:sync-opportunities --all-history
php artisan salesforce:sync-commercial-reviews --all
php artisan salesforce:sync-tasaciones --all
```

Los reprocesados de datos operativos no modifican snapshots definitivos salvo
reapertura autorizada.

Archivos principales:

- `app/Services/Reports/CommercialCommissions/CommissionMonthResolver.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionClosureService.php`;
- `app/Services/Reports/CommercialCommissions/AreaRestrictedCommissionScope.php`;
- servicios de Call Center, Contact Center, Área Manager y Financieros.
