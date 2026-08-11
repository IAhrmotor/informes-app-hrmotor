<?php

namespace App\Http\Controllers\Reports\Stock;

use App\Http\Controllers\Controller;
use App\Models\StockCatalogAlias;
use App\Models\StockCatalogValue;
use App\Services\Reports\Stock\StockCatalogNormalizer;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockCatalogAliasApprovalController extends Controller
{
    public function store(Request $request, StockCatalogNormalizer $normalizer): RedirectResponse
    {
        abort_unless(ReportUserAccess::canApproveStockCatalogAliases($request), 403);

        $data = $request->validate([
            'field_api_name' => ['required', 'string', Rule::in(array_values(StockCatalogNormalizer::FIELD_BY_DIMENSION))],
            'raw_value' => ['required', 'string', 'max:255'],
            'stock_catalog_value_id' => ['required', 'integer', Rule::exists('stock_catalog_values', 'id')],
            'rule_name' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($data, $normalizer, $request): void {
            $target = StockCatalogValue::query()->lockForUpdate()->findOrFail($data['stock_catalog_value_id']);
            abort_unless(
                $target->object_api_name === 'Product2'
                && $target->field_api_name === $data['field_api_name']
                && $target->is_active,
                422,
                'El destino debe ser un valor Product2 activo del mismo campo.',
            );

            StockCatalogAlias::query()->updateOrCreate(
                [
                    'field_api_name' => $data['field_api_name'],
                    'normalized_key' => $normalizer->key($data['raw_value']),
                ],
                [
                    'raw_value' => $normalizer->display($data['raw_value']),
                    'stock_catalog_value_id' => $target->id,
                    'rule_name' => $data['rule_name'],
                    'reason' => $data['reason'],
                    'approval_status' => StockCatalogAlias::APPROVAL_APPROVED,
                    'approved_by_report_user_id' => ReportUserAccess::reportUser($request)?->id,
                    'approved_at' => now(),
                ],
            );
        });

        return back()->with('status', 'Alias de catálogo aprobado.');
    }
}
