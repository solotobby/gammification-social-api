<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payhankey - Security Alert: Password Changed</title>
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
        .alert-box {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 14px 18px;
            border-radius: 4px;
            margin: 20px 0;
            font-size: 14px;
            color: #991b1b;
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
            <h3>Security Alert: Password Updated 🔒</h3>
            <p>Dear {{ $user->name }},</p>
            <p>This email confirms that the password for your Payhankey account (<strong>{{ $user->email }}</strong>) was changed on <strong>{{ now()->toFormattedDateString() }} at {{ now()->format('H:i T') }}</strong>.</p>

            <div class="alert-box">
                <strong>Didn't make this change?</strong><br>
                If you did not initiate this password change, your account may have been compromised. Please contact Payhankey support immediately to secure your account.
            </div>

            <p>If you made this change yourself, no further action is required.</p>

            <p>Stay safe,<br><i>The Payhankey Security Team</i></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Payhankey. All rights reserved.
        </div>
    </div>
</body>
</html>
