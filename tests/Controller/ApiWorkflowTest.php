<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

class ApiWorkflowTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $manager;
    private array $tokens = [];

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with a dedicated test database.');
        }

        $this->browser = self::createClient();
        $this->browser->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
        $params = $this->manager->getConnection()->getParams();
        $safe = ($params['driver'] ?? '') === 'pdo_sqlite' && ($params['memory'] ?? false)
            || (bool) preg_match('/_test$/', $params['dbname'] ?? '');
        if (!$safe) {
            self::fail('Refusing to reset a database that is not in-memory SQLite or named *_test.');
        }

        $schema = new SchemaTool($this->manager);
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->manager->getConnection()->executeStatement('DROP TABLE IF EXISTS auth_rate_limits');
    }

    private function user(string $email, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())->setEmail($email)->setPassword('test-hash')
            ->setRoles($roles)->setfirstName('Test')->setlastName('User')
            ->setCreatedAt(new DateTimeImmutable())->setGuestNumber(2);
        $token = $user->issueApiToken();
        $this->manager->persist($user);
        $this->manager->flush();
        $this->tokens[$user->getId()] = $token;

        return $user;
    }

    private function restaurant(User $owner, int $capacity = 4): Restaurant
    {
        $restaurant = (new Restaurant())->setName('Quai Antique')->setDescription('Test')
            ->setMaxGuest($capacity)->setOwner($owner)->setCreatedAt(new DateTimeImmutable())
            ->setAmOpeningTime(['12:00', '14:00'])->setPmOpeningTime(['19:00', '21:00']);
        $this->manager->persist($restaurant);
        $this->manager->flush();

        return $restaurant;
    }

    private function json(string $method, string $path, ?array $data = null, ?User $user = null): array
    {
        $this->browser->request($method, $path, server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTH_TOKEN' => $user ? $this->tokens[$user->getId()] : null,
        ]), content: $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR));

        $body = $this->browser->getResponse()->getContent();
        if ($this->browser->getResponse()->getStatusCode() >= 400) {
            return json_decode($body, true) ?: [];
        }
        self::assertJson($body, sprintf('HTTP %d: %s', $this->browser->getResponse()->getStatusCode(), substr($body, 0, 1000)));

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testRegistrationDoesNotGrantAdminAndAccountDeletionIsProtected(): void
    {
        $response = $this->json('POST', '/api/registration', [
            'email' => 'client@example.test', 'password' => 'a-long-secret123', 'firstName' => 'Alice',
            'lastName' => 'Martin', 'guestNumber' => 2, 'roles' => ['ROLE_ADMIN'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotContains('ROLE_ADMIN', $response['roles']);

        $token = $response['apiToken'];
        $this->json('POST', '/api/login', ['username' => 'client@example.test', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
        $login = $this->json('POST', '/api/login', ['username' => 'client@example.test', 'password' => 'a-long-secret123']);
        self::assertResponseIsSuccessful();
        self::assertNotSame($token, $login['apiToken']);
        $this->json('GET', '/api/account/me');
        self::assertResponseStatusCodeSame(401);
        $this->browser->request('GET', '/api/account/me', server: ['HTTP_X_AUTH_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(401);
        $this->browser->request('GET', '/api/account/me', server: ['HTTP_X_AUTH_TOKEN' => $login['apiToken']]);
        self::assertResponseIsSuccessful();

        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $this->json('DELETE', '/api/account/me', user: $admin);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->manager->getRepository(User::class)->find($admin->getId()));

        $this->json('POST', '/api/registration', [
            'email' => 'client@example.test', 'password' => 'a-long-secret123', 'firstName' => 'Alice',
            'lastName' => 'Martin', 'guestNumber' => 2,
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testBookingCapacityValidationAndOwnership(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $alice = $this->user('alice@example.test');
        $bob = $this->user('bob@example.test');
        $restaurant = $this->restaurant($admin);
        $date = (new DateTimeImmutable('next tuesday'))->format('Y-m-d');
        $path = '/api/bookings';
        $payload = ['restaurantId' => $restaurant->getId(), 'date' => $date, 'hour' => '19:00', 'guestNumber' => 3];

        $this->json('POST', $path, $payload);
        self::assertResponseStatusCodeSame(401);
        $this->json('POST', $path, [...$payload, 'hour' => '19:10'], $alice);
        self::assertResponseStatusCodeSame(400);
        $created = $this->json('POST', $path, $payload, $alice);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(3, $created['booking']['guestNumber']);
        $id = $created['booking']['id'];

        $this->json('GET', '/api/bookings/availability?'.http_build_query([
            'restaurantId' => $restaurant->getId(), 'date' => $date, 'hour' => '19:00', 'guestNumber' => 2,
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame(false, json_decode($this->browser->getResponse()->getContent(), true)['available']);

        $this->json('POST', $path, [...$payload, 'guestNumber' => 2], $bob);
        self::assertResponseStatusCodeSame(409);
        $this->json('GET', '/api/bookings/'.$id, user: $bob);
        self::assertResponseStatusCodeSame(403);
        $this->json('GET', '/api/bookings/'.$id, user: $alice);
        self::assertResponseIsSuccessful();
    }

    public function testGalleryUploadPersistsAndCanBeRenamedAndDeleted(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $client = $this->user('client@example.test');
        $restaurant = $this->restaurant($admin);
        $path = '/api/restaurants/'.$restaurant->getId().'/pictures';

        $this->json('POST', $path, user: $client);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', $path, ['title' => 'Vide'], server: ['HTTP_X_AUTH_TOKEN' => $this->tokens[$admin->getId()]]);
        self::assertResponseStatusCodeSame(400);

        $invalidFile = tempnam(sys_get_temp_dir(), 'gallery-invalid-');
        try {
            file_put_contents($invalidFile, 'not an image');
            $this->browser->request('POST', $path, ['title' => 'Invalide'], [
                'imageFile' => new UploadedFile($invalidFile, 'photo.txt', 'text/plain', null, true),
            ], ['HTTP_X_AUTH_TOKEN' => $this->tokens[$admin->getId()]]);
            self::assertResponseStatusCodeSame(400);
        } finally {
            if (is_file($invalidFile)) {
                unlink($invalidFile);
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'gallery-test-');
        try {
            file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/iRsAAAAASUVORK5CYII='));
            $this->browser->request('POST', $path, ['title' => 'Photo test'], [
                'imageFile' => new UploadedFile($file, 'test.png', 'image/png', null, true),
            ], ['HTTP_X_AUTH_TOKEN' => $this->tokens[$admin->getId()]]);
            self::assertResponseStatusCodeSame(201);
            $picture = json_decode($this->browser->getResponse()->getContent(), true)['picture'];
            self::assertStringStartsWith('data:image/png;base64,', $picture['imageUrl']);
            $id = $picture['id'];

            $this->manager->clear();
            $listing = $this->json('GET', $path);
            self::assertSame(1, $listing['total']);
            self::assertSame($id, $listing['pictures'][0]['id']);

            $renamed = $this->json('PUT', $path.'/'.$id, ['title' => 'Nouvelle photo'], $admin);
            self::assertResponseIsSuccessful();
            self::assertSame('Nouvelle photo', $renamed['picture']['title']);

            $this->json('DELETE', $path.'/'.$id, user: $admin);
            self::assertResponseIsSuccessful();
            $listing = $this->json('GET', $path);
            self::assertSame(0, $listing['total']);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testRegistrationRejectsInvalidValuesAndLimitsAttempts(): void
    {
        $valid = ['email' => 'valid@example.test', 'password' => 'long-password-123', 'firstName' => 'Alice', 'lastName' => 'Test', 'guestNumber' => 2];
        foreach ([
            ['email' => 'invalid'], ['password' => 'x'], ['guestNumber' => 1.9],
            ['firstName' => ['invalid']], ['allergy' => str_repeat('x', 256)],
        ] as $invalid) {
            $this->json('POST', '/api/registration', [...$valid, ...$invalid]);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertSame(0, $this->manager->getRepository(User::class)->count([]));
        $this->json('POST', '/api/registration', $valid);
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->browser->getResponse()->headers->get('Retry-After'));
    }

    public function testTokensAreHashedRotatedExpiredAndRevoked(): void
    {
        $email = 'session@example.test';
        $password = 'first-long-password';
        $created = $this->json('POST', '/api/registration', [
            'email' => $email, 'password' => $password, 'firstName' => 'Alice', 'lastName' => 'Test', 'guestNumber' => 2,
        ]);
        self::assertResponseStatusCodeSame(201);
        $user = $this->manager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertSame(hash('sha256', $created['apiToken']), $user->getApiToken());
        $this->tokens[$user->getId()] = $created['apiToken'];
        $this->json('PUT', '/api/account/password', ['password' => 'second-long-password', 'currentPassword' => 'wrong'], $user);
        self::assertResponseStatusCodeSame(403);
        $this->json('PUT', '/api/account/password', ['password' => 'second-long-password', 'currentPassword' => $password], $user);
        self::assertResponseIsSuccessful();
        $this->json('GET', '/api/account/me', user: $user);
        self::assertResponseStatusCodeSame(401);

        $login = $this->json('POST', '/api/login', ['username' => $email, 'password' => 'second-long-password', 'rememberMe' => true]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($created['apiToken'], $login['apiToken']);
        self::assertGreaterThan(new DateTimeImmutable('+6 days'), new DateTimeImmutable($login['expiresAt']));
        $this->tokens[$user->getId()] = $login['apiToken'];
        $this->json('POST', '/api/logout', [], $user);
        self::assertResponseIsSuccessful();
        $this->json('GET', '/api/account/me', user: $user);
        self::assertResponseStatusCodeSame(401);

        $login = $this->json('POST', '/api/login', ['username' => $email, 'password' => 'second-long-password']);
        self::assertLessThan(new DateTimeImmutable('+2 hours'), new DateTimeImmutable($login['expiresAt']));
        $this->tokens[$user->getId()] = $login['apiToken'];
        $user = $this->manager->find(User::class, $user->getId());
        (new \ReflectionProperty(User::class, 'apiTokenExpiresAt'))->setValue($user, new DateTimeImmutable('-1 second'));
        $this->manager->flush();
        $this->json('GET', '/api/account/me', user: $user);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginAttemptsAreLimited(): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $this->json('POST', '/api/login', ['username' => 'unknown@example.test', 'password' => 'wrong']);
            self::assertResponseStatusCodeSame(401);
        }
        $this->json('POST', '/api/login', ['username' => 'unknown@example.test', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    public function testConcurrentBookingsCannotExceedServiceCapacity(): void
    {
        $connection = $this->manager->getConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            self::markTestSkipped('Concurrency is verified against PostgreSQL.');
        }
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $alice = $this->user('alice@example.test');
        $bob = $this->user('bob@example.test');
        $restaurant = $this->restaurant($admin);
        $workers = [];
        $connection->beginTransaction();
        $connection->executeQuery('SELECT id FROM restaurant ORDER BY id FOR UPDATE')->free();
        try {
            foreach ([$alice, $bob] as $index => $user) {
                $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Support/booking_request.php']);
                $worker->setInput(json_encode([
                    'token' => $this->tokens[$user->getId()],
                    'booking' => ['restaurantId' => $restaurant->getId(), 'date' => (new DateTimeImmutable('next tuesday'))->format('Y-m-d'), 'hour' => $index ? '19:15' : '19:00', 'guestNumber' => 4],
                ], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                $connection->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                $waiting = (int) $connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query = 'SELECT id FROM restaurant ORDER BY id FOR UPDATE'");
                if ($waiting === 2) {
                    break;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both workers must contend for the restaurant lock.');
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), 'A booking must wait while the restaurant row is locked.');
            }
            $connection->commit();
            $statuses = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $statuses[] = (int) trim($worker->getOutput());
            }
            sort($statuses);
            $workerOutput = array_map(static fn (Process $worker): string => sprintf(
                "exit=%d stdout=%s stderr=%s",
                $worker->getExitCode() ?? -1,
                trim($worker->getOutput()),
                trim($worker->getErrorOutput())
            ), $workers);
            self::assertSame([201, 409], $statuses, implode("\n", $workerOutput));
            self::assertSame(4, (int) $connection->fetchOne('SELECT SUM(guest_number) FROM booking'));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function testCapacityIsSharedAcrossSlotsAndExclusionRequiresOwnership(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $alice = $this->user('alice@example.test');
        $bob = $this->user('bob@example.test');
        $restaurant = $this->restaurant($admin);
        $payload = ['restaurantId' => $restaurant->getId(), 'date' => (new DateTimeImmutable('next tuesday'))->format('Y-m-d'), 'hour' => '19:00', 'guestNumber' => 4];
        $created = $this->json('POST', '/api/bookings', $payload, $alice);
        self::assertResponseStatusCodeSame(201);
        $id = $created['booking']['id'];
        $this->json('POST', '/api/bookings', [...$payload, 'hour' => '19:15'], $bob);
        self::assertResponseStatusCodeSame(409);
        $availability = '/api/bookings/availability?'.http_build_query([...$payload, 'hour' => '19:30', 'excludeBookingId' => $id]);
        $this->json('GET', $availability);
        self::assertResponseStatusCodeSame(403);
        $this->json('GET', $availability, user: $bob);
        self::assertResponseStatusCodeSame(403);
        $result = $this->json('GET', $availability, user: $alice);
        self::assertTrue($result['available']);
        $this->json('PUT', '/api/bookings/'.$id, [...$payload, 'hour' => '19:15'], $alice);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/bookings', [...$payload, 'hour' => '12:00'], $bob);
        self::assertResponseStatusCodeSame(201);
        $this->json('PUT', '/api/restaurants/'.$restaurant->getId(), ['maxGuest' => 3], $admin);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPastDatesAndClosedOrInvalidServicesAreRejected(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $alice = $this->user('alice@example.test');
        $restaurant = $this->restaurant($admin);
        $payload = ['restaurantId' => $restaurant->getId(), 'date' => '2000-01-04', 'hour' => '12:00', 'guestNumber' => 2];
        $this->json('POST', '/api/bookings', $payload, $alice);
        self::assertResponseStatusCodeSame(400);
        $result = $this->json('GET', '/api/bookings/availability?'.http_build_query($payload));
        self::assertFalse($result['available']);
        $this->json('PUT', '/api/restaurants/'.$restaurant->getId(), ['amOpeningTime' => ['99:00', '00:00']], $admin);
        self::assertResponseStatusCodeSame(400);
        $this->json('PUT', '/api/restaurants/'.$restaurant->getId(), ['amOpeningTime' => [], 'pmOpeningTime' => []], $admin);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/bookings', [...$payload, 'date' => (new DateTimeImmutable('next tuesday'))->format('Y-m-d'), 'hour' => '03:00'], $alice);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCatalogueRequiresAdminAndPersistsRelations(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $client = $this->user('client@example.test');
        $restaurant = $this->restaurant($admin);

        $this->json('POST', '/api/categories', ['title' => 'Entrées'], $client);
        self::assertResponseStatusCodeSame(403);
        $category = $this->json('POST', '/api/categories', ['title' => 'Entrées'], $admin);
        self::assertResponseStatusCodeSame(201);
        $categoryId = $category['category']['id'];

        $this->json('POST', '/api/foods', ['title' => 'Soupe', 'description' => 'Maison', 'price' => -1], $admin);
        self::assertResponseStatusCodeSame(400);
        $food = $this->json('POST', '/api/foods', [
            'title' => 'Soupe', 'description' => 'Maison', 'price' => 12, 'categoryIds' => [$categoryId],
        ], $admin);
        self::assertResponseStatusCodeSame(201);

        $menu = $this->json('POST', '/api/menus', [
            'title' => 'Découverte', 'description' => 'Menu du jour', 'price' => 32,
            'restaurantId' => $restaurant->getId(), 'categoryIds' => [$categoryId],
        ], $admin);
        self::assertResponseStatusCodeSame(201);

        $this->manager->clear();
        $listed = $this->json('GET', '/api/foods');
        self::assertResponseIsSuccessful();
        self::assertSame($food['food']['id'], $listed['foods'][0]['id']);
        self::assertSame($categoryId, $listed['foods'][0]['categories'][0]['id']);
        $listed = $this->json('GET', '/api/menus');
        self::assertSame($menu['menu']['id'], $listed['menus'][0]['id']);

        $this->json('DELETE', '/api/categories/'.$categoryId, user: $client);
        self::assertResponseStatusCodeSame(403);
    }
}
