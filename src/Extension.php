<?php

declare(strict_types=1);

namespace IgniterLabs\SmsNotify;

use IgniterLabs\SmsNotify\AviMarketplace\Admin;
use IgniterLabs\SmsNotify\AviMarketplace\Setup;

class Extension extends UpstreamExtension
{
    private bool $aviMarketplaceGated = false;

    public function __construct($app)
    {
        parent::__construct($app);

        $gated = Setup::gated();
        $this->aviMarketplaceGated = $gated;

        if ($gated) {
            $this->disabled = true;
        }

        rescue(fn () => Admin::attach($gated), null, false);
    }

    public function register(): void
    {
        if (!Setup::withholds($this->aviMarketplaceGated)) {
            parent::register();
        }
    }

    public function boot(): void
    {
        if (method_exists(parent::class, 'boot') && !Setup::withholds($this->aviMarketplaceGated)) {
            parent::boot();
        }
    }
}
