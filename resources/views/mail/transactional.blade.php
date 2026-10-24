<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f5f9;font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif;color:#1b2433;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#13294b;padding:16px 28px;">
                        <a href="{{ $frontendUrl }}" style="text-decoration:none;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align:middle;padding-right:12px;">
                                        <img src="{{ $logoUrl }}" width="84" height="41" alt="Pilotwardrobe" style="display:block;border:0;outline:none;width:84px;height:41px;">
                                    </td>
                                    <td style="vertical-align:middle;color:#ffffff;font-weight:700;font-size:18px;letter-spacing:.3px;">Pilotwardrobe</td>
                                </tr>
                            </table>
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#13294b;">{{ $heading }}</h1>
                        @foreach ($lines as $line)
                            @if (preg_match('/^\d{4,8}$/', $line))
                                <p style="margin:0 0 16px;font-size:32px;font-weight:700;letter-spacing:8px;color:#13294b;">{{ $line }}</p>
                            @else
                                <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">{{ $line }}</p>
                            @endif
                        @endforeach
                        @if ($cta)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;">
                                <tr>
                                    <td style="background:#13294b;border-radius:8px;">
                                        <a href="{{ $cta['url'] }}" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">{{ $cta['label'] }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:12px 0 0;font-size:12px;color:#6b7686;word-break:break-all;">Or open: {{ $cta['url'] }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;background:#f7f8fb;font-size:12px;line-height:1.5;color:#6b7686;">
                        Pilotwardrobe never asks for your password, card details or verification codes by email or phone.
                        Payments are held in escrow until your item lands.
                        @if ($unsubscribeUrl)
                            <br><a href="{{ $unsubscribeUrl }}" style="color:#6b7686;">Manage email preferences</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
