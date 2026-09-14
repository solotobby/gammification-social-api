<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payhankey - Password Reset Code</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            color: #343a40;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 28px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.08);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .header strong {
            font-size: 24px;
            color: #1FAE64;
            letter-spacing: -0.5px;
        }
        .content {
            padding: 24px 0;
            line-height: 1.6;
        }
        .otp-box {
            background-color: #fef2f2;
            border: 2px dashed #ef4444;
            border-radius: 8px;
            padding: 16px 24px;
            text-align: center;
            margin: 20px 0;
        }
        .otp-code {
            font-size: 34px;
            font-weight: bold;
            letter-spacing: 8px;
            color: #dc2626;
            margin: 0;
        }
        .notice {
            font-size: 13px;
            color: #6c757d;
            margin-top: 14px;
        }
        .security-alert {
            background-color: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            margin-top: 20px;
            font-size: 13px;
            color: #92400e;
            border-radius: 4px;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <strong>Payhankey</strong>
        </div>
        <div class="content">
            <h3>Password Reset Request</h3>
            <p>Dear {{ $name ?? 'Member' }},</p>
            <p>We received a request to reset the password for your Payhankey account. Enter the 6-digit verification code below to proceed:</p>

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
            </div>

            <p class="notice">
                ⏰ This code will expire in <strong>15 minutes</strong>.
            </p>

            <div class="security-alert">
                <strong>Didn't request this?</strong> If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged.
            </div>

            <p style="margin-top: 24px;">Best regards,<br><i>The Payhankey Team</i></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Payhankey. All rights reserved.
        </div>
    </div>
</body>
</html>
