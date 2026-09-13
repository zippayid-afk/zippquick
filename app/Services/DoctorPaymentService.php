<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Appointment;
use App\Models\DoctorWallet;
use App\Models\DoctorWalletTransaction;
use App\Models\Transaction;
use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DoctorPaymentService
{
    /**
     * Process appointment payment and credit doctor's wallet
     * This gets called after a patient successfully pays for an appointment
     */
    public function processAppointmentPayment($appointmentId, $amount, $currency = 'USD', $paymentTransactionId = null): array
    {
        try {
            DB::beginTransaction();

            $appointment = Appointment::findOrFail($appointmentId);
            $doctor = Doctor::findOrFail($appointment->doctor_id);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                throw new \Exception('Doctor wallet not found');
            }

            // Calculate commission (e.g., 10% platform fee)
            $commissionPercentage = $this->getCommissionPercentage($doctor->country_id);
            $commissionAmount = ($amount * $commissionPercentage) / 100;
            $doctorAmount = $amount - $commissionAmount;

            // Update appointment payment status
            $appointment->payment_status = 'completed';
            $appointment->amount = $amount;
            $appointment->currency = $currency;
            $appointment->save();

            // Update doctor wallet
            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $doctorAmount;

            $wallet->balance = $balanceAfter;
            $wallet->total_earned += $doctorAmount;
            $wallet->save();

            // Create transaction record
            $referenceId = 'APT-' . $appointmentId . '-' . time();

            DoctorWalletTransaction::create([
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'type' => DoctorWalletTransaction::TYPE_CREDIT,
                'amount' => $doctorAmount,
                'currency' => $currency,
                'currency_code' => $currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_date' => now(),
                'status' => DoctorWalletTransaction::STATUS_COMPLETED,
                'message' => "Appointment payment - {$commissionPercentage}% commission deducted",
                'txn_id' => $paymentTransactionId,
                'reference_id' => $referenceId,
                'country_id' => $doctor->country_id,
            ]);

            // Log the transaction
            Log::info("Doctor payment processed", [
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'amount' => $amount,
                'commission' => $commissionAmount,
                'doctor_receives' => $doctorAmount,
            ]);

            DB::commit();

            return [
                'success' => true,
                'appointment_id' => $appointmentId,
                'doctor_id' => $doctor->id,
                'total_amount' => $amount,
                'commission' => $commissionAmount,
                'doctor_receives' => $doctorAmount,
                'new_balance' => $balanceAfter,
                'reference_id' => $referenceId,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing appointment payment: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to process payment',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process doctor withdrawal request
     */
    public function processWithdrawal($doctorId, $amount, $bankDetails = []): array
    {
        try {
            DB::beginTransaction();

            $doctor = Doctor::findOrFail($doctorId);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                throw new \Exception('Doctor wallet not found');
            }

            if ($wallet->balance < $amount) {
                throw new \Exception('Insufficient balance for withdrawal');
            }

            // Validate withdrawal amount (minimum $10 or equivalent)
            if ($amount < 10) {
                throw new \Exception('Minimum withdrawal amount is $10');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $amount;

            // Update wallet
            $wallet->balance = $balanceAfter;
            $wallet->total_withdrawn += $amount;
            $wallet->save();

            // Create debit transaction
            $referenceId = 'WTH-' . $doctorId . '-' . time();

            DoctorWalletTransaction::create([
                'doctor_id' => $doctorId,
                'type' => DoctorWalletTransaction::TYPE_DEBIT,
                'amount' => $amount,
                'currency' => $wallet->currency,
                'currency_code' => $wallet->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_date' => now(),
                'status' => DoctorWalletTransaction::STATUS_COMPLETED,
                'message' => 'Withdrawal request processed',
                'reference_id' => $referenceId,
                'country_id' => $doctor->country_id,
            ]);

            Log::info("Doctor withdrawal processed", [
                'doctor_id' => $doctorId,
                'amount' => $amount,
                'new_balance' => $balanceAfter,
                'bank_details' => !empty($bankDetails),
            ]);

            DB::commit();

            return [
                'success' => true,
                'doctor_id' => $doctorId,
                'amount' => $amount,
                'new_balance' => $balanceAfter,
                'reference_id' => $referenceId,
                'status' => 'processed',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing withdrawal: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get commission percentage based on country
     */
    private function getCommissionPercentage($countryId): float
    {
        // You can configure this per country or globally
        // Default 10% commission
        $commissionConfig = [
            // 'country_id' => percentage
            // Examples:
            // 1 => 10,  // 10% for US
            // 2 => 15,  // 15% for India
        ];

        return $commissionConfig[$countryId] ?? 10;
    }

    /**
     * Get doctor financial summary
     */
    public function getDoctorFinancialSummary($doctorId): array
    {
        try {
            $doctor = Doctor::with('wallet')->findOrFail($doctorId);
            $wallet = $doctor->wallet;

            if (!$wallet) {
                return [
                    'success' => false,
                    'message' => 'Wallet not found',
                ];
            }

            // Get transactions for this month
            $thisMonth = DoctorWalletTransaction::byDoctor($doctorId)
                ->whereYear('transaction_date', now()->year)
                ->whereMonth('transaction_date', now()->month)
                ->get();

            $monthlyCredits = $thisMonth->where('type', DoctorWalletTransaction::TYPE_CREDIT)->sum('amount');
            $monthlyDebits = $thisMonth->where('type', DoctorWalletTransaction::TYPE_DEBIT)->sum('amount');

            // Get appointment stats
            $completedAppointments = Appointment::byDoctor($doctorId)
                ->where('status', Appointment::STATUS_COMPLETED)
                ->count();

            $pendingAppointments = Appointment::byDoctor($doctorId)
                ->where('status', Appointment::STATUS_PENDING)
                ->count();

            return [
                'success' => true,
                'doctor_id' => $doctorId,
                'doctor_name' => $doctor->full_name,
                'wallet' => [
                    'current_balance' => (float) $wallet->balance,
                    'total_earned' => (float) $wallet->total_earned,
                    'total_withdrawn' => (float) $wallet->total_withdrawn,
                    'currency' => $wallet->currency,
                ],
                'monthly_statistics' => [
                    'credits' => (float) $monthlyCredits,
                    'debits' => (float) $monthlyDebits,
                    'net_earnings' => (float) ($monthlyCredits - $monthlyDebits),
                ],
                'appointment_statistics' => [
                    'completed' => $completedAppointments,
                    'pending' => $pendingAppointments,
                    'total' => $completedAppointments + $pendingAppointments,
                ],
                'timestamp' => now(),
            ];
        } catch (\Exception $e) {
            Log::error('Error getting financial summary: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error retrieving financial summary',
            ];
        }
    }

    /**
     * Initialize doctor wallet (called when doctor is registered)
     */
    public function initializeWallet($doctorId, $countryId): DoctorWallet
    {
        $country = Country::find($countryId);
        $currency = $country->currency ?? 'USD';

        return DoctorWallet::create([
            'doctor_id' => $doctorId,
            'balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
            'currency' => $currency,
            'country_id' => $countryId,
        ]);
    }

    /**
     * Get transaction history
     */
    public function getTransactionHistory($doctorId, $months = 3): array
    {
        try {
            $transactions = DoctorWalletTransaction::byDoctor($doctorId)
                ->where('transaction_date', '>=', now()->subMonths($months))
                ->orderBy('transaction_date', 'desc')
                ->get()
                ->groupBy(function ($transaction) {
                    return $transaction->transaction_date->format('Y-m');
                });

            $history = [];
            foreach ($transactions as $month => $monthTransactions) {
                $credits = $monthTransactions->where('type', DoctorWalletTransaction::TYPE_CREDIT)->sum('amount');
                $debits = $monthTransactions->where('type', DoctorWalletTransaction::TYPE_DEBIT)->sum('amount');

                $history[$month] = [
                    'credits' => (float) $credits,
                    'debits' => (float) $debits,
                    'net' => (float) ($credits - $debits),
                    'transaction_count' => count($monthTransactions),
                ];
            }

            return [
                'success' => true,
                'doctor_id' => $doctorId,
                'history' => $history,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting transaction history: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error retrieving transaction history',
            ];
        }
    }

    /**
     * Check if doctor is eligible for withdrawal
     */
    public function isEligibleForWithdrawal($doctorId): array
    {
        try {
            $doctor = Doctor::with('wallet')->findOrFail($doctorId);
            $wallet = $doctor->wallet;

            $minimumBalance = 50; // Minimum $50 to withdraw
            $isEligible = $wallet->balance >= $minimumBalance;

            return [
                'success' => true,
                'is_eligible' => $isEligible,
                'current_balance' => (float) $wallet->balance,
                'minimum_required' => $minimumBalance,
                'reason' => !$isEligible ? "Minimum balance of \${$minimumBalance} required" : 'Eligible',
            ];
        } catch (\Exception $e) {
            Log::error('Error checking withdrawal eligibility: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Error checking eligibility',
            ];
        }
    }
}
