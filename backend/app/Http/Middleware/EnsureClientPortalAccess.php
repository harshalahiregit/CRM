<?php

namespace App\Http\Middleware;

use App\Models\Customer\ClientContact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the customer portal.
 *
 * Three separate checks, because they fail for different reasons and a caller
 * deserves to know which:
 *
 *  1. the token subject must BE a ClientContact — a staff or vendor token must
 *     never reach a customer endpoint even if a route is mis-registered;
 *  2. the contact must still be active as a relationship contact;
 *  3. portal access must be switched on, which is separate from being active —
 *     most contacts are people we mail invoices to and nothing more.
 *
 * The contact and its client are stashed on the request so every downstream
 * controller scopes to them without re-reading the token.
 */
class EnsureClientPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $contact = $request->user();

        if (! $contact instanceof ClientContact) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This area is for customer portal accounts only.',
            ], 403);
        }

        if ($contact->active === false) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This contact has been deactivated.',
            ], 403);
        }

        if ($contact->portal_status !== 'active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Portal access has not been enabled for this contact.',
            ], 403);
        }

        $client = $contact->client;

        if (! $client) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This contact is not linked to a customer.',
            ], 403);
        }

        /*
         | And the customer itself must still be live.
         |
         | ClientPortalAuthService::login() checks this, and that was read as the
         | whole fix — but login happens once and a token lasts. Switching a
         | customer off with ClientController::toggleActive() revokes nothing, so
         | every contact under it kept working normally until their token aged
         | out. Whoever flipped the switch believed access was cut.
         |
         | Checked on every request rather than by deleting tokens at toggle
         | time, because this holds however the column comes to be false — a
         | direct edit, an import, a future bulk action — instead of only in the
         | one code path somebody remembered.
         |
         | A soft-deleted customer needs no case of its own: `$contact->client`
         | already excludes trashed rows, which is the 403 above.
         */
        if (! $client->active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This account is no longer active. Please speak to your account manager.',
            ], 403);
        }

        $request->attributes->set('clientContact', $contact);
        $request->attributes->set('portalClient', $client);

        return $next($request);
    }
}
