<?php
/**
 * YBT Management Software — Transactional Mailer Service
 * Configurable transactional email delivery with SMTP and secure local logging fallback.
 */

class MailerService {
    /**
     * In-memory only last dispatched OTP for automated test verification.
     * Strictly NOT written to logs or disk.
     */
    public static ?string $lastDispatchedOtp = null;

    /**
     * Diagnostic fields for tracking SMTP operations safely without exposing secrets.
     */
    public static ?string $lastError = null;
    public static ?string $lastStatus = null;
    public static ?string $lastServerResponse = null;

    /**
     * Mask an email address for safe diagnostic logging (e.g., ab***z@domain.com).
     */
    public static function maskEmail(string $email): string {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $name = $parts[0];
        $domain = $parts[1];
        $len = strlen($name);
        if ($len <= 2) {
            $masked = substr($name, 0, 1) . '***';
        } else {
            $masked = substr($name, 0, 2) . '***' . substr($name, -1);
        }
        return $masked . '@' . $domain;
    }

    /**
     * Send email verification 6-digit OTP code to newly registered user
     */
    public static function sendVerificationOtp(string $email, string $name, string $otp): bool {
        self::$lastDispatchedOtp = $otp;
        $subject = "Your Verification Code: {$otp} — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "Thank you for registering on " . APP_NAME . ".\n\n"
              . "Your 6-digit email verification code is:\n\n"
              . "   " . $otp . "\n\n"
              . "This verification code will expire in 15 minutes.\n"
              . "Enter this code on the verification screen to activate your business account.\n\n"
              . "If you did not request this verification code, please ignore this email.\n\n"
              . "Regards,\n" . APP_NAME . " Security Team";

        return self::send($email, $name, $subject, $body);
    }

    /**
     * Send password reset 6-digit OTP code
     */
    public static function sendPasswordResetOtp(string $email, string $name, string $otp): bool {
        self::$lastDispatchedOtp = $otp;
        $subject = "Your Password Reset Code: {$otp} — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "We received a request to reset your password on " . APP_NAME . ".\n\n"
              . "Your 6-digit password reset code is:\n\n"
              . "   " . $otp . "\n\n"
              . "This reset code will expire in 15 minutes.\n"
              . "Enter this code on the password reset screen along with your new password.\n\n"
              . "If you did not request a password reset, no action is needed.\n\n"
              . "Regards,\n" . APP_NAME . " Security Team";

        return self::send($email, $name, $subject, $body);
    }

    /**
     * Send email verification link to newly registered shopkeeper
     */
    public static function sendVerificationEmail(string $email, string $name, string $rawToken): bool {
        $verifyUrl = url('verify-email.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email));
        $subject = "Verify your shopkeeper account — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "Thank you for registering your shop on " . APP_NAME . ".\n\n"
              . "Please verify your email address and activate your shopkeeper account by clicking the link below:\n"
              . $verifyUrl . "\n\n"
              . "This verification link will expire in 24 hours.\n\n"
              . "If you did not create an account, you can safely ignore this email.\n\n"
              . "Regards,\n" . APP_NAME . " Security Team";

        return self::send($email, $name, $subject, $body);
    }

    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail(string $email, string $name, string $rawToken): bool {
        $resetUrl = url('reset-password.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email));
        $subject = "Password Reset Request — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "We received a request to reset your password for " . APP_NAME . ".\n\n"
              . "Click the link below to set a new password:\n"
              . $resetUrl . "\n\n"
              . "This password reset link will expire in 1 hour.\n\n"
              . "If you did not request a password reset, no action is needed.\n\n"
              . "Regards,\n" . APP_NAME . " Security Team";

        return self::send($email, $name, $subject, $body);
    }

    /**
     * Send staff invitation email
     */
    public static function sendStaffInvitationEmail(string $email, string $name, string $shopName, string $rawToken): bool {
        $inviteUrl = url('accept-invitation.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email));
        $subject = "Staff Invitation to " . $shopName . " — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "You have been invited to join the staff team at " . htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') . " on " . APP_NAME . ".\n\n"
              . "Please accept your invitation and activate your account by clicking the link below:\n"
              . $inviteUrl . "\n\n"
              . "This invitation link will expire in 48 hours.\n\n"
              . "Regards,\n" . APP_NAME . " Team";

        return self::send($email, $name, $subject, $body);
    }

    /**
     * Base email dispatch handler
     */
    public static function send(string $toEmail, string $toName, string $subject, string $body): bool {
        $host = app_env('SMTP_HOST', '');
        $port = (int)app_env('SMTP_PORT', 587);
        $user = app_env('SMTP_USERNAME', '');
        $pass = app_env('SMTP_PASSWORD', '');
        $fromEmail = app_env('MAIL_FROM_ADDRESS', 'noreply@apkashow.com');
        $fromName = app_env('MAIL_FROM_NAME', APP_NAME);

        self::$lastError = null;
        self::$lastStatus = null;
        self::$lastServerResponse = null;

        $smtpSent = false;
        if ($host !== '') {
            $smtpSent = self::sendViaSmtp($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $toName, $subject, $body);
        }

        // Security Invariant: Strictly redact 6-digit OTP codes and passwords from logs
        $logDir = YBT_ROOT . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $safeSubject = preg_replace('/\b\d{6}\b/', '******', $subject);
        $safeBody = preg_replace('/\b\d{6}\b/', '******', $body);
        $safeBody = preg_replace('/(password[:=]\s*)[^\s]+/i', '$1[REDACTED]', $safeBody);

        $statusLabel = 'LOCAL_LOG_ONLY';
        if ($host !== '') {
            $statusLabel = $smtpSent
                ? ('SMTP_DELIVERED [250 OK - ' . (self::$lastServerResponse ?? 'queued') . ']')
                : ('SMTP_FAILED [' . (self::$lastError ?? 'unknown error') . ']');
        }

        $logEntry = sprintf(
            "[%s] DISPATCHED TO: %s <%s> | SUBJECT: %s | STATUS: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $toName,
            self::maskEmail($toEmail),
            $safeSubject,
            $statusLabel,
            str_repeat('-', 60),
            $safeBody
        );

        file_put_contents($logDir . '/mail.log', $logEntry, FILE_APPEND | LOCK_EX);

        if ($host !== '') {
            return $smtpSent;
        }

        return true;
    }

    /**
     * Read complete (potentially multiline) SMTP response according to RFC 5321
     */
    private static function readSmtpResponse($socket): string {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // In RFC 5321 multiline responses have '-' after the 3-digit code (e.g. "250-SIZE").
            // The final line has a space ' ' after the code (e.g. "250 OK") or ends cleanly.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            if (strlen($line) <= 4 && (str_ends_with($line, "\r\n") || str_ends_with($line, "\n"))) {
                break;
            }
        }
        return $response;
    }

    /**
     * RFC 5321 compliant direct socket SMTP client
     */
    private static function sendViaSmtp(string $host, int $port, string $user, string $pass, string $from, string $fromName, string $to, string $toName, string $subject, string $body): bool {
        try {
            $timeout = 15;
            $connectHost = ($port === 465 && !str_starts_with($host, 'ssl://')) ? 'ssl://' . $host : $host;
            $maskedTo = self::maskEmail($to);

            $socket = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
            if (!$socket) {
                self::$lastError = "Connection failed to {$connectHost}:{$port} ({$errno}: {$errstr})";
                error_log("SMTP Error: " . self::$lastError);
                return false;
            }
            stream_set_timeout($socket, $timeout);

            // 1. Service banner
            $banner = self::readSmtpResponse($socket);
            if (!str_starts_with(trim($banner), '220')) {
                self::$lastError = "Service banner rejected: " . trim($banner);
                error_log("SMTP Error: " . self::$lastError);
                fclose($socket);
                return false;
            }

            // 2. EHLO / HELO
            $hostname = gethostname() ?: 'localhost';
            fputs($socket, "EHLO {$hostname}\r\n");
            $ehloResp = self::readSmtpResponse($socket);
            if (!str_starts_with(trim($ehloResp), '250')) {
                fputs($socket, "HELO {$hostname}\r\n");
                $ehloResp = self::readSmtpResponse($socket);
                if (!str_starts_with(trim($ehloResp), '250')) {
                    self::$lastError = "EHLO/HELO rejected: " . trim($ehloResp);
                    error_log("SMTP Error: " . self::$lastError);
                    fclose($socket);
                    return false;
                }
            }

            // 3. AUTH LOGIN
            if ($user !== '' && $pass !== '') {
                fputs($socket, "AUTH LOGIN\r\n");
                $authChallenge = self::readSmtpResponse($socket);
                if (!str_starts_with(trim($authChallenge), '334')) {
                    self::$lastError = "AUTH LOGIN rejected: " . trim($authChallenge);
                    error_log("SMTP Error: " . self::$lastError);
                    fclose($socket);
                    return false;
                }

                fputs($socket, base64_encode($user) . "\r\n");
                $userChallenge = self::readSmtpResponse($socket);
                if (!str_starts_with(trim($userChallenge), '334')) {
                    self::$lastError = "Username rejected: " . trim($userChallenge);
                    error_log("SMTP Error: " . self::$lastError);
                    fclose($socket);
                    return false;
                }

                fputs($socket, base64_encode($pass) . "\r\n");
                $authResp = self::readSmtpResponse($socket);
                if (!str_starts_with(trim($authResp), '235')) {
                    self::$lastError = "Authentication rejected (code " . substr(trim($authResp), 0, 3) . ")";
                    error_log("SMTP Error: " . self::$lastError);
                    fclose($socket);
                    return false;
                }
            }

            // 4. MAIL FROM
            fputs($socket, "MAIL FROM:<{$from}>\r\n");
            $fromResp = self::readSmtpResponse($socket);
            if (!str_starts_with(trim($fromResp), '250')) {
                self::$lastError = "MAIL FROM rejected: " . trim($fromResp);
                error_log("SMTP Error: " . self::$lastError);
                fclose($socket);
                return false;
            }

            // 5. RCPT TO
            fputs($socket, "RCPT TO:<{$to}>\r\n");
            $rcptResp = self::readSmtpResponse($socket);
            if (!str_starts_with(trim($rcptResp), '250') && !str_starts_with(trim($rcptResp), '251')) {
                self::$lastError = "RCPT TO rejected for {$maskedTo}: " . trim($rcptResp);
                error_log("SMTP Error: " . self::$lastError);
                fclose($socket);
                return false;
            }

            // 6. DATA
            fputs($socket, "DATA\r\n");
            $dataResp = self::readSmtpResponse($socket);
            if (!str_starts_with(trim($dataResp), '354')) {
                self::$lastError = "DATA rejected: " . trim($dataResp);
                error_log("SMTP Error: " . self::$lastError);
                fclose($socket);
                return false;
            }

            // 7. Message payload & RFC 5321 dot-stuffing
            $date = date('r');
            $msgId = '<' . md5(uniqid((string)mt_rand(), true)) . '@' . $hostname . '>';
            $toHeader = (!empty($toName)) ? "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>\r\n" : "To: <{$to}>\r\n";
            $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
                     . $toHeader
                     . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                     . "Date: {$date}\r\n"
                     . "Message-ID: {$msgId}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n"
                     . "Content-Transfer-Encoding: 8bit\r\n\r\n";

            $dotStuffed = preg_replace('/^\./m', '..', $body);
            fputs($socket, $headers . $dotStuffed . "\r\n.\r\n");
            $finalResp = self::readSmtpResponse($socket);

            fputs($socket, "QUIT\r\n");
            self::readSmtpResponse($socket);
            fclose($socket);

            if (!str_starts_with(trim($finalResp), '250')) {
                self::$lastError = "Message rejected: " . trim($finalResp);
                error_log("SMTP Error: " . self::$lastError);
                return false;
            }

            self::$lastServerResponse = trim($finalResp);
            self::$lastStatus = "Accepted for {$maskedTo}: " . trim($finalResp);
            error_log("SMTP Success: " . self::$lastStatus);
            return true;

        } catch (Throwable $e) {
            self::$lastError = "Exception: " . $e->getMessage();
            error_log("SMTP Exception: " . self::$lastError);
            return false;
        }
    }
}
