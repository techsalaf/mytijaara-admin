<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Vendor;
use App\Models\WithdrawRequest;

class VendorWalletReadService
{
    /**
     * Get safe financial overview for vendor without exposing sensitive PII.
     *
     * @return array{
     *     balance: float,
     *     pending_withdraw: float,
     *     total_withdrawn: float,
     *     payout_configured: bool,
     *     bank_name: ?string,
     *     account_masked: ?string,
     *     recent_withdrawals: array,
     *     formatted_message: string
     * }
     */
    public function getWalletSummary(Vendor $vendor): array
    {
        $wallet = $vendor->wallet ?? null;

        $balance = (float) ($wallet?->balance ?? 0.0);
        $pendingWithdraw = (float) ($wallet?->pending_withdraw ?? 0.0);
        $totalWithdrawn = (float) ($wallet?->total_withdrawn ?? 0.0);

        // Mask bank account number (e.g. *******1234)
        $rawAccount = (string) ($vendor->account_no ?? '');
        $accountMasked = strlen($rawAccount) >= 4
            ? str_repeat('*', max(0, strlen($rawAccount) - 4)) . substr($rawAccount, -4)
            : ($rawAccount ? '****' : null);

        $bankName = $vendor->bank_name ?? null;
        $isConfigured = !empty($rawAccount) && !empty($bankName);

        // Recent withdrawals (last 5)
        $recent = [];
        if (class_exists(WithdrawRequest::class)) {
            $recent = WithdrawRequest::where('vendor_id', $vendor->id)
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get(['id', 'amount', 'status', 'created_at'])
                ->map(fn ($wr) => [
                    'id' => $wr->id,
                    'amount' => (float) $wr->amount,
                    'status' => $wr->status,
                    'date' => $wr->created_at?->toDateString(),
                ])
                ->toArray();
        }

        $formatted = "💳 *MyTijaara Vendor Wallet*\n\n";
        $formatted .= "• *Available Balance:* ₦" . number_format($balance, 2) . "\n";
        $formatted .= "• *Pending Withdrawals:* ₦" . number_format($pendingWithdraw, 2) . "\n";
        $formatted .= "• *Total Withdrawn:* ₦" . number_format($totalWithdrawn, 2) . "\n\n";

        $formatted .= "*Payout Account:*\n";
        if ($isConfigured) {
            $formatted .= "• Bank: {$bankName}\n";
            $formatted .= "• Account: `{$accountMasked}`\n";
            $formatted .= "• Status: ✅ Verified for disbursements\n";
        } else {
            $formatted .= "• Status: ⚠️ Not configured. Please add your bank details in the vendor portal.\n";
        }

        if (!empty($recent)) {
            $formatted .= "\n*Recent Withdrawals:*\n";
            foreach ($recent as $r) {
                $statusEmoji = match ($r['status']) {
                    'approved' => '✅',
                    'pending' => '⏳',
                    'denied' => '❌',
                    default => 'ℹ️',
                };
                $formatted .= "• #{$r['id']} — ₦" . number_format($r['amount'], 2) . " [{$r['status']} {$statusEmoji}] ({$r['date']})\n";
            }
        }

        return [
            'balance' => $balance,
            'pending_withdraw' => $pendingWithdraw,
            'total_withdrawn' => $totalWithdrawn,
            'payout_configured' => $isConfigured,
            'bank_name' => $bankName,
            'account_masked' => $accountMasked,
            'recent_withdrawals' => $recent,
            'formatted_message' => $formatted,
        ];
    }
}
