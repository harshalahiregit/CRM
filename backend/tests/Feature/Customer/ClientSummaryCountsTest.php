<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Services\Customer\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customers summary has to count what the login gate counts.
 *
 * `with_portal` counted `whereNotNull('user_id')` — the column from the July
 * design, when a portal contact was expected to have a `users` row behind it.
 * The portal was rebuilt on contact-side auth (client_contacts.password /
 * portal_status, migration 2026_10_15_000002) and nothing has written user_id
 * since, so this reported 0 however many contacts could actually sign in.
 *
 * Nothing renders the figure today, which is exactly why it is worth a test: the
 * next screen to show it would have shown a zero and nobody would have known
 * where the zero came from.
 */
class ClientSummaryCountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->t->id, 'company' => 'Northwind Ltd', 'active' => true,
        ]);
    }

    /**
     * `portal_status` is NOT NULL with a default of 'inactive', so a contact
     * always holds one of the four values — there is no "unset" state to test.
     * Passing null here would hit the constraint, which is the schema saying the
     * same thing.
     */
    private function contact(string $email, ?string $portalStatus = null): ClientContact
    {
        $attrs = [
            'tenant_id' => $this->t->id, 'client_id' => $this->client->id,
            'first_name' => 'A', 'last_name' => 'Person', 'email' => $email,
            'active' => true,
        ];

        if ($portalStatus !== null) {
            $attrs['portal_status'] = $portalStatus;
        }

        return ClientContact::create($attrs);
    }

    private function summary(): array
    {
        return app(ClientService::class)->summary($this->t->id);
    }

    /**
     * 'invited' and 'active' is the pair ClientPortalAuthService::portalAccountFor()
     * treats as "has a portal account". The count has to mean what the gate
     * means, or the two disagree about the same contact.
     */
    public function test_it_counts_contacts_with_portal_access(): void
    {
        $this->contact('active@northwind.test', 'active');
        $this->contact('invited@northwind.test', 'invited');

        $this->assertSame(2, $this->summary()['with_portal']);
    }

    public function test_it_does_not_count_contacts_without_portal_access(): void
    {
        $this->contact('nobody@northwind.test', 'inactive');
        // No portal_status given, so the column's own default applies. A contact
        // nobody invited must not be counted as having access.
        // fresh(), because a default lives in the schema — the instance Eloquent
        // hands back from create() has not read it.
        $defaulted = $this->contact('imported@northwind.test')->fresh();

        $this->assertSame('inactive', $defaulted->portal_status, 'The default is no access.');
        $this->assertSame(0, $this->summary()['with_portal']);
        $this->assertSame(2, $this->summary()['contacts'], 'They are still contacts — just not portal ones.');
    }

    /**
     * The regression itself. A contact who can sign in today has no user_id, so
     * the old predicate counted them as having no portal access.
     */
    public function test_a_signed_in_contact_is_counted_even_with_no_user_row(): void
    {
        $contact = $this->contact('riya@northwind.test', 'active');

        $this->assertNull($contact->user_id, 'Nothing writes this column any more.');
        $this->assertSame(1, $this->summary()['with_portal']);
    }
}
