<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppVendorPreference;

class NotificationPreferenceService
{
    /**
     * Get or initialize preferences for a contact.
     */
    public function getPreferences(WhatsAppContact $contact): WhatsAppVendorPreference
    {
        return WhatsAppVendorPreference::firstOrCreate(
            ['contact_id' => $contact->id],
            [
                'vendor_id' => $contact->vendor_id,
                'opt_in_order_alerts' => true,
                'opt_in_status_alerts' => true,
                'opt_in_stock_alerts' => true,
                'is_paused' => false,
                'locale' => $contact->language ?? 'en',
                'consent_audit_log' => [
                    [
                        'action' => 'initialized',
                        'timestamp' => Carbon::now()->toIso8601String(),
                        'channel' => 'system',
                    ]
                ],
            ]
        );
    }

    /**
     * Evaluate whether a notification can be delivered right now.
     */
    public function canReceiveNotification(
        WhatsAppContact $contact,
        string $eventType,
        bool $isCritical = false
    ): array {
        $prefs = $this->getPreferences($contact);

        // Critical notifications (e.g. security alerts, payment charge failures) bypass normal opt-outs
        if ($isCritical) {
            return ['allowed' => true, 'reason' => 'critical_override'];
        }

        if ($prefs->is_paused) {
            return ['allowed' => false, 'reason' => 'alerts_paused'];
        }

        // Check category-specific opt-ins
        $categoryAllowed = match ($eventType) {
            'order_created', 'order_status_update', 'order_action_required' => $prefs->opt_in_order_alerts,
            'vendor_approved', 'vendor_rejected', 'subscription_status' => $prefs->opt_in_status_alerts,
            'low_stock', 'product_unavailable' => $prefs->opt_in_stock_alerts,
            default => true,
        };

        if (!$categoryAllowed) {
            return ['allowed' => false, 'reason' => "opted_out_of_{$eventType}"];
        }

        // Check quiet hours
        if ($prefs->quiet_hours_start && $prefs->quiet_hours_end) {
            if ($this->isCurrentTimeInQuietHours($prefs->quiet_hours_start, $prefs->quiet_hours_end)) {
                return ['allowed' => false, 'reason' => 'quiet_hours_active'];
            }
        }

        return ['allowed' => true, 'reason' => 'permitted'];
    }

    /**
     * Process deterministic preference commands from inbound chat.
     */
    public function handleInboundCommand(WhatsAppContact $contact, string $rawMessage): ?array
    {
        $command = strtoupper(trim($rawMessage));
        $prefs = $this->getPreferences($contact);

        return match ($command) {
            'STOP', 'UNSUBSCRIBE' => $this->pauseAlerts($prefs, 'STOP command received'),
            'PAUSE ALERTS', 'PAUSE' => $this->pauseAlerts($prefs, 'PAUSE ALERTS command received'),
            'RESUME ALERTS', 'START', 'RESUME', 'UNPAUSE' => $this->resumeAlerts($prefs, 'RESUME ALERTS command received'),
            default => null,
        };
    }

    public function pauseAlerts(WhatsAppVendorPreference $prefs, string $reason): array
    {
        $prefs->is_paused = true;
        $this->appendAuditLog($prefs, 'paused', $reason);
        $prefs->save();

        return [
            'status' => 'paused',
            'message' => "Alerts paused. You will not receive non-critical WhatsApp notifications.\nSend RESUME ALERTS or START anytime to unpause.",
        ];
    }

    public function resumeAlerts(WhatsAppVendorPreference $prefs, string $reason): array
    {
        $prefs->is_paused = false;
        $this->appendAuditLog($prefs, 'resumed', $reason);
        $prefs->save();

        return [
            'status' => 'resumed',
            'message' => "Alerts resumed! You will now receive order, stock, and application status updates.",
        ];
    }

    public function updateCategoryOptIn(
        WhatsAppContact $contact,
        string $category,
        bool $enabled
    ): WhatsAppVendorPreference {
        $prefs = $this->getPreferences($contact);
        $field = match ($category) {
            'order' => 'opt_in_order_alerts',
            'status' => 'opt_in_status_alerts',
            'stock' => 'opt_in_stock_alerts',
            default => throw new \InvalidArgumentException("Unknown category: {$category}"),
        };

        $prefs->{$field} = $enabled;
        $this->appendAuditLog($prefs, 'category_updated', "{$category} set to " . ($enabled ? 'enabled' : 'disabled'));
        $prefs->save();

        return $prefs;
    }

    public function setQuietHours(
        WhatsAppContact $contact,
        ?string $startTime,
        ?string $endTime
    ): WhatsAppVendorPreference {
        $prefs = $this->getPreferences($contact);
        $prefs->quiet_hours_start = $startTime;
        $prefs->quiet_hours_end = $endTime;
        $this->appendAuditLog($prefs, 'quiet_hours_updated', "Quiet hours set from {$startTime} to {$endTime}");
        $prefs->save();

        return $prefs;
    }

    protected function isCurrentTimeInQuietHours(string $start, string $end): bool
    {
        $now = Carbon::now();
        $startTime = Carbon::createFromTimeString($start);
        $endTime = Carbon::createFromTimeString($end);

        if ($startTime->lessThanOrEqualTo($endTime)) {
            return $now->between($startTime, $endTime);
        }

        // Quiet hours cross midnight (e.g. 22:00 to 07:00)
        return $now->greaterThanOrEqualTo($startTime) || $now->lessThanOrEqualTo($endTime);
    }

    protected function appendAuditLog(WhatsAppVendorPreference $prefs, string $action, string $detail): void
    {
        $log = $prefs->consent_audit_log ?? [];
        $log[] = [
            'action' => $action,
            'detail' => $detail,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
        $prefs->consent_audit_log = $log;
    }
}
