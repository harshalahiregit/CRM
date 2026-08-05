<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrTrainingProvider;
use App\Models\User;
use App\Repositories\Hr\TrainingRepository;
use Illuminate\Support\Facades\Log;

/**
 * Training Providers (L&D Phase 1). Internal or external training vendors,
 * tenant-unique by name. Never hard-deleted — deactivate to retire.
 * Tenant-scoped, audited.
 */
class TrainingProviderService
{
    private const TYPES = ['Internal', 'External'];

    public function __construct(private TrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->providers($tenantId, $f)->map(fn ($p) => $this->present($p))->all(),
            'stats' => $this->repo->stats(HrTrainingProvider::class, $tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertUniqueName($data['name'] ?? null, $tenantId);

        $provider = HrTrainingProvider::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $provider->recordAudit('Training Provider Created', $actor, null, ['name' => $provider->name]);
        $this->log('Training provider created', $tenantId, $provider->id);

        return $this->present($provider);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $provider = $this->find($id, $tenantId);
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName($data['name'], $tenantId, $provider->id);
        }
        $provider->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $provider->recordAudit('Training Provider Updated', $actor, null, ['name' => $provider->name]);

        return $this->present($provider->fresh());
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $provider = $this->find($id, $tenantId);
        $provider->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $provider->recordAudit($active ? 'Training Provider Activated' : 'Training Provider Deactivated', $actor);

        return $this->present($provider->fresh());
    }

    private function assertUniqueName(?string $name, int $tenantId, ?int $ignoreId = null): void
    {
        if (! trim((string) $name)) {
            throw new BusinessException('Provider name is required.');
        }
        $exists = HrTrainingProvider::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new BusinessException("A training provider named “{$name}” already exists.");
        }
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'name'           => $d['name'] ?? null,
            'code'           => $d['code'] ?? null,
            'contact_person' => $d['contact_person'] ?? null,
            'email'          => $d['email'] ?? null,
            'phone'          => $d['phone'] ?? null,
            'website'        => $d['website'] ?? null,
            'description'    => $d['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('provider_type', $d)) {
            $attrs['provider_type'] = in_array($d['provider_type'], self::TYPES, true) ? $d['provider_type'] : 'External';
        }
        if (array_key_exists('is_active', $d)) {
            $attrs['is_active'] = (bool) $d['is_active'];
        }

        // #22 — assigned explicitly, not through array_filter(): a deliberate
        // empty list must be able to CLEAR a provider's expertise, and
        // array_filter() would drop it as if it had never been sent.
        if (array_key_exists('department_id', $d)) {
            $attrs['department_id'] = $d['department_id'] ?: null;
        }
        foreach (['expertise', 'certifications', 'qualifications', 'skills'] as $list) {
            if (array_key_exists($list, $d)) {
                // Reuses the Phase A cleaner: trims, drops blanks, de-duplicates
                // case-insensitively — the same treatment employee skills get.
                $attrs[$list] = \App\Support\Hr\SkillMatcher::clean($d[$list] ?? []);
            }
        }

        return $attrs;
    }

    private function present(HrTrainingProvider $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code,
            'provider_type' => $p->provider_type, 'contact_person' => $p->contact_person,
            'email' => $p->email, 'phone' => $p->phone, 'website' => $p->website,
            'description' => $p->description, 'is_active' => $p->is_active,
            // #22
            'department_id'   => $p->department_id,
            'department_name' => $p->department?->name,
            'expertise'       => $p->expertise ?: [],
            'certifications'  => $p->certifications ?: [],
            'qualifications'  => $p->qualifications ?: [],
            'skills'          => $p->skills ?: [],
        ];
    }

    private function find(int $id, int $tenantId): HrTrainingProvider
    {
        $provider = $this->repo->findProvider($id, $tenantId);
        if (! $provider) {
            throw new BusinessException('Training provider not found', 404);
        }

        return $provider;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
