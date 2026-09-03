<?php

namespace BHO\Tests;

use BHO_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;

/** The site is reset between tests, so nothing one leaves behind can pass the next. */
abstract class PluginTestCase extends TestCase
{
    protected function setUp(): void
    {
        BHO_Test_Site::reset();
    }

    /** Queues one 200 answer with this body. */
    protected function answer(array $body): void
    {
        BHO_Test_Site::$answers[] = [
            'response' => ['code' => 200],
            'body' => json_encode($body),
        ];
    }

    /** Queues a refusal, so the caller can see what a failing ladder does to the page. */
    protected function refuse(int $status = 503, string $body = ''): void
    {
        BHO_Test_Site::$answers[] = ['response' => ['code' => $status], 'body' => $body];
    }

    /** Queues an unreachable ladder — a transport failure rather than an HTTP status. */
    protected function unreachable(string $message = 'Connection timed out'): void
    {
        BHO_Test_Site::$answers[] = new WP_Error('http_request_failed', $message);
    }

    /** @return list<string> every URL the plugin asked for, in order */
    protected function asked(): array
    {
        return array_column(BHO_Test_Site::$requests, 'url');
    }
}
