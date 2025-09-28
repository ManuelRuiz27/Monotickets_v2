<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Import\ImportRowIndexRequest;
use App\Http\Requests\Import\ImportStoreRequest;
use App\Jobs\ProcessImportJob;
use App\Models\Event;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Logging\StructuredLogging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage imports lifecycle.
 */
class ImportController extends Controller
{
    use InteractsWithTenants;
    use StructuredLogging;

    /**
     * Queue an import for the provided event.
     */
    public function store(ImportStoreRequest $request, string $eventId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $event = $this->locateEvent($request, $authUser, $eventId);

        if ($event === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $validated = $request->validated();

        $import = new Import();
        $import->tenant_id = $event->tenant_id;
        $import->event_id = $event->id;
        $import->source = $validated['source'];
        $import->status = 'uploaded';
        $import->rows_total = 0;
        $import->rows_ok = 0;
        $import->rows_failed = 0;
        $import->save();
        $import->refresh();

        ProcessImportJob::dispatch(
            $import->id,
            $validated['file_url'],
            $validated['mapping'],
            $validated['options'],
        );

        $this->logEntityLifecycle(
            $request,
            $authUser,
            'import',
            (string) $import->id,
            'queued',
            (string) $event->tenant_id,
            [
                'event_id' => $event->id,
                'source' => $import->source,
            ]
        );

        return response()->json([
            'data' => $this->formatImport($import),
        ], 202);
    }

    /**
     * Display import status details.
     */
    public function show(Request $request, string $importId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $import = $this->locateImport($request, $authUser, $importId);

        if ($import === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        return response()->json([
            'data' => $this->formatImport($import),
        ]);
    }

    /**
     * List processed rows for the import.
     */
    public function rows(ImportRowIndexRequest $request, string $importId): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $import = $this->locateImport($request, $authUser, $importId);

        if ($import === null) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', null, 404);
        }

        $filters = $request->validated();

        $query = ImportRow::query()
            ->where('import_id', $import->id)
            ->orderBy('row_num');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rows = $query->get()->map(function (ImportRow $row) {
            return $this->formatImportRow($row);
        })->all();

        return response()->json([
            'data' => $rows,
        ]);
    }

    /**
     * Locate event ensuring tenant restrictions.
     */
    private function locateEvent(Request $request, User $authUser, string $eventId): ?Event
    {
        $query = Event::query()->whereKey($eventId);
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser)) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
        } else {
            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    /**
     * Locate import ensuring tenant constraints.
     */
    private function locateImport(Request $request, User $authUser, string $importId): ?Import
    {
        $query = Import::query()->with('event')->whereKey($importId);
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser)) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
        } else {
            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatImport(Import $import): array
    {
        return [
            'id' => $import->id,
            'tenant_id' => $import->tenant_id,
            'event_id' => $import->event_id,
            'source' => $import->source,
            'status' => $import->status,
            'rows_total' => $import->rows_total,
            'rows_ok' => $import->rows_ok,
            'rows_failed' => $import->rows_failed,
            'progress' => $this->calculateImportProgress($import),
            'report_file_url' => $import->report_file_url,
            'created_at' => optional($import->created_at)->toISOString(),
            'updated_at' => optional($import->updated_at)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatImportRow(ImportRow $row): array
    {
        return [
            'id' => $row->id,
            'import_id' => $row->import_id,
            'row_num' => $row->row_num,
            'data_json' => $row->data_json,
            'status' => $row->status,
            'error_msg' => $row->error_msg,
            'entity_id_created' => $row->entity_id_created,
            'created_at' => optional($row->created_at)->toISOString(),
            'updated_at' => optional($row->updated_at)->toISOString(),
        ];
    }

    private function calculateImportProgress(Import $import): float
    {
        if ($import->rows_total <= 0) {
            return $import->status === 'completed' ? 1.0 : 0.0;
        }

        $processed = $import->rows_ok + $import->rows_failed;

        if ($processed <= 0) {
            return 0.0;
        }

        $progress = $processed / $import->rows_total;

        if ($progress > 1) {
            return 1.0;
        }

        if ($progress < 0) {
            return 0.0;
        }

        return round($progress, 4);
    }
}
