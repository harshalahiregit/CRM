<?php

namespace Tests\Feature\Email;

use App\Exceptions\BusinessException;
use App\Models\Email\EmailTemplate;
use App\Services\Email\EmailTemplateService;
use App\Support\Email\EmailTemplateRegistry;
use App\Support\Email\MergeContext;
use App\Support\Email\MergeFields\MergeFieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Increment F — Email Templates + merge fields. Covers resolution (shipped default
 * vs tenant override), merge-field expansion, the HTML/text escaping contract,
 * validation, preview and restore. Does not touch increments A–E.
 */
class EmailTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailTemplateService $svc;

    private MergeFieldRegistry $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(EmailTemplateService::class);
        $this->fields = app(MergeFieldRegistry::class);
    }

    protected function tearDown(): void
    {
        EmailTemplateRegistry::flush();
        parent::tearDown();
    }

    /* ── Registry ────────────────────────────────────────────────────────── */

    public function test_registry_ships_all_required_categories(): void
    {
        foreach ([
            'authentication', 'users', 'hr', 'recruitment', 'employees', 'payroll',
            'attendance', 'leave', 'purchase', 'sales', 'finance', 'inventory',
            'projects', 'helpdesk', 'tpv', 'vendor', 'customer', 'meetings',
            'notifications', 'system',
        ] as $category) {
            $this->assertArrayHasKey($category, EmailTemplateRegistry::categories());
        }
    }

    public function test_every_shipped_template_is_well_formed(): void
    {
        foreach (EmailTemplateRegistry::all() as $key => $t) {
            $this->assertNotSame('', trim($t['subject']), "{$key} has no subject");
            $this->assertNotSame('', trim(strip_tags($t['body_html'])), "{$key} has no body");
            $this->assertNotSame('', trim($t['body_text']), "{$key} has no text part");
            $this->assertArrayHasKey($t['category'], EmailTemplateRegistry::categories(), "{$key} has an unknown category");
        }
    }

    /** A shipped template must never reference a merge field that cannot resolve. */
    public function test_no_shipped_template_uses_an_unknown_merge_field(): void
    {
        foreach (EmailTemplateRegistry::all() as $key => $t) {
            $result = $this->svc->validate($t);
            $this->assertSame([], $result['unknown_tokens'], "{$key} uses unknown merge fields");
        }
    }

    public function test_new_templates_register_without_touching_the_engine(): void
    {
        EmailTemplateRegistry::register('custom.thing', ['system', 'Custom Thing', 'Hi {{user.name}}', '<p>Body</p>', 'desc']);

        $this->assertTrue(EmailTemplateRegistry::exists('custom.thing'));
        $this->assertSame('Custom Thing', $this->svc->find(1, 'custom.thing')['name']);
    }

    /* ── Resolution: shipped default vs tenant override ──────────────────── */

    public function test_find_returns_the_shipped_default_when_not_customised(): void
    {
        $t = $this->svc->find(1, 'system.test');

        $this->assertFalse($t['customised']);
        $this->assertTrue($t['is_system']);
        $this->assertSame(0, $t['version']);
    }

    public function test_update_creates_an_override_and_bumps_the_version(): void
    {
        $first = $this->svc->update(1, 'system.test', ['subject' => 'Mine v1']);
        $this->assertSame('Mine v1', $first['subject']);
        $this->assertTrue($first['customised']);
        $this->assertSame(1, $first['version']);

        $second = $this->svc->update(1, 'system.test', ['subject' => 'Mine v2']);
        $this->assertSame(2, $second['version']);
        $this->assertDatabaseCount('email_templates', 1);   // still ONE row, not two
    }

    public function test_unknown_template_key_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->svc->find(1, 'not.a.template');
    }

    public function test_all_lists_every_shipped_template_with_overrides_applied(): void
    {
        $this->svc->update(1, 'system.test', ['subject' => 'Custom']);

        $all = collect($this->svc->all(1));
        $this->assertCount(count(EmailTemplateRegistry::all()), $all);
        $this->assertSame('Custom', $all->firstWhere('key', 'system.test')['subject']);
    }

    public function test_filters_by_category_and_search(): void
    {
        $finance = $this->svc->all(1, ['category' => 'finance']);
        $this->assertNotEmpty($finance);
        foreach ($finance as $t) {
            $this->assertSame('finance', $t['category']);
        }

        $found = $this->svc->all(1, ['search' => 'payslip']);
        $this->assertNotEmpty($found);
    }

    /* ── Tenant isolation ────────────────────────────────────────────────── */

    public function test_customisations_are_isolated_per_tenant(): void
    {
        $this->svc->update(1, 'system.test', ['subject' => 'Tenant One Subject']);

        $this->assertSame('Tenant One Subject', $this->svc->find(1, 'system.test')['subject']);

        $other = $this->svc->find(2, 'system.test');
        $this->assertFalse($other['customised']);
        $this->assertSame(EmailTemplateRegistry::get('system.test')['subject'], $other['subject']);
    }

    /* ── Restore default ─────────────────────────────────────────────────── */

    public function test_restore_default_drops_only_that_override(): void
    {
        $this->svc->update(1, 'system.test', ['subject' => 'Changed A']);
        $this->svc->update(1, 'auth.welcome', ['subject' => 'Changed B']);

        $restored = $this->svc->restoreDefault(1, 'system.test');

        $this->assertFalse($restored['customised']);
        $this->assertSame(EmailTemplateRegistry::get('system.test')['subject'], $restored['subject']);
        // The OTHER customisation survives — that is the point of storing overrides.
        $this->assertSame('Changed B', $this->svc->find(1, 'auth.welcome')['subject']);
        $this->assertDatabaseCount('email_templates', 1);
    }

    public function test_restore_is_a_no_op_when_never_customised(): void
    {
        $t = $this->svc->restoreDefault(1, 'system.test');
        $this->assertFalse($t['customised']);
    }

    /* ── Merge fields ────────────────────────────────────────────────────── */

    public function test_merge_fields_resolve_from_supplied_data(): void
    {
        $out = $this->svc->renderTemplate(1, [
            'subject' => 'Hello {{customer.name}}',
            'body_html' => '<p>Invoice {{document.number}} for {{customer.name}}</p>',
            'body_text' => 'Invoice {{document.number}}',
        ], ['customer' => ['name' => 'Acme'], 'document' => ['number' => 'INV-1']]);

        $this->assertSame('Hello Acme', $out['subject']);
        $this->assertStringContainsString('Invoice INV-1 for Acme', $out['html']);
        $this->assertSame('Invoice INV-1', $out['text']);
    }

    public function test_unknown_merge_fields_render_as_empty_not_raw(): void
    {
        $out = $this->svc->renderTemplate(1, ['subject' => 'A{{nope.field}}B', 'body_html' => '', 'body_text' => '']);

        $this->assertSame('AB', $out['subject']);
        $this->assertStringNotContainsString('{{', $out['subject']);
    }

    /** The security contract: values are escaped in HTML, raw in text. */
    public function test_merge_values_are_escaped_in_html_but_not_in_text(): void
    {
        $payload = ['customer' => ['name' => '<script>alert(1)</script>']];

        $out = $this->svc->renderTemplate(1, [
            'subject' => '{{customer.name}}',
            'body_html' => '<p>{{customer.name}}</p>',
            'body_text' => '{{customer.name}}',
        ], $payload);

        $this->assertStringNotContainsString('<script>', $out['html']);
        $this->assertStringContainsString('&lt;script&gt;', $out['html']);
        // Plain text is not HTML — escaping there would corrupt the message.
        $this->assertSame('<script>alert(1)</script>', $out['text']);
    }

    public function test_the_authored_html_body_itself_is_not_escaped(): void
    {
        $out = $this->svc->renderTemplate(1, ['body_html' => '<p><strong>Bold</strong></p>', 'subject' => 's', 'body_text' => '']);

        $this->assertStringContainsString('<strong>Bold</strong>', $out['html']);
    }

    public function test_dates_and_currency_use_the_tenant_formatter(): void
    {
        $out = $this->svc->renderTemplate(1, [
            'subject' => '{{amount}}', 'body_html' => '<p>{{date.today}}</p>', 'body_text' => '',
        ], ['amount' => 1234567.891]);

        // Indian grouping + rupee symbol come from Increment C, not from this engine.
        $this->assertSame('₹12,34,567.89', $out['subject']);
        $this->assertMatchesRegularExpression('~\d{2}/\d{2}/\d{4}~', $out['html']);
    }

    public function test_company_fields_fall_back_to_general_settings(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set(1, 'general', 'company_name', 'Sangoe Pvt Ltd');

        $out = $this->svc->renderTemplate(1, ['subject' => '{{company.name}}', 'body_html' => '', 'body_text' => '']);
        $this->assertSame('Sangoe Pvt Ltd', $out['subject']);
    }

    public function test_document_next_number_never_consumes_a_number(): void
    {
        \App\Models\Numbering\DocumentNumberConfig::create(array_merge(
            \App\Support\Numbering\DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => 1, 'enabled' => true],
        ));

        $this->svc->renderTemplate(1, ['subject' => '{{document.next_number}}', 'body_html' => '', 'body_text' => ''],
            ['document' => ['type_key' => 'invoice']]);

        // Rendering an email must never burn a sequence number.
        $this->assertDatabaseCount('document_number_sequences', 0);
    }

    public function test_merge_field_catalogue_is_grouped(): void
    {
        $catalogue = $this->fields->catalogue();

        $this->assertArrayHasKey('Customer', $catalogue);
        $this->assertArrayHasKey('Company', $catalogue);
        $this->assertArrayHasKey('Date & Time', $catalogue);
        $this->assertStringStartsWith('{{', $catalogue['Customer'][0]['token']);
    }

    public function test_registry_is_pluggable(): void
    {
        $this->fields->register(new \App\Support\Email\MergeFields\CallableMergeFieldResolver(
            'demo.field', 'Demo', 'A demo field', fn (MergeContext $c) => 'DEMO'
        ));

        $out = $this->svc->renderTemplate(1, ['subject' => '{{demo.field}}', 'body_html' => '', 'body_text' => '']);
        $this->assertSame('DEMO', $out['subject']);
    }

    /* ── Sanitising, validation, preview ─────────────────────────────────── */

    public function test_saving_strips_dangerous_html(): void
    {
        $t = $this->svc->update(1, 'system.test', [
            'subject' => 'S', 'body_html' => '<p>ok</p><script>alert(1)</script><a href="javascript:x">bad</a>',
        ]);

        $this->assertStringNotContainsString('<script', $t['body_html']);
        $this->assertStringNotContainsString('javascript:', $t['body_html']);
        $this->assertStringContainsString('<p>ok</p>', $t['body_html']);
    }

    public function test_blank_text_body_is_derived_from_the_html(): void
    {
        $t = $this->svc->update(1, 'system.test', [
            'subject' => 'S', 'body_html' => '<p>Hello there</p>', 'body_text' => '',
        ]);

        $this->assertStringContainsString('Hello there', $t['body_text']);
        $this->assertStringNotContainsString('<p>', $t['body_text']);
    }

    public function test_validation_reports_missing_subject_and_body_as_errors(): void
    {
        $result = $this->svc->validate(['subject' => '', 'body_html' => '', 'body_text' => '']);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('subject', $result['errors']);
        $this->assertArrayHasKey('body_html', $result['errors']);
    }

    /** An unknown field is a content mistake, not a broken template — a warning. */
    public function test_unknown_merge_fields_are_a_warning_not_a_blocking_error(): void
    {
        $unknown = $this->svc->validate(['subject' => 'x {{bogus.thing}}', 'body_html' => '<p>y</p>']);

        $this->assertTrue($unknown['valid']);          // still saveable
        $this->assertSame([], $unknown['errors']);
        $this->assertArrayHasKey('merge_fields', $unknown['warnings']);
        $this->assertSame(['bogus.thing'], $unknown['unknown_tokens']);
    }

    /* ── Regressions found by the adversarial review ─────────────────────── */

    /**
     * The sanitiser strips any href that is not literally http(s) — which would
     * delete the ONLY link in most shipped templates on first save.
     */
    public function test_saving_preserves_merge_field_links(): void
    {
        $t = $this->svc->update(1, 'auth.password_reset', []);

        $this->assertStringContainsString('href="{{url.reset}}"', $t['body_html']);

        // …and the rendered link is a real URL.
        $rendered = $this->svc->render(1, 'auth.password_reset');
        $this->assertMatchesRegularExpression('~href="https?://[^"]+"~', $rendered['html']);
    }

    public function test_every_shipped_template_keeps_its_links_through_a_save(): void
    {
        foreach (EmailTemplateRegistry::all() as $key => $default) {
            $before = substr_count($default['body_html'], '<a href=');
            if ($before === 0) {
                continue;
            }
            $after = substr_count($this->svc->update(1, $key, [])['body_html'], '<a href=');
            $this->assertSame($before, $after, "{$key} lost a link when saved");
        }
    }

    /** A javascript: value must never become a live href. */
    public function test_url_merge_values_are_scheme_guarded(): void
    {
        $out = $this->svc->renderTemplate(1, [
            'subject' => 's', 'body_text' => '',
            'body_html' => '<p><a href="{{url.portal}}">Go</a></p>',
        ], ['url' => ['portal' => 'javascript:alert(1)']]);

        $this->assertStringNotContainsString('javascript:', $out['html']);

        foreach (['data:text/html;base64,AAAA', '//evil.test/x', "java\tscript:alert(1)", ' javascript:alert(1)'] as $bad) {
            $r = $this->svc->renderTemplate(1, ['subject' => '{{url.portal}}', 'body_html' => '', 'body_text' => ''],
                ['url' => ['portal' => $bad]]);
            $this->assertStringNotContainsString('javascript', strtolower($r['subject']), "allowed: {$bad}");
            $this->assertStringNotContainsString('data:', strtolower($r['subject']), "allowed: {$bad}");
        }

        // A legitimate URL still passes through untouched.
        $ok = $this->svc->renderTemplate(1, ['subject' => '{{url.portal}}', 'body_html' => '', 'body_text' => ''],
            ['url' => ['portal' => 'https://portal.example.com/x']]);
        $this->assertSame('https://portal.example.com/x', $ok['subject']);
    }

    /** A partial update must not silently discard a hand-authored text part. */
    public function test_partial_update_keeps_an_authored_plain_text_body(): void
    {
        $this->svc->update(1, 'system.test', [
            'subject' => 'S', 'body_html' => '<p>Hello</p>', 'body_text' => 'HAND WRITTEN',
        ]);

        $after = $this->svc->update(1, 'system.test', ['subject' => 'S2']);
        $this->assertSame('HAND WRITTEN', $after['body_text']);
    }

    /** A null `enabled` (cleared form field) must not disable the template. */
    public function test_null_enabled_does_not_disable_the_template(): void
    {
        $t = $this->svc->update(1, 'system.test', ['subject' => 'S', 'enabled' => null]);
        $this->assertTrue($t['enabled']);
    }

    /** Markup the sanitiser deletes wholesale must not pass validation. */
    public function test_content_that_sanitises_to_nothing_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->svc->update(1, 'system.test', ['subject' => 'S', 'body_html' => '<style>p{color:red}</style>', 'body_text' => '']);
    }

    public function test_plain_text_keeps_link_urls_and_separates_blocks(): void
    {
        $text = EmailTemplateRegistry::toPlainText('<p>Go <a href="https://x.test/reset">here</a></p><ul><li>One</li><li>Two</li></ul>');

        $this->assertStringContainsString('https://x.test/reset', $text);
        $this->assertStringContainsString("One\nTwo", $text);
    }

    public function test_shipped_password_reset_text_part_contains_a_url(): void
    {
        $t = EmailTemplateRegistry::get('auth.password_reset');
        $this->assertStringContainsString('{{url.reset}}', $t['body_text']);
    }

    public function test_preview_uses_sample_data_and_does_not_persist(): void
    {
        $p = $this->svc->preview(1, 'finance.invoice');

        $this->assertStringContainsString('INV-2026-001', $p['subject']);
        $this->assertNotEmpty($p['html']);
        $this->assertArrayHasKey('validation', $p);
        $this->assertDatabaseCount('email_templates', 0);   // preview never saves
    }

    public function test_preview_accepts_an_unsaved_draft(): void
    {
        $p = $this->svc->preview(1, 'system.test', ['subject' => 'Draft {{customer.name}}']);

        $this->assertSame('Draft Acme Industries', $p['subject']);
        $this->assertDatabaseCount('email_templates', 0);
    }
}
