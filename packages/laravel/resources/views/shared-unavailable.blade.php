<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $limited ? 'Please try again shortly' : ($sessionExpired ? 'Refresh your session' : 'Collection unavailable') }}</title>
    <style nonce="{{ $nonce }}">body{margin:0;padding:32px 20px;background:#fafafa;color:#202124;font:16px/1.5 system-ui,sans-serif}main{max-width:480px;margin:12vh auto;background:white;border:1px solid #e4e4e7;border-radius:16px;padding:28px}h1{font-size:24px;margin-top:0}p{color:#667085}a{color:#2551ba;text-underline-offset:3px}a:focus-visible{outline:3px solid #2551ba;outline-offset:3px}</style>
</head>
<body>
<main>
    @if ($limited)
        <h1>Please try again shortly</h1>
        <p>Too many requests were made. Wait a minute before trying again.</p>
        <a href="{{ $collectionUrl }}">Back to collection</a>
    @elseif ($sessionExpired)
        <h1>Refresh your session</h1>
        <p>Your session has expired. Open the collection again before submitting the form.</p>
        <a href="{{ $collectionUrl }}">Open collection</a>
    @else
        <h1>Collection unavailable</h1>
        <p>This link is no longer available, or you do not have access. Ask the person who shared it for a current link.</p>
    @endif
</main>
</body>
</html>
