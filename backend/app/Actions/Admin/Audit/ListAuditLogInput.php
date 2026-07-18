<?php

declare(strict_types=1);

namespace App\Actions\Admin\Audit;

final readonly class ListAuditLogInput
{
    public function __construct(
        public ?string $entityType = null,
        public ?string $entityId = null,
        public ?string $userId = null,
        public ?string $action = null,
        public ?string $from = null,   // 'YYYY-MM-DD', inclusive — a created_at date bound
        public ?string $to = null,     // 'YYYY-MM-DD', inclusive — a created_at date bound
        public int $page = 1,
    ) {}
}
