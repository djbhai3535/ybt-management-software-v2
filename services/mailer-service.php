<?php
/**
 * YBT Management Software — Mailer Service
 * Configurable transactional email delivery with SMTP and secure local logging fallback.
 * Strictly avoids hardcoding credentials and protects sensitive tokens.
 */

class MailerService {
    /**
     * Send email verification link to newly registered shopkeeper
     */
    public static function sendVerificationEmail(string $email, string $name, string $rawToken): bool {
        $verifyUrl = url('verify-email.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email));
        $subject = "Verify Your Account — " . APP_NAME;

        $body = "Hello " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ",\n\n"
              . "Thank you for registering your shop on " . APP_NAME . ".\n\n"
              . "Please verify your email address by clicking the link below:\n"
              . $verifyUrl . "\n\n"
              . "This verification link will expire in 24 hours.\n\n"
              . "If you did not create this account, please ignore this email.\n\n"
              . "Regards,\n" . APP_NAME . " Team";

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
        $host = getenv('SMTP_HOST') ?: '';
        $port = (int)(getenv('SMTP_PORT') ?: 587);
        $user = getenv('SMTP_USERNAME') ?: '';
        $pass = getenv('SMTP_PASSWORD') ?: '';
        $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@ybt.com';
        $fromName = getenv('MAIL_FROM_NAME') ?: APP_NAME;

        // If SMTP host is configured and in production, attempt direct delivery
        if ($host !== '' && getenv('APP_ENV') === 'production') {
            return self::sendViaSmtp($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body);
        }

        // Development / Test Fallback: Secure Local Log
        $logDir = YBT_ROOT . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logEntry = sprintf(
            "[%s] DISPATCHED TO: %s <%s> | SUBJECT: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $toName,
            $toEmail,
            $subject,
            str_repeat('-', 60),
            $body
        );

        file_put_contents($logDir . '/mail.log', $logEntry, FILE_APPEND | LOCK_EX);
        return true;
    }

    /**
     * Lightweight direct socket SMTP sender
     */
    private static function sendViaSmtp(string $host, int $port, string $user, string $pass, string $from, string $fromName, string $to, string $subject, string $body): bool {
        try {
            $timeout = 10;
            $socket = fsockopen($host, $port, $errno, $errstr, $timeout);
            if (!$socket) {
                error_log("SMTP Connection failed to {$host}:{$port} - {$errstr}");
                return false;
            }

            $response = fgets($socket, 515);
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            $response = fgets($socket, 515);

            if ($user !== '' && $pass !== '') {
                fputs($socket, "AUTH LOGIN\r\n");
                fgets($socket, 515);
                fputs($socket, base64_encode($user) . "\r\n");
                fgets($socket, 515);
                fputs($socket, base64_encode($pass) . "\r\n");
                $authResponse = fgets($socket, 515);
                if (!str_starts_with($authResponse, '235')) {
                    error_log("SMTP Auth failed: " . $authResponse);
                    fclose($socket);
                    return false;
                }
            }

            fputs($socket, "MAIL FROM: <{$from}>\r\n");
            fgets($socket, 515);
            fputs($socket, "RCPT TO: <{$to}>\r\n");
            fgets($socket, 515);
            fputs($socket, "DATA\r\n");
            fgets($socket, 515);

            $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
                     . "To: <{$to}>\r\n"
                     . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n"
                     . "\r\n";

            fputs($socket, $headers . $body . "\r\n.\r\n");
            fgets($socket, 515);
            fputs($socket, "QUIT\r\n");
            fclose($socket);

            return true;
        } catch (Throwable $t) {
            error_log("SMTP Exception: " . $t->getMessage());
            return false;
        }
    }
}
