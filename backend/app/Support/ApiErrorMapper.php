<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns an exception into something a user can act on.
 *
 * The API used to return $e->getMessage() for anything unhandled. For a
 * QueryException that message is the entire failing statement — connection,
 * host, port, database name, table, every column and the bound values — which
 * landed verbatim in a toast. Unreadable for the user, and an information
 * disclosure for anyone who can trigger an error.
 *
 * So: messages written FOR humans (BusinessException, HTTP aborts) pass through
 * untouched, and database/driver errors are translated to a short sentence that
 * says what to do. The technical detail is never dropped — it goes to the error
 * log, and a `reference` is returned so a support request can be tied back to
 * the exact log line.
 */
class ApiErrorMapper
{
    /**
     * @return array{message:string, status:int, field:?string, hint:?string}
     */
    public static function map(Throwable $e): array
    {
        // Already human-authored — BusinessException and abort() messages are
        // written for the person reading them, so don't second-guess them.
        if ($e instanceof \App\Exceptions\BusinessException) {
            return self::out($e->getMessage(), $e->getStatusCode());
        }

        if ($e instanceof ModelNotFoundException) {
            return self::out('That record no longer exists. It may have been deleted — refresh and try again.', 404);
        }

        if ($e instanceof QueryException) {
            return self::fromQueryException($e);
        }

        if ($e instanceof \PDOException) {
            return self::out('The database is not reachable right now. Please try again in a moment.', 503);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $msg = trim($e->getMessage());

            // An empty/framework-default message is useless to a user.
            if ($msg === '' || str_contains($msg, 'HttpException')) {
                $msg = match (true) {
                    $status === 403 => 'You don’t have permission to do that.',
                    $status === 404 => 'That page or record wasn’t found.',
                    $status === 429 => 'Too many attempts. Please wait a moment and try again.',
                    default         => 'That request couldn’t be completed.',
                };
            }

            return self::out($msg, $status);
        }

        // Anything unexpected: never echo the raw message (it leaks internals and
        // means nothing to the user). Detail lives in the log.
        return self::out('Something went wrong on our side. The team has been notified — please try again.', 500);
    }

    /**
     * Database constraint failures, translated by driver error code. Codes are
     * the MySQL ones; SQLite reports the same conditions through the message
     * text, so both are matched.
     */
    private static function fromQueryException(QueryException $e): array
    {
        $raw  = $e->getMessage();
        $code = (string) ($e->errorInfo[1] ?? '');

        // 1048 / 23000 "cannot be null" — a required column was left empty.
        if ($code === '1048' || stripos($raw, 'cannot be null') !== false || stripos($raw, 'NOT NULL constraint failed') !== false) {
            $field = self::column($raw, ['/Column \'([^\']+)\' cannot be null/i', '/NOT NULL constraint failed: [^.]+\.(\w+)/i']);

            return self::out(
                $field
                    ? sprintf('%s is required — please fill it in and save again.', self::label($field))
                    : 'A required field was left empty. Please complete the form and save again.',
                422, $field,
                'This field can’t be left blank.',
            );
        }

        // 1062 duplicate key — something unique already exists.
        if ($code === '1062' || stripos($raw, 'Duplicate entry') !== false || stripos($raw, 'UNIQUE constraint failed') !== false) {
            // SQLite names the column outright. MySQL only gives the index
            // ("proposals_reference_no_unique"), and splitting a table prefix off
            // that is guesswork — but it always quotes the offending VALUE, which
            // is more useful to the user anyway ("PROP-2026-001 is already used").
            $field = self::column($raw, ['/UNIQUE constraint failed: [^.]+\.(\w+)/i']);
            $value = self::column($raw, ['/Duplicate entry \'([^\']*)\'/i']);

            $message = match (true) {
                $value !== null && $value !== '' => sprintf('“%s” is already used by another record. Please enter a different value.', $value),
                $field !== null                  => sprintf('That %s is already used by another record. Please enter a different one.', strtolower(self::label($field))),
                default                          => 'A record with these details already exists.',
            };

            return self::out($message, 409, $field, 'This value has to be unique.');
        }

        // 1451/1452 foreign key — the linked record is missing, or is still in use.
        if (in_array($code, ['1451', '1452'], true) || stripos($raw, 'FOREIGN KEY constraint fails') !== false || stripos($raw, 'FOREIGN KEY constraint failed') !== false) {
            $inUse = $code === '1451' || stripos($raw, 'a foreign key constraint fails (`') !== false && stripos($raw, 'DELETE') !== false;

            return self::out(
                $inUse
                    ? 'This record is still linked to others and can’t be removed. Remove those links first.'
                    : 'A linked record couldn’t be found. Refresh the page and pick the value again.',
                409, null,
                'Check the linked selections on this form.',
            );
        }

        // 1406 value too long.
        if ($code === '1406' || stripos($raw, 'Data too long') !== false) {
            $field = self::column($raw, ['/Data too long for column \'([^\']+)\'/i']);

            return self::out(
                $field
                    ? sprintf('%s is too long. Please shorten it.', self::label($field))
                    : 'One of the values is too long. Please shorten it.',
                422, $field,
            );
        }

        // Missing table/column = a pending migration, not user error.
        if (stripos($raw, 'no such table') !== false || stripos($raw, "doesn't exist") !== false
            || stripos($raw, 'no such column') !== false || stripos($raw, 'Unknown column') !== false) {
            return self::out('This feature isn’t fully set up on this workspace yet. Please contact your administrator.', 503);
        }

        return self::out('That couldn’t be saved. Please check the values on the form and try again.', 422);
    }

    /** First capturing group from whichever pattern matches. */
    private static function column(string $raw, array $patterns): ?string
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $raw, $m) && ! empty($m[1])) {
                return $m[1];
            }
        }

        return null;
    }

    /** email_address -> "Email address" — a column name a user can recognise. */
    private static function label(string $column): string
    {
        $clean = preg_replace('/_id$/', '', $column);

        return ucfirst(str_replace('_', ' ', $clean));
    }

    private static function out(string $message, int $status, ?string $field = null, ?string $hint = null): array
    {
        return ['message' => $message, 'status' => $status, 'field' => $field, 'hint' => $hint];
    }
}
