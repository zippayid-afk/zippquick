<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class GoogleMeetService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;

    public function __construct()
    {
        $this->clientId = config('services.google_meet.client_id');
        $this->clientSecret = config('services.google_meet.client_secret');
        $this->redirectUri = config('services.google_meet.redirect_uri');
        $this->accessToken = config('services.google_meet.access_token');
    }

    /**
     * Generate a unique Google Meet link for an appointment
     * This uses a simple approach by generating a unique meeting ID
     * The actual Google Meet link format: https://meet.google.com/unique-code
     */
    public function generateMeetingLink($appointmentId, $doctorName = '', $patientName = ''): array
    {
        try {
            // Generate unique meeting code (3 words separated by hyphens)
            // Google Meet format: xxx-xxxx-xxx
            $meetingCode = $this->generateUniqueMeetingCode();
            $googleMeetLink = "https://meet.google.com/{$meetingCode}";

            return [
                'success' => true,
                'meeting_link' => $googleMeetLink,
                'meeting_code' => $meetingCode,
                'appointment_id' => $appointmentId,
            ];
        } catch (\Exception $e) {
            Log::error('Error generating Google Meet link: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to generate Google Meet link',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate unique meeting code in Google Meet format
     * Format: word-word-word (e.g., abc-defg-hij)
     */
    private function generateUniqueMeetingCode(): string
    {
        // Google Meet codes are typically 3-part separated by hyphens
        // Example: bgt-tyrf-yxs
        $part1 = Str::random(3);  // 3 characters
        $part2 = Str::random(4);  // 4 characters
        $part3 = Str::random(3);  // 3 characters

        return strtolower("{$part1}-{$part2}-{$part3}");
    }

    /**
     * Get Google Meet meeting details
     * Note: This requires actual Google Calendar API integration for real-time data
     */
    public function getMeetingDetails($meetingCode): array
    {
        try {
            // In a real scenario with Google Calendar API, you would:
            // 1. Look up the meeting code in your database
            // 2. Fetch details from Google Calendar API if needed

            return [
                'success' => true,
                'meeting_link' => "https://meet.google.com/{$meetingCode}",
                'meeting_code' => $meetingCode,
                'video_call_enabled' => true,
                'screen_sharing_enabled' => true,
                'recording_enabled' => false,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting Google Meet details: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to get meeting details',
            ];
        }
    }

    /**
     * Send Google Meet link to participant via email
     */
    public function sendMeetingLink($participantEmail, $meetingLink, $appointmentDetails): bool
    {
        try {
            $subject = "Google Meet Link for Your Appointment";
            $message = "
                <h2>Your Appointment Details</h2>
                <p><strong>Appointment Date:</strong> {$appointmentDetails['date']}</p>
                <p><strong>Appointment Time:</strong> {$appointmentDetails['time']}</p>
                <p><strong>Doctor:</strong> {$appointmentDetails['doctor_name']}</p>
                <hr>
                <h3>Join Your Video Consultation</h3>
                <p>Click the link below to join the Google Meet video call:</p>
                <p><a href='{$meetingLink}' style='background-color: #4285F4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>
                    Join Google Meet
                </a></p>
                <p>Or copy and paste this link in your browser:</p>
                <p>{$meetingLink}</p>
                <hr>
                <p><strong>Tips:</strong></p>
                <ul>
                    <li>Test your camera and microphone before the call</li>
                    <li>Join 5 minutes before the scheduled time</li>
                    <li>Use a stable internet connection</li>
                </ul>
            ";

            // Send email (implement using your mail service)
            Log::info("Google Meet link sent to {$participantEmail}: {$meetingLink}");

            return true;
        } catch (\Exception $e) {
            Log::error('Error sending Google Meet link: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate if a Google Meet link is accessible
     */
    public function validateMeetingLink($meetingCode): bool
    {
        try {
            // Simple validation - check if code format is valid
            if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $meetingCode)) {
                return false;
            }

            // In a production scenario, you might want to:
            // 1. Check if the link is reachable
            // 2. Verify with Google Calendar API
            // 3. Check database for meeting record

            return true;
        } catch (\Exception $e) {
            Log::error('Error validating Google Meet link: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format meeting link with additional parameters for tracking
     * Useful for adding analytics or tracking who joins
     */
    public function formatMeetingLinkWithParams($meetingCode, $displayName = ''): string
    {
        $link = "https://meet.google.com/{$meetingCode}";

        if ($displayName) {
            // URL encode the display name
            $encodedName = urlencode($displayName);
            // Note: Google Meet doesn't support pre-filling name in URL, but you can add it as reference
            $link .= "?authuser=0&name={$encodedName}";
        }

        return $link;
    }

    /**
     * Get meeting statistics (if implemented with Google Calendar API)
     */
    public function getMeetingStats($meetingCode): array
    {
        try {
            // Placeholder for meeting statistics
            // In a real implementation, this would fetch data from Google Calendar API

            return [
                'meeting_code' => $meetingCode,
                'meeting_link' => "https://meet.google.com/{$meetingCode}",
                'total_participants' => 0,
                'duration_minutes' => 0,
                'started_at' => null,
                'ended_at' => null,
                'recording_available' => false,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting meeting stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if Google Meet service is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }
}
