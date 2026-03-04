<?php

declare(strict_types=1);

function estimate_bot_class(string $userAgent, array $server): string
{
    $ua = strtolower(trim($userAgent));

    if ($ua === '') {
        return 'unknown';
    }

    $botPattern = '/bot|crawl|spider|slurp|headless|preview|facebookexternalhit|monitor|pingdom|uptime|wget|curl|python-requests|httpclient|go-http-client/i';
    if (preg_match($botPattern, $ua)) {
        return 'bot';
    }

    $hasMozilla = strpos($ua, 'mozilla/') !== false;
    $hasLanguage = !empty($server['HTTP_ACCEPT_LANGUAGE']);

    if ($hasMozilla && $hasLanguage) {
        return 'human';
    }

    return 'unknown';
}
