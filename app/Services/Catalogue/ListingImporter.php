<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ListingCondition;
use App\Exceptions\ApiException;
use App\Integrations\Payments\Amounts;
use App\Models\User;
use App\Support\Money\Currency;
use Illuminate\Support\Facades\Validator;

/**
 * CSV bulk import into drafts (`POST /listings/imports`). Columns (header row required):
 * title, description, category, condition, price (major units, e.g. 745.00), currency,
 * brand, model, quantity. Photos are added afterwards in the wizard.
 */
final class ListingImporter
{
    private const MAX_ROWS = 500;

    public function __construct(private readonly ListingService $listings) {}

    /** @return array{created: int, failed: list<array{row: int, errors: array<string, list<string>>}>, listing_ids: list<string>} */
    public function import(User $seller, string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ApiException::validation(['file' => 'The file could not be read.']);
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (! is_array($header) || ! in_array('title', array_map(fn ($h) => strtolower(trim((string) $h)), $header), true)) {
            throw ApiException::validation(['file' => 'The first row must be a header containing at least a "title" column.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $failed = [];
        $ids = [];
        $row = 1;

        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row++;
            if ($row - 1 > self::MAX_ROWS) {
                $failed[] = ['row' => $row, 'errors' => ['file' => ['Only the first 500 rows are imported.']]];
                break;
            }
            if ($values === [null]) {
                continue;
            }

            $data = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
            $validator = Validator::make($data, [
                'title' => ['required', 'string', 'max:140'],
                'description' => ['nullable', 'string', 'max:5000'],
                'category' => ['nullable', 'string', 'max:80'],
                'condition' => ['nullable', 'in:'.implode(',', ListingCondition::values())],
                'price' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
                'currency' => ['nullable', 'in:'.implode(',', Currency::codes())],
                'brand' => ['nullable', 'string', 'max:80'],
                'model' => ['nullable', 'string', 'max:80'],
                'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            ]);

            if ($validator->fails()) {
                $failed[] = ['row' => $row, 'errors' => $validator->errors()->toArray()];

                continue;
            }

            try {
                $currency = strtoupper($data['currency'] ?? '') ?: ($seller->currency ?: 'USD');
                $listing = $this->listings->createDraft($seller, array_filter([
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'category_id' => $data['category'] ?? null,
                    'condition' => $data['condition'] ?? null,
                    'brand' => $data['brand'] ?? null,
                    'model' => $data['model'] ?? null,
                    'stock' => isset($data['quantity']) ? (int) $data['quantity'] : null,
                    'price' => isset($data['price']) && $data['price'] !== ''
                        ? ['amount' => Amounts::toMinor($data['price']), 'currency' => $currency]
                        : null,
                ], fn ($v) => $v !== null && $v !== ''));
                $ids[] = $listing->id;
                $created++;
            } catch (ApiException $e) {
                $failed[] = ['row' => $row, 'errors' => $e->errors ?? ['row' => [$e->getMessage()]]];
            }
        }

        fclose($handle);

        return ['created' => $created, 'failed' => $failed, 'listing_ids' => $ids];
    }
}
