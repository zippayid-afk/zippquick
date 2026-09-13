<?php

namespace App\Http\Controllers\API;

use App\Models\Doctor;
use App\Models\DoctorWallet;
use App\Models\DoctorWalletTransaction;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DoctorFinancialApiController extends Controller
{
    /**
     * Get doctor's wallet details
     */
    public function getWalletDetails($doctorId)
    {
        try {
            $doctor = Doctor::findOrFail($doctorId);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                return CommonHelper::responseError('Wallet not found for this doctor');
            }

            return CommonHelper::responseWithData([
                'doctor_id' => $doctor->id,
                'doctor_name' => $doctor->full_name,
                'balance' => (float) $wallet->balance,
                'total_earned' => (float) $wallet->total_earned,
                'total_withdrawn' => (float) $wallet->total_withdrawn,
                'currency' => $wallet->currency,
                'country_id' => $wallet->country_id,
                'created_at' => $wallet->created_at,
                'updated_at' => $wallet->updated_at,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching wallet details: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching wallet details');
        }
    }

    /**
     * Get wallet transactions
     */
    public function getWalletTransactions(Request $request)
    {
        try {
            $doctorId = $request->get('doctor_id');
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $type = $request->get('type', null);
            $dateFrom = $request->get('date_from', null);
            $dateTo = $request->get('date_to', null);

            $query = DoctorWalletTransaction::query();

            if ($doctorId) {
                $query->byDoctor($doctorId);
            }

            if ($type) {
                $query->byType($type);
            }

            if ($dateFrom) {
                $query->whereDate('transaction_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('transaction_date', '<=', $dateTo);
            }

            $total = $query->count();
            $transactions = $query->with(['doctor', 'appointment', 'country'])
                ->orderBy('transaction_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedTransactions = $transactions->map(fn($transaction) => $this->formatTransactionResponse($transaction));

            return CommonHelper::responseWithData($formattedTransactions, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching wallet transactions: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching wallet transactions');
        }
    }

    /**
     * Add credit to doctor's wallet (from appointment payment)
     */
    public function addWalletCredit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'amount' => 'required|numeric|min:0.01',
                'appointment_id' => 'nullable|exists:appointments,id',
                'currency' => 'required|string|max:10',
                'reference_id' => 'required|string|max:100',
                'message' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $doctor = Doctor::findOrFail($request->doctor_id);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                return CommonHelper::responseError('Wallet not found for this doctor');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $request->amount;

            // Update wallet balance
            $wallet->balance = $balanceAfter;
            $wallet->total_earned += $request->amount;
            $wallet->save();

            // Create transaction record
            DoctorWalletTransaction::create([
                'doctor_id' => $request->doctor_id,
                'appointment_id' => $request->appointment_id,
                'type' => DoctorWalletTransaction::TYPE_CREDIT,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'currency_code' => $request->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_date' => now(),
                'status' => DoctorWalletTransaction::STATUS_COMPLETED,
                'message' => $request->message ?? 'Appointment payment credited',
                'txn_id' => null,
                'reference_id' => $request->reference_id,
                'country_id' => $wallet->country_id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData([
                'balance' => (float) $wallet->balance,
                'total_earned' => (float) $wallet->total_earned,
            ], 1, 'Credit added successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding wallet credit: ' . $e->getMessage());
            return CommonHelper::responseError('Error adding wallet credit');
        }
    }

    /**
     * Deduct amount from doctor's wallet
     */
    public function deductWalletAmount(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|max:10',
                'reference_id' => 'required|string|max:100',
                'message' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $doctor = Doctor::findOrFail($request->doctor_id);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                return CommonHelper::responseError('Wallet not found for this doctor');
            }

            if ($wallet->balance < $request->amount) {
                return CommonHelper::responseError('Insufficient balance in wallet');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $request->amount;

            // Update wallet balance
            $wallet->balance = $balanceAfter;
            $wallet->save();

            // Create transaction record
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
                'message' => $request->message ?? 'Wallet debit',
                'txn_id' => null,
                'reference_id' => $request->reference_id,
                'country_id' => $wallet->country_id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData([
                'balance' => (float) $wallet->balance,
            ], 1, 'Amount deducted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deducting wallet amount: ' . $e->getMessage());
            return CommonHelper::responseError('Error deducting wallet amount');
        }
    }

    /**
     * Get financial summary
     */
    public function getFinancialSummary(Request $request)
    {
        try {
            $doctorId = $request->get('doctor_id');
            $dateFrom = $request->get('date_from', null);
            $dateTo = $request->get('date_to', null);

            $doctor = Doctor::findOrFail($doctorId);
            $wallet = $doctor->wallet;

            $transactionsQuery = DoctorWalletTransaction::byDoctor($doctorId);

            if ($dateFrom) {
                $transactionsQuery->whereDate('transaction_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $transactionsQuery->whereDate('transaction_date', '<=', $dateTo);
            }

            $totalCredits = $transactionsQuery->where('type', DoctorWalletTransaction::TYPE_CREDIT)->sum('amount');
            $totalDebits = $transactionsQuery->where('type', DoctorWalletTransaction::TYPE_DEBIT)->sum('amount');
            $appointmentCount = $doctor->appointments()->count();
            $completedAppointments = $doctor->appointments()->where('status', 'completed')->count();

            return CommonHelper::responseWithData([
                'doctor_id' => $doctor->id,
                'doctor_name' => $doctor->full_name,
                'current_balance' => (float) $wallet->balance,
                'total_earned' => (float) $wallet->total_earned,
                'total_withdrawn' => (float) $wallet->total_withdrawn,
                'total_credits' => (float) $totalCredits,
                'total_debits' => (float) $totalDebits,
                'total_appointments' => $appointmentCount,
                'completed_appointments' => $completedAppointments,
                'currency' => $wallet->currency,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching financial summary: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching financial summary');
        }
    }

    /**
     * Format transaction response
     */
    private function formatTransactionResponse(DoctorWalletTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'doctor_id' => $transaction->doctor_id,
            'doctor_name' => $transaction->doctor?->full_name,
            'appointment_id' => $transaction->appointment_id,
            'type' => $transaction->type,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'balance_before' => (float) $transaction->balance_before,
            'balance_after' => (float) $transaction->balance_after,
            'transaction_date' => $transaction->transaction_date,
            'status' => $transaction->status,
            'message' => $transaction->message,
            'txn_id' => $transaction->txn_id,
            'reference_id' => $transaction->reference_id,
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];
    }
}
