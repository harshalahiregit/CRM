<?php

namespace App\Services\Access;

use App\Exceptions\BusinessException;
use App\Models\Access\AccessRole;
use App\Models\Access\Department;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The roles and departments an admin maintains from Settings.
 *
 * The rules here are all about not breaking what is already running:
 *
 *  - A slug is what a route guard spells and what sits in users.internal_role.
 *    Changing one would silently strip everyone holding it of their access, so
 *    a slug is set once, at creation, and never edited. The display name is
 *    free to change as often as you like.
 *  - System roles are the slugs the code itself references. They can be renamed
 *    and deactivated but never deleted, because a guard somewhere spells them.
 *  - Nothing in use is deleted. Deleting a role that people hold would leave
 *    them with an internal_role matching nothing, which reads as "no access"
 *    with no explanation anywhere.
 */
class AccessCatalogService
{
    /**
     * Slugs the application itself relies on — route guards (`role:hr`,
     * `role:manager`), and the model's own helpers (isHRExecutive and friends).
     * Seeded on first read so the list is never empty, and locked thereafter.
     */
    public const SYSTEM_ROLES = [
        'hr'             => 'HR',
        'hr_executive'   => 'HR Executive',
        'hr_recruiter'   => 'HR Recruiter',
        'hiring_manager' => 'Hiring Manager',
        'manager'        => 'Manager',
    ];

    /**
     * Account types, shown read-only beside the editable roles.
     *
     * Each is a separate front door — its own portal, its own login branch, its
     * own middleware. Creating one is a feature, not a settings change, so the
     * UI shows them and says so rather than pretending the list is complete.
     */
    public const ACCOUNT_TYPES = [
        'admin'              => 'Admin',
        'staff'              => 'Staff / Employee',
        'doctor'             => 'Doctor',
        'third_party_vendor' => 'Third-Party Vendor',
        'vendor'             => 'Vendor',
        'client'             => 'Client / Customer',
        'company'            => 'Company',
    ];

    /* ── Roles ──────────────────────────────────────────────────────────── */

    public function roles(int $tenantId)
    {
        $this->seedSystemRoles($tenantId);

        return AccessRole::forTenant($tenantId)->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (AccessRole $r) => $r->toArray() + ['user_count' => $r->user_count]);
    }

    public function createRole(int $tenantId, array $data): AccessRole
    {
        // Seed first. The built-ins are created on first READ, so without
        // this a workspace that had never opened the roles screen would let
        // "Manager" be created as an ordinary role — and then the real,
        // code-referenced Manager could never be seeded alongside it.
        $this->seedSystemRoles($tenantId);

        $slug = $this->slugFor($data['slug'] ?? $data['name']);

        if (AccessRole::forTenant($tenantId)->where('slug', $slug)->exists()) {
            throw new BusinessException("A role with the key \"{$slug}\" already exists.", 422);
        }
        // An internal role that collides with an ACCOUNT type would be read as
        // that account type by the guard, handing out access nobody granted.
        if (array_key_exists($slug, self::ACCOUNT_TYPES)) {
            throw new BusinessException("\"{$slug}\" is an account type, not a job role. Choose a different key.", 422);
        }

        return AccessRole::create([
            'tenant_id'   => $tenantId,
            'name'        => $data['name'],
            'slug'        => $slug,
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'is_system'   => false,
        ]);
    }

    public function updateRole(AccessRole $role, array $data): AccessRole
    {
        // The slug is the credential. Renaming it would strip access from
        // everyone holding it, silently, so only the label is editable.
        $role->update([
            'name'        => $data['name'] ?? $role->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
            'is_active'   => $data['is_active'] ?? $role->is_active,
        ]);

        return $role->fresh();
    }

    public function deleteRole(AccessRole $role): void
    {
        if ($role->is_system) {
            throw new BusinessException(
                "\"{$role->name}\" is built into the application and cannot be deleted. Deactivate it instead.",
                422,
            );
        }

        $count = $role->user_count;
        if ($count > 0) {
            throw new BusinessException(
                "{$count} ".($count === 1 ? 'person holds' : 'people hold')." this role. Move them to another role first.",
                422,
            );
        }

        $role->delete();
    }

    /* ── Departments ────────────────────────────────────────────────────── */

    public function departments(int $tenantId)
    {
        return Department::forTenant($tenantId)->with('head:id,name')->orderBy('name')->get()
            ->map(fn (Department $d) => $d->toArray() + ['user_count' => $d->user_count]);
    }

    public function createDepartment(int $tenantId, array $data): Department
    {
        if (Department::forTenant($tenantId)->where('name', $data['name'])->exists()) {
            throw new BusinessException("A department called \"{$data['name']}\" already exists.", 422);
        }

        return Department::create([
            'tenant_id'    => $tenantId,
            'name'         => $data['name'],
            'code'         => $data['code'] ?? null,
            'description'  => $data['description'] ?? null,
            'head_user_id' => $this->headFor($tenantId, $data['head_user_id'] ?? null),
            'is_active'    => $data['is_active'] ?? true,
        ]);
    }

    public function updateDepartment(Department $dept, array $data): Department
    {
        $newName = $data['name'] ?? $dept->name;

        if ($newName !== $dept->name
            && Department::forTenant($dept->tenant_id)->where('name', $newName)->exists()) {
            throw new BusinessException("A department called \"{$newName}\" already exists.", 422);
        }

        $dept->update([
            'name'         => $newName,
            'code'         => array_key_exists('code', $data) ? $data['code'] : $dept->code,
            'description'  => array_key_exists('description', $data) ? $data['description'] : $dept->description,
            'head_user_id' => array_key_exists('head_user_id', $data)
                ? $this->headFor($dept->tenant_id, $data['head_user_id'])
                : $dept->head_user_id,
            'is_active'    => $data['is_active'] ?? $dept->is_active,
        ]);

        return $dept->fresh('head');
    }

    public function deleteDepartment(Department $dept): void
    {
        $count = $dept->user_count;
        if ($count > 0) {
            throw new BusinessException(
                "{$count} ".($count === 1 ? 'person is' : 'people are')." in this department. Move them first.",
                422,
            );
        }

        $dept->delete();
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    /** Create the code-referenced roles once, so the list is never empty. */
    private function seedSystemRoles(int $tenantId): void
    {
        $have = AccessRole::forTenant($tenantId)->pluck('slug')->all();

        foreach (self::SYSTEM_ROLES as $slug => $name) {
            if (in_array($slug, $have, true)) {
                continue;
            }
            AccessRole::create([
                'tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug,
                'description' => 'Built into the application.',
                'is_active' => true, 'is_system' => true,
            ]);
        }
    }

    private function slugFor(string $value): string
    {
        return Str::of($value)->lower()->replace(['-', ' '], '_')->replaceMatches('/[^a-z0-9_]/', '')->toString();
    }

    /** A head must be a real user of this tenant, or nobody. */
    private function headFor(int $tenantId, $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return User::where('tenant_id', $tenantId)->whereKey($userId)->exists() ? (int) $userId : null;
    }
}
