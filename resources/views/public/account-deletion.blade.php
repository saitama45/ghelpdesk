<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Delete Your Account — The Coffee Bean &amp; Tea Leaf Rewards</title>
    <meta name="description" content="How to request deletion of your The Coffee Bean &amp; Tea Leaf Rewards account and associated data.">
    <style>
        :root {
            --espresso: #3b2416;
            --coffee: #5b3a22;
            --amber: #b06a1f;
            --cream: #fbf7f1;
            --line: #e6dbcd;
            --muted: #6b5a4a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--cream);
            color: var(--espresso);
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap { max-width: 820px; margin: 0 auto; padding: 0 20px 72px; }
        header.hero {
            background: var(--espresso);
            color: #fff;
            padding: 40px 20px 34px;
        }
        header.hero .wrap { padding-bottom: 0; }
        .badge {
            display: inline-block;
            font-size: 12px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #f0d7b8;
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 999px;
            padding: 4px 12px;
            margin-bottom: 14px;
        }
        h1 { font-size: 30px; line-height: 1.25; margin: 0 0 10px; }
        .hero p { margin: 0; color: #e8dccd; }
        h2 {
            font-size: 20px;
            margin: 38px 0 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--line);
        }
        h3 { font-size: 16px; margin: 22px 0 6px; }
        ol.steps { padding-left: 0; list-style: none; counter-reset: step; margin: 0; }
        ol.steps > li {
            counter-increment: step;
            position: relative;
            padding: 0 0 18px 46px;
            border-left: 2px solid var(--line);
            margin-left: 15px;
        }
        ol.steps > li:last-child { border-left-color: transparent; padding-bottom: 0; }
        ol.steps > li::before {
            content: counter(step);
            position: absolute;
            left: -16px;
            top: -2px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--amber);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px 22px;
            margin: 18px 0;
        }
        .card.accent { border-left: 4px solid var(--amber); }
        .mail {
            font-size: 19px;
            font-weight: 700;
            color: var(--coffee);
            word-break: break-all;
        }
        .mail a { color: var(--coffee); }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { background: #f3ece2; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
        td:first-child { width: 46%; }
        ul.plain { padding-left: 20px; margin: 8px 0; }
        ul.plain li { margin-bottom: 6px; }
        code {
            background: #f3ece2;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 14px;
        }
        footer {
            margin-top: 44px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            font-size: 14px;
            color: var(--muted);
        }
        @media (max-width: 600px) {
            h1 { font-size: 24px; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            td { border: none; padding: 4px 0; width: auto; }
            tr { border-bottom: 1px solid var(--line); padding: 10px 0; }
            td:first-child { font-weight: 700; width: auto; }
        }
    </style>
</head>
<body>
    <header class="hero">
        <div class="wrap">
            <span class="badge">Account &amp; Data Deletion</span>
            <h1>Delete your account and associated data</h1>
            <p>App: <strong>The Coffee Bean &amp; Tea Leaf Rewards</strong> &middot; Developer: <strong>{{ $developer }}</strong></p>
        </div>
    </header>

    <div class="wrap">
        <div class="card accent">
            <p style="margin:0 0 6px"><strong>You can ask us to delete your account and all data associated with it at any time.</strong></p>
            <p style="margin:0">Send a deletion request to the address below. There is no charge, and you do not need to give a reason.</p>
        </div>

        <h2>How to request deletion</h2>
        <ol class="steps">
            <li>
                <h3>Open your email app</h3>
                <p style="margin:0">Use the same email address you registered with in the app &mdash; this is how we confirm the request is really yours.</p>
            </li>
            <li>
                <h3>Address it to our support mailbox</h3>
                <p class="mail" style="margin:4px 0"><a href="mailto:{{ $supportEmail }}?subject=Account%20Deletion%20Request">{{ $supportEmail }}</a></p>
            </li>
            <li>
                <h3>Use this exact subject line</h3>
                <p style="margin:0"><code>Account Deletion Request</code></p>
            </li>
            <li>
                <h3>Include these details in the message</h3>
                <ul class="plain">
                    <li>Your full name as registered in the app</li>
                    <li>The email address registered to the account</li>
                    <li>The mobile number registered to the account, if you provided one</li>
                    <li>State clearly: <em>&ldquo;Please delete my account and all associated data.&rdquo;</em></li>
                </ul>
            </li>
            <li>
                <h3>Wait for confirmation</h3>
                <p style="margin:0">Your request is logged as a support ticket and acknowledged within <strong>3 business days</strong>. We may reply once to verify your identity. Deletion is completed within <strong>30 days</strong> of verification, and we email you when it is done.</p>
            </li>
        </ol>

        <div class="card">
            <p style="margin:0"><strong>Important:</strong> deleting your account permanently forfeits any unredeemed stamps and any reward on a partially filled card. This cannot be undone or restored, and a new account starts from zero.</p>
        </div>

        <h2>What is deleted, and what is kept</h2>

        <h3>Deleted permanently</h3>
        <table>
            <thead><tr><th>Data</th><th>Details</th></tr></thead>
            <tbody>
                <tr><td>Account profile</td><td>Your name, email address, mobile number and profile photo.</td></tr>
                <tr><td>Login credentials</td><td>Your password (stored only as a one-way hash) and your member record.</td></tr>
                <tr><td>Device sessions</td><td>All app access tokens for every device you signed in on.</td></tr>
                <tr><td>Verification codes</td><td>One-time email codes issued for sign-in verification.</td></tr>
                <tr><td>Loyalty stamp cards</td><td>Your stamp cards, their progress, and the campaigns you joined.</td></tr>
                <tr><td>Stamp and reward history</td><td>Each stamp you earned and each reward you redeemed, as records identifying you.</td></tr>
                <tr><td>Member QR code</td><td>The scannable code that links in-store scans to you.</td></tr>
                <tr><td>Data on your phone</td><td>The app keeps a copy of your cards and ledger on the device itself. Uninstalling the app removes it. You can also clear it from Android Settings &rarr; Apps &rarr; The Coffee Bean &amp; Tea Leaf Rewards &rarr; Storage &rarr; Clear data.</td></tr>
            </tbody>
        </table>

        <h3>Kept, and for how long</h3>
        <table>
            <thead><tr><th>Data</th><th>Retention period</th></tr></thead>
            <tbody>
                <tr>
                    <td>Anonymised transaction records &mdash; that a stamp was issued or a reward was redeemed at a store, with your identity removed so the record can no longer be traced back to you</td>
                    <td>Retained for financial, tax and audit purposes for the period required by Philippine law. These records hold no personal identifiers once deletion is complete.</td>
                </tr>
                <tr>
                    <td>Your deletion request itself and our correspondence about it</td>
                    <td>Kept for up to <strong>12 months</strong> as proof that the request was received and honoured, then deleted.</td>
                </tr>
                <tr>
                    <td>Records subject to an active dispute, fraud investigation, or a legal or regulatory obligation</td>
                    <td>Kept only until that matter is resolved, then deleted.</td>
                </tr>
                <tr>
                    <td>Encrypted system backups</td>
                    <td>Backups rotate on a rolling cycle, and any residual copy is overwritten within <strong>90 days</strong> of deletion. Backups are never used to restore a deleted account.</td>
                </tr>
            </tbody>
        </table>

        <h2>Questions</h2>
        <p>For anything about this process, or to follow up on a request you already sent, email <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>

        <footer>
            <p style="margin:0 0 4px">The Coffee Bean &amp; Tea Leaf Rewards is operated by {{ $developer }}</p>
            <p style="margin:0">Last updated {{ $updatedAt }}.</p>
        </footer>
    </div>
</body>
</html>
