<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sllhsmile\HyperfLog\Enum\HttpStatusClass;

final class HttpStatusClassTest extends TestCase
{
    /** @return array<string, array{int, ?HttpStatusClass}> */
    public static function statusCodes(): array
    {
        return [
            'below range' => [99, null],
            'first informational' => [100, HttpStatusClass::Informational],
            'last informational' => [199, HttpStatusClass::Informational],
            'first success' => [200, HttpStatusClass::Success],
            'last redirection' => [399, HttpStatusClass::Redirection],
            'first client error' => [400, HttpStatusClass::ClientError],
            'last client error' => [499, HttpStatusClass::ClientError],
            'first server error' => [500, HttpStatusClass::ServerError],
            'last server error' => [599, HttpStatusClass::ServerError],
            'above range' => [600, null],
        ];
    }

    #[DataProvider('statusCodes')]
    public function testOnlyStandardStatusCodesHaveAClass(int $statusCode, ?HttpStatusClass $expected): void
    {
        self::assertSame($expected, HttpStatusClass::tryFromStatusCode($statusCode));
    }
}
