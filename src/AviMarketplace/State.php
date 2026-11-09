<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify\AviMarketplace;

final class State
{
    public static function get(string $kind): ?string
    {
        $content = @file_get_contents(self::path($kind));

        return $content === false ? null : $content;
    }

    public static function put(string $kind, string $content = ''): void
    {
        $path = self::path($kind);

        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, $content);
    }

    public static function forget(string $kind): bool
    {
        return @unlink(self::path($kind));
    }

    private static function path(string $kind): string
    {
        return storage_path('app/avi-marketplace/'.Setup::CODE.'.'.$kind);
    }
}
