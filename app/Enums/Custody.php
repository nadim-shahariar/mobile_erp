<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a physical handset currently IS (custody), independent of sellability. */
enum Custody: string
{
    case InUnit = 'IN_UNIT';
    case AtExternalParty = 'AT_EXTERNAL_PARTY';
    case InTransit = 'IN_TRANSIT';
    case AtRepairVendor = 'AT_REPAIR_VENDOR';
    case WithCustomer = 'WITH_CUSTOMER';
    case ReturnPendingInspection = 'RETURN_PENDING_INSPECTION';
    case WrittenOff = 'WRITTEN_OFF';

    public function label(): string
    {
        return match ($this) {
            self::InUnit => 'In unit',
            self::AtExternalParty => 'At shop / person',
            self::InTransit => 'In transit',
            self::AtRepairVendor => 'At repair vendor',
            self::WithCustomer => 'With customer',
            self::ReturnPendingInspection => 'Pending inspection',
            self::WrittenOff => 'Written off',
        };
    }

    public function isOwned(): bool
    {
        return in_array($this, [
            self::InUnit,
            self::AtExternalParty,
            self::InTransit,
            self::AtRepairVendor,
            self::ReturnPendingInspection,
        ], true);
    }

    public function isSellableLocation(): bool
    {
        return $this === self::InUnit;
    }
}
