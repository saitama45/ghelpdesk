<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Satisfaction Survey</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.6; }
        .wrapper { max-width: 640px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2563eb; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; }
        .content { padding: 32px 24px; text-align: center; }
        .greeting { font-size: 18px; font-weight: 600; margin-bottom: 16px; color: #111827; }
        .message { font-size: 16px; color: #4b5563; margin-bottom: 24px; }
        .action-button { display: inline-block; background-color: #1e40af; color: #ffffff !important; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 18px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }

        .guide-intro { font-size: 14px; color: #4b5563; margin: 8px 0 12px; text-align: left; }
        .guide-title { font-size: 16px; font-weight: 700; color: #111827; margin: 24px 0 4px; text-align: left; }
        .guide-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; border: 1px solid #e5e7eb; }
        .guide-table th { background-color: #1d4ed8; color: #ffffff; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 12px; text-align: left; border: 1px solid #1d4ed8; }
        .guide-table td { padding: 12px; font-size: 13px; color: #374151; border: 1px solid #e5e7eb; vertical-align: top; text-align: left; }
        .guide-rating { white-space: nowrap; font-weight: 700; font-size: 13px; color: #111827; }
        .guide-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
        .guide-dot-green { background-color: #22c55e; }
        .guide-dot-blue { background-color: #3b82f6; }
        .guide-dot-amber { background-color: #eab308; }
        .guide-dot-red { background-color: #ef4444; }
        .guide-indicators { margin: 0; padding-left: 18px; }
        .guide-indicators li { margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>TAS Support</h1>
        </div>
        <div class="content">
            <p class="greeting">Hi {{ $recipientName }},</p>
            <p class="message">
                Thank you for your time. Your feedback matters! <br>
                Please take a moment to rate your recent support experience for ticket <strong>{{ $ticket->ticket_key }}</strong>.<br>
                This survey should take under a minute to complete.
            </p>

            <p class="guide-title">Feedback Guide</p>
            <p class="guide-intro">Use this guide to help you choose a rating:</p>
            <table class="guide-table">
                <thead>
                    <tr>
                        <th>Rating</th>
                        <th>Guide Questions</th>
                        <th>Expected Indicators</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="guide-rating">
                            <span class="guide-dot guide-dot-green"></span>EXCELLENT (4)
                        </td>
                        <td>Did the Tech Engineer exceed expectations?</td>
                        <td>
                            <ul class="guide-indicators">
                                <li>Concern resolved accurately and promptly</li>
                                <li>Clear and proactive communication</li>
                                <li>Showed ownership and initiative</li>
                                <li>Provided updates without follow-up</li>
                                <li>Professional and helpful attitude</li>
                                <li>Minimized store/business impact</li>
                            </ul>
                        </td>
                    </tr>
                    <tr>
                        <td class="guide-rating">
                            <span class="guide-dot guide-dot-blue"></span>GOOD (3)
                        </td>
                        <td>Did the Tech Engineer meet expectations?</td>
                        <td>
                            <ul class="guide-indicators">
                                <li>Concern resolved properly</li>
                                <li>Communication was clear</li>
                                <li>Response time acceptable</li>
                                <li>Followed standard process</li>
                                <li>Professional handling of concern</li>
                            </ul>
                        </td>
                    </tr>
                    <tr>
                        <td class="guide-rating">
                            <span class="guide-dot guide-dot-amber"></span>FAIR (2)
                        </td>
                        <td>Were there noticeable gaps in handling the concern?</td>
                        <td>
                            <ul class="guide-indicators">
                                <li>Delayed response or updates</li>
                                <li>Concern required repeated follow-ups</li>
                                <li>Partial resolution or temporary fix only</li>
                                <li>Communication lacked clarity</li>
                                <li>Some impact to operations experienced</li>
                            </ul>
                        </td>
                    </tr>
                    <tr>
                        <td class="guide-rating">
                            <span class="guide-dot guide-dot-red"></span>POOR (1)
                        </td>
                        <td>Was the support experience unsatisfactory?</td>
                        <td>
                            <ul class="guide-indicators">
                                <li>Concern unresolved or incorrectly handled</li>
                                <li>Very delayed response</li>
                                <li>Lack of coordination or updates</li>
                                <li>Unprofessional handling</li>
                                <li>Significant operational disruption experienced</li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>

            <a href="{{ route('public.survey', $ticket->survey_token) }}" class="action-button">
                Take the survey
            </a>

            <p style="margin-top: 32px; color: #9ca3af; font-size: 14px;">
                Regards,<br>
                TAS Support
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
