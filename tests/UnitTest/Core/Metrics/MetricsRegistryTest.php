<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Metrics;

use App\Core\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

final class MetricsRegistryTest extends TestCase
{
    public function testHistogramSuffixesPrecedeLabels(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->observe('http_request_duration_seconds', ['route' => 'demo'], 0.01, [0.01]);

        $rendered = $metrics->render();

        self::assertStringContainsString('http_request_duration_seconds_bucket{route="demo",le="0.01"} 1', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_bucket{route="demo",le="+Inf"} 1', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_sum{route="demo"} 0.01', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_count{route="demo"} 1', $rendered);
        self::assertStringNotContainsString('http_request_duration_seconds{route="demo"}_bucket', $rendered);
    }
}
