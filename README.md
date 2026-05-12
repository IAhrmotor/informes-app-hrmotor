<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Informes Salesforce IA - Dashboard Comercial

## Contexto funcional

Este proyecto sustituye progresivamente el informe mensual comercial enviado por email/PDF por un front interactivo en Laravel.

El objetivo principal es que Dirección pueda consultar los datos comerciales de leads desde una pantalla visual con:

- KPIs generales.
- Evolución frente al periodo anterior.
- Portales.
- Delegaciones.
- Comerciales.
- Procedencia de leads.
- Llamadas frente a formularios.
- Conversión con Exposición y sin Exposición.
- Calidad de dato pendiente.
- Tablas filtrables.
- Gráficos.
- Comparativa entre periodos.

El informe completo debe vivir en el front. El email podrá mantenerse como aviso o resumen, pero no como fuente principal de consulta.

## Objetivo de la herramienta

La herramienta debe permitir analizar:

- Qué entra por cada portal.
- Qué parte es llamada y qué parte es formulario.
- Qué entra por cada delegación.
- Qué portales alimentan cada delegación.
- Qué comerciales gestionan mejor o peor.
- Conversión con y sin Exposición.
- Calidad de dato pendiente.
- Comparativas entre periodos.

## Pestañas previstas

1. Resumen Dirección.
2. KPIs clave.
3. Procedencia por portal.
4. Detalle de portal.
5. Delegaciones.
6. Comerciales.
7. Comparativa entre periodos.
8. Calidad de dato.

## Principio técnico

La lógica de negocio no debe vivir en Blade.

El proyecto se organiza en tres capas:

1. Datos brutos: leads importados desde Salesforce o fuente intermedia.
2. Datos normalizados: leads ya clasificados para reporting.
3. Presentación: dashboards, tablas, gráficos y filtros.

## Campos normalizados principales

Cada lead debe poder resolverse a:

- canal_direccion
- portal_original
- grupo_portal
- delegacion_real
- grupo_comercial
- es_exposicion
- convertido
- descartado
- potencial
- calidad_dato_estado
- calidad_dato_incidencia

## Reglas críticas

### Canal

Si `Medio_Nuevo__c = "Llamada"`:

- Canal = Llamada.

En cualquier otro caso:

- Canal = Formulario.

No se debe usar `LEA_SEL_Medio_Origen__c` como campo principal para detectar llamadas.

### Portal

Si el canal es Llamada:

- Portal = `Fuente_Nuevo__c`.

Si el canal es Formulario:

- Portal = campo de portal usado en el informe actual.
- Prioridad inicial: `Portal`, `LEA_SEL_Fuente_Origen__c`, `Fuente_Nuevo__c`.

### Exposición

Si `Portal = "Exposición"`:

- es_exposicion = true.

Todos los KPIs principales deben poder verse:

- Con Exposición.
- Sin Exposición.
- Solo Exposición.

## Stack inicial

- Laravel.
- Blade.
- Vite.
- CSS propio inicial.
- JavaScript sencillo.
- MariaDB/MySQL.
- PHPUnit para tests iniciales.

## Desarrollo local

Instalar dependencias:

```bash
composer install
npm install