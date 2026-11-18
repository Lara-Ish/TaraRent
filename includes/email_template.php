<?php
/**
 * TaraRent - Responsive HTML Email Template Builder
 * Generates mobile-responsive, beautifully styled emails with TaraRent 60-30-10 branding:
 * - 60% Dominant: Light Beige/Tan (#F5F0E6 / #FAF5EC)
 * - 30% Secondary: Medium Brown / Clay (#B89B72 / #9A7B4F)
 * - 10% Accent: Dark Roast Brown (#4A3728 / #3E2723)
 */

declare(strict_types=1);

class EmailTemplate
{
    /**
     * Render a standard branded TaraRent HTML email
     */
    public static function render(
        string $heading,
        string $badgeText,
        string $recipientName,
        string $introMessage,
        array $details = [],
        ?string $buttonText = null,
        ?string $buttonUrl = null,
        ?string $secondaryNotice = null
    ): string {
        $detailRowsHtml = '';
        if (!empty($details)) {
            $rows = '';
            foreach ($details as $label => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
                $safeVal = htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
                $rows .= "
                    <tr>
                        <td style=\"padding: 11px 16px; font-size: 13px; font-weight: 600; color: #5C4A3A; background-color: #FAF5EC; border-bottom: 1px solid #E8D8B0; width: 35%;\">{$safeLabel}</td>
                        <td style=\"padding: 11px 16px; font-size: 13.5px; font-weight: 700; color: #2B1A06; background-color: #ffffff; border-bottom: 1px solid #E8D8B0;\">{$safeVal}</td>
                    </tr>
                ";
            }
            if ($rows !== '') {
                $detailRowsHtml = "
                    <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"border-collapse: separate; border-spacing: 0; border: 1.5px solid #B89B72; border-radius: 12px; overflow: hidden; margin: 20px 0;\">
                        {$rows}
                    </table>
                ";
            }
        }

        $buttonHtml = '';
        if (!empty($buttonText) && !empty($buttonUrl)) {
            $safeBtnText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
            $safeBtnUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
            $buttonHtml = "
                <div style=\"text-align: center; margin: 28px 0 20px 0;\">
                    <a href=\"{$safeBtnUrl}\" target=\"_blank\" style=\"display: inline-block; padding: 13px 32px; background-color: #4A3728; color: #ffffff; text-decoration: none; font-size: 14.5px; font-weight: 700; border-radius: 12px; box-shadow: 0 4px 14px rgba(74, 55, 40, 0.35); text-align: center;\">
                        {$safeBtnText}
                    </a>
                </div>
            ";
        }

        $secondaryNoticeHtml = '';
        if (!empty($secondaryNotice)) {
            $safeNotice = htmlspecialchars($secondaryNotice, ENT_QUOTES, 'UTF-8');
            $secondaryNoticeHtml = "
                <p style=\"font-size: 12px; color: #7A6245; line-height: 1.5; margin-top: 18px; text-align: center;\">
                    {$safeNotice}
                </p>
            ";
        }

        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeBadge = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $currentDate = date('F j, Y • g:i A');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeHeading}</title>
    <!--[if mso]>
    <style type="text/css">
        table {border-collapse: collapse; border-spacing: 0; margin: 0;}
        div, td {padding: 0;}
        div {margin: 0 !important;}
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #F5F0E6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F5F0E6; padding: 32px 12px;">
        <tr>
            <td align="center">
                <!-- Main Email Container (60-30-10 palette) -->
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px rgba(74, 55, 40, 0.12); border: 2px solid #B89B72;">
                    
                    <!-- Header Banner (30% Secondary Medium Brown) -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #B89B72 0%, #9A7B4F 100%); padding: 32px 36px; text-align: center;">
                            <div style="display: inline-block; background: rgba(74, 55, 40, 0.25); border-radius: 999px; padding: 5px 14px; margin-bottom: 12px; border: 1px solid rgba(255, 255, 255, 0.35);">
                                <span style="color: #ffffff; font-size: 11px; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase;">
                                    {$safeBadge}
                                </span>
                            </div>
                            <h1 style="color: #ffffff; font-size: 22px; font-weight: 800; margin: 0; letter-spacing: -0.4px; line-height: 1.3;">
                                {$safeHeading}
                            </h1>
                            <p style="color: rgba(255, 255, 255, 0.9); font-size: 12.5px; margin: 6px 0 0 0; font-weight: 500;">
                                TaraRent Apartment Management System
                            </p>
                        </td>
                    </tr>

                    <!-- Body Content (10% Accent Dark Brown text) -->
                    <tr>
                        <td style="padding: 34px 36px 28px 36px; background-color: #ffffff;">
                            <p style="font-size: 15px; font-weight: 700; color: #3E2723; margin: 0 0 12px 0;">
                                Hello {$safeName},
                            </p>
                            <div style="font-size: 14.5px; color: #4A3728; line-height: 1.6; margin: 0 0 18px 0;">
                                {$introMessage}
                            </div>

                            {$detailRowsHtml}

                            {$buttonHtml}

                            {$secondaryNoticeHtml}
                        </td>
                    </tr>

                    <!-- Footer (60% Light Beige background with Medium/Dark Brown text) -->
                    <tr>
                        <td style="background-color: #FAF5EC; padding: 24px 36px; text-align: center; border-top: 1px solid #E8D8B0;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 13px; font-weight: 800; color: #4A3728;">TaraRent</span>
                                <span style="font-size: 12px; color: #7A6245;">• Apartment Community</span>
                            </div>
                            <p style="font-size: 11.5px; color: #7A6245; margin: 0 0 6px 0; line-height: 1.5;">
                                This is an automated notification sent on {$currentDate}.<br>
                                Please do not reply directly to this email.
                            </p>
                            <p style="font-size: 11px; color: #A89078; margin: 0;">
                                &copy; 2026 TaraRent. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Render a 6-digit verification code email
     */
    public static function renderVerificationCode(
        string $recipientName,
        string $code,
        int $expireMinutes = 5
    ): string {
        $formattedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $codeBoxHtml = "
            <div style=\"text-align: center; margin: 24px 0;\">
                <div style=\"font-size: 13px; font-weight: 700; color: #5C4A3A; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;\">Verification Code:</div>
                <div style=\"display: inline-block; background: #FAF5EC; border: 2px dashed #B89B72; border-radius: 16px; padding: 16px 36px; box-shadow: 0 4px 14px rgba(74, 55, 40, 0.1);\">
                    <span style=\"font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; letter-spacing: 10px; color: #4A3728;\">{$formattedCode}</span>
                </div>
                <p style=\"font-size: 13.5px; font-weight: 600; color: #b91c1c; margin-top: 14px;\">
                    This code is valid for {$expireMinutes} minutes.
                </p>
            </div>
        ";

        return self::render(
            'Password Reset Verification Code',
            'SECURITY VERIFICATION',
            $recipientName,
            "We received a request to reset your password. Use the verification code below to complete the process:" . $codeBoxHtml,
            [],
            null,
            null,
            "This code is valid for {$expireMinutes} minutes. If you did not request a password reset, please ignore this email.<br><br>Thank you!"
        );
    }
}
