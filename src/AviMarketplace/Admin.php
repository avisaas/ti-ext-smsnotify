<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify\AviMarketplace;

use Igniter\Admin\Classes\AdminController;
use Igniter\System\Classes\ExtensionManager;
use Igniter\User\Facades\AdminAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;

final class Admin
{
    private const PERMISSION = 'Admin.Extensions';

    public static function attach(bool $gated): void
    {
        $pending = self::pending();
        $finishing = !Setup::isHand() && State::get('finish') !== null;

        if (!$gated && !$pending && !$finishing) {
            return;
        }

        Event::listen('admin.controller.beforeResponse', function () use ($gated, $finishing) {
            rescue(fn () => self::page($gated, $finishing));
        });

        if ($pending) {
            AdminController::extend(function (AdminController $controller) {
                $controller->addDynamicMethod(Setup::HANDLER, fn () => self::retry());
            });
        }
    }

    private static function page(bool $gated, bool $finishing): void
    {
        $user = AdminAuth::user();

        if ($user === null || !request()->isMethod('GET') || request()->ajax()) {
            return;
        }

        if ($finishing) {
            Setup::finish();
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            return;
        }

        if ($gated) {
            self::flash(State::get('held') !== null ? self::withRetry(Messages::held(Setup::NAME)) : self::notActive());
        } elseif (State::get('failed') !== null && !resolve(ExtensionManager::class)->isDisabled(Setup::CODE)) {
            self::flash(self::withRetry(Messages::failed(Setup::NAME)));
        }
    }

    private static function retry(): RedirectResponse
    {
        if (AdminAuth::user()?->hasPermission(self::PERMISSION) && self::pending()) {
            Setup::run();
        }

        return redirect()->back();
    }

    private static function pending(): bool
    {
        return State::get('failed') !== null || State::get('held') !== null;
    }

    private static function notActive(): string
    {
        return sprintf(
            "%s <a href='%s' class='text-reset fw-bold text-decoration-underline'>%s</a>",
            e(Messages::notActive(Setup::NAME)),
            e(admin_url('extensions')),
            e(Messages::activate()),
        );
    }

    private static function withRetry(string $message): string
    {
        return sprintf(
            "%s <button type='button' class='btn btn-light btn-sm' data-request='%s'>%s</button>",
            e($message),
            Setup::HANDLER,
            e(Messages::tryAgain()),
        );
    }

    private static function flash(string $html): void
    {
        if (flash()->messages()->contains(fn ($message) => $message->message === $html)) {
            return;
        }

        flash()->warning($html)->important();
    }
}
