<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;

class PaymentController extends Controller
{
    private const MILESTONE_RATIOS = [
        'Reservation' => 0.10,
        'DownPayment' => 0.70,
        'Final' => 0.20,
    ];

    private const MILESTONE_LABELS = [
        'Reservation' => '10% Downpayment',
        'DownPayment' => '70% Progress Payment',
        'Final' => '20% Final Payment',
    ];

    public function initializeCheckout(Request $request, PayMongoService $payMongo)
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
        ]);

        $booking = Booking::with(['user', 'payments'])
            ->where('id', $validated['booking_id'])
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $payment = $booking->payments
            ->firstWhere('id', (int) $validated['payment_id']);

        if (!$payment) {
            return back()->with('error', 'Payment milestone not found for this booking.');
        }

        $validationError = $this->validatePayableMilestone($booking, $payment);

        if ($validationError) {
            return back()->with('error', $validationError);
        }

        $amount = $this->calculateMilestoneAmount($booking, $payment->payment_type);
        $description = $this->checkoutDescription($booking, $payment);
        $successUrl = route('checkout.success', [
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
        ]);
        $cancelUrl = route('checkout.cancelled');

        try {
            $checkout = $payMongo->createCheckoutSession(
                amount: $amount,
                description: $description,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                metadata: [
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'payment_type' => $payment->payment_type,
                    'milestone_percentage' => $this->milestonePercentage($payment->payment_type),
                    'reference_number' => $this->referenceNumber($booking, $payment),
                ],
                booking: $booking
            );
        } catch (RuntimeException $exception) {
            Log::error('PayMongo checkout initialization failed', [
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->with(
                'error',
                config('app.debug')
                    ? $exception->getMessage()
                    : 'Unable to create PayMongo checkout. Please try again in a moment.'
            );
        }

        $payment->forceFill([
            'amount' => $amount,
            'payment_method' => 'PayMongo Checkout',
            'paymongo_checkout_session_id' => $checkout['id'],
            'paymongo_reference_number' => $this->referenceNumber($booking, $payment),
        ])->save();

        Log::info('PayMongo checkout session created', [
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'paymongo_checkout_session_id' => $checkout['id'],
            'amount' => $amount,
        ]);

        return Inertia::location($checkout['checkout_url']);
    }

    public function showSecureCheckout(Request $request)
    {
        abort(410, 'Local secure checkout has been replaced by PayMongo Checkout.');
    }

    public function processPayment(Request $request)
    {
        return response()->json([
            'error' => 'Direct checkout processing is disabled. Payments must be completed through PayMongo and confirmed by webhook.',
        ], 410);
    }

    private function validatePayableMilestone(Booking $booking, Payment $payment): ?string
    {
        if (!array_key_exists($payment->payment_type, self::MILESTONE_RATIOS)) {
            return 'Only the configured 10%, 70%, and 20% payment milestones can be paid online.';
        }

        if (!in_array($payment->status, ['Pending', 'Failed', 'Rejected'], true)) {
            return 'This payment milestone is not payable.';
        }

        if ((float) $booking->total_cost <= 0) {
            return 'Booking total must be greater than zero before checkout can be created.';
        }

        $nextPayment = $booking->payments
            ->whereIn('status', ['Pending', 'Failed', 'Rejected'])
            ->sortBy(fn (Payment $candidate) => $this->milestoneOrder($candidate->payment_type))
            ->first();

        if ($nextPayment && $nextPayment->id !== $payment->id) {
            return 'Payments must be completed in the required 10%, 70%, then 20% order.';
        }

        return null;
    }

    private function calculateMilestoneAmount(Booking $booking, string $paymentType): float
    {
        $totalCost = (float) $booking->total_cost;

        if ($paymentType === 'Final') {
            $priorMilestones = collect(['Reservation', 'DownPayment'])
                ->sum(fn (string $type) => round($totalCost * self::MILESTONE_RATIOS[$type], 2));

            return round($totalCost - $priorMilestones, 2);
        }

        return round($totalCost * self::MILESTONE_RATIOS[$paymentType], 2);
    }

    private function checkoutDescription(Booking $booking, Payment $payment): string
    {
        $eventName = $booking->event_type ?: 'Event';

        return sprintf(
            '%s for %s Booking #%d',
            self::MILESTONE_LABELS[$payment->payment_type],
            $eventName,
            $booking->id
        );
    }

    private function milestonePercentage(string $paymentType): string
    {
        return match ($paymentType) {
            'Reservation' => '10',
            'DownPayment' => '70',
            'Final' => '20',
            default => '0',
        };
    }

    private function milestoneOrder(string $paymentType): int
    {
        return match ($paymentType) {
            'Reservation' => 1,
            'DownPayment' => 2,
            'Final' => 3,
            default => 99,
        };
    }

    private function referenceNumber(Booking $booking, Payment $payment): string
    {
        return sprintf('ECS-%d-P%d', $booking->id, $payment->id);
    }
}
