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
 * CONSIGNMENT is the fourth, and is not in DB-019's description. It is here
 * because D-41 ruled on 2026-09-12 that LR and DO stay documents rather than
 * getting `transport_lr_records` and `transport_delivery_orders` tables of their
 * own — and if they are documents, something has to be the thing they are filed
 * against. That something is the consignment. Requested in
 * docs/transport/REQUEST-person3-document-entity.md; grounds under D-41.
 *
 * It unblocks four P0 requirements that had nowhere to write: ORD-005 capture LR,
 * ORD-006 capture DO, CTD-004 link container to LR, CTD-005 link container to DO.
 *
 * Plain strings rather than model class names: the registry's unique key is a
 * cross-system contract, and storing "vehicle" keeps it readable and stable if a
 * class is ever renamed or moved. This is deliberately NOT Laravel's polymorphic
 * default of storing the FQCN.
 */
final class TransportDocumentEntity
{
    public const VEHICLE     = 'vehicle';
    public const DRIVER      = 'driver';
    public const CUSTOMER    = 'customer';
    public const CONSIGNMENT = 'consignment';

    public const ALL = [self::VEHICLE, self::DRIVER, self::CUSTOMER, self::CONSIGNMENT];

    /** Entity types a ticket actually writes today. */
    public const ACTIVE = [self::VEHICLE, self::DRIVER, self::CONSIGNMENT];

    public static function isValid(string $entity): bool
    {
        return in_array($entity, self::ALL, true);
    }
}
