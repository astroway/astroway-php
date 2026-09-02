<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * GET lookup namespace methods. Before this the generator filtered on `post`,
 * so the spec's GET-only paths (the zodiac, tarot and esoteric dictionaries,
 * /acg/categories, /muhurta/types) were reachable only through the raw client.
 */
final class GetNamespacesTest extends TestCase
{
    private function jsonResponse(string $body): Response
    {
        return new Response(200, ['content-type' => 'application/json'], $body);
    }

    public function testGetLookupIssuesGetWithNoBody(): void
    {
        $http = new MockHttpClient([$this->jsonResponse('{"ok":true,"data":{"count":19}}')]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $http]);

        $result = $aw->acg()->categoriesGet();

        $req = $http->requests()[0];
        self::assertSame('GET', $req->getMethod());
        self::assertStringEndsWith('/acg/categories', $req->getUri()->getPath());
        // A GET carrying a body would mean the generator fell back to the POST
        // call path, which is the regression this guards.
        self::assertSame('', (string) $req->getBody());
        self::assertSame(['count' => 19], $result);
    }

    public function testGetLookupForwardsHeaders(): void
    {
        $http = new MockHttpClient([$this->jsonResponse('{"ok":true,"data":{"items":[]}}')]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $http]);

        $aw->esoteric()->crystalsGet(['headers' => ['X-Custom' => 'yes']]);

        self::assertSame('yes', $http->requests()[0]->getHeaderLine('X-Custom'));
    }

    public function testRepresentativeLookupsExist(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => new MockHttpClient()]);

        self::assertTrue(method_exists($aw->acg(), 'categoriesGet'));
        self::assertTrue(method_exists($aw->muhurta(), 'typesGet'));
        self::assertTrue(method_exists($aw->esoteric(), 'crystalsGet'));
        self::assertTrue(method_exists($aw->reference(), 'signsGet'));
    }

    /** The widgets answer text/html and /public/* duplicates keyed endpoints. */
    public function testHtmlWidgetsAndKeylessMirrorsAreNotGenerated(): void
    {
        self::assertFalse(class_exists('Astroway\\Namespaces\\EmbedService'));
        self::assertFalse(class_exists('Astroway\\Namespaces\\PublicService'));
    }
}
