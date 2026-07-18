<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class ModifierGroupRequired extends DomainException
{
    public function __construct(
        private readonly string $groupId,
        private readonly string $groupName,
        private readonly int $min,
        private readonly ?int $max,
        private readonly int $selected,
    ) {
        parent::__construct("Modifier group \"{$groupName}\" needs between {$min} and ".($max ?? '∞')." selections; got {$selected}.");
    }

    public function errorCode(): string
    {
        return 'modifier_group_required';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['group_id' => $this->groupId, 'min_select' => $this->min, 'max_select' => $this->max, 'selected' => $this->selected];
    }
}
