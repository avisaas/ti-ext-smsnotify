<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify\AviMarketplace;

use Composer\InstalledVersions;
use Igniter\System\Helpers\CacheHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class ComposerRun
{
    private const DOWNLOAD = 'https://getcomposer.org/download/latest-stable/composer.phar';

    private const PATH = '/usr/local/bin:/usr/bin:/bin';

    public static function install(string ...$packages): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is not available');
        }

        $env = [
            'COMPOSER_MEMORY_LIMIT' => '-1',
            'PATH' => getenv('PATH') ?: self::PATH,
        ];

        if (!getenv('COMPOSER_HOME')) {
            $env['COMPOSER_HOME'] = storage_path('app/composer');
        }

        $command = [...self::binary($env['PATH']), 'require', ...$packages, '--update-no-dev', '--no-interaction'];
        $saved = self::saved();
        $cache = resolve(CacheHelper::class);

        $cache->clearCompiled();

        try {
            (new Process($command, base_path(), $env))->setTimeout(null)->mustRun();
        } catch (Throwable $e) {
            foreach ($saved as $path => $content) {
                file_put_contents($path, $content);
            }

            throw $e;
        } finally {
            $cache->clearCompiled();
        }
    }

    private static function binary(string $path): array
    {
        $phar = base_path('composer.phar');

        if (is_file($phar)) {
            return [self::php(), $phar];
        }

        $found = (new ExecutableFinder)->find('composer', null, explode(PATH_SEPARATOR, $path));

        return $found === null ? [self::php(), self::download($phar)] : [$found];
    }

    private static function php(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: throw new RuntimeException('php binary not found');
    }

    private static function download(string $phar): string
    {
        $expected = trim(Http::timeout(60)->get(self::DOWNLOAD.'.sha256')->throw()->body());
        $part = $phar.'.'.bin2hex(random_bytes(6)).'.part';

        try {
            Http::timeout(600)->sink($part)->get(self::DOWNLOAD)->throw();

            $hash = hash_file('sha256', $part);

            if ($hash === false || !hash_equals(strtolower($expected), $hash)) {
                throw new RuntimeException('composer.phar checksum does not match');
            }

            if (!rename($part, $phar)) {
                throw new RuntimeException('composer.phar could not be saved');
            }
        } finally {
            File::delete($part);
        }

        return $phar;
    }

    private static function saved(): array
    {
        $vendor = dirname((new ReflectionClass(InstalledVersions::class))->getFileName());
        $saved = [];

        foreach ([$vendor.'/installed.json', $vendor.'/installed.php', base_path('composer.json'), base_path('composer.lock')] as $path) {
            if (is_file($path)) {
                $saved[$path] = file_get_contents($path);
            }
        }

        return $saved;
    }
}
