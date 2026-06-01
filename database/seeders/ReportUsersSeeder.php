<?php

namespace Database\Seeders;

use App\Models\ReportUser;
use Illuminate\Database\Seeder;

class ReportUsersSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('REPORT_ADMIN_EMAIL');
        $password = env('REPORT_ADMIN_PASSWORD');
        $name = env('REPORT_ADMIN_NAME', 'Administrador informes');

        if (blank($email) || blank($password)) {
            $this->command?->warn('REPORT_ADMIN_EMAIL y REPORT_ADMIN_PASSWORD no estan configurados. No se ha creado admin de informes.');

            return;
        }

        ReportUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => ReportUser::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $this->command?->info("Usuario admin de informes disponible: {$email}");
    }
}
