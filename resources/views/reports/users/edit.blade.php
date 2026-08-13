<x-reports.app-shell title="Editar usuario" current-admin-page="users" body-class="campaigns-report report-users-page">
    <x-slot:head>
    @vite(['resources/css/reports/leads-dashboard.css'])
    </x-slot:head>
<div class="wrap">
    <main>
        <section class="header report-users-header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Editar usuario</h1>
                <p class="sub">Actualiza identidad, rol, estado y contraseña del acceso seleccionado.</p>
            </div>
            <div class="report-users-header-actions">
                <a href="{{ route('reports.users.index') }}" class="main-tab">Volver a usuarios</a>
            </div>
        </section>

        @if ($errors->any())
            <div class="notice">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>{{ $managedUser->email }}</h2>
                    <div class="small">Deja la contraseña vacia si no quieres cambiarla.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('reports.users.update', $managedUser) }}" class="report-user-edit-grid">
                @csrf
                @method('PUT')
                <div class="filter-group">
                    <label for="name">Nombre</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $managedUser->name) }}" autocomplete="name">
                </div>
                <div class="filter-group">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $managedUser->email) }}" autocomplete="email" required>
                </div>
                <div class="filter-group">
                    <label for="password">Nueva contrasena</label>
                    <input id="password" name="password" type="password" minlength="12" autocomplete="new-password">
                </div>
                <div class="filter-group">
                    <label for="role">Rol</label>
                    <select id="role" name="role" required>
                        @foreach ($roleOptions as $roleValue => $roleLabel)
                            <option value="{{ $roleValue }}" @selected(old('role', $managedUser->role) === $roleValue)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <label for="area_zone">Zona del Area Manager</label>
                    <select id="area_zone" name="area_zone">
                        <option value="">No aplica</option>
                        @foreach ($areaZoneOptions as $zoneValue => $zoneLabel)
                            <option value="{{ $zoneValue }}" @selected(old('area_zone', $managedUser->area_zone) === $zoneValue)>{{ $zoneLabel }}</option>
                        @endforeach
                    </select>
                    <small>Obligatoria para el rol Area Manager.</small>
                </div>
                <div class="filter-group">
                    <label for="is_active">Estado</label>
                    <select id="is_active" name="is_active">
                        <option value="1" @selected((string) old('is_active', $managedUser->is_active ? '1' : '0') === '1')>Activo</option>
                        <option value="0" @selected((string) old('is_active', $managedUser->is_active ? '1' : '0') === '0')>Inactivo</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Permisos independientes</label>
                    @foreach ($permissionOptions as $permission)
                        <label><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, old('permissions', $managedUser->permissions ?? []), true))> {{ $permission }}</label>
                    @endforeach
                </div>
                <div class="filter-group">
                    <label for="master_delegation_id">Delegación responsable</label>
                    <select id="master_delegation_id" name="master_delegation_id">
                        <option value="">No aplica</option>
                        @foreach($delegationOptions as $delegation)<option value="{{ $delegation->id }}" @selected((string) old('master_delegation_id', $managedUser->master_delegation_id) === (string) $delegation->id)>{{ $delegation->delegation_name }}</option>@endforeach
                    </select>
                    <small>Obligatoria para Responsable de delegación.</small>
                </div>
                <div class="filter-group">
                    <label for="salesforce_user_id">Salesforce User ID</label>
                    <input id="salesforce_user_id" name="salesforce_user_id" value="{{ old('salesforce_user_id', $managedUser->salesforce_user_id) }}" maxlength="18">
                    <small>Obligatorio para Comercial.</small>
                </div>
                <div class="filter-group">
                    <label>Ultimo login</label>
                    <input type="text" value="{{ optional($managedUser->last_login_at)->format('d/m/Y H:i') ?: 'Nunca' }}" disabled>
                </div>
                <div class="report-user-form-actions">
                    <button type="submit" class="main-tab active">Guardar cambios</button>
                    <a href="{{ route('reports.users.index') }}" class="filter-reset report-user-cancel-link">Cancelar</a>
                </div>
            </form>
        </section>
    </main>
</div>
</x-reports.app-shell>
