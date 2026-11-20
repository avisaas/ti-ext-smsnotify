<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify\AviMarketplace;

final class Messages
{
    public static function notActive(string $name): string
    {
        return sprintf('%s is copied in but not active yet.', $name);
    }

    public static function activate(): string
    {
        return 'Activate';
    }

    public static function failed(string $name): string
    {
        return sprintf("%s is active. AVi Marketplace couldn't be added yet.", $name);
    }

    public static function held(string $name): string
    {
        return sprintf('%s needs one more step to switch on.', $name);
    }

    public static function tryAgain(): string
    {
        return 'Try again';
    }
}
