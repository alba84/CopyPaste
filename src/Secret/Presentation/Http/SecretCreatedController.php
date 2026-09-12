<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Http;

use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SecretCreatedController extends AbstractController
{
    #[Route('/created/{token}', name: 'app_paste_created', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function __invoke(string $token, SecretRepository $secretRepository): Response
    {
        $secret = $secretRepository->findByToken(new Token($token));
        if (null === $secret) {
            throw $this->createNotFoundException();
        }

        return $this->render('paste/created.html.twig', [
            'created' => new SecretCreatedView($secret->expiresAt()),
            'paste_url' => $this->generateUrl('app_paste_view', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }
}
