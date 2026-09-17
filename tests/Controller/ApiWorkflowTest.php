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

class ApiWorkflowTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with a dedicated test database.');
        }

        $this->browser = self::createClient();
        $this->browser->disableReboot();
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
    }

    private function user(string $email, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())->setEmail($email)->setPassword('test-hash')
            ->setRoles($roles)->setfirstName('Test')->setlastName('User')
            ->setCreatedAt(new DateTimeImmutable())->setGuestNumber(2);
        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }

    private function restaurant(User $owner, int $capacity = 4): Restaurant
    {
        $restaurant = (new Restaurant())->setName('Quai Antique')->setDescription('Test')
            ->setMaxGuest($capacity)->setOwner($owner)->setCreatedAt(new DateTimeImmutable())
            ->setAmOpeningTime(['11:00', '14:00'])->setPmOpeningTime(['18:00', '22:00']);
        $this->manager->persist($restaurant);
        $this->manager->flush();

        return $restaurant;
    }

    private function json(string $method, string $path, ?array $data = null, ?User $user = null): array
    {
        $this->browser->request($method, $path, server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTH_TOKEN' => $user?->getApiToken(),
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
            'email' => 'client@example.test', 'password' => 'secret123', 'firstName' => 'Alice',
            'lastName' => 'Martin', 'guestNumber' => 2, 'roles' => ['ROLE_ADMIN'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotContains('ROLE_ADMIN', $response['roles']);

        $token = $response['apiToken'];
        $this->json('POST', '/api/login', ['username' => 'client@example.test', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
        $login = $this->json('POST', '/api/login', ['username' => 'client@example.test', 'password' => 'secret123']);
        self::assertResponseIsSuccessful();
        self::assertSame($token, $login['apiToken']);
        $this->json('GET', '/api/account/me');
        self::assertResponseStatusCodeSame(401);
        $this->browser->request('GET', '/api/account/me', server: ['HTTP_X_AUTH_TOKEN' => $token]);
        self::assertResponseIsSuccessful();

        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $this->json('DELETE', '/api/account/me', user: $admin);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->manager->getRepository(User::class)->find($admin->getId()));

        $this->json('POST', '/api/registration', [
            'email' => 'client@example.test', 'password' => 'secret123', 'firstName' => 'Alice',
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
        $this->browser->request('POST', $path, ['title' => 'Vide'], server: ['HTTP_X_AUTH_TOKEN' => $admin->getApiToken()]);
        self::assertResponseStatusCodeSame(400);

        $invalidFile = tempnam(sys_get_temp_dir(), 'gallery-invalid-');
        try {
            file_put_contents($invalidFile, 'not an image');
            $this->browser->request('POST', $path, ['title' => 'Invalide'], [
                'imageFile' => new UploadedFile($invalidFile, 'photo.txt', 'text/plain', null, true),
            ], ['HTTP_X_AUTH_TOKEN' => $admin->getApiToken()]);
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
            ], ['HTTP_X_AUTH_TOKEN' => $admin->getApiToken()]);
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
