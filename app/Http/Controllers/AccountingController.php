<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Accounting dashboard controller
 * 8 methods for the Accounting dashboard.
 */
class AccountingController extends Controller
{
    /**
     * Show the Accounting dashboard page.
     */
    public function index()
    {
        return Inertia::render('DashboardAccounting');
    }

    /**
     * Get all bookings with their payment schedules.
     * Ported from: accountingController.getBookingsWithPayments()
     */
    public function getBookingsWithPayments()
    {
        $bookings = Booking::with(['user:id,username', 'payments' => function ($q) {
                $q->whereNotNull('payment_type')
                  ->orderByRaw("CASE payment_type WHEN 'Reservation' THEN 1 WHEN 'DownPayment' THEN 2 WHEN 'Final' THEN 3 ELSE 4 END")
                  ->orderBy('due_date')
                  ->orderBy('id');
            }])
            ->where('status', '!=', 'Cancelled')
            ->where('status', '!=', 'Pending') // Do not show pending (unapproved) bookings
            ->orderBy('event_date', 'asc')
            ->get()
            ->map(function ($b) {
                return array_merge($b->toArray(), [
                    'totalCost' => $b->total_cost ?? $b->budget ?? 0,
                    'username'  => $b->user->username ?? null,
                ]);
            });

        return response()->json($bookings);
    }

    /**
     * Get pending payments for verification queue.
     * Ported from: accountingController.getPendingPayments()
     */
    public function getPendingPayments()
    {
        $payments = Payment::with(['booking:id,event_date,client_full_name,user_id', 'booking.user:id,username'])
            ->where('status', 'Pending')
            ->whereHas('booking', function ($q) {
                $q->where('status', '!=', 'Pending'); // Only payments for approved/confirmed bookings
            })
            ->orderBy('due_date', 'asc')
            ->get()
            ->map(function ($p) {
                $data = $p->toArray();
                $data['event_date'] = $p->booking->event_date ?? null;
                $data['client_full_name'] = $p->booking->client_full_name ?? null;
                $data['username'] = $p->booking->user->username ?? null;
                return $data;
            });

        return response()->json($payments);
    }

    /**
     * Verify or reject a payment.
     * Ported from: accountingController.verifyPayment()
     */
    public function verifyPayment(Request $request, int $id)
    {
        $request->validate([
            'action' => 'required|in:Verify,Reject',
        ]);

        $newStatus = $request->action === 'Verify' ? 'Verified' : 'Rejected';
        $verifiedBy = Auth::user()->username ?? 'accounting';

        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $payment->update([
            'status'      => $newStatus,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
        ]);

        // ─── Send notification to the client ───
        try {
            $booking = Booking::find($payment->booking_id);
            if ($booking && $newStatus === 'Verified') {
                $client = \App\Models\User::find($booking->user_id);
                if ($client) {
                    $client->notify(new \App\Notifications\PaymentApprovedNotification(
                        $booking,
                        $payment->payment_type,
                        (float) $payment->amount
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error("Notification failed on payment verify: {$e->getMessage()}");
        }

        return response()->json(['success' => true, 'message' => "Payment {$newStatus}"]);
    }

    /**
     * Update payment term (amount, due_date).
     * Ported from: accountingController.updatePayment()
     */
    public function updatePayment(Request $request, int $id)
    {
        $request->validate([
            'amount'   => 'required|numeric',
            'due_date' => 'required|date',
        ]);

        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if (in_array($payment->status, ['Verified', 'Paid', 'Refunded'], true)) {
            return response()->json(['error' => 'Verified, paid, or refunded payment terms cannot be modified.'], 422);
        }

        $payment->update([
            'amount'   => $request->amount,
            'due_date' => $request->due_date,
        ]);

        return response()->json(['success' => true, 'message' => 'Payment updated successfully']);
    }

    public function updateBookingPaymentTerms(Request $request, int $id)
    {
        $data = $request->validate([
            'terms' => 'required|array|min:1',
            'terms.*.id' => 'nullable|integer|exists:payments,id',
            'terms.*.payment_type' => 'required|string|max:255',
            'terms.*.percentage' => 'required|numeric|min:0.01|max:100',
            'terms.*.due_date' => 'required|date',
        ]);

        $booking = Booking::with('payments')->find($id);
        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        $totalPercentage = collect($data['terms'])->sum(fn ($term) => (float) $term['percentage']);
        if (round($totalPercentage, 2) !== 100.00) {
            return response()->json(['error' => 'Payment term percentages must total 100%.'], 422);
        }

        $totalCost = (float) ($booking->total_cost ?? $booking->budget ?? 0);
        if ($totalCost <= 0) {
            return response()->json(['error' => 'Booking total must be greater than zero before payment terms can be edited.'], 422);
        }

        $existingIds = $booking->payments->pluck('id')->all();
        $incomingIds = collect($data['terms'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($incomingIds as $paymentId) {
            if (!in_array($paymentId, $existingIds, true)) {
                return response()->json(['error' => 'One or more payment terms do not belong to this booking.'], 422);
            }
        }

        $lockedPaymentIds = $booking->payments
            ->whereIn('status', ['Verified', 'Paid', 'Refunded'])
            ->pluck('id')
            ->all();

        foreach ($lockedPaymentIds as $lockedPaymentId) {
            if (!in_array($lockedPaymentId, $incomingIds, true)) {
                return response()->json(['error' => 'Verified, paid, or refunded payment terms must remain in the schedule.'], 422);
            }
        }

        DB::transaction(function () use ($booking, $data, $totalCost, $incomingIds) {
            $stalePayments = $booking->payments();
            if (!empty($incomingIds)) {
                $stalePayments->whereNotIn('id', $incomingIds);
            }
            $stalePayments->whereNotIn('status', ['Verified', 'Paid', 'Refunded'])->delete();

            $remaining = round($totalCost, 2);
            $lastIndex = count($data['terms']) - 1;

            foreach ($data['terms'] as $index => $term) {
                $existingPayment = !empty($term['id'])
                    ? $booking->payments->firstWhere('id', (int) $term['id'])
                    : null;

                if ($existingPayment && in_array($existingPayment->status, ['Verified', 'Paid', 'Refunded'], true)) {
                    $remaining = round($remaining - (float) $existingPayment->amount, 2);
                    continue;
                }

                $amount = $index === $lastIndex
                    ? $remaining
                    : round($totalCost * ((float) $term['percentage'] / 100), 2);

                $payload = [
                    'amount' => $amount,
                    'payment_method' => 'Pending',
                    'status' => 'Pending',
                    'payment_type' => $term['payment_type'],
                    'due_date' => $term['due_date'],
                ];

                if (!empty($term['id'])) {
                    Payment::where('id', $term['id'])
                        ->where('booking_id', $booking->id)
                        ->update($payload);
                } else {
                    $booking->payments()->create($payload);
                }

                $remaining = round($remaining - $amount, 2);
            }
        });

        $booking->load(['payments' => function ($q) {
            $q->orderByRaw("CASE payment_type WHEN 'Reservation' THEN 1 WHEN 'DownPayment' THEN 2 WHEN 'Final' THEN 3 ELSE 4 END")
                ->orderBy('due_date')
                ->orderBy('id');
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Payment terms updated successfully.',
            'booking' => $booking,
        ]);
    }

    /**
     * Get transaction ledger (all payments with filters).
     * Ported from: accountingController.getLedger()
     */
    public function getLedger(Request $request)
    {
        $query = Payment::with(['booking:id,event_date,client_full_name,package_id,user_id', 'booking.user:id,username'])
            ->whereHas('booking', function ($q) {
                $q->whereNotIn('status', ['Pending', 'Cancelled']); // Hide ledger entries for unapproved/cancelled bookings
            });

        if ($request->status && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->startDate) {
            $query->where('created_at', '>=', $request->startDate);
        }

        if ($request->endDate) {
            $query->where('created_at', '<=', $request->endDate);
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($p) {
                $data = $p->toArray();
                $data['event_date'] = $p->booking->event_date ?? null;
                $data['client_full_name'] = $p->booking->client_full_name ?? null;
                $data['package_id'] = $p->booking->package_id ?? null;
                $data['username'] = $p->booking->user->username ?? null;
                return $data;
            });

        return response()->json($payments);
    }

    /**
     * Send a payment reminder (simulated).
     * Ported from: accountingController.remindClient()
     */
    public function remindClient(int $paymentId)
    {
        $payment = Payment::with('booking:id,client_email,client_phone')->find($paymentId);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        Log::info("[SIMULATED NOTIFICATION] Reminder sent to {$payment->booking->client_email} for payment #{$paymentId} due on {$payment->due_date}");

        return response()->json(['success' => true, 'message' => 'Reminder sent successfully']);
    }

    /**
     * Get refund queue (cancelled bookings with verified payments).
     * Ported from: accountingController.getRefundQueue()
     */
    public function getRefundQueue()
    {
        $items = DB::table('bookings as b')
            ->join('payments as p', 'b.id', '=', 'p.booking_id')
            ->where('b.status', 'Cancelled')
            ->where('p.status', 'Verified')
            ->select(
                'b.id as booking_id',
                'b.client_full_name',
                'b.client_email',
                'b.event_date',
                'b.total_cost',
                DB::raw('SUM(p.amount) as total_paid')
            )
            ->groupBy('b.id', 'b.client_full_name', 'b.client_email', 'b.event_date', 'b.total_cost')
            ->get();

        return response()->json($items);
    }

    /**
     * Process refund for a booking.
     * Ported from: accountingController.processRefund()
     */
    public function processRefund(int $bookingId)
    {
        $verifiedBy = Auth::user()->username ?? 'accounting';

        $updated = Payment::where('booking_id', $bookingId)
            ->where('status', 'Verified')
            ->update([
                'status'      => 'Refunded',
                'verified_by' => $verifiedBy,
                'verified_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json(['error' => 'No verified payments found for this booking to refund.'], 404);
        }

        Log::info("[SIMULATED REFUND] Processed refund for booking #{$bookingId}. Updated {$updated} payment records.");

        return response()->json(['success' => true, 'message' => 'Refund processed successfully.']);
    }
}

