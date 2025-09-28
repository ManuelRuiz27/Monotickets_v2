<?php

namespace App\Jobs;

use App\Events\ImportProcessingCompleted;
use App\Events\ImportProcessingStarted;
use App\Models\Guest;
use App\Models\GuestList;
use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use function event;

/**
 * Process uploaded import files and materialise guests.
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var string
     */
    private string $importId;

    /**
     * @var string
     */
    private string $fileUrl;

    /**
     * @var array<string, string|null>
     */
    private array $mapping;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * Allowed guest attributes for mapping.
     *
     * @var array<int, string>
     */
    private array $allowedFields = [
        'full_name',
        'email',
        'phone',
        'organization',
        'rsvp_status',
        'rsvp_at',
        'allow_plus_ones',
        'plus_ones_limit',
        'custom_fields_json',
        'guest_list_id',
    ];

    /**
     * Create a new job instance.
     *
     * @param  array<string, string|null>  $mapping
     * @param  array<string, mixed>  $options
     */
    public function __construct(string $importId, string $fileUrl, array $mapping, array $options = [])
    {
        $this->importId = $importId;
        $this->fileUrl = $fileUrl;
        $this->mapping = $mapping;
        $this->options = $options;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var Import|null $import */
        $import = Import::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $import->status = 'processing';
        $import->save();
        $import->refresh();

        event(new ImportProcessingStarted($import));

        $rowsOk = 0;
        $rowsFailed = 0;
        $rowsTotal = 0;

        try {
            $rows = $this->parseFile($this->fileUrl);
            $dedupe = (bool) ($this->options['dedupe_by_email'] ?? false);
            $processedEmails = [];

            foreach ($rows as $index => $row) {
                $rowsTotal++;
                $rowNumber = $index + 1;
                $guestData = $this->mapRow($row);

                try {
                    $guest = $this->materialiseGuest($import, $guestData, $dedupe, $processedEmails);

                    ImportRow::create([
                        'import_id' => $import->id,
                        'row_num' => $rowNumber,
                        'data_json' => [
                            'raw' => $row,
                            'mapped' => $guestData,
                        ],
                        'status' => 'ok',
                        'entity_id_created' => $guest->id,
                    ]);

                    if ($dedupe && ! empty($guest->email)) {
                        $processedEmails[] = Str::lower($guest->email);
                    }

                    $rowsOk++;
                } catch (Throwable $throwable) {
                    ImportRow::create([
                        'import_id' => $import->id,
                        'row_num' => $rowNumber,
                        'data_json' => [
                            'raw' => $row,
                            'mapped' => $guestData,
                        ],
                        'status' => 'failed',
                        'error_msg' => $throwable->getMessage(),
                    ]);

                    $rowsFailed++;
                }
            }

            $import->rows_total = $rowsTotal;
            $import->rows_ok = $rowsOk;
            $import->rows_failed = $rowsFailed;
            $import->status = $rowsFailed > 0 ? 'failed' : 'completed';
            $import->report_file_url = $this->generateReportUrl($import->id);
            $import->save();
        } catch (Throwable $exception) {
            Log::error('imports.processing_failed', [
                'import_id' => $import->id,
                'message' => $exception->getMessage(),
            ]);

            $import->rows_total = $rowsTotal;
            $import->rows_ok = $rowsOk;
            $import->rows_failed = max($rowsFailed, $rowsTotal - $rowsOk);
            $import->status = 'failed';
            $import->report_file_url = $import->report_file_url ?? $this->generateReportUrl($import->id);
            $import->save();
        }

        $import->refresh();

        event(new ImportProcessingCompleted($import));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $mapped = [];

        foreach ($this->mapping as $field => $column) {
            if (! in_array($field, $this->allowedFields, true)) {
                continue;
            }

            $value = $column !== null ? Arr::get($row, $column) : null;

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            if ($field === 'allow_plus_ones' && $value !== null) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if ($field === 'plus_ones_limit' && $value !== null) {
                $value = is_numeric($value) ? (int) $value : null;
            }

            if ($field === 'custom_fields_json' && is_string($value)) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $mapped[$field] = $value;
        }

        return $mapped;
    }

    /**
     * Materialise a guest from the mapped payload.
     *
     * @param  array<string, mixed>  $guestData
     */
    private function materialiseGuest(Import $import, array $guestData, bool $dedupe, array $processedEmails): Guest
    {
        if (! array_key_exists('full_name', $guestData) || $guestData['full_name'] === null) {
            throw new RuntimeException('The guest full name is required.');
        }

        if ($dedupe) {
            $email = isset($guestData['email']) ? (string) $guestData['email'] : '';

            if ($email !== '') {
                $normalised = Str::lower($email);

                if (in_array($normalised, $processedEmails, true)) {
                    throw new RuntimeException('duplicated email');
                }

                $exists = Guest::query()
                    ->where('event_id', $import->event_id)
                    ->whereNotNull('email')
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(email) = ?', [$normalised])
                    ->exists();

                if ($exists) {
                    throw new RuntimeException('A guest with this email already exists for the event.');
                }
            }
        }

        if (array_key_exists('guest_list_id', $guestData) && $guestData['guest_list_id'] !== null) {
            $guestListId = (string) $guestData['guest_list_id'];

            $belongsToEvent = GuestList::query()
                ->where('event_id', $import->event_id)
                ->whereKey($guestListId)
                ->exists();

            if (! $belongsToEvent) {
                throw new RuntimeException('Guest list does not belong to the import event.');
            }
        }

        return DB::transaction(function () use ($import, $guestData) {
            $guest = new Guest();
            $guest->fill(Arr::only($guestData, $this->allowedFields));
            $guest->event_id = $import->event_id;
            $guest->save();

            return $guest->fresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseFile(string $fileUrl): array
    {
        if (str_starts_with($fileUrl, 'stub:')) {
            return $this->stubRows();
        }

        $path = $this->resolveLocalPath($fileUrl);

        if ($path === null || ! is_readable($path)) {
            throw new RuntimeException('Unable to read import file: '.$fileUrl);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open import file: '.$fileUrl);
        }

        try {
            $headers = null;
            $rows = [];

            while (($data = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = $this->normaliseCsvHeaders($data);
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = $data[$index] ?? null;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $headers
     * @return array<int, string>
     */
    private function normaliseCsvHeaders(array $headers): array
    {
        return array_map(function ($header) {
            if ($header === null) {
                return '';
            }

            return trim((string) $header);
        }, $headers);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stubRows(): array
    {
        return [
            [
                'Full Name' => 'Sample Guest',
                'Email' => 'sample@example.com',
            ],
        ];
    }

    private function resolveLocalPath(string $fileUrl): ?string
    {
        if (str_starts_with($fileUrl, 'file://')) {
            return substr($fileUrl, 7);
        }

        if (str_starts_with($fileUrl, '/')) {
            return $fileUrl;
        }

        $relative = base_path($fileUrl);

        if (is_readable($relative)) {
            return $relative;
        }

        return null;
    }

    private function generateReportUrl(string $importId): string
    {
        return sprintf('https://reports.local/imports/%s/report.json', $importId);
    }
}
