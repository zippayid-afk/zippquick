<?php

namespace App\Http\Controllers\API;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Prescription;
use App\Http\Controllers\Controller;
use App\Helpers\CommonHelper;
use App\Services\GoogleMeetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AppointmentApiController extends Controller
{
    /**
     * Get appointments with filtering
     */
    public function getAppointments(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $search = $request->get('search', '');
            $doctorId = $request->get('doctor_id', null);
            $status = $request->get('status', null);
            $type = $request->get('type', null);
            $dateFrom = $request->get('date_from', null);
            $dateTo = $request->get('date_to', null);

            $query = Appointment::query();

            if ($search) {
                $query->search($search);
            }

            if ($doctorId) {
                $query->byDoctor($doctorId);
            }

            if ($status) {
                $query->byStatus($status);
            }

            if ($type) {
                $query->byType($type);
            }

            if ($dateFrom) {
                $query->whereDate('appointment_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('appointment_date', '<=', $dateTo);
            }

            $total = $query->count();
            $appointments = $query->with(['doctor', 'patient', 'clinic', 'prescription'])
                ->orderBy('appointment_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $formattedAppointments = $appointments->map(fn($appointment) => $this->formatAppointmentResponse($appointment));

            return CommonHelper::responseWithData($formattedAppointments, $total);
        } catch (\Exception $e) {
            Log::error('Error fetching appointments: ' . $e->getMessage());
            return CommonHelper::responseError('Error fetching appointments');
        }
    }

    /**
     * Get appointment details
     */
    public function getAppointmentDetail($id)
    {
        try {
            $appointment = Appointment::with(['doctor', 'patient', 'clinic', 'prescription', 'messages'])
                ->findOrFail($id);

            return CommonHelper::responseWithData($this->formatAppointmentResponse($appointment));
        } catch (\Exception $e) {
            Log::error('Error fetching appointment detail: ' . $e->getMessage());
            return CommonHelper::responseError('Appointment not found');
        }
    }

    /**
     * Approve appointment
     */
    public function approveAppointment(Request $request)
    {
        try {
            $appointmentId = $request->get('id');
            $appointment = Appointment::findOrFail($appointmentId);

            if (!$appointment->canBeRescheduled()) {
                return CommonHelper::responseError('Appointment cannot be approved');
            }

            $appointment->status = Appointment::STATUS_APPROVED;
            $appointment->save();

            return CommonHelper::responseWithData($this->formatAppointmentResponse($appointment), 1, 'Appointment approved successfully');
        } catch (\Exception $e) {
            Log::error('Error approving appointment: ' . $e->getMessage());
            return CommonHelper::responseError('Error approving appointment');
        }
    }

    /**
     * Reject appointment
     */
    public function rejectAppointment(Request $request)
    {
        try {
            $appointmentId = $request->get('id');
            $reason = $request->get('reason', 'Appointment rejected by doctor');

            $appointment = Appointment::findOrFail($appointmentId);

            if ($appointment->status !== Appointment::STATUS_PENDING) {
                return CommonHelper::responseError('Only pending appointments can be rejected');
            }

            $appointment->status = Appointment::STATUS_REJECTED;
            $appointment->cancellation_reason = $reason;
            $appointment->save();

            return CommonHelper::responseWithData($this->formatAppointmentResponse($appointment), 1, 'Appointment rejected');
        } catch (\Exception $e) {
            Log::error('Error rejecting appointment: ' . $e->getMessage());
            return CommonHelper::responseError('Error rejecting appointment');
        }
    }

    /**
     * Complete appointment
     */
    public function completeAppointment(Request $request)
    {
        try {
            $appointmentId = $request->get('id');
            $appointment = Appointment::findOrFail($appointmentId);

            if ($appointment->status !== Appointment::STATUS_APPROVED) {
                return CommonHelper::responseError('Only approved appointments can be completed');
            }

            $appointment->status = Appointment::STATUS_COMPLETED;
            $appointment->save();

            return CommonHelper::responseWithData($this->formatAppointmentResponse($appointment), 1, 'Appointment completed successfully');
        } catch (\Exception $e) {
            Log::error('Error completing appointment: ' . $e->getMessage());
            return CommonHelper::responseError('Error completing appointment');
        }
    }

    /**
     * Cancel appointment
     */
    public function cancelAppointment(Request $request)
    {
        try {
            $appointmentId = $request->get('id');
            $reason = $request->get('reason', 'Appointment cancelled');

            $appointment = Appointment::findOrFail($appointmentId);

            if (!$appointment->canBeCancelled()) {
                return CommonHelper::responseError('Appointment cannot be cancelled');
            }

            $appointment->status = Appointment::STATUS_CANCELLED;
            $appointment->cancellation_reason = $reason;
            $appointment->save();

            return CommonHelper::responseWithData($this->formatAppointmentResponse($appointment), 1, 'Appointment cancelled successfully');
        } catch (\Exception $e) {
            Log::error('Error cancelling appointment: ' . $e->getMessage());
            return CommonHelper::responseError('Error cancelling appointment');
        }
    }

    /**
     * Generate and assign Google Meet link to appointment
     */
    public function generateGoogleMeetLink(Request $request)
    {
        try {
            $appointmentId = $request->get('id');
            $appointment = Appointment::findOrFail($appointmentId);

            if ($appointment->type !== Appointment::TYPE_VIDEO) {
                return CommonHelper::responseError('Google Meet link can only be generated for video appointments');
            }

            $googleMeetService = new GoogleMeetService();
            $meetingResult = $googleMeetService->generateMeetingLink(
                $appointmentId,
                $appointment->doctor?->full_name,
                $appointment->patient_name
            );

            if (!$meetingResult['success']) {
                return CommonHelper::responseError($meetingResult['message']);
            }

            // Update appointment with Google Meet link
            $appointment->google_meet_link = $meetingResult['meeting_link'];
            $appointment->google_meet_id = $meetingResult['meeting_code'];
            $appointment->save();

            // Send email to patient with the link
            if ($appointment->patient_email) {
                $googleMeetService->sendMeetingLink(
                    $appointment->patient_email,
                    $meetingResult['meeting_link'],
                    [
                        'date' => $appointment->appointment_date->format('F d, Y'),
                        'time' => $appointment->appointment_time->format('H:i A'),
                        'doctor_name' => $appointment->doctor?->full_name,
                    ]
                );
            }

            return CommonHelper::responseWithData(
                array_merge($this->formatAppointmentResponse($appointment), [
                    'google_meet_link' => $meetingResult['meeting_link'],
                    'google_meet_id' => $meetingResult['meeting_code'],
                ]),
                1,
                'Google Meet link generated and sent to patient'
            );
        } catch (\Exception $e) {
            Log::error('Error generating Google Meet link: ' . $e->getMessage());
            return CommonHelper::responseError('Error generating Google Meet link');
        }
    }

    /**
     * Format appointment response
     */
    private function formatAppointmentResponse(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'doctor_name' => $appointment->doctor?->full_name,
            'patient_id' => $appointment->patient_id,
            'patient_name' => $appointment->patient?->name ?? $appointment->patient_name,
            'patient_email' => $appointment->patient_email,
            'patient_phone' => $appointment->patient_phone,
            'clinic_id' => $appointment->clinic_id,
            'clinic_name' => $appointment->clinic?->name,
            'appointment_date' => $appointment->appointment_date,
            'appointment_time' => $appointment->appointment_time,
            'duration_minutes' => $appointment->duration_minutes,
            'type' => $appointment->type,
            'status' => $appointment->status,
            'symptoms' => $appointment->symptoms,
            'notes' => $appointment->notes,
            'google_meet_link' => $appointment->google_meet_link,
            'google_meet_id' => $appointment->google_meet_id,
            'is_rescheduled' => $appointment->is_rescheduled,
            'original_appointment_id' => $appointment->original_appointment_id,
            'cancellation_reason' => $appointment->cancellation_reason,
            'prescription_id' => $appointment->prescription_id,
            'amount' => $appointment->amount,
            'currency' => $appointment->currency,
            'payment_status' => $appointment->payment_status,
            'created_at' => $appointment->created_at,
            'updated_at' => $appointment->updated_at,
        ];
    }
}
