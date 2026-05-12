<?php

declare(strict_types=1);

namespace Astroway\Tests\Helpers;

use Astroway\Helpers\BirthDateTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BirthDateTimeTest extends TestCase
{
    public function testFromCoordinatesProducesWireShape(): void
    {
        $b = BirthDateTime::fromCoordinates(
            date: '1990-07-14',
            time: '14:30:00',
            latitude: 50.45,
            longitude: 30.52,
            timezoneOffset: 3,
        );
        $arr = $b->toArray();
        self::assertSame('1990-07-14', $arr['date']);
        self::assertSame('14:30:00', $arr['time']);
        self::assertEqualsWithDelta(50.45, $arr['latitude'], 0.0001);
        self::assertEqualsWithDelta(30.52, $arr['longitude'], 0.0001);
        self::assertEqualsWithDelta(3.0, $arr['timezoneOffset'], 0.0001);
        self::assertArrayNotHasKey('name', $arr);
        self::assertArrayNotHasKey('city', $arr);
    }

    public function testFromCoordinatesNormalisesShortTime(): void
    {
        $b = BirthDateTime::fromCoordinates(
            date: '1990-07-14',
            time: '14:30',
            latitude: 0,
            longitude: 0,
            timezoneOffset: 0,
        );
        self::assertSame('14:30:00', $b->time);
    }

    public function testRejectsBadDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::fromCoordinates(date: '14-07-1990', time: '14:30:00', latitude: 0, longitude: 0, timezoneOffset: 0);
    }

    public function testRejectsBadTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::fromCoordinates(date: '1990-07-14', time: '2:30 pm', latitude: 0, longitude: 0, timezoneOffset: 0);
    }

    public function testRejectsLatitudeOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::fromCoordinates(date: '1990-07-14', time: '14:30:00', latitude: 91, longitude: 0, timezoneOffset: 0);
    }

    public function testRejectsLongitudeOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::fromCoordinates(date: '1990-07-14', time: '14:30:00', latitude: 0, longitude: -181, timezoneOffset: 0);
    }

    public function testRejectsTimezoneOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::fromCoordinates(date: '1990-07-14', time: '14:30:00', latitude: 0, longitude: 0, timezoneOffset: 15);
    }

    public function testFromDateTimeImmutableUsesIntrinsicOffset(): void
    {
        $dt = new \DateTimeImmutable('1990-07-14T14:30:00+03:00');
        $b = BirthDateTime::fromDateTimeImmutable($dt, latitude: 50.45, longitude: 30.52);
        self::assertSame('1990-07-14', $b->date);
        self::assertSame('14:30:00', $b->time);
        self::assertEqualsWithDelta(3.0, $b->timezoneOffset, 0.0001);
    }

    public function testFromDateTimeImmutableAcceptsExplicitOffsetOverride(): void
    {
        $dt = new \DateTimeImmutable('1990-07-14T11:30:00Z');
        $b = BirthDateTime::fromDateTimeImmutable($dt, latitude: 0, longitude: 0, timezoneOffset: 3);
        self::assertEqualsWithDelta(3.0, $b->timezoneOffset, 0.0001);
    }

    public function testParseAcceptsIsoWithOffset(): void
    {
        $b = BirthDateTime::parse('1990-07-14T14:30:00+03:00', latitude: 50.45, longitude: 30.52);
        self::assertSame('14:30:00', $b->time);
        self::assertEqualsWithDelta(3.0, $b->timezoneOffset, 0.0001);
    }

    public function testParseRequiresExplicitOffsetWhenIsoIsNaive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::parse('1990-07-14T14:30:00', latitude: 0, longitude: 0);
    }

    public function testParseAcceptsNaiveIsoWithExplicitOffset(): void
    {
        $b = BirthDateTime::parse('1990-07-14T14:30:00', latitude: 0, longitude: 0, timezoneOffset: 3);
        self::assertEqualsWithDelta(3.0, $b->timezoneOffset, 0.0001);
    }

    public function testParseRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BirthDateTime::parse('not-an-iso', latitude: 0, longitude: 0, timezoneOffset: 0);
    }

    public function testToDateTimeImmutableRoundtrip(): void
    {
        $b = BirthDateTime::fromCoordinates(
            date: '1990-07-14', time: '14:30:00', latitude: 50.45, longitude: 30.52, timezoneOffset: 3,
        );
        $dt = $b->toDateTimeImmutable();
        self::assertSame('1990-07-14T14:30:00+03:00', $dt->format(\DateTimeInterface::ATOM));
    }

    public function testToDateTimeImmutableHandlesFractionalOffset(): void
    {
        $b = BirthDateTime::fromCoordinates(
            date: '1990-07-14', time: '14:30:00', latitude: 0, longitude: 0, timezoneOffset: 5.5,
        );
        $dt = $b->toDateTimeImmutable();
        self::assertSame('+05:30', $dt->format('P'));
    }

    public function testNameAndCitySerialisedWhenSet(): void
    {
        $b = BirthDateTime::fromCoordinates(
            date: '1990-07-14',
            time: '14:30:00',
            latitude: 50.45,
            longitude: 30.52,
            timezoneOffset: 3,
            name: 'Alice',
            city: 'Kyiv',
        );
        $arr = $b->toArray();
        self::assertSame('Alice', $arr['name']);
        self::assertSame('Kyiv', $arr['city']);
    }
}
