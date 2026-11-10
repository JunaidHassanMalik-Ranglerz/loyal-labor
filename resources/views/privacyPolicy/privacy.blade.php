<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy{{ !empty($setting?->app_name) ? ' - ' . $setting->app_name : '' }}</title>
    @if (!empty($setting?->favicon))
        <link rel="icon" type="image/png" href="{{ asset($setting->favicon) }}">
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --loyal-primary: #FE7701;
            --loyal-primary-dark: #e56a01;
            --loyal-primary-light: #fff4eb;
        }

        * {
            font-family: "Heebo", sans-serif;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-size: 16px;
            color: #333;
            background: #fff;
        }

        .banner {
            background: linear-gradient(115deg, var(--loyal-primary) 0%, var(--loyal-primary-dark) 89%);
            padding: 60px 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #fff;
            margin-bottom: 20px;
            animation: slideFromTop 1s ease-out;
        }

        .banner img {
            max-height: 56px;
            margin-bottom: 16px;
            background: #fff;
            padding: 8px 14px;
            border-radius: 8px;
        }

        .banner h1 {
            margin: 0;
            font-family: "Nunito", sans-serif;
            font-size: 80px;
            color: #fff;
            text-shadow: 0 2px 6px rgba(0, 0, 0, .459);
            text-align: center;
        }

        .container {
            max-width: 960px;
            margin: 0 auto 48px;
            padding: 0 20px 40px;
            animation: slideFrombottom 1s ease-out;
            line-height: 1.7;
        }

        .container h1,
        .container h2,
        .container h3 {
            color: var(--loyal-primary);
            font-family: "Nunito", sans-serif;
        }

        .container a {
            color: var(--loyal-primary);
        }

        .container p:empty {
            display: none;
        }

        .empty-note {
            color: #666;
            text-align: center;
            padding: 40px 0;
        }

        @keyframes slideFromTop {
            from { transform: translateY(-100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes slideFrombottom {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 991.5px) and (min-width: 400px) {
            .banner h1 { font-size: 40px; }
            .banner { padding: 32px 16px; }
            .container { padding: 0 15px 32px; }
            body { font-size: 14px; }
        }

        @media (max-width: 399px) {
            .banner h1 { font-size: 30px; }
            .banner { padding: 30px 12px; }
            .container { padding: 0 7px 28px; }
            body { font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="banner">
        @if (!empty($setting?->logo))
            <img src="{{ asset($setting->logo) }}" alt="{{ $setting->app_name ?? 'Loyal Labor' }}">
        @endif
        <h1>Privacy Policy</h1>
    </div>

    <div class="container">
        @if (!empty($data?->description))
            {!! $data->description !!}
        @else
            <p class="empty-note">Privacy policy content is not available yet.</p>
        @endif
    </div>
</body>
</html>
