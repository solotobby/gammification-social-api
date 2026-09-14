<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payhankey - Account Verified</title>
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
        .success-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 16px 20px;
            text-align: center;
            margin: 20px 0;
        }
        .badge {
            display: inline-block;
            background-color: #1FAE64;
            color: white;
            font-size: 14px;
            font-weight: bold;
            padding: 6px 14px;
            border-radius: 20px;
            margin-bottom: 8px;
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
            <h3>Your Account is Verified! ✅</h3>
            <p>Dear {{ $user->name }},</p>
            <p>Congratulations! Your email address (<strong>{{ $user->email }}</strong>) has been successfully verified.</p>

            <div class="success-box">
                <div class="badge">Verified Member</div>
                <p style="margin: 4px 0 0 0; color: #166534; font-weight: 500;">
                    Your account is now fully active.
                </p>
            </div>

            <p>You can now log in, set up your profile, create posts, participate in discussions, join paid & free communities, and explore creator monetisation.</p>

            <p>Thank you for choosing Payhankey!<br><i>The Payhankey Team</i></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Payhankey. All rights reserved.
        </div>
    </div>
</body>
</html>
