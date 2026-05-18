<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoService
{
    /**
     * Create a hosted PayMongo Checkout Session.
     *
     * Amounts are accepted in PHP pesos and converted to centavos for PayMongo.
     */
    public function createCheckoutSession(
        float $amount,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?Booking $booking = null
    ): array {
        $this->assertConfigured();

        $payload = [
            'data' => [
                'attributes' => [
                    'billing' => $booking ? $this->billingDetails($booking) : null,
                    'cancel_url' => $cancelUrl,
                    'description' => $description,
                    'line_items' => [
                        [
                            'amount' => $this->toCentavos($amount),
                            'currency' => config('services.paymongo.currency', 'PHP'),
                            'description' => $description,
                            'name' => $description,
                            'quantity' => 1,
                        ],
                    ],
                    'metadata' => $this->stringifyMetadata($metadata),
                    'payment_method_types' => config('services.paymongo.payment_method_types', ['card', 'gcash', 'paymaya']),
                    'reference_number' => $metadata['reference_number'] ?? null,
                    'send_email_receipt' => (bool) config('services.paymongo.send_email_receipt', true),
                    'show_description' => true,
                    'show_line_items' => true,
                    'statement_descriptor' => config('services.paymongo.statement_descriptor'),
                    'success_url' => $successUrl,
                ],
            ],
        ];

        $payload['data']['attributes'] = array_filter(
            $payload['data']['attributes'],
            fn ($value) => $value !== null && $value !== '' && $value !== []
        );

        try {
            $response = Http::baseUrl((string) config('services.paymongo.base_url'))
                ->withBasicAuth((string) config('services.paymongo.secret_key'), '')
                ->acceptJson()
                ->asJson()
                ->withOptions([
                    'verify' => $this->certificateAuthorityBundle(),
                ])
                ->timeout((int) config('services.paymongo.timeout', 20))
                ->post((string) config('services.paymongo.checkout_endpoint'), $payload)
                ->throw()
                ->json();
        } catch (ConnectionException $exception) {
            report($exception);

            throw new RuntimeException(
                'Unable to connect securely to PayMongo. Please verify the configured CA bundle and internet connection.',
                previous: $exception
            );
        } catch (RequestException $exception) {
            report($exception);

            $message = Arr::get($exception->response?->json() ?? [], 'errors.0.detail')
                ?? Arr::get($exception->response?->json() ?? [], 'errors.0.code')
                ?? 'PayMongo rejected the checkout request.';

            throw new RuntimeException($message, previous: $exception);
        }

        $checkoutUrl = Arr::get($response, 'data.attributes.checkout_url')
            ?? Arr::get($response, 'data.attributes.url')
            ?? Arr::get($response, 'data.checkout_url')
            ?? Arr::get($response, 'data.url');

        if (!$checkoutUrl) {
            throw new RuntimeException('PayMongo did not return a checkout URL.');
        }

        return [
            'id' => Arr::get($response, 'data.id'),
            'checkout_url' => $checkoutUrl,
            'raw' => $response,
        ];
    }

    public function assertConfigured(): void
    {
        if (!config('services.paymongo.secret_key')) {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }
    }

    private function billingDetails(Booking $booking): array
    {
        return array_filter([
            'name' => $booking->client_full_name ?: $booking->user?->username,
            'email' => $booking->client_email ?: $booking->user?->email,
            'phone' => $booking->client_phone,
            'address' => array_filter([
                'line1' => $booking->venue_address_line ?: $booking->venue_street,
                'line2' => $booking->venue_building_details,
                'city' => $booking->venue_city,
                'state' => $booking->venue_province,
                'postal_code' => $booking->venue_zip_code,
                'country' => 'PH',
            ]),
        ]);
    }

    private function stringifyMetadata(array $metadata): array
    {
        return collect($metadata)
            ->reject(fn ($value) => $value === null || $value === '')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    private function certificateAuthorityBundle(): bool|string
    {
        $path = config('services.paymongo.ca_bundle');

        if (!$path) {
            return true;
        }

        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) && !str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        return is_file($path) ? $path : true;
    }

    private function toCentavos(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
