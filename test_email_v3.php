<?php

function stripQuotedText($body)
{
    // 1. Normalize line endings and whitespace
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace(["\xe2\x80\xaf", "\xc2\xa0", "\t"], " ", $body);

    // 2. Save original for fallback
    $originalBody = $body;

    // 3. Identify the cutoff point using aggressive markers
    $cutoff = strlen($body);

    $markers = [
        '/(?:\n|^)\s*On\s+.*?(?:wrote|sent):?/is',
        '/(?:\n|^)\s*From:\s+.*?\n(?:Sent|To):/is',
        '/(?:\n|^)\s*---\s*Original Message\s*---/i',
        '/(?:\n|^)\s*-----Original Message-----/i',
        '/(?:\n|^)\s*________________________________/i',
        '/(?:\n|^)\s*Sent from my (?:iPhone|Android|Samsung|iPad)/i',
        '/\n\s*>\s*/', // Any line starting with > after a newline
    ];

    foreach ($markers as $marker) {
        if (preg_match($marker, $body, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            if ($pos < $cutoff) {
                $cutoff = $pos;
            }
        }
    }

    $body = substr($body, 0, $cutoff);

    // 4. Line-by-line cleanup (catch signatures and loose quotes)
    $lines = explode("\n", $body);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Standard signature separator
        if ($trimmed === '--' || $trimmed === '-- ') break;
        // If we missed a quote block start
        if (str_starts_with($trimmed, '>')) break;
        
        $cleanLines[] = $line;
    }

    $result = trim(implode("\n", $cleanLines));

    // 5. Fallback: If we stripped EVERYTHING, maybe it was a forward or a very short reply.
    // In that case, return the original but at least strip the deepest quotes.
    if (empty($result) && !empty(trim($originalBody))) {
        return trim($originalBody);
    }

    return $result;
}

$body = "how long is this going to take?

On Tue, Mar 10, 2026 at 9:28 PM Gener Magbanua <
gen.magbanua@tablegroup.com.ph> wrote:

> how are you going to resolve this?
";

echo "--- TEST 1 (User's Case) ---\n";
echo "[" . stripQuotedText($body) . "]\n\n";

$body2 = "Fixed it.
--
TAS Support";
echo "--- TEST 2 (Signature) ---\n";
echo "[" . stripQuotedText($body2) . "]\n\n";

$body3 = "Forwarding this.

---------- Forwarded message ---------
From: Someone <someone@example.com>
Date: Mon, Mar 9, 2026 at 10:00 AM
Subject: Help
To: Support <support@example.com>

Please help.";
echo "--- TEST 3 (Forward - should ideally keep most) ---\n";
echo "[" . stripQuotedText($body3) . "]\n\n";
