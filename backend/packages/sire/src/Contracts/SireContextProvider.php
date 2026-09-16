<?php

namespace Sire\Contracts;

use Sire\Dto\SireScreenContext;

/**
 * SIRE SDK — WHERE THEY WERE.
 *
 * The provider that decides whether Report Issue is one click or a form.
 *
 * When a user reports a problem, SIRE must already know which module, section
 * and screen they were on and which record they were looking at. The browser
 * knows the URL. Only the host knows that /clients/482/invoices/91 is the
 * Invoice screen of the Billing module showing invoice 91.
 *
 * TWO WAYS TO ANSWER, AND DECLARING BEATS INFERRING
 *
 *   1. DECLARED — the host calls `SireContext.register({...})` on a screen, or
 *      registers a route map here. Always wins: a screen that names itself is
 *      authoritative in a way no pattern match can be, and it is the only way to
 *      describe a wizard whose step lives in component state or a console whose
 *      entity is not in the URL.
 *
 *   2. INFERRED — SIRE matches the path against the registered map, most
 *      specific pattern first.
 *
 * THE SERVER IS THE AUTHORITY
 *
 * The browser resolves context first so the modal opens already filled in, but
 * a client can post any module and screen it likes. Whatever is STORED is what
 * resolve() derives here from the submitted path.
 *
 * UNMATCHED IS A SUPPORTED OUTCOME
 *
 * No match returns confidence 'low' with nulls, and the modal shows an editable
 * context. NEVER block a report because a route is unmapped — a user who cannot
 * report a problem is a far worse outcome than an issue filed against an unknown
 * screen.
 */
interface SireContextProvider
{
    /**
     * Resolve an application path to a screen context.
     *
     * @param string $path as submitted, e.g. /app/clients/482/invoices/91
     */
    public function resolve(string $path): SireScreenContext;

    /**
     * Every known module key, for dashboard filters and the manual override
     * picker in the context preview.
     *
     * @return array<int, string>
     */
    public function modules(): array;

    /** Human label for a module key. Falls back to a titleised key. */
    public function moduleLabel(string $module): string;

    /**
     * The full route map, for tooling — `sire:doctor`, the route audit, and the
     * generator that keeps the JavaScript and PHP maps in step.
     *
     * @return array<int, array<string, mixed>>
     */
    public function routeMap(): array;
}
