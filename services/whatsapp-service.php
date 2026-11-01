<?php
/**
 * YBT Management Software — WhatsApp Invoice Sharing Service
 * Normalizes local and international phone numbers and generates click-to-chat invoice shares
 */

declare(strict_types=1);

class WhatsAppService {
    /**
     * Normalize local and international mobile phone numbers for WhatsApp API / Click-to-Chat.
     * Formats without '+', '-', spaces, or leading zeroes.
     *
     * Pakistani Numbers:
     *   "03001234567"   -> "923001234567"
     *   "+923001234567" -> "923001234567"
     *   "00923001234567"-> "923001234567"
     *   "0300-1234567"  -> "923001234567"
     *   "3001234567"    -> "923001234567"
     *
     * International Numbers:
     *   "+971501234567" -> "971501234567" (UAE)
     *   "+919876543210" -> "919876543210" (India)
     *   "+14155552671"  -> "14155552671"  (US)
     *   "+447911123456" -> "447911123456" (UK)
     */
    public static function normalizePhoneNumber(?string $phone, string $defaultCountry = 'Pakistan'): string {
        if ($phone === null || trim($phone) === '') {
            return '';
        }

        $raw = trim($phone);

        // Check if starts with '+'
        $hadPlus = str_starts_with($raw, '+');

        // Remove all non-digits
        $digits = preg_replace('/[^\d]/', '', $raw);
        if ($digits === '' || $digits === null) {
            return '';
        }

        // Remove international dialling prefix 00 (e.g. 0092...)
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // If it had a '+', trust the country code already provided
        if ($hadPlus) {
            return $digits;
        }

        // Distinct Pakistani Mobile Prefix Check (03XX is exclusively Pakistan)
        if (strlen($digits) === 11 && str_starts_with($digits, '03')) {
            return '92' . substr($digits, 1);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return '92' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '923')) {
            return $digits;
        }

        $defaultCountry = strtolower($defaultCountry);

        if ($defaultCountry === 'india') {
            // Indian mobile numbers (10 digits starting with 6, 7, 8, 9)
            if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
                return '91' . $digits;
            }
            if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                return '91' . substr($digits, 1);
            }
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                return $digits;
            }
        }

        // Fallback: return clean digits
        return $digits;
    }

    /**
     * Generate bilingual invoice message text for WhatsApp sharing
     */
    public static function formatInvoiceMessage(array $invoice, array $business, ?string $invoiceUrl = null): string {
        $currency = $invoice['currency'] ?? $business['default_currency'] ?? 'PKR';
        $customerName = trim($invoice['customer_name'] ?? 'Valued Customer');
        $businessName = trim($business['name'] ?? 'Our Mobile Shop');
        $invNum = $invoice['invoice_number'] ?? ('#' . $invoice['id']);
        $invDate = !empty($invoice['invoice_date']) ? date('d M Y', strtotime($invoice['invoice_date'])) : date('d M Y');
        $grandTotal = number_format((float)($invoice['grand_total'] ?? 0), 2);
        $amountPaid = number_format((float)($invoice['amount_paid'] ?? 0), 2);
        $outstanding = number_format((float)($invoice['outstanding'] ?? 0), 2);
        $status = strtolower($invoice['status'] ?? 'draft');

        // Cancelled Invoice Safety Guard: never share as valid payable invoice
        if ($status === 'cancelled') {
            $msg = "*⚠️ NOTICE: INVOICE CANCELLED*\n";
            $msg .= "*اطلاع: انوائس منسوخ کر دی گئی ہے*\n\n";
            $msg .= "Dear *{$customerName}*,\n";
            $msg .= "Please note that Invoice *{$invNum}* dated {$invDate} issued by *{$businessName}* has been *CANCELLED* and is no longer payable.\n\n";
            $msg .= "محترم {$customerName}، براہ کرم نوٹ فرمائیں کہ یہ انوائس منسوخ ہو چکی ہے۔\n\n";
            if (!empty($business['phone'])) {
                $msg .= "📞 Helpline / رابطہ: {$business['phone']}\n";
            }
            $msg .= "— {$businessName}";
            return $msg;
        }

        // Active / Payable Invoice Message
        $msg = "*INVOICE / بل*\n\n";
        $msg .= "Assalam-o-Alaikum / Greetings *{$customerName}*,\n\n";
        $msg .= "Thank you for shopping at *{$businessName}*.\n";
        $msg .= "Here is your invoice summary:\n\n";
        $msg .= "📄 *Invoice No:* {$invNum}\n";
        $msg .= "📅 *Date:* {$invDate}\n";
        $msg .= "💰 *Total Amount:* {$currency} {$grandTotal}\n";
        $msg .= "✅ *Paid:* {$currency} {$amountPaid}\n";

        if ((float)($invoice['outstanding'] ?? 0) > 0.009) {
            $msg .= "⏳ *Balance Due (بقیہ واجب الادا):* {$currency} {$outstanding}\n";
        } else {
            $msg .= "🎉 *Status:* Paid in Full (حساب بے باق)\n";
        }

        if ($invoiceUrl !== null && trim($invoiceUrl) !== '') {
            $msg .= "\n🔗 *View / Print Online:* {$invoiceUrl}\n";
        }

        $msg .= "\nشکریہ! Thank you for your business.\n";
        if (!empty($business['phone'])) {
            $msg .= "📞 Helpline: {$business['phone']}\n";
        }
        $msg .= "— {$businessName}";

        return $msg;
    }

    /**
     * Build the WhatsApp click-to-chat URL
     */
    public static function getClickToChatUrl(array $invoice, array $business, ?string $invoiceUrl = null): string {
        $phone = $invoice['customer_phone'] ?? '';
        $country = $business['country'] ?? 'Pakistan';
        $normalizedPhone = self::normalizePhoneNumber($phone, $country);
        $message = self::formatInvoiceMessage($invoice, $business, $invoiceUrl);
        $encodedMsg = rawurlencode($message);

        if (!empty($normalizedPhone)) {
            return "https://wa.me/{$normalizedPhone}?text={$encodedMsg}";
        }

        // Fallback: allows user to select contact in WhatsApp
        return "https://wa.me/?text={$encodedMsg}";
    }
}
