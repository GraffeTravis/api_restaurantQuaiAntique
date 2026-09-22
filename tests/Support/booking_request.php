<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

$database = parse_url(getenv('DATABASE_URL') ?: '', PHP_URL_PATH);
if (getenv('RUN_DB_TESTS') !== '1' || !str_ends_with($database ?: '', '_test')) {
    throw new RuntimeException('This worker only accepts explicitly enabled test databases.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$kernel = new Kernel('test', true);
$request = Request::create('/api/bookings', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTH_TOKEN' => $input['token'],
], content: json_encode($input['booking'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
$kernel->shutdown();
