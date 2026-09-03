<?php

namespace Tests\Feature;

use App\Models\CampaignSalesforceLead;
use App\Models\SalesforceLead;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignAttributionFieldMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_utm_dimensions_win_independently_and_expose_the_real_matching_field(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-new-utm',
            'fuente_origen' => 'Legacy lead source',
            'medio_origen' => 'Legacy lead medium',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_id' => 'legacy-id',
            'content_acquired' => 'legacy-content',
            'acquired_source_legacy' => 'Legacy acquired source',
            'acquired_medium_legacy' => 'Legacy acquired medium',
            'utm_campaign_new' => 'New campaign',
            'utm_id_new' => 'new-id',
            'utm_source_new' => 'New acquired source',
            'utm_medium_new' => 'New acquired medium',
            'utm_content_new' => 'new-content',
            'delegation_origin_new' => 'Alcobendas',
        ]);
        $this->createMetric('metric-new-campaign', 'campaign-new', 'New campaign');

        $stats = $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-new-utm',
            'campaign_id' => 'campaign-new',
            'campaign_acquired' => 'New campaign',
            'acquired_id' => 'new-id',
            'source_acquired' => 'New acquired source',
            'medium_acquired' => 'New acquired medium',
            'content_acquired' => 'new-content',
            'matched_source_field' => 'utm_campaign__c',
            'attribution_rule_version' => '2026-09-03.1',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-new-utm',
            'lead_delegation' => 'Alcobendas',
        ]);
        $this->assertSame(1, data_get($stats, 'field_resolution_sources.utm_campaign.utm_campaign__c'));
        $this->assertSame(1, data_get($stats, 'field_resolution_sources.utm_source.utm_source__c'));
    }

    public function test_blank_new_values_use_each_legacy_fallback(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-blank-utm',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_id' => 'legacy-id',
            'content_acquired' => 'legacy-content',
            'acquired_source_legacy' => 'Legacy acquired source',
            'acquired_medium_legacy' => 'Legacy acquired medium',
            'utm_campaign_new' => " \t ",
            'utm_id_new' => '',
            'utm_source_new' => null,
            'utm_medium_new' => '   ',
            'utm_content_new' => "\n",
        ]);
        $this->createMetric('metric-legacy-campaign', 'campaign-legacy', 'Legacy campaign');

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-blank-utm',
            'campaign_id' => 'campaign-legacy',
            'campaign_acquired' => 'Legacy campaign',
            'acquired_id' => 'legacy-id',
            'source_acquired' => 'Legacy acquired source',
            'medium_acquired' => 'Legacy acquired medium',
            'content_acquired' => 'legacy-content',
            'matched_source_field' => 'Campa_a_Adquirida__c',
        ]);
    }

    public function test_non_blank_placeholder_blocks_a_more_informative_legacy_campaign(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-placeholder',
            'campaign_acquired' => 'Legacy campaign',
            'utm_campaign_new' => 'Sin clasificar',
        ]);
        $this->createMetric('metric-placeholder-legacy', 'campaign-legacy', 'Legacy campaign');

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-placeholder',
            'platform' => 'salesforce',
            'campaign_name' => 'Sin clasificar',
            'matched_source_field' => 'utm_campaign__c',
        ]);
        $this->assertDatabaseMissing('campaign_attributions', [
            'lead_id' => '00Q-placeholder',
            'campaign_id' => 'campaign-legacy',
        ]);
    }

    public function test_unmatched_new_id_does_not_reactivate_legacy_id_but_an_independent_content_can_match(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-independent-fields',
            'campaign_acquired' => 'Legacy gate',
            'acquired_id' => 'legacy-matching-id',
            'content_acquired' => 'legacy-content',
            'utm_id_new' => 'new-unmatched-id',
            'utm_content_new' => 'new-matching-content',
        ]);
        $this->createMetric('metric-legacy-id', 'legacy-id-campaign', 'Legacy ID campaign');
        DB::table('campaign_platform_daily_metrics')->where('unique_key', 'metric-legacy-id')->update([
            'campaign_id' => 'legacy-matching-id',
        ]);
        $this->createMetric('metric-new-content', 'content-campaign', 'Content campaign', 'new-matching-content');

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-independent-fields',
            'campaign_id' => 'content-campaign',
            'acquired_id' => 'new-unmatched-id',
            'content_acquired' => 'new-matching-content',
            'matched_source_field' => 'utm_content__c',
        ]);
    }

    public function test_new_utm_id_matches_in_the_legacy_position_and_traces_its_api_name(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-new-id-match',
            'campaign_acquired' => 'Legacy gate',
            'acquired_id' => 'legacy-id',
            'utm_id_new' => 'new-campaign-id',
        ]);
        $this->createMetric('metric-new-id', 'new-campaign-id', 'Campaign by new ID');

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-new-id-match',
            'campaign_id' => 'new-campaign-id',
            'attribution_method' => 'campaign_id_match',
            'matched_source_field' => 'utm_id__c',
        ]);
    }

    public function test_new_fields_alone_do_not_expand_the_legacy_campaign_universe(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-utm-only',
            'campaign_acquired' => null,
            'utm_campaign_new' => 'New only campaign',
            'utm_id_new' => 'new-only-id',
            'utm_source_new' => 'New only source',
            'utm_medium_new' => 'New only medium',
            'utm_content_new' => 'new-only-content',
        ]);

        $stats = $this->build();

        $this->assertSame(0, $stats['candidate_leads']);
        $this->assertSame(1, $stats['discarded_invalid_values']);
        $this->assertDatabaseMissing('campaign_attributions', ['lead_id' => '00Q-utm-only']);
    }

    public function test_exact_exclusions_are_evaluated_with_the_effective_campaign(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-effective-exclusion',
            'campaign_acquired' => 'Legacy campaign',
            'utm_campaign_new' => 'tasador',
        ]);

        $stats = $this->build();

        $this->assertSame(1, $stats['excluded_campaigns']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-effective-exclusion',
            'platform' => 'excluded',
            'campaign_acquired' => 'tasador',
            'matched_source_field' => 'utm_campaign__c',
        ]);
    }

    public function test_meta_direct_form_keeps_legacy_admission_but_uses_effective_source_origin(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-meta-fallback',
            'campaign_acquired' => null,
            'portal_text' => 'Meta',
            'fuente_origen' => 'Facebook',
            'source_origin_new' => '   ',
        ]);
        $this->createLead([
            'salesforce_id' => '00Q-meta-new-source',
            'campaign_acquired' => null,
            'portal_text' => 'Meta',
            'fuente_origen' => 'Facebook',
            'source_origin_new' => 'Google',
        ]);
        $this->createLead([
            'salesforce_id' => '00Q-meta-new-only',
            'campaign_acquired' => null,
            'portal_text' => 'Meta',
            'fuente_origen' => 'Google',
            'source_origin_new' => 'Facebook',
        ]);

        $stats = $this->build();

        $this->assertSame(2, $stats['candidate_leads']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-meta-fallback',
            'campaign_id' => 'meta_instantforms_direct_form',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-meta-new-source',
            'platform' => 'salesforce',
            'campaign_name' => null,
        ]);
        $this->assertDatabaseMissing('campaign_attributions', ['lead_id' => '00Q-meta-new-only']);
    }

    public function test_raw_payload_hydrates_only_blank_local_fields_and_preserves_placeholders(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-raw-payload',
            'campaign_acquired' => 'Legacy gate',
            'utm_campaign_new' => '   ',
            'utm_source_new' => 'Sin informar',
            'raw_payload' => [
                'utm_campaign__c' => 'Campaign from raw',
                'utm_source__c' => 'Source from raw',
                'utm_medium__c' => 'Málaga',
                'Delegacion_procedencia__c' => 'Alcobendas',
            ],
        ]);
        $this->createMetric('metric-raw-payload', 'campaign-raw', 'Campaign from raw');

        $this->build();

        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-raw-payload',
            'campaign_id' => 'campaign-raw',
            'source_acquired' => 'Sin informar',
            'medium_acquired' => 'Málaga',
            'matched_source_field' => 'utm_campaign__c',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'lead_id' => '00Q-raw-payload',
            'lead_delegation' => 'Alcobendas',
        ]);
    }

    public function test_campaign_specific_snapshot_uses_the_same_effective_resolution(): void
    {
        CampaignSalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-snapshot',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'campaign_acquired' => 'Legacy campaign',
            'utm_campaign_new' => 'New campaign',
            'acquired_source_legacy' => 'Legacy source',
            'utm_source_new' => 'New source',
        ]);
        $this->createMetric('metric-snapshot', 'campaign-snapshot', 'New campaign');

        $stats = $this->build();

        $this->assertSame('campaign_salesforce_leads', $stats['lead_source_table']);
        $this->assertDatabaseHas('campaign_attributions', [
            'lead_id' => '00Q-campaign-snapshot',
            'campaign_id' => 'campaign-snapshot',
            'source_acquired' => 'New source',
            'matched_source_field' => 'utm_campaign__c',
        ]);
    }

    private function createLead(array $attributes): SalesforceLead
    {
        return SalesforceLead::query()->create(array_merge([
            'salesforce_id' => '00Q-default',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
        ], $attributes));
    }

    private function createMetric(string $uniqueKey, string $campaignId, string $campaignName, ?string $adId = null): void
    {
        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => $uniqueKey,
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => 'account-test',
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'ad_id' => $adId,
            'spend' => 1,
            'impressions' => 1,
            'clicks' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function build(): array
    {
        return app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        );
    }
}
