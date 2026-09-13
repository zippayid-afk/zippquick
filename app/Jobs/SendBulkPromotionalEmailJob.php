<?php

namespace App\Jobs;

use App\Helpers\CommonHelper;
use App\Models\DeliveryBoy;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends an admin-composed promotional email to many recipients in the background.
 * SMTP is the slowest channel we have (~1s+ per mail): sending to the whole customer
 * base inline would hold the admin request open for minutes and then time out.
 *
 * Recipients (resolved at RUN time, so profile-edited emails and new signups are
 * included, deleted accounts skipped):
 *  - user: active customers who HAVE an email — regardless of how they registered
 *    (phone signups can add an email in their profile later)
 *  - delivery_boy: active delivery boys; their login email lives on the linked admin
 *
 * $ids = null means "all" of the chosen recipient type.
 */
class SendBulkPromotionalEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    private string $recipientType; // user | delivery_boy
    private ?array $ids;
    private string $title;
    private string $message;
    private ?string $imagePath;

    public function __construct(string $recipientType, ?array $ids, string $title, string $message, ?string $imagePath = null)
    {
        $this->recipientType = $recipientType;
        $this->ids = $ids;
        $this->title = $title;
        $this->message = $message;
        $this->imagePath = $imagePath;
    }

    public function handle(): void
    {
        $appName = Setting::get_value('app_name') ?? '';
        $supportEmail = Setting::get_value('smtp_from_mail') ?? '';
        $attachment = $this->imagePath ? storage_path('app/public/' . $this->imagePath) : null;

        $query = $this->recipientQuery();

        $query->chunk(200, function ($recipients) use ($appName, $supportEmail, $attachment) {
            foreach ($recipients as $r) {
                if (empty($r->email)) {
                    continue;
                }
                $replacements = [$r->name, $r->email, $appName, $supportEmail];
                $placeholders = ['[Customer Name]', '[Customer Email]', '[App Name]', '[Support Email]'];

                $subject = str_replace($placeholders, $replacements, $this->title);
                $message = str_replace($placeholders, $replacements, $this->message);

                $data = [
                    'name' => $r->name,
                    'content' => $message,
                    'type' => 'promotional_mail',
                    'attachment' => $attachment,
                ];

                try {
                    CommonHelper::sendMail($r->email, $subject, $data);
                } catch (\Throwable $e) {
                    Log::error("Error sending promotional email to {$r->email}: " . $e->getMessage());
                }
            }
        });
    }

    /** Rows exposing ->name and ->email for the chosen recipient type. */
    private function recipientQuery()
    {
        if ($this->recipientType === 'delivery_boy') {
            // A boy's login email is on the linked admin account.
            return DeliveryBoy::query()
                ->join('admins', 'admins.id', '=', 'delivery_boys.admin_id')
                ->where('delivery_boys.status', 1)
                ->whereNotNull('admins.email')
                ->where('admins.email', '!=', '')
                ->when($this->ids !== null, fn ($q) => $q->whereIn('delivery_boys.id', $this->ids))
                ->orderBy('delivery_boys.id')
                ->select(['delivery_boys.id', 'delivery_boys.name', 'admins.email']);
        }

        // Customers: anyone ACTIVE with an email, whatever their signup type —
        // filtering on login type (email/gmail/apple) silently skipped google- and
        // phone-registered accounts that added an email later.
        return User::query()
            ->where('status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($this->ids !== null, fn ($q) => $q->whereIn('id', $this->ids))
            ->orderBy('id')
            ->select(['id', 'name', 'email']);
    }
}
