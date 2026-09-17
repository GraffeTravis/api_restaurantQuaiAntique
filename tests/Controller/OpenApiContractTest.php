<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class OpenApiContractTest extends WebTestCase
{
    public function testEveryApiRouteHasMethodsParametersAndResponses(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $document = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $paths = $document['paths'];
        $count = 0;

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/') || str_starts_with($path, '/api/doc')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $operation = $paths[$path][strtolower($method)] ?? null;
                self::assertNotNull($operation, sprintf('%s %s absent from OpenAPI', $method, $path));
                self::assertNotEmpty($operation['responses'] ?? [], sprintf('%s %s needs responses', $method, $path));
                foreach ($operation['responses'] as $status => $response) {
                    self::assertNotEmpty($response['description'] ?? '', sprintf('%s %s response %s', $method, $path, $status));
                }
                foreach ($route->compile()->getPathVariables() as $variable) {
                    $parameters = $operation['parameters'] ?? [];
                    self::assertNotEmpty(array_filter($parameters, static fn (array $parameter): bool =>
                        ($parameter['name'] ?? '') === $variable
                        && ($parameter['in'] ?? '') === 'path'
                        && ($parameter['required'] ?? false) === true
                    ), sprintf('%s %s must document path parameter %s', $method, $path, $variable));
                }
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $path !== '/api/login') {
                    self::assertArrayHasKey('requestBody', $operation, sprintf('%s %s needs requestBody', $method, $path));
                }
                ++$count;
            }
        }

        self::assertGreaterThanOrEqual(37, $count);
    }
}
