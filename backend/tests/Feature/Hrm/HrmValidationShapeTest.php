<?php

namespace Tests\Feature\Hrm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a rejected field looks like to the phone, and to the browser.
 *
 * These two clients cannot read the same answer, and the app is the fussier of
 * the pair. Its network layer keeps the body only on a 200; anything else is
 * toasted and thrown away, and it tests `status == 1` as an INTEGER, so the
 * CRM frontend's `{status: 'error'}` at 422 reached somebody's phone as the
 * literal words "Validation failed" followed by a second, generic toast —
 * naming no field and offering nothing to fix.
 *
 * So a rejected field answers 200 with status 0 — the same envelope as every
 * other refusal on this surface — and promotes the first real message, which is
 * the one sentence the person actually reads.
 * The React frontend's 422 shape must not move; it is what every form in the
 * CRM is built against.
 */
class HrmValidationShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_surface_answers_200_with_an_integer_status(): void
    {
        $r = $this->postJson('/api/Hrm/login', []);

        // 200, not 403: the app keeps the body only on a 200. On anything else
        // it toasts once, returns null, and the caller adds a second, generic
        // toast — so a 403 costs the person the only useful sentence available.
        $r->assertOk();

        // Integer, not the string 'error': the app compares `status == 1`.
        $this->assertSame(0, $r->json('status'));
        $this->assertIsInt($r->json('status'));
    }

    public function test_the_message_names_the_field_rather_than_saying_validation_failed(): void
    {
        $r = $this->postJson('/api/Hrm/login', []);

        $message = (string) $r->json('message');

        $this->assertNotSame('Validation failed', $message);
        $this->assertStringContainsStringIgnoringCase(
            'email', $message,
            'The toast is the only thing the person holding the phone sees, so it has to name the problem.'
        );

        // The full set is still there for anything that wants to mark up a form.
        $this->assertArrayHasKey('email', $r->json('errors'));
        $this->assertArrayHasKey('password', $r->json('errors'));
    }

    public function test_the_crm_frontends_shape_is_untouched(): void
    {
        $r = $this->postJson('/api/auth/login', []);

        $r->assertStatus(422);
        $this->assertSame('error', $r->json('status'));
        $this->assertSame('Validation failed', $r->json('message'));
        $this->assertArrayHasKey('email', $r->json('errors'));
    }
}
