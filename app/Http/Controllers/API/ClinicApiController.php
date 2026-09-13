<?php

namespace App\Http\Controllers\API;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ClinicApiController extends Controller
{
    /**
     * Get clinics with filtering
     */
    public function getClinics(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $search = $request->get('search', '');
            $doctorId = $request->get('doctor_id', null);
            $countryId = $request->get('country_id', null);
            $status = $request->get('status', null);

            $query = Clinic::query();

            if ($search) {
                $query->search($search);
            }

            if ($doctorId) {
                $query->byDoctor($doctorId);
            }

            if ($countryId) {
                $query->byCountry($countryId);
            }

            if ($status === 'active') {
                $query->active();
            } elseif ($status === 'verified') {
                $query->verified();
            }

            $total = $query->count();
            $clinics = $query->with(['doctor', 'country', 'zone'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedClinics = $clinics->map(fn($clinic) => $this->formatClinicResponse($clinic));

            return CommonHelper::responseWithData($formattedClinics, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching clinics: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching clinics');
        }
    }

    /**
     * Get clinic details
     */
    public function getClinicDetail($id)
    {
        try {
            $clinic = Clinic::with(['doctor', 'country', 'zone', 'appointments'])
                ->findOrFail($id);

            $response = $this->formatClinicResponse($clinic);
            $response['appointments_count'] = $clinic->appointments()->count();

            return CommonHelper::responseWithData($response);
        } catch (\Exception $e) {
            Log::error('Error fetching clinic detail: ' . $e->getMessage());
            return CommonHelper::responseError('Clinic not found');
        }
    }

    /**
     * Create clinic
     */
    public function saveClinic(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'name' => 'required|string|max:255',
                'address' => 'required|string|max:500',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'postal_code' => 'required|string|max:20',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email',
                'website' => 'nullable|url',
                'clinic_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'registration_number' => 'nullable|string|max:100',
                'license_number' => 'nullable|string|max:100',
                'country_id' => 'required|exists:countries,id',
                'zone_id' => 'nullable|exists:zones,id',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $clinicImage = null;
            if ($request->hasFile('clinic_image')) {
                $clinicImage = CloudinaryHelper::uploadImage($request->file('clinic_image'), 'clinic/images');
            }

            $clinic = Clinic::create([
                'doctor_id' => $request->doctor_id,
                'name' => $request->name,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'postal_code' => $request->postal_code,
                'phone' => $request->phone,
                'email' => $request->email,
                'website' => $request->website,
                'clinic_image' => $clinicImage,
                'registration_number' => $request->registration_number,
                'license_number' => $request->license_number,
                'is_verified' => false,
                'is_active' => true,
                'country_id' => $request->country_id,
                'zone_id' => $request->zone_id,
            ]);

            DB::commit();

            return CommonHelper::responseWithData($this->formatClinicResponse($clinic), 1, 'Clinic created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating clinic: ' . $e->getMessage());
            return CommonHelper::responseError('Error creating clinic');
        }
    }

    /**
     * Update clinic
     */
    public function updateClinic(Request $request)
    {
        try {
            $clinicId = $request->get('id');
            $clinic = Clinic::findOrFail($clinicId);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'address' => 'sometimes|string|max:500',
                'city' => 'sometimes|string|max:100',
                'state' => 'sometimes|string|max:100',
                'postal_code' => 'sometimes|string|max:20',
                'phone' => 'sometimes|string|max:20',
                'email' => 'nullable|email',
                'website' => 'nullable|url',
                'clinic_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'registration_number' => 'nullable|string|max:100',
                'license_number' => 'nullable|string|max:100',
                'zone_id' => 'nullable|exists:zones,id',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            if ($request->hasFile('clinic_image')) {
                if ($clinic->clinic_image) {
                    CloudinaryHelper::delete($clinic->clinic_image);
                }
                $clinic->clinic_image = CloudinaryHelper::uploadImage($request->file('clinic_image'), 'clinic/images');
            }

            $clinic->update($request->except(['clinic_image', 'id']));

            DB::commit();

            return CommonHelper::responseWithData($this->formatClinicResponse($clinic), 1, 'Clinic updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating clinic: ' . $e->getMessage());
            return CommonHelper::responseError('Error updating clinic');
        }
    }

    /**
     * Verify clinic
     */
    public function verifyClinic(Request $request)
    {
        try {
            $clinicId = $request->get('id');
            $clinic = Clinic::findOrFail($clinicId);

            $clinic->is_verified = true;
            $clinic->save();

            return CommonHelper::responseWithData($this->formatClinicResponse($clinic), 1, 'Clinic verified successfully');
        } catch (\Exception $e) {
            Log::error('Error verifying clinic: ' . $e->getMessage());
            return CommonHelper::responseError('Error verifying clinic');
        }
    }

    /**
     * Toggle clinic status
     */
    public function toggleClinicStatus(Request $request)
    {
        try {
            $clinicId = $request->get('id');
            $clinic = Clinic::findOrFail($clinicId);

            $clinic->is_active = !$clinic->is_active;
            $clinic->save();

            $status = $clinic->is_active ? 'activated' : 'deactivated';
            return CommonHelper::responseWithData($this->formatClinicResponse($clinic), 1, "Clinic {$status} successfully");
        } catch (\Exception $e) {
            Log::error('Error toggling clinic status: ' . $e->getMessage());
            return CommonHelper::responseError('Error updating clinic status');
        }
    }

    /**
     * Delete clinic
     */
    public function deleteClinic(Request $request)
    {
        try {
            $clinicId = $request->get('id');
            $clinic = Clinic::findOrFail($clinicId);

            // Delete image from Cloudinary if exists
            if (!empty($clinic->clinic_image)) {
                $publicId = $this->extractPublicIdFromUrl($clinic->clinic_image);
                if (!empty($publicId)) {
                    CloudinaryHelper::delete($publicId);
                }
            }

            $clinic->delete();

            return CommonHelper::responseSuccess('Clinic deleted successfully');
        } catch (\Exception $e) {
            Log::error('Error deleting clinic: ' . $e->getMessage());
            return CommonHelper::responseError('Error deleting clinic');
        }
    }

    /**
     * Format clinic response
     */
    private function extractPublicIdFromUrl(string $url): string
    {
        // Extract the path after /upload/v{version}/
        if (preg_match('/\/upload\/(?:v\d+\/)?(.+?)(?:\.\w+)?$/', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Format clinic response
     */
    private function formatClinicResponse(Clinic $clinic): array
    {
        return [
            'id' => $clinic->id,
            'doctor_id' => $clinic->doctor_id,
            'doctor_name' => $clinic->doctor?->full_name,
            'name' => $clinic->name,
            'address' => $clinic->address,
            'city' => $clinic->city,
            'state' => $clinic->state,
            'postal_code' => $clinic->postal_code,
            'phone' => $clinic->phone,
            'email' => $clinic->email,
            'website' => $clinic->website,
            'clinic_image_url' => $clinic->clinic_image_url,
            'registration_number' => $clinic->registration_number,
            'license_number' => $clinic->license_number,
            'is_verified' => $clinic->is_verified,
            'is_active' => $clinic->is_active,
            'country_id' => $clinic->country_id,
            'country_name' => $clinic->country?->name,
            'zone_id' => $clinic->zone_id,
            'zone_name' => $clinic->zone?->name,
            'opening_hours' => $clinic->opening_hours,
            'created_at' => $clinic->created_at,
            'updated_at' => $clinic->updated_at,
        ];
    }
}
