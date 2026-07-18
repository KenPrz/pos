<?php

// backend/app/Actions/Admin/Catalog/CreateCategory.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class CreateCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateCategoryInput $in): Category
    {
        return DB::transaction(function () use ($in): Category {
            $category = Category::create([
                'name' => $in->name,
                'parent_id' => $in->parentId,
                'sort_order' => $in->sortOrder,
            ]);

            $this->audit->record('admin.category.create', $category, $in->actorId, [
                'name' => $in->name, 'parent_id' => $in->parentId,
            ]);

            return $category;
        });
    }
}
