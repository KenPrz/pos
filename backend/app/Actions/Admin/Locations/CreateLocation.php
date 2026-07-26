<?php

// backend/app/Actions/Admin/Locations/CreateLocation.php
declare(strict_types=1);

namespace App\Actions\Admin\Locations;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payments\PaymentMethodProvisioner;
use App\Domain\Rbac\RoleProvisioner;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

final class CreateLocation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleProvisioner $provisioner,
        private readonly PaymentMethodProvisioner $paymentMethods,
    ) {}

    public function execute(CreateLocationInput $in): Location
    {
        return DB::transaction(function () use ($in): Location {
            $location = Location::create([
                'name' => $in->name,
                'code' => $in->code,
                'timezone' => $in->timezone,
                'prices_include_tax' => $in->pricesIncludeTax,
                'receipt_header' => $in->receiptHeader,
                'receipt_footer' => $in->receiptFooter,
                'variance_approval_threshold_cents' => $in->varianceApprovalThresholdCents,
                'low_stock_threshold' => $in->lowStockThreshold,
            ]);

            $this->provisioner->provisionForLocation($location);

            // A location with no tenders can take no payment — the same class of bug the
            // roles call above fixes. See PaymentMethodProvisioner.
            $this->paymentMethods->provisionForLocation($location->id);

            $this->audit->record('admin.location.create', $location, $in->actorId, [
                'name' => $in->name, 'code' => $in->code,
            ]);

            return $location;
        });
    }
}
