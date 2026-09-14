<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to Payhankey!</title>
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
        .highlight-box {
            background-color: #f0fdf4;
            border-left: 4px solid #1FAE64;
            padding: 16px 20px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .highlight-box ul {
            margin: 8px 0 0 0;
            padding-left: 20px;
        }
        .highlight-box li {
            margin-bottom: 6px;
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
            <h3>Welcome to Payhankey, {{ $user->name }}! 🎉</h3>
            <p>Your email address has been successfully verified, and your account (<strong>@{{ $user->username }}</strong>) is now fully active.</p>

            <div class="highlight-box">
                <strong>What you can do on Payhankey:</strong>
                <ul>
                    <li>🚀 <strong>Share & Create:</strong> Post updates, videos, and images with the community.</li>
                    <li>💰 <strong>Earn & Monetize:</strong> Earn PayKoin and rewards for quality posts and engagement.</li>
                    <li>👥 <strong>Join Communities:</strong> Connect with like-minded creators and exclusive groups.</li>
                    <li>🎁 <strong>Send & Receive Gifts:</strong> Support fellow creators with interactive post gifts.</li>
                </ul>
            </div>

            <p>You can now log in, explore the feed, and start connecting with fellow members.</p>

            <p>Welcome aboard!<br><i>The Payhankey Team</i></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Payhankey. All rights reserved.
        </div>
    </div>
</body>
</html>
