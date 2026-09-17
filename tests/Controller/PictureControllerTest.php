<?php

namespace App\Tests\Controller;

use App\Controller\PictureController;
use App\Entity\Picture;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\PictureRepository;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;

class PictureControllerTest extends TestCase
{
    public function testRejectedUploadReturnsUsefulErrorInsteadOfServerError(): void
    {
        $controller = $this->createController($this->createStub(EntityManagerInterface::class));
        $file = new UploadedFile('', 'photo.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);
        $request = Request::create('/', 'POST', ['title' => 'Photo'], [], ['imageFile' => $file]);

        $response = $controller->create(1, $request, $this->admin());

        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('5 Mo', $response->getContent());
    }

    public function testValidUploadIsStoredAndReturned(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('persist')->with(self::callback(
            fn (Picture $picture) => $picture->getImageMimeType() === 'image/jpeg'
                && $picture->getImageData() === base64_encode(file_get_contents(__DIR__ . '/../../public/uploads/restaurants/ratatouille.jpg'))
        ));
        $manager->expects(self::once())->method('flush');

        $controller = $this->createController($manager);
        $path = __DIR__ . '/../../public/uploads/restaurants/ratatouille.jpg';
        $file = new UploadedFile($path, 'photo.jpg', null, UPLOAD_ERR_OK, true);
        $request = Request::create('/', 'POST', ['title' => 'Notre photo'], [], ['imageFile' => $file]);

        $response = $controller->create(1, $request, $this->admin());

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith(
            'data:image/jpeg;base64,',
            json_decode($response->getContent(), true)['picture']['imageUrl']
        );
    }

    private function createController(EntityManagerInterface $manager): PictureController
    {
        $restaurants = $this->createMock(RestaurantRepository::class);
        $restaurants->expects(self::once())->method('find')->with(1)->willReturn(new Restaurant());

        $controller = new PictureController(
            $manager,
            $this->createStub(PictureRepository::class),
            $restaurants,
            new AsciiSlugger()
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    private function admin(): User
    {
        return (new User())->setRoles(['ROLE_ADMIN']);
    }
}
