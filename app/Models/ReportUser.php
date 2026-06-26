<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class ReportUser extends Model
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_DIRECTOR = 'director';
    public const ROLE_AREA_MANAGER = 'area_manager';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_WEIGHTS = [
        self::ROLE_VIEWER => 10,
        self::ROLE_AREA_MANAGER => 20,
        self::ROLE_DIRECTOR => 30,
        self::ROLE_ADMIN => 40,
    ];
    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrador',
        self::ROLE_DIRECTOR => 'Direccion',
        self::ROLE_AREA_MANAGER => 'Area Manager',
        self::ROLE_VIEWER => 'Viewer',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = str_starts_with($value, '$2y$') || str_starts_with($value, '$argon')
            ? $value
            : Hash::make($value);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDirector(): bool
    {
        return $this->role === self::ROLE_DIRECTOR;
    }

    public function isAreaManager(): bool
    {
        return $this->role === self::ROLE_AREA_MANAGER;
    }

    public static function availableRoles(): array
    {
        return array_keys(self::ROLE_LABELS);
    }

    public static function roleOptions(): array
    {
        return self::ROLE_LABELS;
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role ?? ''] ?? (string) $role;
    }

    public static function roleWeight(?string $role): int
    {
        return self::ROLE_WEIGHTS[$role ?? ''] ?? 0;
    }
}
