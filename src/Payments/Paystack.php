<?php

declare(strict_types=1);

namespace Tipowerup\Paystack\Payments;

use Exception;
use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Main\Classes\MainController;
use Igniter\PayRegister\Classes\BasePaymentGateway;
use Igniter\PayRegister\Concerns\WithPaymentProfile;
use Igniter\PayRegister\Concerns\WithPaymentRefund;
use Igniter\PayRegister\Models\Payment;
use Igniter\PayRegister\Models\PaymentProfile;
use Igniter\System\Traits\SessionMaker;
use Igniter\User\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Override;
use Tipowerup\Paystack\Classes\PaystackApi;

class Paystack extends BasePaymentGateway
{
    use SessionMaker;
    use WithPaymentProfile;
    use WithPaymentRefund;

    protected string $sessionKey = 'ti_tipowerup_paystack';

    public static ?string $paymentFormView = 'tipowerup.paystack::_partials.paystack.payment_form';

    #[Override]
    public function defineFieldsConfig(): string
    {
        return 'tipowerup.paystack::/models/paystack';
    }

    #[Override]
    public function registerEntryPoints(): array
    {
        return [
            'paystack_initialize_transaction' => 'initializeTransaction',
            'paystack_payment_successful' => 'paymentSuccessful',
            'paystack_webhook' => 'processWebhookUrl',
            'paystack_cancel_url' => 'paymentCancelled',
        ];
    }

    /**
     * @param  self  $host
     * @param  MainController  $controller
     */
    #[Override]
    public function beforeRenderPaymentForm($host, $controller): void
    {
        $controller->addJs('https://js.paystack.co/v2/inline.js', 'paystack-inline-js');
        $controller->addJs('tipowerup.paystack::/js/process.paystack.js', 'process-paystack-js');
    }

    public function isTestMode(): bool
    {
        return $this->model->transaction_mode != 'live';
    }

    public function getSecretKey(): string
    {
        return $this->isTestMode()
            ? (string) $this->model->test_secret_key
            : (string) $this->model->live_secret_key;
    }

    public function getIntegrationType(): string
    {
        return $this->model->integration_type ?? 'popup';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getSecretKey());
    }

    #[Override]
    public function completesPaymentOnClient(): bool
    {
        return $this->getIntegrationType() === 'popup';
    }

    //
    // Payment Processing
    //

    /**
     * @param  array  $data
     * @param  Payment  $host
     * @param  Order  $order
     *
     * @throws ApplicationException
     */
    #[Override]
    public function processPaymentForm($data, $host, $order): bool|RedirectResponse
    {
        $this->validateApplicableFee($order, $host);

        if ($this->getIntegrationType() === 'popup') {
            return true;
        }

        try {
            $response = $this->initializeTransaction([$order->hash]);
            if (! array_has($response, 'authorization_url')) {
                throw new ApplicationException(lang('tipowerup.paystack::default.alert_transaction_failed'));
            }

            return redirect()->to(array_get($response, 'authorization_url'));
        } catch (Exception $ex) {
            $order->logPaymentAttempt(
                lang('tipowerup.paystack::default.alert_payment_error', ['message' => $ex->getMessage()]),
                0,
                [],
                request()->only(['reference', 'trxref']),
            );

            throw new ApplicationException($ex->getMessage());
        }
    }

    public function initializeTransaction(array $params = []): JsonResponse|array
    {
        $hash = $params[0] ?? null;
        $order = $hash ? $this->createOrderModel()->whereHash($hash)->first() : null;

        if (! $order) {
            return response()->json(['message' => lang('tipowerup.paystack::default.alert_transaction_failed')], 404);
        }

        $this->forgetSession($this->sessionKey.'.create_payment_profile');

        if (request()->input('create_payment_profile')) {
            $this->putSession($this->sessionKey.'.create_payment_profile', true);
        }

        try {
            $metadata = $this->getMetadata($order);
            $metadata['cancel_action'] = $this->makeEntryPointUrl('paystack_cancel_url').'/'.$order->hash;

            $data = [
                'email' => $order->email,
                'amount' => (int) ($order->order_total * 100),
                'currency' => currency()->getUserCurrency(),
                'metadata' => json_encode($metadata),
            ];

            if ($this->getIntegrationType() === 'redirect') {
                $data['callback_url'] = $this->makeEntryPointUrl('paystack_payment_successful').'/'.$order->hash;
            }

            $response = $this->createGateway()->initializeTransaction($data);

            return $response['data'] ?? [];
        } catch (Exception $ex) {
            $order->logPaymentAttempt($ex->getMessage(), 0, [], []);

            return response()->json(['message' => $ex->getMessage()], 422);
        }
    }

    //
    // Payment Profiles
    //

    #[Override]
    public function supportsPaymentProfiles(): bool
    {
        return true;
    }

    #[Override]
    public function updatePaymentProfile(Customer $customer, array $data = []): PaymentProfile
    {
        if (! $profile = $this->model->findPaymentProfile($customer)) {
            $profile = $this->model->initPaymentProfile($customer);
        }

        $profileData = array_merge((array) $profile->profile_data, $data);

        $profile->card_brand = strtolower((string) array_get($profileData, 'card_type'));
        $profile->card_last4 = array_get($profileData, 'last4');
        $profile->setProfileData($profileData);

        return $profile;
    }

    #[Override]
    public function deletePaymentProfile(Customer $customer, PaymentProfile $profile): void
    {
        // Paystack does not support revoking authorizations via API
    }

    #[Override]
    public function payFromPaymentProfile(Order $order, array $data = []): void
    {
        $profile = $this->model->findPaymentProfile($order->customer);
        if (! $profile || ! array_has($profile->profile_data, 'authorization_code')) {
            throw new ApplicationException(
                lang('tipowerup.paystack::default.alert_payment_profile_not_found')
            );
        }

        $metadata = $this->getMetadata($order);
        $metadata['cancel_action'] = $this->makeEntryPointUrl('paystack_cancel_url').'/'.$order->hash;

        $chargeData = [
            'email' => $order->email,
            'amount' => (int) ($order->order_total * 100),
            'currency' => currency()->getUserCurrency(),
            'authorization_code' => $profile->profile_data['authorization_code'],
            'metadata' => json_encode($metadata),
        ];

        $response = [];

        try {
            $response = $this->createGateway()->chargeAuthorization($chargeData);

            if (array_get($response, 'data.paused')) {
                redirect()->to(array_get($response, 'data.authorization_url'))->send();

                return;
            }

            if (array_get($response, 'data.status') != 'success') {
                throw new ApplicationException(array_get($response, 'message'));
            }

            $order->logPaymentAttempt(
                lang('tipowerup.paystack::default.alert_payment_successful'),
                1,
                $chargeData,
                $response,
                true,
            );
            $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
        } catch (Exception $ex) {
            $order->logPaymentAttempt(
                lang('tipowerup.paystack::default.alert_payment_error', ['message' => $ex->getMessage()]),
                0,
                $chargeData,
                $response,
            );

            throw new ApplicationException($ex->getMessage());
        }
    }

    #[Override]
    public function paymentProfileExists(Customer $customer): ?bool
    {
        $profile = $this->model->findPaymentProfile($customer);

        return $profile && ! empty(array_get((array) $profile->profile_data, 'authorization_code'));
    }

    //
    // Refunds
    //

    #[Override]
    public function processRefundForm($data, $order, $paymentLog): void
    {
        $paymentResponse = $paymentLog->response;
        if (! is_null($paymentLog->refunded_at) || ! is_array($paymentResponse)) {
            throw new ApplicationException(lang('tipowerup.paystack::default.alert_refund_nothing_to_refund'));
        }

        if (array_get($paymentResponse, 'data.status') != 'success') {
            throw new ApplicationException(lang('tipowerup.paystack::default.alert_payment_not_settled'));
        }

        $transactionId = array_get($paymentLog->response, 'data.reference');

        $refundAmount = array_get($data, 'refund_type') === 'full'
            ? $order->order_total
            : array_get($data, 'refund_amount');

        if ($refundAmount > $order->order_total) {
            throw new ApplicationException(lang('tipowerup.paystack::default.alert_refund_amount_should_be_less'));
        }

        $response = [];

        try {
            $response = $this->createGateway()->createRefund($transactionId, (int) ($refundAmount * 100));
            $status = array_get($response, 'status');
            if ($status === 'success' || $status === 'pending') {
                $paymentLog->markAsRefundProcessed();
                $order->logPaymentAttempt(array_get($response, 'message'), 1, [], $response);
            }
        } catch (Exception $ex) {
            $order->logPaymentAttempt(
                lang('tipowerup.paystack::default.alert_refund_failed', ['message' => $ex->getMessage()]),
                0,
                [],
                $response,
            );

            throw new ApplicationException($ex->getMessage());
        }
    }

    //
    // Webhook
    //

    public function processWebhookUrl(): mixed
    {
        if (strtolower(request()->method()) !== 'post') {
            return response()->json('Request method must be POST', 400);
        }

        $signature = request()->header('x-paystack-signature');
        if (! $signature) {
            return response()->json('Missing signature', 400);
        }

        $input = request()->getContent();
        $secretKey = $this->getSecretKey();

        if ($signature !== hash_hmac('sha512', $input, $secretKey)) {
            return response()->json('Invalid signature', 400);
        }

        $payload = json_decode($input, true);

        if (($payload['event'] ?? null) !== 'charge.success') {
            return response()->json('Webhook received');
        }

        $orderHash = $this->extractOrderHash($payload);
        if (! $orderHash) {
            return response()->json('Order hash not found', 400);
        }

        $order = $this->createOrderModel()->whereHash($orderHash)->first();
        if (! $order) {
            return response()->json('Order not found', 404);
        }

        if ($order->isPaymentProcessed()) {
            return response()->json('Order already processed');
        }

        $payloadData = $payload['data'] ?? [];
        if ((int) ($payloadData['amount'] ?? 0) !== (int) ($order->order_total * 100)) {
            return response()->json('Amount mismatch', 400);
        }

        if (($payloadData['status'] ?? null) === 'success') {
            $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
            $order->markAsPaymentProcessed();
            $order->logPaymentAttempt(
                lang('tipowerup.paystack::default.alert_payment_successful'),
                1,
                $payload,
                $payloadData,
                true,
            );
        } else {
            $order->logPaymentAttempt($payloadData['message'] ?? 'Unknown error', 0, $payload, $payloadData);
        }

        return response()->json('Webhook Handled');
    }

    //
    // Entry Points
    //

    /**
     * @param  array<int, string>  $params
     */
    public function paymentSuccessful(array $params = []): mixed
    {
        $hash = $params[0] ?? null;
        $order = $hash ? $this->createOrderModel()->whereHash($hash)->first() : null;

        try {
            if (! $order) {
                throw new Exception(lang('tipowerup.paystack::default.alert_transaction_failed'));
            }

            if (! $order->isPaymentProcessed()) {
                $reference = request()->input('reference') ?? request()->input('trxref');
                if (! $reference) {
                    throw new Exception(lang('tipowerup.paystack::default.alert_transaction_failed'));
                }

                $response = $this->createGateway()->verifyTransaction($reference);

                $orderHash = $this->extractOrderHash($response);
                if ($orderHash != $order->hash) {
                    throw new Exception(lang('tipowerup.paystack::default.alert_order_hash_mismatch'));
                }

                if ((int) array_get($response, 'data.amount', 0) !== (int) ($order->order_total * 100)) {
                    throw new Exception(lang('tipowerup.paystack::default.alert_amount_mismatch'));
                }

                if (array_get($response, 'data.status') === 'success') {
                    $order->updateOrderStatus($this->model->order_status, ['notify' => false]);
                    $order->markAsPaymentProcessed();
                    $order->logPaymentAttempt(
                        lang('tipowerup.paystack::default.alert_payment_successful'),
                        1,
                        request()->only(['reference', 'trxref']),
                        $response,
                        true,
                    );
                } else {
                    $order->logPaymentAttempt(
                        'Payment verification returned status: '.array_get($response, 'data.status', 'unknown'),
                        0,
                        request()->only(['reference', 'trxref']),
                        $response,
                    );

                    throw new Exception(lang('tipowerup.paystack::default.alert_transaction_failed'));
                }

                $createPaymentProfile = $this->getSession($this->sessionKey.'.create_payment_profile');

                if ($createPaymentProfile && array_get($response, 'data.authorization.reusable')) {
                    $this->updatePaymentProfile($order->customer, array_get($response, 'data.authorization'));
                }

                $this->forgetSession($this->sessionKey.'.create_payment_profile');
            }

            if ($this->getIntegrationType() === 'redirect') {
                return redirect()->to(page_url('checkout.checkout'));
            }

            return response()->json(['success' => true]);
        } catch (Exception $ex) {
            $order?->logPaymentAttempt($ex->getMessage(), 0, request()->only(['reference', 'trxref']), $response ?? []);

            if ($this->getIntegrationType() === 'redirect') {
                flash()->warning(lang('tipowerup.paystack::default.alert_transaction_failed'))
                    ->important()->now();

                return redirect()->to(page_url('checkout.checkout'));
            }

            return response()->json(['message' => $ex->getMessage()], 422);
        }
    }

    /**
     * @param  array<int, string>  $params
     */
    public function paymentCancelled(array $params = []): RedirectResponse
    {
        $hash = $params[0] ?? null;
        $order = $hash ? $this->createOrderModel()->whereHash($hash)->first() : null;

        if (! $order || ! $order->isPaymentProcessed()) {
            flash()->warning(lang('tipowerup.paystack::default.alert_transaction_failed'))
                ->important()->now();
        }

        return redirect()->to(page_url('checkout.checkout'));
    }

    //
    // Helpers
    //

    protected function getCustomFields(Order $order): array
    {
        $customFields = [
            [
                'display_name' => 'Invoice ID',
                'variable_name' => 'invoice_id',
                'value' => $order->order_id,
            ],
            [
                'display_name' => 'Customer Name',
                'variable_name' => 'customer_name',
                'value' => $order->first_name.' '.$order->last_name,
            ],
            [
                'display_name' => 'Customer Email',
                'variable_name' => 'customer_email',
                'value' => $order->email,
            ],
            [
                'display_name' => 'Customer Phone',
                'variable_name' => 'customer_phone',
                'value' => $order->telephone,
            ],
            [
                'display_name' => 'Order Hash',
                'variable_name' => 'order_hash',
                'value' => $order->hash,
            ],
        ];

        $eventResult = $this->fireSystemEvent('tipowerup.paystack.extendCustomFields', [$customFields, $order], false);

        if (is_array($eventResult)) {
            $customFields = array_merge($customFields, ...$eventResult);
        }

        return $customFields;
    }

    protected function getMetadata(Order $order): array
    {
        $metadata = [
            'order_hash' => $order->hash,
            'custom_fields' => $this->getCustomFields($order),
        ];

        $eventResult = $this->fireSystemEvent('tipowerup.paystack.extendMetadata', [$metadata, $order], false);

        if (is_array($eventResult)) {
            $metadata = array_merge($metadata, ...$eventResult);
        }

        return $metadata;
    }

    protected function extractOrderHash(array $data): ?string
    {
        // Try metadata root first
        if ($hash = array_get($data, 'data.metadata.order_hash')) {
            return $hash;
        }

        // Fallback to custom_fields
        $customFields = array_get($data, 'data.metadata.custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (($field['variable_name'] ?? null) === 'order_hash') {
                    return $field['value'] ?? null;
                }
            }
        }

        return null;
    }

    protected function createGateway(): PaystackApi
    {
        return new PaystackApi($this->getSecretKey());
    }

    public function isCardIconSupported(string $cardType): bool
    {
        $cards = ['visa', 'mastercard', 'paypal', 'amex', 'discover', 'diners-club', 'jcb'];

        return in_array($cardType, $cards);
    }
}
