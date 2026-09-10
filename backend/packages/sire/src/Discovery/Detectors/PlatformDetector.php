<?php

namespace Sire\Discovery\Detectors;

use Illuminate\Support\Facades\DB;
use Sire\Discovery\Finding;

/**
 * SIRE — framework, PHP and database.
 *
 * The least interesting detector and the most load-bearing: everything the
 * compatibility report says rests on these three numbers, and getting them from
 * the running process is the only honest way to know them. A composer.json
 * constraint says what is ALLOWED; `PHP_VERSION` says what is actually there.
 */
class PlatformDetector implements Detector
{
    public function name(): string
    {
        return 'platform';
    }

    public function detect(): array
    {
        $out = [
            'framework.name'    => Finding::found('laravel', Finding::HIGH, ['Illuminate\Foundation\Application']),
            'framework.version' => Finding::found(app()->version(), Finding::HIGH, ['app()->version()']),
            'php.version'       => Finding::found(PHP_VERSION, Finding::HIGH, ['PHP_VERSION']),
            'php.extensions'    => Finding::found(
                array_values(array_intersect(['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'], get_loaded_extensions())),
                Finding::HIGH,
                ['get_loaded_extensions()'],
            ),
        ];

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            $out['database.driver'] = Finding::found($driver, Finding::HIGH, ['DB::connection()->getDriverName()']);

            // The SERVER version, not the client's. MariaDB reports itself
            // through the same channel and is distinguished by the string, which
            // matters: its JSON and index behaviour differ from MySQL's.
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            $out['database.version'] = Finding::found((string) $version, Finding::HIGH, ['PDO::ATTR_SERVER_VERSION']);
            $out['database.engine'] = Finding::found(
                stripos((string) $version, 'mariadb') !== false ? 'mariadb' : $driver,
                Finding::HIGH,
                ['server version string'],
            );
        } catch (\Throwable $e) {
            // A database SIRE cannot reach is a real finding, not a crash: the
            // compatibility report should say so rather than fail to render.
            $out['database.driver'] = Finding::absent(['could not connect: '.$e->getMessage()]);
        }

        return $out;
    }
}
