<?php

namespace Modules\Warehouse\Services;

final class ComponentIssueNumber
{
    public function __construct(private readonly DailyDocumentNumber $numbers) {}

    public function next(): string
    {
        return $this->numbers->next('CID', 'component_issue_daily_numbers', [
            'component_issue_documents' => 'component_issue_no',
        ]);
    }
}
