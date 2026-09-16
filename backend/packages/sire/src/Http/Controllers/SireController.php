<?php

namespace Sire\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * SIRE — the base controller every SIRE endpoint extends.
 *
 * WHY SIRE OWNS THIS
 *
 * It used to extend `App\Http\Controllers\Controller`, the host's own base
 * class. That was the very last host symbol anywhere in SIRE, and it was a real
 * dependency, not a cosmetic one: a host whose base controller lives elsewhere,
 * or is namespaced differently, or does not exist at all — a package, an
 * API-only skeleton, a Lumen-style app — could not load a single SIRE
 * controller.
 *
 * Extending Laravel's own `Illuminate\Routing\Controller` instead means SIRE
 * depends on the FRAMEWORK, which is the thing every target host has by
 * definition, rather than on an application class that merely tends to exist.
 *
 * The two traits are the ones Laravel's default skeleton adds, kept so that
 * $this->validate() and $this->dispatch() behave exactly as a Laravel developer
 * expects when reading SIRE code.
 */
abstract class SireController extends BaseController
{
    use DispatchesJobs;
    use ValidatesRequests;
}
