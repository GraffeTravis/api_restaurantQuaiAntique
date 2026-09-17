<?php

namespace App\Controller;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/account', name: 'app_api_account_')]
#[OA\Tag(name: 'Compte', description: 'Profil de l’utilisateur connecté')]
class AccountController extends AbstractController
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[OA\Get(
        summary: 'Consulter mon compte',
        responses: [
            new OA\Response(response: 200, description: 'Profil', content: new OA\JsonContent(ref: '#/components/schemas/AccountUser')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ]
    )]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/me', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Modifier mon profil',
        description: 'Tous les champs sont facultatifs. Un nom vide est ignoré ; une allergie vide ou null et guestNumber null effacent la valeur.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'firstName', type: 'string', example: 'Jean'),
                new OA\Property(property: 'lastName', type: 'string', example: 'Dupont'),
                new OA\Property(property: 'guestNumber', type: 'integer', minimum: 1, nullable: true, example: 4),
                new OA\Property(property: 'allergy', type: 'string', nullable: true, example: 'Arachides'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Profil modifié', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'user', ref: '#/components/schemas/AccountUser'),
            ])),
            new OA\Response(response: 400, description: 'JSON ou nombre de convives invalide', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ]
    )]
    public function update(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(
                ['message' => 'JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (isset($data['firstName']) && trim((string) $data['firstName']) !== '') {
            $user->setfirstName(trim((string) $data['firstName']));
        }

        if (isset($data['lastName']) && trim((string) $data['lastName']) !== '') {
            $user->setlastName(trim((string) $data['lastName']));
        }

        if (array_key_exists('guestNumber', $data)) {
            $guestNumber = $data['guestNumber'];

            if ($guestNumber !== null && (!is_numeric($guestNumber) || (int) $guestNumber < 1)) {
                return $this->json(
                    ['message' => 'Le nombre de convives doit être supérieur à 0'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $user->setGuestNumber($guestNumber === null ? null : (int) $guestNumber);
        }

        if (array_key_exists('allergy', $data)) {
            $allergy = trim((string) ($data['allergy'] ?? ''));
            $user->setAllergy($allergy === '' ? null : $allergy);
        }

        $user->setUpdatedAt(new DateTimeImmutable());
        $this->manager->flush();

        return $this->json([
            'message' => 'Compte modifié avec succès',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/password', name: 'password', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Changer mon mot de passe',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object', required: ['password'], properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Mot de passe modifié', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 400, description: 'Mot de passe absent ou trop court', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ]
    )]
    public function updatePassword(
        Request $request,
        #[CurrentUser] ?User $user,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data['password'])) {
            return $this->json(
                ['message' => 'Le nouveau mot de passe est obligatoire'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $plainPassword = (string) $data['password'];

        if (strlen($plainPassword) < 8) {
            return $this->json(
                ['message' => 'Le mot de passe doit contenir au moins 8 caractères'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
        $user->setUpdatedAt(new DateTimeImmutable());
        $this->manager->flush();

        return $this->json(['message' => 'Mot de passe modifié avec succès']);
    }

    #[Route('/me', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Supprimer mon compte',
        description: 'La suppression du compte administrateur est interdite.',
        responses: [
            new OA\Response(response: 200, description: 'Compte supprimé', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, description: 'Utilisateur non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Compte administrateur', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ]
    )]
    public function delete(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(
                ['message' => 'Utilisateur non authentifié'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(
                ['message' => 'Le compte administrateur ne peut pas être supprimé depuis cet écran'],
                Response::HTTP_FORBIDDEN
            );
        }

        $this->manager->remove($user);
        $this->manager->flush();

        return $this->json(['message' => 'Compte supprimé avec succès']);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getfirstName(),
            'lastName' => $user->getlastName(),
            'guestNumber' => $user->getGuestNumber(),
            'allergy' => $user->getAllergy(),
            'roles' => $user->getRoles(),
        ];
    }
}
