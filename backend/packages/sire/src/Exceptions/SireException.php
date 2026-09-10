<?php

namespace Sire\Exceptions;

use RuntimeException;

/**
 * SIRE — a rule the user broke, not a bug.
 *
 * Thrown when a request is well-formed but not allowed right now: an invalid
 * workflow transition, a release gate that has not passed, a duplicate link
 * that would form a cycle. The message is written FOR THE USER and is safe to
 * display verbatim — "This issue is assigned to someone else. Ask a lead to
 * reassign it first." rather than a stack trace.
 *
 * Deliberately distinct from validation (422, per-field) and from authorization
 * (403, "not yours"). This is 409: the state of the world is wrong, not the
 * input and not the identity.
 *
 * If the CRM already has an equivalent — most do, often called
 * SireException — bind it in one place instead: see the render() note in
 * docs/BACKEND.md. Nothing in SIRE catches this type by name except the
 * exception handler.
 */
class SireException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 409,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> extra fields for the error payload */
    public function context(): array
    {
        return $this->context;
    }

    /** Laravel calls this automatically when the exception reaches the handler. */
    public function render($request)
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'error'   => 'sire_rule_violation',
            'context' => $this->context ?: null,
        ]), $this->status);
    }
}
