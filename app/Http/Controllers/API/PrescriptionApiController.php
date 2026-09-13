<?php

namespace App\Http\Controllers\API;

use App\Models\Prescription;
use App\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PrescriptionApiController extends Controller
{
    /**
     * Get prescriptions with filtering
     */
    public function getPrescriptions(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $doctorId = $request->get('doctor_id', null);
            $patientId = $request->get('patient_id', null);
            $status = $request->get('status', null);

            $query = Prescription::query();

            if ($doctorId) {
                $query->byDoctor($doctorId);
            }

            if ($patientId) {
                $query->where('patient_id', $patientId);
            }

            if ($status) {
                $query->byStatus($status);
            }

            $total = $query->count();
            $prescriptions = $query->with(['doctor', 'patient', 'appointment'])
                ->orderBy('prescription_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedPrescriptions = $prescriptions->map(fn($prescription) => $this->formatPrescriptionResponse($prescription));

            return CommonHelper::responseWithData($formattedPrescriptions, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching prescriptions: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching prescriptions');
        }
    }

    /**
     * Get prescription details
     */
    public function getPrescriptionDetail($id)
    {
        try {
            $prescription = Prescription::with(['doctor', 'patient', 'appointment', 'prescriptionOrders'])
                ->findOrFail($id);

            return CommonHelper::responseWithData($this->formatPrescriptionResponse($prescription));
        } catch (\Exception $e) {
            Log::error('Error fetching prescription detail: ' . $e->getMessage());
            return CommonHelper::responseError('Prescription not found');
        }
    }

    /**
     * Create or update prescription
     */
    public function savePrescription(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'doctor_id' => 'required|exists:doctors,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'patient_id' => 'required|exists:users,id',
                'diagnosis' => 'required|string|max:1000',
                'medications' => 'required|array|min:1',
                'medications.*.name' => 'required|string',
                'medications.*.dosage' => 'required|string',
                'medications.*.frequency' => 'required|string',
                'medications.*.duration' => 'required|string',
                'clinical_notes' => 'nullable|string|max:2000',
                'special_instructions' => 'nullable|string|max:500',
                'valid_until' => 'required|date|after:today',
                'doctor_signature' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'seal_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            DB::beginTransaction();

            $prescriptionId = $request->get('id');
            $prescription = $prescriptionId ? Prescription::findOrFail($prescriptionId) : new Prescription();

            // Handle file uploads
            if ($request->hasFile('doctor_signature')) {
                if ($prescription->doctor_signature) {
                    CloudinaryHelper::delete($prescription->doctor_signature);
                }
                $prescription->doctor_signature = CloudinaryHelper::uploadImage($request->file('doctor_signature'), 'prescription/signatures');
            }

            if ($request->hasFile('seal_image')) {
                if ($prescription->seal_image) {
                    CloudinaryHelper::delete($prescription->seal_image);
                }
                $prescription->seal_image = CloudinaryHelper::uploadImage($request->file('seal_image'), 'prescription/seals');
            }

            $prescription->doctor_id = $request->doctor_id;
            $prescription->appointment_id = $request->appointment_id;
            $prescription->patient_id = $request->patient_id;
            $prescription->prescription_date = now();
            $prescription->medications = $request->medications;
            $prescription->diagnosis = $request->diagnosis;
            $prescription->clinical_notes = $request->clinical_notes;
            $prescription->special_instructions = $request->special_instructions;
            $prescription->valid_until = $request->valid_until;
            $prescription->status = Prescription::STATUS_ISSUED;
            $prescription->save();

            DB::commit();

            return CommonHelper::responseWithData($this->formatPrescriptionResponse($prescription), 1, 'Prescription saved successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving prescription: ' . $e->getMessage());
            return CommonHelper::responseError('Error saving prescription');
        }
    }

    /**
     * Update prescription status
     */
    public function updatePrescriptionStatus(Request $request)
    {
        try {
            $prescriptionId = $request->get('id');
            $status = $request->get('status');

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:draft,issued,fulfilled,cancelled',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $prescription = Prescription::findOrFail($prescriptionId);
            $prescription->status = $status;
            $prescription->save();

            return CommonHelper::responseWithData($this->formatPrescriptionResponse($prescription), 1, 'Prescription status updated');
        } catch (\Exception $e) {
            Log::error('Error updating prescription status: ' . $e->getMessage());
            return CommonHelper::responseError('Error updating prescription status');
        }
    }

    /**
     * Cancel prescription
     */
    public function cancelPrescription(Request $request)
    {
        try {
            $prescriptionId = $request->get('id');
            $prescription = Prescription::findOrFail($prescriptionId);

            if ($prescription->status === Prescription::STATUS_CANCELLED) {
                return CommonHelper::responseError('Prescription is already cancelled');
            }

            $prescription->status = Prescription::STATUS_CANCELLED;
            $prescription->save();

            return CommonHelper::responseWithData($this->formatPrescriptionResponse($prescription), 1, 'Prescription cancelled successfully');
        } catch (\Exception $e) {
            Log::error('Error cancelling prescription: ' . $e->getMessage());
            return CommonHelper::responseError('Error cancelling prescription');
        }
    }

    /**
     * Format prescription response
     */
    private function formatPrescriptionResponse(Prescription $prescription): array
    {
        return [
            'id' => $prescription->id,
            'doctor_id' => $prescription->doctor_id,
            'doctor_name' => $prescription->doctor?->full_name,
            'appointment_id' => $prescription->appointment_id,
            'patient_id' => $prescription->patient_id,
            'patient_name' => $prescription->patient?->name,
            'prescription_date' => $prescription->prescription_date,
            'medications' => $prescription->medications,
            'diagnosis' => $prescription->diagnosis,
            'clinical_notes' => $prescription->clinical_notes,
            'doctor_signature_url' => $prescription->doctor_signature_url,
            'seal_image_url' => $prescription->seal_image_url,
            'pdf_file_url' => $prescription->pdf_file_url,
            'status' => $prescription->status,
            'valid_until' => $prescription->valid_until,
            'special_instructions' => $prescription->special_instructions,
            'created_at' => $prescription->created_at,
            'updated_at' => $prescription->updated_at,
        ];
    }
}
