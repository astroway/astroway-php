<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Dto\BirthData;
use Astroway\Dto\SynastryRequest;
use Astroway\Dto\TransitsRequest;
use Astroway\Dto\VedicDashaRequest;
use Astroway\Tests\Support\MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function testBirthDataValidatesDateFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BirthData(date: 'not-a-date', time: '14:30:00');
    }

    public function testBirthDataValidatesTimeFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BirthData(date: '1990-07-14', time: '14:30');
    }

    public function testBirthDataToArrayOmitsUnsetOptionalFields(): void
    {
        $b = new BirthData(date: '1990-07-14', time: '14:30:00', timezoneOffset: 3, latitude: 50.45, longitude: 30.52);
        $arr = $b->toArray();
        self::assertSame('1990-07-14', $arr['date']);
        self::assertEqualsWithDelta(3.0, $arr['timezoneOffset'], 0.0001);
        self::assertArrayNotHasKey('name', $arr);
        self::assertArrayNotHasKey('city', $arr);
        self::assertArrayNotHasKey('ayanamsaId', $arr);
    }

    public function testServiceMethodAcceptsBirthDataDto(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['ok' => true]])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $birth = new BirthData(date: '1990-07-14', time: '14:30:00', timezoneOffset: 3, latitude: 50.45, longitude: 30.52);
        $aw->chart()->compute($birth);
        $body = json_decode((string) $mock->requests()[0]->getBody(), true);
        self::assertSame('1990-07-14', $body['date']);
        self::assertSame('14:30:00', $body['time']);
        self::assertEqualsWithDelta(3, $body['timezoneOffset'], 0.0001);
        self::assertEqualsWithDelta(50.45, $body['latitude'], 0.0001);
        self::assertEqualsWithDelta(30.52, $body['longitude'], 0.0001);
        self::assertSame('P', $body['houseSystem']);
    }

    public function testServiceMethodStillAcceptsArray(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->chart()->compute(['date' => '1990-07-14', 'time' => '14:30:00']);
        $body = json_decode((string) $mock->requests()[0]->getBody(), true);
        self::assertSame(['date' => '1990-07-14', 'time' => '14:30:00'], $body);
    }

    public function testSynastryRequestSerializesNestedCharts(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $req = new SynastryRequest(
            chart1: new BirthData(date: '1990-07-14', time: '14:30:00', timezoneOffset: 3, latitude: 50.45, longitude: 30.52),
            chart2: new BirthData(date: '1992-03-22', time: '09:15:00', timezoneOffset: 2, latitude: 48.85, longitude: 2.35),
            orbFactor: 1.5,
        );
        $aw->synastry()->compute($req);
        $body = json_decode((string) $mock->requests()[0]->getBody(), true);
        self::assertEqualsWithDelta(1.5, $body['orbFactor'], 0.0001);
        self::assertEqualsWithDelta(3, $body['chart1']['timezoneOffset'], 0.0001);
        self::assertEqualsWithDelta(48.85, $body['chart2']['latitude'], 0.0001);
    }

    public function testTransitsRequestInlinesBirthFields(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->transits()->compute(new TransitsRequest(
            date: '1990-07-14',
            time: '14:30:00',
            timezoneOffset: 3,
            latitude: 50.45,
            longitude: 30.52,
            targetDate: '2027-01-01',
        ));
        $body = json_decode((string) $mock->requests()[0]->getBody(), true);
        self::assertSame('2027-01-01', $body['targetDate']);
        self::assertEqualsWithDelta(3, $body['timezoneOffset'], 0.0001);
    }

    public function testVedicDashaRequestAcceptsAyanamsaId(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->vedic()->dashasVimshottariMaha(new VedicDashaRequest(
            date: '1985-07-22',
            time: '06:45:00',
            timezoneOffset: 5.5,
            latitude: 19.07,
            longitude: 72.87,
            ayanamsaId: 1,
        ));
        $body = json_decode((string) $mock->requests()[0]->getBody(), true);
        self::assertEqualsWithDelta(1, $body['ayanamsaId'], 0.0001);
    }

    public function testReadonlyDtoCannotBeMutated(): void
    {
        $b = new BirthData(date: '1990-07-14', time: '14:30:00');
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional readonly violation
        $b->date = '2000-01-01';
    }
}
