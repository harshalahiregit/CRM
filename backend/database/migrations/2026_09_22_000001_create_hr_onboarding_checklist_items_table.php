<?php

use App\Support\Hr\OnboardingTaskCategory as TaskCat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding checklist, as a tenant master instead of a PHP constant.
 *
 * OnboardingTaskCategory::DEFAULT_TASKS held 27 tasks in code, so a company
 * that wanted one more induction step, or did not issue laptops, needed a
 * developer and a deploy. This is that list as rows somebody can edit.
 *
 * The columns are exactly what seedTasks() actually reads — category, title,
 * owner_role, is_mandatory, sort_order — plus is_active so an item can be
 * retired without losing the record of it. Nothing speculative: the task table
 * also carries description and visible_to_employee, and the seeder has never
 * set either, so neither is templated here.
 *
 * BACKFILL. Every existing tenant gets the 27 rows it is already behaving as
 * if it had, so nothing changes on deploy and nobody re-enters anything. The
 * runtime keeps a fallback to the same constant for a tenant with NO rows at
 * all — a workspace created after this migration — which is the difference
 * between "never configured" and "deliberately emptied": deactivating every
 * item leaves rows behind and therefore leaves the checklist empty, as asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('category', 50);
            $table->string('title', 200);
            // HR|Manager|Employee|System — who may action the task once seeded.
            $table->string('owner_role', 30)->default('HR');
            $table->boolean('is_mandatory')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Named explicitly: the generated name would be
            // hr_onboarding_checklist_items_tenant_id_sort_order_index at 58
            // characters, which fits, but the pattern of naming these has
            // already caught one 65-character index in this codebase.
            $table->index(['tenant_id', 'sort_order'], 'hr_onb_checklist_tenant_order_idx');
            $table->index(['tenant_id', 'is_active'], 'hr_onb_checklist_tenant_active_idx');
        });

        $this->backfill();
    }

    /**
     * Give every existing workspace the list it is already using.
     *
     * Idempotent per tenant: a tenant that somehow already has rows is left
     * alone rather than having a second copy of the defaults appended.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $already = DB::table('hr_onboarding_checklist_items')
                ->where('tenant_id', $tenantId)->exists();

            if ($already) {
                continue;
            }

            $rows = [];
            $sort = 0;

            foreach (TaskCat::DEFAULT_TASKS as $category => $tasks) {
                foreach ($tasks as $task) {
                    $rows[] = [
                        'tenant_id'    => $tenantId,
                        'category'     => $category,
                        'title'        => $task['title'],
                        'owner_role'   => $task['owner_role'],
                        'is_mandatory' => $task['is_mandatory'],
                        'sort_order'   => $sort++,
                        'is_active'    => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
            }

            if ($rows) {
                DB::table('hr_onboarding_checklist_items')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Only the template goes. Tasks already seeded onto an onboarding are
        // copies living in hr_employee_onboarding_tasks and are not touched —
        // rolling this back must not erase somebody's half-finished checklist.
        Schema::dropIfExists('hr_onboarding_checklist_items');
    }
};
