<?php

namespace App\Http\Controllers\API;

use App\Models\Doctor;
use App\Models\Clinic;
use App\Models\Admin;
use App\Models\Country;
use App\Models\DoctorWallet;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DoctorApiController extends Controller
{
    /**
     * Get list of doctors with filtering and search
     */
    public function getDoctors(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $search = $request->get('search', '');
            $status = $request->get('status', null);
            $countryId = $request->get('country_id', null);
            $sortBy = $request->get('sort_by', 'latest');

            $query = Doctor::query();

            // Search
            if ($search) {
                $query->search($search);
            }

            // Filter by status
            if ($status === 'approved') {
                $query->approved();
            } elseif ($status === 'pending') {
                $query->pending();
            } elseif ($status === 'active') {
                $query->active();
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }

            // Filter by country
            if ($countryId) {
                $query->byCountry($countryId);
            }

            // Sorting
            switch ($sortBy) {
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'name_asc':
                    $query->orderBy('first_name', 'asc')->orderBy('last_name', 'asc');
                    break;
                case 'name_desc':
                    $query->orderBy('first_name', 'desc')->orderBy('last_name', 'desc');
                    break;
                default:
                    $query->orderBy('created_at', 'desc');
            }

            $total = $query->count();
            $doctors = $query->with(['admin', 'country', 'wallet'])
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedDoctors = $doctors->map(fn($doctor) => $this->formatDoctorResponse($doctor));

            return CommonHelper::responseWithData($formattedDoctors, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching doctors: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching doctors');
        }
    }

    /**
     * Get single doctor details
     */
    public function getDoctorDetail($id)
    {
        try {
            $doctor = Doctor::with(['admin', 'country', 'zone', 'wallet', 'clinics', 'ratings'])
                ->findOrFail($id);

            $appointmentCount = $doctor->getAppointmentCount();
            $averageRating = $doctor->getAverageRating();
            $totalEarnings = $doctor->getTotalEarnings();
            $currentBalance = $doctor->getCurrentBalance();

            $response = $this->formatDoctorResponse($doctor);
            $response['appointments_count'] = $appointmentCount;
            $response['average_rating'] = $averageRating;
            $response['total_earnings'] = $totalEarnings;
            $response['current_balance'] = $currentBalance;
            $response['clinics_count'] = $doctor->clinics()->count();

            return CommonHelper::responseWithData($response);
        } catch (\Exception $e) {
            Log::error('Error fetching doctor detail: ' . $e->getMessage());
            return CommonHelper::responseError('Doctor not found');
        }
    }

    /**
     * Create new doctor
     */
    public function saveDoctorDetail(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email|unique:doctors,email',
                'mobile' => 'required|string|unique:doctors,mobile',
                'specialization' => 'required|string|max:100',
                'qualification' => 'required|string|max:255',
                'license_number' => 'required|string|unique:doctors,license_number',
                'experience_years' => 'required|integer|min:0|max:100',
                'bio' => 'nullable|string|max:1000',
                'country_id' => 'required|exists:countries,id',
                'zone_id' => 'nullable|exists:zones,id',
                'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'license_document' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
                'certificate_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            // Create Admin account for doctor
            $admin = new Admin();
            $admin->name = $request->first_name . ' ' . $request->last_name;
            $admin->email = $request->email;
            $admin->password = Hash::make($request->email); // Temporary password
            $admin->is_active = true;
            $admin->role_id = 4; // Doctor role (adjust based on your role setup)
            $admin->save();

            // Upload files to Cloudinary
            $profileImage = null;
            $licenseDocument = null;
            $certificateDocument = null;

            if ($request->hasFile('profile_image')) {
                $profileImage = CloudinaryHelper::uploadImage($request->file('profile_image'), 'doctor/profiles');
            }

            if ($request->hasFile('license_document')) {
                $licenseDocument = CloudinaryHelper::upload($request->file('license_document'), 'doctor/documents');
            }

            if ($request->hasFile('certificate_document')) {
                $certificateDocument = CloudinaryHelper::upload($request->file('certificate_document'), 'doctor/documents');
            }

            // Create Doctor
            $doctor = new Doctor();
            $doctor->admin_id = $admin->id;
            $doctor->first_name = $request->first_name;
            $doctor->last_name = $request->last_name;
            $doctor->email = $request->email;
            $doctor->mobile = $request->mobile;
            $doctor->specialization = $request->specialization;
            $doctor->qualification = $request->qualification;
            $doctor->license_number = $request->license_number;
            $doctor->experience_years = $request->experience_years;
            $doctor->bio = $request->bio;
            $doctor->profile_image = $profileImage;
            $doctor->license_document = $licenseDocument;
            $doctor->certificate_document = $certificateDocument;
            $doctor->country_id = $request->country_id;
            $doctor->zone_id = $request->zone_id;
            $doctor->is_verified = false;
            $doctor->is_approved = false;
            $doctor->save();

            // Create wallet for doctor
            $country = Country::find($request->country_id);
            DoctorWallet::create([
                'doctor_id' => $doctor->id,
                'balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
                'currency' => $country->currency ?? 'USD',
                'country_id' => $country->id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData($this->formatDoctorResponse($doctor), 1, 'Doctor created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating doctor: ' . $e->getMessage());
            return CommonHelper::responseError('Error creating doctor');
        }
    }

    /**
     * Update doctor details
     */
    public function updateDoctorDetail(Request $request)
    {
        try {
            $doctorId = $request->get('id');
            $doctor = Doctor::findOrFail($doctorId);

            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'email' => "sometimes|email|unique:doctors,email,{$doctorId}",
                'mobile' => "sometimes|string|unique:doctors,mobile,{$doctorId}",
                'specialization' => 'sometimes|string|max:100',
                'qualification' => 'sometimes|string|max:255',
                'license_number' => "sometimes|string|unique:doctors,license_number,{$doctorId}",
                'experience_years' => 'sometimes|integer|min:0|max:100',
                'bio' => 'nullable|string|max:1000',
                'zone_id' => 'nullable|exists:zones,id',
                'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'license_document' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
                'certificate_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            // Update basic info
            $doctor->update($request->only([
                'first_name', 'last_name', 'email', 'mobile', 'specialization',
                'qualification', 'license_number', 'experience_years', 'bio', 'zone_id'
            ]));

            // Handle file uploads
            if ($request->hasFile('profile_image')) {
                // Delete old image if exists
                if ($doctor->profile_image) {
                    CloudinaryHelper::delete($doctor->profile_image);
                }
                $doctor->profile_image = CloudinaryHelper::uploadImage($request->file('profile_image'), 'doctor/profiles');
                $doctor->save();
            }

            if ($request->hasFile('license_document')) {
                if ($doctor->license_document) {
                    CloudinaryHelper::delete($doctor->license_document);
                }
                $doctor->license_document = CloudinaryHelper::upload($request->file('license_document'), 'doctor/documents');
                $doctor->save();
            }

            if ($request->hasFile('certificate_document')) {
                if ($doctor->certificate_document) {
                    CloudinaryHelper::delete($doctor->certificate_document);
                }
                $doctor->certificate_document = CloudinaryHelper::upload($request->file('certificate_document'), 'doctor/documents');
                $doctor->save();
            }

            DB::commit();

            return CommonHelper::responseWithData($this->formatDoctorResponse($doctor), 1, 'Doctor updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating doctor: ' . $e->getMessage());
            return CommonHelper::responseError('Error updating doctor');
        }
    }

    /**
     * Approve doctor registration
     */
    public function approveDoctorRegistration(Request $request)
    {
        try {
            $doctorId = $request->get('id');
            $doctor = Doctor::findOrFail($doctorId);

            if ($doctor->is_approved) {
                return CommonHelper::responseError('Doctor is already approved');
            }

            $doctor->is_approved = true;
            $doctor->is_verified = true;
            $doctor->approval_date = now();
            $doctor->save();

            return CommonHelper::responseWithData($this->formatDoctorResponse($doctor), 1, 'Doctor approved successfully');
        } catch (\Exception $e) {
            Log::error('Error approving doctor: ' . $e->getMessage());
            return CommonHelper::responseError('Error approving doctor');
        }
    }

    /**
     * Reject doctor registration
     */
    public function rejectDoctorRegistration(Request $request)
    {
        try {
            $doctorId = $request->get('id');
            $reason = $request->get('reason', '');

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $doctor = Doctor::findOrFail($doctorId);

            if ($doctor->is_approved) {
                return CommonHelper::responseError('Cannot reject an already approved doctor');
            }

            $doctor->rejection_reason = $reason;
            $doctor->save();

            return CommonHelper::responseWithData($this->formatDoctorResponse($doctor), 1, 'Doctor registration rejected');
        } catch (\Exception $e) {
            Log::error('Error rejecting doctor: ' . $e->getMessage());
            return CommonHelper::responseError('Error rejecting doctor');
        }
    }

    /**
     * Activate/Deactivate doctor
     */
    public function toggleDoctorStatus(Request $request)
    {
        try {
            $doctorId = $request->get('id');
            $doctor = Doctor::findOrFail($doctorId);

            $doctor->is_active = !$doctor->is_active;
            $doctor->save();

            $status = $doctor->is_active ? 'activated' : 'deactivated';
            return CommonHelper::responseWithData($this->formatDoctorResponse($doctor), 1, "Doctor {$status} successfully");
        } catch (\Exception $e) {
            Log::error('Error toggling doctor status: ' . $e->getMessage());
            return CommonHelper::responseError('Error updating doctor status');
        }
    }

    /**
     * Delete doctor (soft delete)
     */
    public function deleteDoctor(Request $request)
    {
        try {
            $doctorId = $request->get('id');
            $doctor = Doctor::findOrFail($doctorId);

            // Soft delete
            $doctor->delete();

            return CommonHelper::responseSuccess('Doctor deleted successfully');
        } catch (\Exception $e) {
            Log::error('Error deleting doctor: ' . $e->getMessage());
            return CommonHelper::responseError('Error deleting doctor');
        }
    }

    /**
     * Format doctor response
     */
    private function formatDoctorResponse(Doctor $doctor): array
    {
        return [
            'id' => $doctor->id,
            'admin_id' => $doctor->admin_id,
            'full_name' => $doctor->full_name,
            'first_name' => $doctor->first_name,
            'last_name' => $doctor->last_name,
            'email' => $doctor->email,
            'mobile' => $doctor->mobile,
            'specialization' => $doctor->specialization,
            'qualification' => $doctor->qualification,
            'license_number' => $doctor->license_number,
            'experience_years' => $doctor->experience_years,
            'bio' => $doctor->bio,
            'profile_image_url' => $doctor->profile_image_url,
            'license_document_url' => $doctor->license_document_url,
            'certificate_document_url' => $doctor->certificate_document_url,
            'is_verified' => $doctor->is_verified,
            'is_approved' => $doctor->is_approved,
            'is_active' => $doctor->is_active,
            'approval_date' => $doctor->approval_date,
            'rejection_reason' => $doctor->rejection_reason,
            'country_id' => $doctor->country_id,
            'country_name' => $doctor->country?->name,
            'zone_id' => $doctor->zone_id,
            'zone_name' => $doctor->zone?->name,
            'created_at' => $doctor->created_at,
            'updated_at' => $doctor->updated_at,
        ];
    }

    /**
     * Get doctor management statistics
     */
    public function getStatistics()
    {
        try {
            $totalDoctors = Doctor::count();
            $approvedDoctors = Doctor::where('is_approved', true)->count();
            $pendingRequests = Doctor::where('is_approved', false)
                ->whereNull('rejection_reason')
                ->count();
            $deniedDoctors = Doctor::whereNotNull('rejection_reason')->count();
            
            $totalClinics = \App\Models\Clinic::count();
            $totalAppointments = \App\Models\Appointment::count();
            $todayAppointments = \App\Models\Appointment::whereDate('appointment_date', today())->count();
            
            // Get total revenue from appointments
            $totalRevenue = \App\Models\Appointment::where('payment_status', 'paid')
                ->sum('amount');
            
            $currency = \App\Models\Setting::get_value('currency') ?? '$';
            
            $statistics = [
                'totalDoctors' => $totalDoctors,
                'approvedDoctors' => $approvedDoctors,
                'pendingRequests' => $pendingRequests,
                'deniedDoctors' => $deniedDoctors,
                'doctorCategories' => 1, // Placeholder - implement categories later
                'inhouseCategories' => 1, // Placeholder - implement categories later
                'totalAppointments' => $totalAppointments,
                'todayAppointments' => $todayAppointments,
                'totalClinics' => $totalClinics,
                'totalRevenue' => $currency . number_format($totalRevenue, 2),
            ];

            return CommonHelper::responseWithData($statistics);
        } catch (\Exception $e) {
            Log::error('Error fetching doctor statistics: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching statistics');
        }
    }
}
