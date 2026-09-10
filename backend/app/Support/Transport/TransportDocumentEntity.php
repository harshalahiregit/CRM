<?php

namespace App\Support\Transport;

/**
 * What a transport document is filed against — the `entity_type` half of
 * IDX-010's unique key: UNIQUE(company_id, entity_type, entity_id, document_type, version).
 *
 * DB-019 describes transport_documents as "Vehicle/driver/customer documents",
 * so those three are the declared set. CUSTOMER is declared but unused until a
 * ticket owns customer documents; VEHICLE and DRIVER are live from SNG-TRN-003
 * and 004 respectively.
 *
 * Plain strings rather than model class names: the registry's unique key is a
 * cross-system contract, and storing "vehicle" keeps it readable and stable if a
 * class is ever renamed or moved. This is deliberately NOT Laravel's polymorphic
 * default of storing the FQCN.
 */
final class TransportDocumentEntity
{
    public const VEHICLE  = 'vehicle';
    public const DRIVER   = 'driver';
    public const CUSTOMER = 'customer';

    public const ALL = [self::VEHICLE, self::DRIVER, self::CUSTOMER];

    /** Entity types a ticket actually writes today. */
    public const ACTIVE = [self::VEHICLE, self::DRIVER];

    public static function isValid(string $entity): bool
    {
        return in_array($entity, self::ALL, true);
    }
}
