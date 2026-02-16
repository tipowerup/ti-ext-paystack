<?php

declare(strict_types=1);

namespace Tipowerup\Paystack\Classes;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PaystackApi
{
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct(private readonly string $secretKey) {}

    /**
     * @throws Exception
     */
    public function initializeTransaction(array $data): array
    {
        $response = $this->initializeClient()
            ->post('/transaction/initialize', $data);

        $responseData = $response->json();
        if ($response->failed()) {
            throw new Exception($responseData['message'] ?? 'Failed to initialize transaction');
        }

        return $responseData;
    }

    /**
     * @throws Exception
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->initializeClient()
            ->get('/transaction/verify/'.urlencode($reference));

        $responseData = $response->json();
        if ($response->failed()) {
            throw new Exception($responseData['message'] ?? 'Failed to verify transaction');
        }

        return $responseData;
    }

    /**
     * @throws Exception
     */
    public function chargeAuthorization(array $data): array
    {
        $response = $this->initializeClient()
            ->post('/transaction/charge_authorization', $data);

        $responseData = $response->json();
        if ($response->failed()) {
            throw new Exception($responseData['message'] ?? 'Failed to charge authorization');
        }

        return $responseData;
    }

    /**
     * @throws Exception
     */
    public function createRefund(string $transactionId, int $amount): array
    {
        $response = $this->initializeClient()
            ->post('/refund', [
                'transaction' => $transactionId,
                'amount' => $amount,
            ]);

        $responseData = $response->json();
        if ($response->failed()) {
            throw new Exception($responseData['message'] ?? 'Failed to refund transaction');
        }

        return $responseData;
    }

    private function initializeClient(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }
}
