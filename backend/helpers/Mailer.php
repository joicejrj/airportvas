<?php
// backend/helpers/Mailer.php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Lightweight mail sender.
 *
 * Uses PHPMailer if it's installed (vendor/autoload.php exposes
 * PHPMailer\PHPMailer\PHPMailer); falls back to PHP's mail() otherwise.
 *
 * The fallback works for local/dev. For production, install PHPMailer:
 *     composer require phpmailer/phpmailer
 * and set SMTP_* constants in config.php.
 *
 * All methods catch and log exceptions — a failed email never breaks
 * the HTTP request that triggered it.
 */
class Mailer
{
    /**
     * Send the "payment handover submitted" email to admin(s).
     *
     * @param array $handover  Row from cash_handovers
     * @param array $tl        TL user row (name, email)
     * @param array $orders    List of orders included [{order_number, daily_serial, amount, plate}]
     */
    public static function sendHandoverSubmitted(array $handover, array $tl, array $orders): bool
    {
        $to = array_map('trim', explode(',', HANDOVER_NOTIFY_EMAILS));

        $subject = sprintf(
            '[Payment Handover] %s — %s %s',
            $tl['name'],
            APP_CURRENCY,
            number_format((float)$handover['amount'], 2)
        );

        $publicLink = APP_URL . '/handover-confirm.html?token=' . $handover['confirm_token'];
        $adminLink  = APP_URL . '/admin/#handovers';

        $rows = '';
        foreach ($orders as $o) {
            // Order rows: serial + order number + amount. Vehicle plate
            // was deliberately removed — handover reports no longer carry
            // customer/vehicle details (post-rework, those don't apply).
            $rows .= sprintf(
                '<tr>
                    <td style="padding:6px 12px;border:1px solid #e5e7eb">#%d</td>
                    <td style="padding:6px 12px;border:1px solid #e5e7eb">%s</td>
                    <td style="padding:6px 12px;border:1px solid #e5e7eb;text-align:right">%s %s</td>
                 </tr>',
                (int)$o['daily_serial'],
                htmlspecialchars($o['order_number'] ?? '—'),
                APP_CURRENCY,
                number_format((float)$o['amount'], 2)
            );
        }

        $html = self::layout(
            'New Payment Handover',
            sprintf(
                '<p>Team Leader <strong>%s</strong> has submitted a payment handover.</p>
                 <table style="border-collapse:collapse;width:100%%;margin:16px 0">
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Date</td>
                        <td style="padding:6px 12px">%s</td></tr>
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Amount</td>
                        <td style="padding:6px 12px;font-size:20px;font-weight:700;color:#16a34a">%s %s</td></tr>
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Orders</td>
                        <td style="padding:6px 12px">%d</td></tr>
                 </table>
                 <h3 style="margin-top:24px">Orders included</h3>
                 <table style="border-collapse:collapse;width:100%%;font-size:13px">
                    <thead>
                        <tr style="background:#f3f4f6">
                            <th style="padding:6px 12px;border:1px solid #e5e7eb;text-align:left">Serial</th>
                            <th style="padding:6px 12px;border:1px solid #e5e7eb;text-align:left">Order #</th>
                            <th style="padding:6px 12px;border:1px solid #e5e7eb;text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                 </table>
                 <div style="margin:32px 0;text-align:center">
                    <a href="%s" style="display:inline-block;padding:12px 24px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;margin:4px">Confirm in Admin Panel</a>
                    <a href="%s" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;margin:4px">Confirm via Link</a>
                 </div>
                 <p style="color:#6b7280;font-size:12px">The public confirm link is valid for %d days and can be used only once.</p>',
                htmlspecialchars($tl['name']),
                htmlspecialchars($handover['handover_date']),
                APP_CURRENCY,
                number_format((float)$handover['amount'], 2),
                (int)$handover['order_count'],
                $rows,
                $adminLink,
                $publicLink,
                HANDOVER_TOKEN_TTL_DAYS
            )
        );

        return self::send($to, $subject, $html);
    }

    /**
     * Notify the TL when admin confirms their handover.
     */
    public static function sendHandoverConfirmed(array $handover, array $tl): bool
    {
        $subject = sprintf(
            '[Confirmed] Payment Handover %s %s',
            APP_CURRENCY,
            number_format((float)$handover['amount'], 2)
        );

        $html = self::layout(
            'Handover Confirmed',
            sprintf(
                '<p>Hi %s,</p>
                 <p>Your payment handover for <strong>%s</strong> has been confirmed.</p>
                 <table style="border-collapse:collapse;width:100%%;margin:16px 0">
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Amount</td>
                        <td style="padding:6px 12px">%s %s</td></tr>
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Orders</td>
                        <td style="padding:6px 12px">%d</td></tr>
                    <tr><td style="padding:6px 12px;background:#f9fafb;font-weight:600">Confirmed at</td>
                        <td style="padding:6px 12px">%s</td></tr>
                 </table>
                 <p style="color:#6b7280;font-size:13px">Thank you.</p>',
                htmlspecialchars($tl['name']),
                htmlspecialchars($handover['handover_date']),
                APP_CURRENCY,
                number_format((float)$handover['amount'], 2),
                (int)$handover['order_count'],
                htmlspecialchars($handover['confirmed_at'] ?? '—')
            )
        );

        return self::send([$tl['email']], $subject, $html);
    }

    // =================================================================
    // Internals
    // =================================================================
    private static function layout(string $heading, string $bodyHtml): string
    {
        return sprintf(
            '<!DOCTYPE html>
             <html><head><meta charset="utf-8"></head>
             <body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#111827">
                <table width="100%%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0">
                    <tr><td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
                            <tr><td style="background:#0ea5e9;padding:20px;color:#fff">
                                <div style="font-size:14px;opacity:.8">%s</div>
                                <div style="font-size:22px;font-weight:700">%s</div>
                            </td></tr>
                            <tr><td style="padding:24px">%s</td></tr>
                            <tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;background:#f9fafb;color:#6b7280;font-size:12px">
                                %s · UAE Airport Services
                            </td></tr>
                        </table>
                    </td></tr>
                </table>
             </body></html>',
            htmlspecialchars(APP_NAME),
            htmlspecialchars($heading),
            $bodyHtml,
            htmlspecialchars(APP_NAME)
        );
    }

    /**
     * @param string[] $to
     */
    private static function send(array $to, string $subject, string $html): bool
    {
        if (empty($to)) return false;

        try {
            // Prefer PHPMailer if available
            $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($vendorAutoload)
                && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {

                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

                if (SMTP_HOST) {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->Port       = SMTP_PORT;
                    if (SMTP_USER) {
                        $mail->SMTPAuth = true;
                        $mail->Username = SMTP_USER;
                        $mail->Password = SMTP_PASS;
                    }
                    if (SMTP_ENCRYPTION === 'tls') {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    } elseif (SMTP_ENCRYPTION === 'ssl') {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    }
                }

                $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
                foreach ($to as $addr) {
                    $mail->addAddress($addr);
                }
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html;
                $mail->AltBody = trim(strip_tags($html));
                $mail->CharSet = 'UTF-8';

                return $mail->send();
            }

            // Fallback: PHP's mail() — works on most Linux servers with sendmail
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_EMAIL . ">\r\n";
            $headers .= "Reply-To: " . MAIL_FROM_EMAIL . "\r\n";

            $allSent = true;
            foreach ($to as $addr) {
                if (!@mail($addr, $subject, $html, $headers)) {
                    $allSent = false;
                    error_log("Mailer: mail() failed for {$addr}");
                }
            }
            return $allSent;

        } catch (\Throwable $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }
}