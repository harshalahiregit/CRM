<?php

namespace Sire\Discovery\Detectors;

use Sire\Discovery\Finding;

/**
 * SIRE — which frontend the host runs, and where its routes live.
 *
 * SIRE's Report Issue experience is best in React, works in Blade, and the
 * backend is fully functional with neither. This detector decides which of those
 * three the installer should offer, and it does so by reading package.json and
 * the filesystem — never by executing anything.
 *
 * A HOST WITH NO JAVASCRIPT AT ALL IS A SUPPORTED OUTCOME. The API is the
 * product; the SPA is a client of it. Reporting "frontend: none" leads to a
 * Blade-based Report Issue and a fully working SIRE, not to a failed install.
 */
class FrontendDetector implements Detector
{
    private const ROUTE_FILES = [
        'resources/js/app/routes.jsx', 'resources/js/routes.jsx', 'resources/js/router.jsx',
        'resources/js/app/routes.js', 'resources/js/routes.js',
        'src/app/routes.jsx', 'src/routes.jsx', 'src/router/index.js',
    ];

    private const LAYOUT_FILES = [
        'resources/js/Layouts/AppLayout.jsx', 'resources/js/components/layout/Layout.jsx',
        'resources/js/Layouts/AuthenticatedLayout.jsx', 'src/components/layout/Layout.jsx',
    ];

    public function name(): string
    {
        return 'frontend';
    }

    public function detect(): array
    {
        $packages = $this->packageJson();

        $has = fn (string $name) => isset($packages[$name]);

        $type = match (true) {
            $has('@inertiajs/react')             => 'inertia-react',
            $has('@inertiajs/vue3'), $has('@inertiajs/inertia-vue3') => 'inertia-vue',
            $has('react')                        => 'react',
            $has('vue')                          => 'vue',
            $has('livewire/livewire')            => 'livewire',
            default                              => null,
        };

        $out = [
            'frontend.type' => $type === null
                ? Finding::absent(['no react/vue/inertia dependency in package.json'])
                : Finding::found($type, Finding::HIGH, ['package.json dependencies']),

            'frontend.bundler' => match (true) {
                $has('vite'), $has('laravel-vite-plugin') => Finding::found('vite', Finding::HIGH, ['package.json']),
                $has('laravel-mix')                       => Finding::found('mix', Finding::HIGH, ['package.json']),
                default                                   => Finding::absent(['no vite or laravel-mix']),
            },

            // What SIRE would need to reuse. Reported so the installer can say
            // "your kit has these, ours will fall back for the rest" rather than
            // demanding a component library SIRE has no right to require.
            'frontend.ui_library' => match (true) {
                $has('@mui/material')     => Finding::found('mui', Finding::HIGH, ['package.json']),
                $has('antd')              => Finding::found('antd', Finding::HIGH, ['package.json']),
                $has('@chakra-ui/react')  => Finding::found('chakra', Finding::HIGH, ['package.json']),
                $has('tailwindcss')       => Finding::found('tailwind', Finding::HIGH, ['package.json']),
                $has('bootstrap')         => Finding::found('bootstrap', Finding::HIGH, ['package.json']),
                default                   => Finding::absent(['no recognised UI library']),
            },

            'frontend.http_client' => match (true) {
                $has('axios')  => Finding::found('axios', Finding::HIGH, ['package.json']),
                default        => Finding::absent(['no axios; SIRE will use its own fetch client']),
            },

            'frontend.query_library' => $has('@tanstack/react-query')
                ? Finding::found('@tanstack/react-query', Finding::HIGH, ['package.json'])
                : Finding::absent(['SIRE ships its own data-fetching fallback']),
        ];

        $out['frontend.route_file'] = $this->firstExisting(self::ROUTE_FILES)
            ?? Finding::absent(['no conventional route file found; declare screens with SireContext.register()']);

        $out['frontend.layout_file'] = $this->firstExisting(self::LAYOUT_FILES)
            ?? Finding::absent(['no conventional layout file; mount ReportIssueRoot manually']);

        $out['frontend.blade_fallback'] = Finding::found(
            $type === null,
            Finding::HIGH,
            [$type === null
                ? 'no SPA detected — SIRE will offer the Blade Report Issue widget'
                : 'an SPA is present — the Blade widget is available but not needed'],
        );

        return $out;
    }

    private function firstExisting(array $paths): ?Finding
    {
        foreach ($paths as $path) {
            if (is_file(base_path($path))) {
                return Finding::found($path, Finding::HIGH, ['file exists']);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function packageJson(): array
    {
        $path = base_path('package.json');

        if (! is_readable($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return [];
        }

        return array_merge(
            (array) ($data['dependencies'] ?? []),
            (array) ($data['devDependencies'] ?? []),
        );
    }
}
