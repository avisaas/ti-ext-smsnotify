<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify\AviMarketplace;

use Composer\InstalledVersions;
use Igniter\Flame\Support\Facades\Igniter;
use Igniter\System\Classes\ExtensionManager;
use Igniter\System\Helpers\CacheHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class Setup
{
    public const CODE = 'igniterlabs.smsnotify';

    public const NAME = 'SMS Notify';

    public const PACKAGE = 'avisaas/ti-ext-smsnotify';

    public const HANDLER = 'onAviRetryIgniterlabsSmsnotify';

    private const MARKETPLACE_CODE = 'avisaas.connector';

    private const MARKETPLACE_PACKAGE = 'avisaas/ti-ext-connector';

    private const LOCK = 'avisaas-connector-composer';

    private const MIGRATION = '9999_12_31_999999_add_avi_marketplace_igniterlabs_smsnotify';

    public static function gated(): bool
    {
        return rescue(fn () => self::isHand() && (State::get('held') !== null || !self::activated()), false, false);
    }

    public static function withholds(bool $gated): bool
    {
        return $gated && rescue(fn () => self::missing() !== [], false, false);
    }

    public static function isHand(?string $root = null): bool
    {
        $extensions = realpath(Igniter::extensionsPath());
        $path = realpath($root ?? self::root());

        return $extensions !== false && $path !== false && str_starts_with($path, $extensions.DIRECTORY_SEPARATOR);
    }

    public static function run(): bool
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        try {
            $running = State::get('active') !== null && State::get('held') === null;

            State::put('active');

            $root = self::leftover();

            if ($root === null) {
                $missing = self::missing();
                $hold = self::isHand() && !$running && $missing !== [];

                if (!$hold) {
                    State::forget('held');
                }

                if (!self::isHand() || (self::hasMarketplace() && $missing === [])) {
                    State::forget('failed');

                    return true;
                }

                self::install($hold);
                $root = self::root();
            }

            self::move($root);

            return true;
        } catch (Throwable $e) {
            self::fail($e, self::isHand() ? self::root() : '');

            return false;
        }
    }

    public static function finish(): void
    {
        if (!State::forget('finish')) {
            return;
        }

        try {
            if (!resolve(ExtensionManager::class)->installExtension(self::CODE, InstalledVersions::getPrettyVersion(self::PACKAGE))) {
                throw new RuntimeException('Extension not found');
            }
        } catch (Throwable $e) {
            State::put('finish');
            logger()->error(self::CODE.': finishing the AVi Marketplace swap failed: '.$e::class.': '.$e->getMessage());
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function backups(): string
    {
        File::ensureDirectoryExists($path = storage_path('app/avi-backups'));

        return $path;
    }

    private static function activated(): bool
    {
        if (State::get('active') !== null) {
            return true;
        }

        if (!self::migrated()) {
            return false;
        }

        State::put('active');

        return true;
    }

    private static function migrationsTable(): string
    {
        $table = config('database.migrations', 'migrations');

        return is_array($table) ? ($table['table'] ?? 'migrations') : $table;
    }

    private static function migrated(): bool
    {
        $table = self::migrationsTable();

        if (!Schema::hasTable($table)) {
            return false;
        }

        $names = [];

        foreach (glob(self::root().'/database/migrations/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            array_push($names, $name, self::CODE.'::'.$name);
        }

        return DB::table($table)->whereIn('migration', $names)->exists();
    }

    private static function hasMarketplace(): bool
    {
        return resolve(ExtensionManager::class)->findExtension(self::MARKETPLACE_CODE) !== null;
    }

    private static function missing(): array
    {
        $composer = json_decode((string) @file_get_contents(self::root().'/composer.json'), true);
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_filter(
            array_keys($require),
            fn ($name) => is_string($name)
                && str_contains($name, '/')
                && $name !== self::MARKETPLACE_PACKAGE
                && !InstalledVersions::isInstalled($name),
        );
    }

    private static function leftover(): ?string
    {
        $root = State::get('failed');

        if ($root === null || $root === '' || !self::isHand($root) || !InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        return $root;
    }

    private static function install(bool $hold): void
    {
        try {
            self::compose();
        } catch (Throwable $e) {
            if ($hold) {
                self::hold();
            }

            throw $e;
        }
    }

    private static function compose(): void
    {
        foreach ([dirname(self::root()), self::root(), self::backups()] as $directory) {
            if (!is_writable($directory)) {
                throw new RuntimeException('Not writable: '.$directory);
            }
        }

        $lock = Cache::lock(self::LOCK, 900);

        if (!$lock->get()) {
            throw new RuntimeException('Another setup is running');
        }

        try {
            ComposerRun::install(...(self::hasMarketplace() ? [self::PACKAGE] : [self::MARKETPLACE_PACKAGE, self::PACKAGE]));
        } finally {
            $lock->release();
        }
    }

    private static function hold(): void
    {
        State::put('held');

        app()->terminating(fn () => rescue(fn () => self::rearm(), null, false));
    }

    private static function rearm(): void
    {
        DB::table(self::migrationsTable())->whereIn('migration', [self::MIGRATION, self::CODE.'::'.self::MIGRATION])->delete();
    }

    private static function move(string $root): void
    {
        State::forget('failed');
        State::forget('held');

        app()->terminating(function () use ($root): void {
            try {
                if (is_dir($root)) {
                    self::relocate($root);
                }

                State::put('finish');
                resolve(CacheHelper::class)->clearCompiled();
            } catch (Throwable $e) {
                self::fail($e, $root);
            }
        });
    }

    private static function relocate(string $root): void
    {
        $backup = self::backups().'/'.basename($root).'-'.date('Ymd-His');

        if (File::moveDirectory($root, $backup)) {
            return;
        }

        try {
            if (!File::copyDirectory($root, $backup)) {
                throw new RuntimeException('Could not copy '.$root);
            }
        } catch (Throwable $e) {
            File::deleteDirectory($backup);

            throw $e;
        }

        if (!File::deleteDirectory($root)) {
            throw new RuntimeException('Could not remove '.$root);
        }
    }

    private static function fail(Throwable $e, string $root): void
    {
        State::put('failed', $root);
        logger()->error(self::CODE.': adding AVi Marketplace failed: '.$e::class.': '.$e->getMessage());
    }
}
