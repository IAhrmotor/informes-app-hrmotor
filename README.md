# Informes HR Motor

Aplicación Laravel de inteligencia de negocio para Leads, Reservas/Ventas,
Llamadas, Campañas, Comisiones y Stock. Consolida fotografías locales de
Salesforce, Google Ads y Meta Ads; los dashboards no dependen de consultas en
vivo a Salesforce durante el render.

## Estado funcional

- Leads normaliza tipos y portales de forma centralizada, separa delegación del
  Lead y del comercial, muestra calidad comercial y excluye eliminados/fusionados
  de KPIs activos sin perder auditoría.
- Reservas/Ventas usa el selector de fecha como cohorte común y deduplica una
  reserva o firma por vehículo/fecha, mostrando conflictos por Opportunity ID.
- Llamadas limita el universo a Tasks con `CallObject`, versiona la
  clasificación, conserva historial y solo permite reprocesados manuales con
  período y simulación o motivo.
- Campañas separa pago, Salesforce-only, pruebas, pendientes, ambiguos y sin
  atribuir. First touch no duplica entidades y las pruebas solo se excluyen por
  clasificación persistida por ID.
- Comisiones comparte mes entre seis pestañas y exports. El mes actual es
  provisional; los definitivos conservan snapshot, corte, fórmulas, aprobación,
  reapertura y ajustes.
- Stock evalúa todo el Disponible, usa Disponible/Reservado/Bloqueado para
  capacidad, presenta Top 3 teórico y genera un plan conjunto sin sobreasignar
  plazas ni crear órdenes logísticas.

Las decisiones todavía abiertas están en
[docs/decisiones-negocio-pendientes.md](docs/decisiones-negocio-pendientes.md).

## Documentación

- General y contraste Salesforce:
  [docs/Documentacion_general_informes_y_contraste_salesforce.md](docs/Documentacion_general_informes_y_contraste_salesforce.md)
- Handoff técnico: [docs/HANDOFF.md](docs/HANDOFF.md)
- Leads: [docs/reglas-negocio-leads.md](docs/reglas-negocio-leads.md)
- Reservas/Ventas: [docs/informe-reservas-ventas.md](docs/informe-reservas-ventas.md)
- Llamadas: [docs/informe-llamadas.md](docs/informe-llamadas.md)
- Campañas: [docs/informe-campanas.md](docs/informe-campanas.md)
- Comisiones: [docs/informe-comisiones.md](docs/informe-comisiones.md)
- Stock: [docs/informe-stock.md](docs/informe-stock.md)
- Despliegue y rollback:
  [docs/despliegue-auditoria-2026-08-05.md](docs/despliegue-auditoria-2026-08-05.md)

## Arquitectura

```text
Salesforce / Google Ads / Meta Ads / ficheros autorizados
  -> comandos y servicios de sincronización
  -> tablas locales y snapshots
  -> normalización, clasificación y atribución
  -> servicios de dataset
  -> controladores JSON/exports y Blade/JavaScript
```

Principios:

- reglas funcionales centralizadas y reutilizadas por sync, dataset, filtros y
  exports;
- límites de período superiores exclusivos salvo que el comando documente lo
  contrario;
- auditoría por ID y motivo, sin ajustes manuales para cuadrar agregados;
- filtros y permisos aplicados en servidor;
- cierres económicos congelados, separados de informes operativos mutables;
- consultas por lotes, selección de columnas, caché y paginación para evitar
  N+1 y cargas completas innecesarias.

## Roles

| Rol | Acceso funcional actual |
|---|---|
| Administrador/IT | Acceso completo, configuración, conciliaciones internas y administración. |
| Dirección | Informes autorizados, exports y auditorías; puede aprobar/reabrir cierres. |
| Área Manager | Leads, Reservas/Ventas, Llamadas y Comisiones, limitado a su zona. |
| Responsable de delegación | Leads, Llamadas y Comisiones, limitado a su delegación; asignarla es obligatorio. |
| Marketing | Leads y Campañas. |
| Financiero | Comisiones, limitado a la vista financiera autorizada. |
| Comercial | Leads, Llamadas y Comisiones, limitado a su Salesforce User ID. |
| Auditor de comisiones | Comisiones y carga de Penalizaciones financieras; sin usuarios, fórmulas ni cierres. |

Los mínimos configurables de cada informe se almacenan en
`report_access_settings`. Los roles funcionales explícitos y sus ámbitos no se
amplían por cambiar un mínimo visual.

## Desarrollo local

Requisitos: PHP 8.4, Composer, Node.js/npm y una base de datos compatible con la
configuración Laravel del entorno.

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan serve
```

Las credenciales y secretos se configuran únicamente en `.env`. No deben
guardarse en documentación, commits, logs ni respuestas JSON.

## Scheduler

El servidor debe ejecutar `php artisan schedule:run` cada minuto. Laravel
coordina las ventanas móviles y `withoutOverlapping`; no hay que editar fechas
del cron diariamente.

Programación principal (`Europe/Madrid`):

- Leads incremental: cada 15 minutos, `--days=2`.
- Tasaciones: 01:00, `--days=120`.
- Meta Ads: 01:30, `--days=120`.
- Google Ads: 01:45, `--days=120`.
- Atribución de campañas: 02:15, `--days=120`.
- Snapshot de campañas: 03:15.
- Stock: 03:30.

## Despliegue

No ejecutar desde herramientas de desarrollo contra producción sin el proceso de
cambio aprobado. Secuencia base:

```bash
php artisan down
php artisan migrate --force
php artisan optimize:clear
npm ci
npm run build
php artisan up
```

Los backfills y reprocesados no forman parte automáticamente del despliegue.
Deben ejecutarse con rango explícito, simulación cuando exista, export previo y
conciliación por ID. Consultar la guía de despliegue antes de usar comandos de
Llamadas, Campañas o Stock.

## Verificación

```bash
php artisan test
npm run build
```

Última verificación de código registrada antes de esta actualización documental:
393 pruebas y 2.667 aserciones correctas; las regresiones finales específicas de
permisos y Reservas/Ventas también fueron correctas.

## Seguridad

- Autenticación configurable mediante `INFORMES_AUTH_ENABLED`.
- CSRF obligatorio en mutaciones web.
- Autorización de pantallas, JSON, exports, filas y columnas sensibles.
- Principio de mínimo privilegio y ámbitos estables: Salesforce User ID,
  delegación normalizada y zona configurada.
- Prohibido incluir tokens de Meta/Google/Salesforce, contraseñas, PII o datos
  financieros reales en documentación y mensajes de error.
