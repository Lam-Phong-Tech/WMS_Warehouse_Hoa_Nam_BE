<?php

namespace Modules\Warehouse\Services;

final class WarrantyCaseNumber
{
    public function __construct(private readonly DailyDocumentNumber $numbers) {}

    public function next(): string
    {
        return $this->numbers->next('WC', 'warranty_daily_numbers', [
            'warranty_case' => 'warranty_case_code',
        ]);
    }
}
