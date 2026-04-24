@php
    /** @var string $message */
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Spendula — callback error</title>
    <style>
        body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; color: #1f2328; }
        h1 { font-size: 1.4rem; color: #cf222e; }
        .panel { background: #ffebe9; border: 1px solid #ff8182; border-radius: 6px; padding: 1rem 1.25rem; margin: 1rem 0; }
    </style>
</head>
<body>
    <h1>Couldn't complete the bank connection</h1>
    <div class="panel">{{ $message }}</div>
</body>
</html>
