<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Services\Transport\TripEventRecorder;
use App\Models\User;
use App\Repositories\Transport\TransportContainerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The container master and STOS-CTD §8's controlled relationship.
 *
 * Requirements: `STOS-REQ-MDM-008` (container master, P0), `CTD-001`'s anchor,
 * and §8's consignment ↔ container relationship. D-39 is the approval that let
 * the entity exist; D-40 ruled the master + association shape.
 *
 * ── THE ONE-AT-A-TIME RULE IS THE DATABASE'S, AND THIS SERVICE KNOWS IT ──
 * §7: reuse "allowed historically but not simultaneously". `attach()` checks
 * first so the caller gets a sentence instead of a constraint violation — but
 * the check is NOT the guarantee. Two concurrent requests both read "nothing
 * active", both pass the check, and both insert; only the unique index over
 * `active_container_key` stops the second.
 *
 * So attach() catches the violation as well as checking for it, and turns it
 * into the same message. The check is for the user; the constraint is for
 * correctness. A test asserts the DATABASE refuses it, with the service check
 * bypassed, so nobody later deletes the index believing the check is enough.
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ──────────────────────────
 * No format validation (CTD §7 asks for it; no format is specified anywhere —
 * ISO 6346 is never named and its check digit would reject legitimate numbers).
 * No container status (D-44). No seal handling (STOS-CMP §76/§77, Person 3's).
 * No back-dating — see D-53 for what would have to come with it.
 *
 * ── TENANCY ─────────────────────────────────────────────────────────────
 * Every lookup is tenant-scoped AT the point of lookup, and a cross-tenant id
 * raises ResourceNotFoundException — 404, never 403. Telling a caller "not
 * yours" confirms the row exists in somebody else's workspace.
 */
class ContainerService
{
    public function __construct(private TransportContainerRepository $containers)
    {
    }

    /** Fields a caller may set. The normalised key is derived, never supplied. */
    private const WRITABLE = ['container_number', 'container_type'];

    /* ── Reads ──────────────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        return $this->containers->filtered($tenantId, $filters);
    }

    public function find(int $id, int $tenantId): TransportContainer
    {
        $container = $this->containers->findForTenant($id, $tenantId);

        if (! $container) {
            throw new ResourceNotFoundException('Container');
        }

        return $container;
    }

    /** CTD-001 — resolve any spelling of a container number to the master row. */
    public function findByNumber(string $number, int $tenantId): TransportContainer
    {
        $container = $this->containers->findByNumber($number, $tenantId);

        if (! $container) {
            throw new ResourceNotFoundException('Container');
        }

        return $container;
    }

    /** STOS-CTD §7 — the whole association history for one container. */
    public function history(int $containerId, int $tenantId)
    {
        // Resolve first, so an unknown or cross-tenant id reads as "no such
        // container" rather than returning a confident empty history.
        $this->find($containerId, $tenantId);

        return $this->containers->attachmentHistory($containerId, $tenantId);
    }

    /**
     * STOS-CTD §8 — the relationship read from the consignment side.
     *
     * A consignment may carry one container or several; the uniqueness rule
     * runs the other way (one consignment per container), so nothing here
     * limits the count. `$activeOnly` separates "what is on it now" from the
     * full history the passport needs.
     */
    public function attachmentsFor(
        TransportConsignment $consignment,
        int $tenantId,
        bool $activeOnly = false,
    ) {
        // Same order as history(): resolve the parent first, so another
        // tenant's consignment reads as "no such consignment" instead of
        // returning an empty list that looks like an answer.
        $this->assertConsignmentTenant($consignment, $tenantId);

        return $this->containers->attachmentsForConsignment($consignment->id, $tenantId, $activeOnly);
    }

    /* ── Writes ─────────────────────────────────────────────────────── */

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, int $tenantId, ?User $actor = null): TransportContainer
    {
        $number = trim((string) ($data['container_number'] ?? ''));

        if ($number === '' || TransportContainer::normalise($number) === '') {
            throw new BusinessException('A container number is required.', 422);
        }

        // Checked before insert so the caller gets a sentence naming the
        // existing container rather than a bare unique-constraint violation.
        // The unique index is still what guarantees it.
        $existing = $this->containers->findByNumber($number, $tenantId);

        if ($existing) {
            throw new BusinessException(
                'Container '.$existing->container_number.' already exists in this workspace.'
                .($existing->container_number === $number ? '' : ' It was entered as "'.$number.'", which is the same number.'),
                422,
            );
        }

        $created = DB::transaction(function () use ($data, $number, $tenantId, $actor) {
            /** @var TransportContainer $container */
            $container = TransportContainer::create(array_merge(
                array_intersect_key($data, array_flip(self::WRITABLE)),
                [
                    'tenant_id'        => $tenantId,
                    'container_number' => $number,
                    'created_by'       => $actor?->id,
                    'updated_by'       => $actor?->id,
                ],
            ));

            $container->audit('transport.container.created', $actor, new: [
                'container_number' => $container->container_number,
                'normalized'       => $container->container_number_normalized,
                'container_type'   => $container->container_type,
            ]);

            Log::channel('transport')->info('Container created', [
                'container_id' => $container->id, 'number' => $container->container_number,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $container->fresh();
        });

        // CTD §31, after the commit. No trip: a container exists before any trip
        // carries it, and the recorder takes containerId for exactly this. D-115.
        app(TripEventRecorder::class)->record(
            'container.created', tenantId: $tenantId, containerId: $created->id, actor: $actor,
            detail: ['container_number' => $created->container_number],
        );

        return $created;
    }

    /**
     * STOS-CTD §8 — attach a container to a consignment.
     *
     * @throws BusinessException when the container is already carried elsewhere
     */
    public function attach(
        TransportContainer $container,
        TransportConsignment $consignment,
        int $tenantId,
        ?User $actor = null,
    ): ConsignmentContainer {
        $this->assertTenant($container, $tenantId);
        $this->assertConsignmentTenant($consignment, $tenantId);

        // For the USER. Not the guarantee — see the class docblock.
        $active = $this->containers->activeAttachment($container->id, $tenantId);

        if ($active) {
            throw new BusinessException($this->alreadyAttachedMessage($container, $active), 422);
        }

        try {
            $attached = DB::transaction(function () use ($container, $consignment, $tenantId, $actor) {
                $attachment = ConsignmentContainer::create([
                    'tenant_id'      => $tenantId,
                    'consignment_id' => $consignment->id,
                    'container_id'   => $container->id,
                    'attached_at'    => now(),
                    'attached_by'    => $actor?->id,
                    'created_by'     => $actor?->id,
                    'updated_by'     => $actor?->id,
                ]);

                $attachment->audit('transport.container.attached', $actor, new: [
                    'container_id'       => $container->id,
                    'container_number'   => $container->container_number,
                    'consignment_id'     => $consignment->id,
                    'consignment_number' => $consignment->consignment_number,
                ]);

                Log::channel('transport')->info('Container attached', [
                    'container_id' => $container->id, 'consignment_id' => $consignment->id,
                    'tenant_id' => $tenantId, 'user_id' => $actor?->id,
                ]);

                return $attachment->fresh();
            });

            // CTD §31. Both entities are named, because this event is read from
            // the container's passport AND from the consignment. D-115.
            app(TripEventRecorder::class)->record(
                'container.attached', tenantId: $tenantId, actor: $actor,
                containerId: $container->id, consignmentId: $consignment->id,
                detail: [
                    'container_number'   => $container->container_number,
                    'consignment_number' => $consignment->consignment_number,
                ],
            );

            return $attached;
        } catch (QueryException $e) {
            // The race the check above cannot close: another request attached
            // this container between our read and our insert. The database
            // refused it, and the user gets the same sentence either way.
            if ($this->isUniqueViolation($e)) {
                throw new BusinessException(
                    $this->alreadyAttachedMessage($container, $this->containers->activeAttachment($container->id, $tenantId)),
                    422,
                );
            }

            throw $e;
        }
    }

    /** STOS-CTD §7 — detach, keeping the row as history. */
    public function detach(TransportContainer $container, int $tenantId, ?User $actor = null): ConsignmentContainer
    {
        $this->assertTenant($container, $tenantId);

        $active = $this->containers->activeAttachment($container->id, $tenantId);

        if (! $active) {
            throw new BusinessException(
                'Container '.$container->container_number.' is not attached to a consignment.',
                422,
            );
        }

        $detached = DB::transaction(function () use ($active, $container, $actor, $tenantId) {
            $active->forceFill([
                'detached_at' => now(),
                'detached_by' => $actor?->id,
                'updated_by'  => $actor?->id,
            ])->save();

            // The row stays. §7 requires the history, and detached_at is what
            // says "not current" — there is no delete path.
            $active->audit('transport.container.detached', $actor, new: [
                'container_id'     => $container->id,
                'container_number' => $container->container_number,
                'consignment_id'   => $active->consignment_id,
                'attached_at'      => $active->attached_at?->toIso8601String(),
            ]);

            Log::channel('transport')->info('Container detached', [
                'container_id' => $container->id, 'attachment_id' => $active->id,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $active->fresh();
        });

        // CTD §31. D-115.
        app(TripEventRecorder::class)->record(
            'container.detached', tenantId: $tenantId, actor: $actor,
            containerId: $container->id, consignmentId: $detached->consignment_id,
            detail: ['container_number' => $container->container_number],
        );

        return $detached;
    }

    /* ── internals ──────────────────────────────────────────────────── */

    private function alreadyAttachedMessage(TransportContainer $container, ?ConsignmentContainer $active): string
    {
        $on = $active?->consignment?->consignment_number;

        return 'Container '.$container->container_number.' is already on '
            .($on ? 'consignment '.$on : 'another consignment')
            .'. Detach it first — a container cannot be on two consignments at once.';
    }

    /** Driver-agnostic: sqlite says "UNIQUE constraint failed", MySQL says 1062. */
    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function assertTenant(TransportContainer $container, int $tenantId): void
    {
        if ((int) $container->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Container tenant mismatch', [
                'container_id' => $container->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Container');
        }
    }

    private function assertConsignmentTenant(TransportConsignment $consignment, int $tenantId): void
    {
        if ((int) $consignment->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Consignment');
        }
    }
}
