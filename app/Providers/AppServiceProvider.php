<?php

namespace App\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Filesystem $files): void
    {
        $this->ensureFileCacheDirectories($files);
    }

    private function ensureFileCacheDirectories(Filesystem $files): void
    {
        $stores = config('cache.stores', []);

        foreach ($stores as $store) {
            if (($store['driver'] ?? null) !== 'file') {
                continue;
            }

            foreach (['path', 'lock_path'] as $key) {
                $path = $store[$key] ?? null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $files->ensureDirectoryExists($path, 0755, true);
            }
        }
    }
}
