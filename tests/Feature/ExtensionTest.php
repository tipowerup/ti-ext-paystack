<?php

declare(strict_types=1);

use Tipowerup\Paystack\Extension;
use Tipowerup\Paystack\Payments\Paystack;

it('boots the extension', function (): void {
    $extension = new Extension($this->app);

    expect($extension)->toBeInstanceOf(Extension::class);
});

it('registers the paystack payment gateway', function (): void {
    $extension = new Extension($this->app);

    $gateways = $extension->registerPaymentGateways();

    expect($gateways)->toHaveKey(Paystack::class)
        ->and($gateways[Paystack::class]['code'])->toBe('paystack');
});
