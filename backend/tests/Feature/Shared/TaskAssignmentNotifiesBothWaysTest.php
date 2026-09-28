<?php

namespace Tests\Feature\Shared;

use App\Mail\Task\SubtaskAssignedMail;
use App\Models\Notification;
use App\Models\Task\Task;
use App\Models\User;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Assigning a task tells the assignee — in the app AND by e-mail.
 *
 * "I created a task, assigned it to the super admin, and nothing came — no app
 * notification, no mail." Two separate things were behind that, and only one of
 * them was a bug.
 *
 * The bug: TaskNotifier sent through `Mail::to(...)`, the global facade, which
 * resolves `config('mail.default')` = `env('MAIL_MAILER', 'log')`. Production
 * runs `config:cache`, so .env is never read and the literal default wins — the
 * mail was written to storage/logs and the send reported success. Nothing ever
 * left the server.
 *
 * The rule: a person is not told about their own action. Assigning a task to
 * yourself is silent on both channels, by design and on purpose — you already
 * know. If the super admin assigned the task to themselves, that is what they
 * saw, and it is correct.
 *
 * Both are pinned here so the distinction survives.
 */
class TaskAssignmentNotifiesBothWaysTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new \App\Models\Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $name, string $email, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'email' => $email,
            'password' => Hash::make('x'), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function task(int $creatorId): Task
    {
        return Task::create([
            'tenant_id' => self::TENANT,
            'name'       => 'Wireframe the homepage',
            'status'     => 'To Do',
            'priority'   => 'high',
            'created_by' => $creatorId,
            'start_date' => now()->toDateString(),
            'due_date'   => now()->addWeek()->toDateString(),
        ]);
    }

    /**
     * Task mail is sent once the response has been flushed, not during it.
     *
     * Opening an SMTP session to the tenant's host takes about eleven seconds,
     * and that used to happen inside the save — see TaskNotifier::mail. A real
     * request terminates on its own and the mail goes; these tests call the
     * service directly, so nothing would ever terminate the application and the
     * mail would sit un-sent forever.
     *
     * Asserting after this is the honest question: by the time the request is
     * over, did the message go?
     */
    private function requestEnds(): void
    {
        $this->app->terminate();
    }

    public function test_assigning_to_someone_else_reaches_the_bell_and_the_inbox(): void
    {
        Mail::fake();

        $admin = $this->user('Super Admin', 'admin@notify.test', 'admin');
        $staff = $this->user('Priya Sharma', 'priya@notify.test');
        $task  = $this->task($admin->id);

        app(TaskService::class)->syncAssignees($task->id, [$staff->id], self::TENANT, $admin->id);

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => self::TENANT,
            'user_id'   => $staff->id,
            'type'      => 'task.assigned',
        ]);

        $this->requestEnds();

        Mail::assertQueued(SubtaskAssignedMail::class, function ($mail) use ($staff) {
            return $mail->hasTo($staff->email);
        });
    }

    /**
     * The in-app bell and the e-mail are separate legs and must both fire.
     *
     * Before the mailer fix they diverged in the worst way: the bell worked, so
     * the feature looked alive, while every message silently went to a log file.
     */
    public function test_the_bell_and_the_mail_are_both_required(): void
    {
        Mail::fake();

        $admin = $this->user('Super Admin', 'admin2@notify.test', 'admin');
        $staff = $this->user('Rohit Verma', 'rohit@notify.test');
        $task  = $this->task($admin->id);

        app(TaskService::class)->syncAssignees($task->id, [$staff->id], self::TENANT, $admin->id);

        $this->assertSame(1, Notification::where('user_id', $staff->id)->where('type', 'task.assigned')->count(),
            'the in-app bell did not fire for the assignee');

        $this->requestEnds();

        // A plain string second argument is read by MailFake as an ADDRESS, not
        // as a failure message — so the expectation goes in the closure.
        Mail::assertQueued(SubtaskAssignedMail::class, fn ($m) => $m->hasTo($staff->email));
    }

    /** Several assignees at once: everyone is told, nobody is missed. */
    public function test_every_new_assignee_is_told(): void
    {
        Mail::fake();

        $admin = $this->user('Super Admin', 'admin3@notify.test', 'admin');
        $a = $this->user('Anjali', 'anjali@notify.test');
        $b = $this->user('Sam', 'sam@notify.test');
        $task = $this->task($admin->id);

        app(TaskService::class)->syncAssignees($task->id, [$a->id, $b->id], self::TENANT, $admin->id);

        foreach ([$a, $b] as $u) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $u->id, 'type' => 'task.assigned',
            ]);
        }

        $this->requestEnds();

        Mail::assertQueued(SubtaskAssignedMail::class, fn ($m) => $m->hasTo($a->email));
        Mail::assertQueued(SubtaskAssignedMail::class, fn ($m) => $m->hasTo($b->email));
    }

    /**
     * Assigning to yourself stays silent — this is the rule, not a fault.
     *
     * If this ever needs to change, change it here first: the expectation is
     * the contract, and somebody reported the silence as a bug once already.
     */
    public function test_assigning_to_yourself_says_nothing(): void
    {
        Mail::fake();

        $admin = $this->user('Super Admin', 'admin4@notify.test', 'admin');
        $task  = $this->task($admin->id);

        app(TaskService::class)->syncAssignees($task->id, [$admin->id], self::TENANT, $admin->id);

        $this->assertSame(0, Notification::where('user_id', $admin->id)->count(),
            'a person does not need to be told about their own action');

        // Terminated first on purpose: mail now leaves after the response, so
        // "nothing was sent" is only worth asserting once that has happened.
        $this->requestEnds();

        Mail::assertNothingQueued();
    }

    /** Re-assigning the same person twice must not tell them twice. */
    public function test_an_unchanged_assignment_does_not_notify_again(): void
    {
        Mail::fake();

        $admin = $this->user('Super Admin', 'admin5@notify.test', 'admin');
        $staff = $this->user('Asha Rao', 'asha@notify.test');
        $task  = $this->task($admin->id);

        $svc = app(TaskService::class);
        $svc->syncAssignees($task->id, [$staff->id], self::TENANT, $admin->id);
        $svc->syncAssignees($task->id, [$staff->id], self::TENANT, $admin->id);

        $this->assertSame(1, Notification::where('user_id', $staff->id)->where('type', 'task.assigned')->count(),
            'only a NEWLY added assignee is notified — re-saving a task must not re-notify');
    }
}
