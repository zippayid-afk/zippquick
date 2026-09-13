<?php

namespace App\Http\Controllers\API;

use App\Models\Doctor;
use App\Models\DoctorWallet;
use App\Models\DoctorWalletTransaction;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use App\Services\DoctorPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DoctorWalletApiController extends Controller
{
    protected $paymentService;

    public function __construct(DoctorPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Get complete wallet summary for a doctor
     */
    public function getWalletSummary($doctorId)
    {
        try {
            $summary = $this->paymentService->getDoctorFinancialSummary($doctorId);

            if (!$summary['success']) {
                return CommonHelper::responseError($summary['message']);
            }

            return CommonHelper::responseWithData($summary);
        } catch (\Exception $e) {
            Log::error('Error fetching wallet summary: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching wallet summary');
        }
    }

    /**
     * Get transaction history
     */
    public function getTransactionHistory(Request $request)
    {
        try {
            $doctorId = $request->get('doctor_id');
            $months = $request->get('months', 3);

            $history = $this->paymentService->getTransactionHistory($doctorId, $months);

            if (!$history['success']) {
                return CommonHelper::responseError($history['message']);
            }

            return CommonHelper::responseWithData($history);
        } catch (\Exception $e) {
            Log::error('Error fetching transaction history: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching transaction history');
        }
    }

    /**
     * Process appointment payment manually (admin)
     * Typically, this would be called after a payment gateway confirms payment
     */
    public function processAppointmentPayment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'appointment_id' => 'required|exists:appointments,id',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|max:10',
                'payment_transaction_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $result = $this->paymentService->processAppointmentPayment(
                $request->appointment_id,
                $request->amount,
                $request->currency,
                $request->payment_transaction_id
            );

            if (!$result['success']) {
                return CommonHelper::responseError($result['message']);
            }

            return CommonHelper::responseWithData($result, 1, 'Payment processed successfully');
        } catch (\Exception $e) {
            Log::error('Error processing payment: ' . $e->getMessage());
            return CommonHelper::responseError('Error processing payment');
        }
    }

    /**
     * Request withdrawal
     */
    public function requestWithdrawal(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'amount' => 'required|numeric|min:10',
                'bank_account_number' => 'nullable|string|max:50',
                'bank_name' => 'nullable|string|max:100',
                'account_holder_name' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            // Check eligibility first
            $eligibility = $this->paymentService->isEligibleForWithdrawal($request->doctor_id);

            if (!$eligibility['is_eligible']) {
                return CommonHelper::responseError($eligibility['reason']);
            }

            // Process withdrawal
            $bankDetails = [
                'account_number' => $request->bank_account_number,
                'bank_name' => $request->bank_name,
                'account_holder' => $request->account_holder_name,
            ];

            $result = $this->paymentService->processWithdrawal(
                $request->doctor_id,
                $request->amount,
                $bankDetails
            );

            if (!$result['success']) {
                return CommonHelper::responseError($result['message']);
            }

            return CommonHelper::responseWithData($result, 1, 'Withdrawal processed successfully');
        } catch (\Exception $e) {
            Log::error('Error processing withdrawal: ' . $e->getMessage());
            return CommonHelper::responseError('Error processing withdrawal');
        }
    }

    /**
     * Check withdrawal eligibility
     */
    public function checkWithdrawalEligibility($doctorId)
    {
        try {
            $eligibility = $this->paymentService->isEligibleForWithdrawal($doctorId);

            if (!$eligibility['success']) {
                return CommonHelper::responseError($eligibility['message']);
            }

            return CommonHelper::responseWithData($eligibility);
        } catch (\Exception $e) {
            Log::error('Error checking eligibility: ' . $e->getMessage());
            return CommonHelper::responseError('Error checking eligibility');
        }
    }

    /**
     * Get detailed transaction list
     */
    public function getDetailedTransactions(Request $request)
    {
        try {
            $doctorId = $request->get('doctor_id');
            $type = $request->get('type', null);
            $status = $request->get('status', null);
            $dateFrom = $request->get('date_from', null);
            $dateTo = $request->get('date_to', null);
            $perPage = $request->get('per_page', 15);
            $page = $request->get('page', 1);

            $query = DoctorWalletTransaction::byDoctor($doctorId);

            if ($type) {
                $query->byType($type);
            }

            if ($status) {
                $query->byStatus($status);
            }

            if ($dateFrom) {
                $query->whereDate('transaction_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('transaction_date', '<=', $dateTo);
            }

            $total = $query->count();
            $transactions = $query->orderBy('transaction_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedTransactions = $transactions->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                    'balance_before' => (float) $transaction->balance_before,
                    'balance_after' => (float) $transaction->balance_after,
                    'transaction_date' => $transaction->transaction_date,
                    'status' => $transaction->status,
                    'message' => $transaction->message,
                    'reference_id' => $transaction->reference_id,
                ];
            });

            return CommonHelper::responseWithData($formattedTransactions, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching detailed transactions: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching transactions');
        }
    }

    /**
     * Get wallet statistics for dashboard
     */
    public function getWalletStatistics()
    {
        try {
            $totalDoctors = Doctor::count();
            $totalBalance = DoctorWallet::sum('balance');
            $totalEarned = DoctorWallet::sum('total_earned');
            $totalWithdrawn = DoctorWallet::sum('total_withdrawn');

            // Top earning doctors
            $topDoctors = DoctorWallet::with('doctor')
                ->orderBy('total_earned', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($wallet) {
                    return [
                        'doctor_id' => $wallet->doctor_id,
                        'doctor_name' => $wallet->doctor->full_name,
                        'total_earned' => (float) $wallet->total_earned,
                        'current_balance' => (float) $wallet->balance,
                    ];
                });

            // Recent transactions
            $recentTransactions = DoctorWalletTransaction::with('doctor')
                ->orderBy('transaction_date', 'desc')
                ->limit(10)
                ->get();

            return CommonHelper::responseWithData([
                'total_doctors' => $totalDoctors,
                'total_platform_balance' => (float) $totalBalance,
                'total_earned_by_doctors' => (float) $totalEarned,
                'total_withdrawn_by_doctors' => (float) $totalWithdrawn,
                'top_earning_doctors' => $topDoctors,
                'recent_transactions' => $recentTransactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching wallet statistics: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching statistics');
        }
    }

    /**
     * Add manual credit to doctor's wallet (admin adjustment)
     */
    public function addManualCredit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'required|string|max:500',
                'currency' => 'required|string|max:10',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $doctor = Doctor::findOrFail($request->doctor_id);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                throw new \Exception('Wallet not found');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $request->amount;

            $wallet->balance = $balanceAfter;
            $wallet->total_earned += $request->amount;
            $wallet->save();

            DoctorWalletTransaction::create([
                'doctor_id' => $request->doctor_id,
                'type' => DoctorWalletTransaction::TYPE_CREDIT,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'currency_code' => $request->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_date' => now(),
                'status' => DoctorWalletTransaction::STATUS_COMPLETED,
                'message' => "Manual credit: {$request->reason}",
                'reference_id' => 'MANUAL-' . $request->doctor_id . '-' . time(),
                'country_id' => $doctor->country_id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData([
                'balance' => (float) $balanceAfter,
                'amount_added' => (float) $request->amount,
            ], 1, 'Credit added successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding manual credit: ' . $e->getMessage());
            return CommonHelper::responseError('Error adding credit');
        }
    }

    /**
     * Deduct manual charge from doctor's wallet (admin adjustment)
     */
    public function addManualDebit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'required|string|max:500',
                'currency' => 'required|string|max:10',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $doctor = Doctor::findOrFail($request->doctor_id);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                throw new \Exception('Wallet not found');
            }

            if ($wallet->balance < $request->amount) {
                throw new \Exception('Insufficient balance');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $request->amount;

            $wallet->balance = $balanceAfter;
            $wallet->save();

            DoctorWalletTransaction::create([
                'doctor_id' => $request->doctor_id,
                'type' => DoctorWalletTransaction::TYPE_DEBIT,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'currency_code' => $request->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_date' => now(),
                'status' => DoctorWalletTransaction::STATUS_COMPLETED,
                'message' => "Manual charge: {$request->reason}",
                'reference_id' => 'DEBIT-' . $request->doctor_id . '-' . time(),
                'country_id' => $doctor->country_id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData([
                'balance' => (float) $balanceAfter,
                'amount_deducted' => (float) $request->amount,
            ], 1, 'Charge applied successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error applying debit: ' . $e->getMessage());
            return CommonHelper::responseError($e->getMessage());
        }
    }
}
