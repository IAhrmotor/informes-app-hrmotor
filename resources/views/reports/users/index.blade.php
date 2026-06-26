<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion de usuarios | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')

    @vite(['resources/css/reports/leads-dashboard.css'])
</head>
@php
    $totalUsers = $users->count();
    $activeUsers = $users->where('is_active', true)->count();
    $adminUsers = $users->where('role', \App\Models\ReportUser::ROLE_ADMIN)->where('is_active', true)->count();
@endphp
<body class="campaigns-report report-users-page">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'users', 'currentAdminPage' => 'users'])

    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Gestion de usuarios</h1>
                <p class="sub">Alta, baja, activacion, contraseñas y asignacion de roles para el acceso a informes.</p>
            </div>
        </section>

        @include('reports.partials.admin-nav', ['currentAdminPage' => 'users'])

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="campaign-context-grid report-users-stats">
            <article class="card campaign-context-card">
                <span>Usuarios totales</span>
                <strong>{{ number_format($totalUsers, 0, ',', '.') }}</strong>
            </article>
            <article class="card campaign-context-card">
                <span>Usuarios activos</span>
                <strong>{{ number_format($activeUsers, 0, ',', '.') }}</strong>
            </article>
            <article class="card campaign-context-card">
                <span>Administradores activos</span>
                <strong>{{ number_format($adminUsers, 0, ',', '.') }}</strong>
            </article>
        </section>

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Crear usuario</h2>
                    <div class="small">La contraseña se guarda cifrada. El rol determina el acceso a informes y permisos internos.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('reports.users.store') }}" class="report-user-form-grid">
                @csrf
                <div class="filter-group">
                    <label for="name">Nombre</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name">
                </div>
                <div class="filter-group">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                </div>
                <div class="filter-group">
                    <label for="password">Contrasena</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required>
                </div>
                <div class="filter-group">
                    <label for="role">Rol</label>
                    <select id="role" name="role" required>
                        @foreach ($roleOptions as $roleValue => $roleLabel)
                            <option value="{{ $roleValue }}" @selected(old('role', \App\Models\ReportUser::ROLE_VIEWER) === $roleValue)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <label for="is_active">Estado</label>
                    <select id="is_active" name="is_active">
                        <option value="1" @selected(old('is_active', '1') === '1')>Activo</option>
                        <option value="0" @selected(old('is_active') === '0')>Inactivo</option>
                    </select>
                </div>
                <div class="filter-actions report-user-create-action">
                    <button type="submit" class="main-tab active">Crear usuario</button>
                </div>
            </form>
        </section>

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Usuarios configurados</h2>
                    <div class="small">Los administradores pueden editar datos, cambiar roles, activar o desactivar usuarios y eliminar accesos.</div>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Ultimo login</th>
                        <th>Creado</th>
                        <th class="num">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->name ?: 'Sin nombre' }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ \App\Models\ReportUser::roleLabel($user->role) }}</td>
                            <td>
                                <span @class(['type-pill', 'group' => $user->is_active, 'pending' => ! $user->is_active])>
                                    {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td>{{ optional($user->last_login_at)->format('d/m/Y H:i') ?: 'Nunca' }}</td>
                            <td>{{ optional($user->created_at)->format('d/m/Y H:i') ?: '-' }}</td>
                            <td class="num">
                                <div class="report-user-row-actions">
                                    <a href="{{ route('reports.users.edit', $user) }}" class="main-tab">Editar</a>
                                    <form method="POST" action="{{ route('reports.users.destroy', $user) }}" onsubmit="return confirm('Se eliminara el usuario {{ $user->email }}. Continuar?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="filter-reset">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">No hay usuarios configurados todavia.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
