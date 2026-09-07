<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $subject }}</title>
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
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
        }
        .content {
            padding: 20px 0;
            line-height: 1.6;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #6c757d;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin-top: 16px;
            font-size: 16px;
            color: #fff !important;
            background-color: #1FAE64;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <strong>Payhankey</strong>
        </div>
        <div class="content">
            <h4>{{ $subject }}</h4>
            <p>Dear {{ $user->name }},</p>

            {!! $content !!}

            <p><i>Payhankey Team</i></p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Payhankey. All rights reserved.
        </div>
    </div>
</body>
</html>
