<?php

// backend/app/Actions/Admin/Catalog/UpdateCategory.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class UpdateCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateCategoryInput $in): Category
    {
        return DB::transaction(function () use ($in): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($in->categoryId);

            $category->fill($in->changes)->save();

            $this->audit->record('admin.category.update', $category, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $category;
        });
    }
}
