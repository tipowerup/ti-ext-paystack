<?php

declare(strict_types=1);

namespace Tipowerup\Paystack;

use Facades\Igniter\System\Helpers\SystemHelper;
use Igniter\System\Classes\BaseExtension;
use Override;
use Tipowerup\Paystack\Payments\Paystack;

class Extension extends BaseExtension
{
    /**
     * Return extension metadata from the package root composer.json.
     *
     * TI resolves the config path from the Extension class file location,
     * which is `src/`. Our composer.json lives one level up at the package root.
     */
    #[Override]
    public function extensionMeta(): array
    {
        if (func_get_args()) {
            return $this->config = func_get_arg(0);
        }

        if (!is_null($this->config)) {
            return $this->config;
        }

        return $this->config = SystemHelper::extensionConfigFromFile(dirname(__DIR__));
    }

    #[Override]
    public function registerPaymentGateways(): array
    {
        return [
            Paystack::class => [
                'code' => 'paystack',
                'name' => 'lang:tipowerup.paystack::default.text_payment_title',
                'description' => 'lang:tipowerup.paystack::default.text_payment_desc',
            ],
        ];
    }
}
