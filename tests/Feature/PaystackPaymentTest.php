<?php

declare(strict_types=1);

use Igniter\Cart\Models\Order;
use Igniter\PayRegister\Models\Payment;
use Tipowerup\Paystack\Payments\Paystack;

function setProtectedProperty(object $object, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($object, $value);
}

beforeEach(function (): void {
    $this->paymentModel = Mockery::mock(Payment::class)->makePartial();
    $this->paymentModel->shouldReceive('getAttribute')->with('transaction_mode')->andReturn('test');
    $this->paymentModel->shouldReceive('getAttribute')->with('test_secret_key')->andReturn('sk_test_123');
    $this->paymentModel->shouldReceive('getAttribute')->with('live_secret_key')->andReturn('sk_live_456');
    $this->paymentModel->shouldReceive('getAttribute')->with('integration_type')->andReturn('popup');
    $this->paymentModel->shouldReceive('getAttribute')->with('order_status')->andReturn(1);
    $this->paymentModel->shouldReceive('getAttribute')->with('order_total')->andReturn(0);
    $this->paymentModel->shouldReceive('getAttribute')->with('order_fee')->andReturn(0);
    $this->paymentModel->shouldReceive('getAttribute')->with('code')->andReturn('paystack');
    $this->paymentModel->shouldReceive('getAttribute')->with('class_name')->andReturn(Paystack::class);
    $this->paymentModel->shouldReceive('getAttribute')->with('name')->andReturn('Paystack');
    $this->paymentModel->shouldReceive('getKey')->andReturn(1);
    $this->paymentModel->exists = false;

    $this->gateway = Mockery::mock(Paystack::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $this->gateway->shouldReceive('getHostObject')->andReturn($this->paymentModel);
    setProtectedProperty($this->gateway, 'model', $this->paymentModel);
});

it('returns true for popup integration type in processPaymentForm', function (): void {
    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('getAttribute')->with('order_total')->andReturn(100);
    $order->shouldReceive('getAttribute')->with('payment_method')->andReturn($this->paymentModel);

    $this->gateway->shouldReceive('validateApplicableFee')->once();
    $this->gateway->shouldReceive('getIntegrationType')->andReturn('popup');

    $result = $this->gateway->processPaymentForm([], $this->paymentModel, $order);

    expect($result)->toBeTrue();
});

it('returns redirect for redirect integration type in processPaymentForm', function (): void {
    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('getAttribute')->with('order_total')->andReturn(100);
    $order->shouldReceive('getAttribute')->with('payment_method')->andReturn($this->paymentModel);
    $order->shouldReceive('logPaymentAttempt')->never();

    $this->gateway->shouldReceive('validateApplicableFee')->once();
    $this->gateway->shouldReceive('getIntegrationType')->andReturn('redirect');
    $this->gateway->shouldReceive('initializeTransaction')
        ->andReturn(['authorization_url' => 'https://paystack.com/pay/test']);

    $result = $this->gateway->processPaymentForm([], $this->paymentModel, $order);

    expect($result)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
});

it('returns correct test mode status', function (): void {
    expect($this->gateway->isTestMode())->toBeTrue();
});

it('returns correct secret key based on mode', function (): void {
    expect($this->gateway->getSecretKey())->toBe('sk_test_123');
});

it('returns default popup integration type', function (): void {
    expect($this->gateway->getIntegrationType())->toBe('popup');
});

it('supports payment profiles', function (): void {
    expect($this->gateway->supportsPaymentProfiles())->toBeTrue();
});

it('validates webhook with valid HMAC signature', function (): void {
    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'status' => 'success',
            'amount' => 10000,
            'metadata' => [
                'order_hash' => 'test-hash',
                'custom_fields' => [],
            ],
        ],
    ]);

    $signature = hash_hmac('sha512', $payload, 'sk_test_123');

    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('getAttribute')->with('order_total')->andReturn(100);
    $order->shouldReceive('isPaymentProcessed')->andReturn(false);
    $order->shouldReceive('updateOrderStatus')->once();
    $order->shouldReceive('markAsPaymentProcessed')->once();
    $order->shouldReceive('logPaymentAttempt')->once();

    $orderModel = Mockery::mock();
    $orderModel->shouldReceive('whereHash')->with('test-hash')->andReturnSelf();
    $orderModel->shouldReceive('first')->andReturn($order);

    $this->gateway->shouldReceive('createOrderModel')->andReturn($orderModel);

    $request = request();
    $request->setMethod('POST');
    $request->headers->set('x-paystack-signature', $signature);

    $reflection = new ReflectionClass($request);
    $property = $reflection->getProperty('content');
    $property->setAccessible(true);
    $property->setValue($request, $payload);

    $response = $this->gateway->processWebhookUrl();

    expect($response->getStatusCode())->toBe(200);
});

it('rejects webhook with invalid signature', function (): void {
    $payload = json_encode(['event' => 'charge.success']);

    $request = request();
    $request->setMethod('POST');
    $request->headers->set('x-paystack-signature', 'invalid-signature');

    $reflection = new ReflectionClass($request);
    $property = $reflection->getProperty('content');
    $property->setAccessible(true);
    $property->setValue($request, $payload);

    $response = $this->gateway->processWebhookUrl();

    expect($response->getStatusCode())->toBe(400);
});

it('rejects non-POST webhook requests', function (): void {
    $request = request();
    $request->setMethod('GET');

    $response = $this->gateway->processWebhookUrl();

    expect($response->getStatusCode())->toBe(400);
});

it('skips already processed orders in webhook', function (): void {
    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'status' => 'success',
            'amount' => 10000,
            'metadata' => [
                'order_hash' => 'test-hash',
                'custom_fields' => [],
            ],
        ],
    ]);

    $signature = hash_hmac('sha512', $payload, 'sk_test_123');

    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('isPaymentProcessed')->andReturn(true);
    $order->shouldReceive('updateOrderStatus')->never();

    $orderModel = Mockery::mock();
    $orderModel->shouldReceive('whereHash')->with('test-hash')->andReturnSelf();
    $orderModel->shouldReceive('first')->andReturn($order);

    $this->gateway->shouldReceive('createOrderModel')->andReturn($orderModel);

    $request = request();
    $request->setMethod('POST');
    $request->headers->set('x-paystack-signature', $signature);

    $reflection = new ReflectionClass($request);
    $property = $reflection->getProperty('content');
    $property->setAccessible(true);
    $property->setValue($request, $payload);

    $response = $this->gateway->processWebhookUrl();

    expect($response->getStatusCode())->toBe(200);
});

it('identifies supported card icons', function (): void {
    expect($this->gateway->isCardIconSupported('visa'))->toBeTrue()
        ->and($this->gateway->isCardIconSupported('mastercard'))->toBeTrue()
        ->and($this->gateway->isCardIconSupported('unknown'))->toBeFalse();
});
