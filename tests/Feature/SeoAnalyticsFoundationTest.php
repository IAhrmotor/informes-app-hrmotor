<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoAnalyticsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_dashboard_remains_restricted_to_admin_and_director(): void
    {
        foreach ([ReportUser::ROLE_ADMIN, ReportUser::ROLE_DIRECTOR] as $role) {
            $this->withSession($this->sessionFor($this->user($role)))
                ->get('/informes/seo-analytics')
                ->assertOk();
        }

        foreach ([ReportUser::ROLE_MARKETING, ReportUser::ROLE_VIEWER] as $role) {
            $this->withSession($this->sessionFor($this->user($role)))
                ->get('/informes/seo-analytics')
                ->assertRedirect('/informes/leads');
        }
    }

    public function test_dashboard_reads_only_local_configuration_and_renders_neutral_source_readiness(): void
    {
        config([
            'services.google_search_console.client_id' => null,
            'services.google_search_console.client_secret' => null,
            'services.google_search_console.refresh_token' => null,
            'services.google_search_console.property' => null,
            'services.google_analytics.client_id' => null,
            'services.google_analytics.client_secret' => null,
            'services.google_analytics.refresh_token' => null,
            'services.google_analytics.property_id' => null,
            'services.sistrix.api_key' => null,
            'salesforce.client_id' => null,
            'salesforce.client_secret' => null,
        ]);
        Http::fake();

        $response = $this->get('/informes/seo-analytics')
            ->assertOk()
            ->assertSee('Search Console')
            ->assertSee('Salesforce')
            ->assertSee('Google Analytics 4')
            ->assertSee('SISTRIX AI Check')
            ->assertSee('Sin métricas analíticas en este lote')
            ->assertDontSee('report-ui-status', false)
            ->assertDontSee('report-ui-kpi-strip', false);

        $this->assertSame(4, substr_count($response->getContent(), 'class="report-ui-source-status"'));
        Http::assertNothingSent();
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'-'.str()->random(8).'@example.test',
            'password' => Hash::make('secret12'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
            'report_user_name' => $user->name,
        ];
    }
}
