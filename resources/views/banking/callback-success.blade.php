@php
    /** @var \App\Models\BankConnection $connection */
    /** @var array<int, \App\Models\BankAccount> $accounts */
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Spendula — bank connected</title>
    <style>
        body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; color: #1f2328; }
        h1 { font-size: 1.4rem; }
        .panel { background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; padding: 1rem 1.25rem; margin: 1rem 0; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid #e2e4e9; }
        th { color: #57606a; font-weight: 500; }
        code { background: #eaeef2; padding: 0.05rem 0.35rem; border-radius: 3px; font-size: 0.9em; }
        .muted { color: #57606a; }
    </style>
</head>
<body>
    <h1>Connected <code>{{ $connection->bank_slug }}</code></h1>

    <div class="panel">
        <div><strong>{{ count($accounts) }}</strong> account{{ count($accounts) === 1 ? '' : 's' }} discovered.</div>
        <div class="muted">Consent valid until {{ $connection->valid_until->toDateTimeString() }} UTC.</div>
    </div>

    @if (count($accounts) > 0)
        <table>
            <thead>
                <tr><th>Bank account id</th><th>Currency</th><th>IBAN</th><th>Seen</th></tr>
            </thead>
            <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <td><code>{{ $account->id }}</code></td>
                        <td>{{ $account->currency }}</td>
                        <td>{{ $account->iban ?? '—' }}</td>
                        <td class="muted">{{ $account->last_seen_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted">You can close this tab. Map accounts to YNAB next:
        <code>php artisan spendula:accounts:seed-mock --bank-account-id=&lt;id&gt; --ynab-account-id=&lt;id&gt;</code>.
    </p>
</body>
</html>
