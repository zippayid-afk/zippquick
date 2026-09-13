<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Clinic;
use App\Models\Appointment;
use App\Models\Admin;
use App\Models\User;
use App\Models\Country;
use App\Models\DoctorWallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DoctorSeeder extends Seeder
{
    public function run()
    {
        // Get first country
        $country = Country::first();
        if (!$country) {
            $this->command->error('No country found. Please run CountrySeeder first.');
            return;
        }

        $specializations = [
            'Cardiologist', 'Dermatologist', 'Pediatrician', 'Neurologist',
            'Orthopedic', 'Psychiatrist', 'General Physician', 'Gynecologist'
        ];

        // Create 25 doctors
        for ($i = 1; $i <= 25; $i++) {
            // Create admin account for doctor
            $admin = Admin::create([
                'username' => "doctor$i",
                'email' => "doctor$i@example.com",
                'password' => Hash::make('password'),
                'status' => 1,
                'role_id' => 2, // Admin role
                'created_by' => 1, // Created by super admin
            ]);

            // Determine approval status
            $isApproved = $i <= 20; // First 20 approved
            $isRejected = $i > 23; // Last 2 rejected
            $isPending = !$isApproved && !$isRejected; // 21-23 pending

            $doctor = Doctor::create([
                'admin_id' => $admin->id,
                'first_name' => "Doctor",
                'last_name' => "$i",
                'email' => "doctor$i@example.com",
                'mobile' => '+1234567' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'specialization' => $specializations[array_rand($specializations)],
                'qualification' => 'MBBS, MD',
                'license_number' => 'LIC' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'experience_years' => rand(2, 20),
                'bio' => "Experienced doctor with expertise in treating various conditions.",
                'country_id' => $country->id,
                'is_verified' => $isApproved,
                'is_approved' => $isApproved,
                'is_active' => $isApproved,
                'approval_date' => $isApproved ? now() : null,
                'rejection_reason' => $isRejected ? 'Incomplete documentation' : null,
            ]);

            // Create wallet
            DoctorWallet::create([
                'doctor_id' => $doctor->id,
                'balance' => rand(100, 5000),
                'total_earned' => rand(1000, 10000),
                'total_withdrawn' => rand(500, 5000),
                'currency' => $country->currency ?? 'USD',
                'country_id' => $country->id,
            ]);

            // Create 1-2 clinics for approved doctors
            if ($isApproved && $i <= 15) {
                $clinicsCount = rand(1, 2);
                for ($c = 1; $c <= $clinicsCount; $c++) {
                    Clinic::create([
                        'doctor_id' => $doctor->id,
                        'name' => "Dr. Doctor $i Clinic $c",
                        'address' => rand(100, 999) . ' Main Street',
                        'city' => 'Sample City',
                        'state' => 'Sample State',
                        'postal_code' => str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT),
                        'phone' => '+1234567' . str_pad($i * 10 + $c, 4, '0', STR_PAD_LEFT),
                        'email' => "clinic$i-$c@example.com",
                        'country_id' => $country->id,
                        'is_verified' => true,
                        'is_active' => true,
                    ]);
                }
            }
        }

        // Create some appointments
        $doctors = Doctor::where('is_approved', true)->get();
        $users = User::limit(10)->get();
        
        if ($users->isEmpty()) {
            // Create some dummy users if none exist
            for ($i = 1; $i <= 5; $i++) {
                $users->push(User::create([
                    'username' => "patient$i",
                    'email' => "patient$i@example.com",
                    'mobile' => '+1987654' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'password' => Hash::make('password'),
                    'country_id' => $country->id,
                ]));
            }
        }

        foreach ($doctors->take(10) as $doctor) {
            $clinic = $doctor->clinics()->first();
            
            for ($a = 0; $a < rand(2, 5); $a++) {
                Appointment::create([
                    'doctor_id' => $doctor->id,
                    'patient_id' => $users->random()->id,
                    'clinic_id' => $clinic?->id,
                    'appointment_date' => now()->addDays(rand(-10, 30)),
                    'appointment_time' => now()->addHours(rand(9, 17)),
                    'duration_minutes' => [30, 45, 60][rand(0, 2)],
                    'type' => ['clinic', 'video', 'in_house'][rand(0, 2)],
                    'status' => ['pending', 'approved', 'completed'][rand(0, 2)],
                    'patient_name' => 'Patient ' . rand(1, 100),
                    'patient_email' => 'patient' . rand(1, 100) . '@example.com',
                    'patient_phone' => '+1555' . rand(1000000, 9999999),
                    'symptoms' => 'General checkup',
                    'amount' => rand(50, 200),
                    'currency' => 'USD',
                ]);
            }
        }

        $this->command->info('Doctor seeder completed successfully!');
        $this->command->info('Created: 25 doctors (20 approved, 3 pending, 2 rejected)');
        $this->command->info('Created: ~15 clinics');
        $this->command->info('Created: ~40 appointments');
    }
}
