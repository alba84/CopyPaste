<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Paste;
use App\Repository\PasteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PasteController extends AbstractController
{
    #[Route('/created/{token}', name: 'app_paste_created', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function created(string $token, PasteRepository $repository): Response
    {
        $paste = $repository->findOneBy(['token' => $token]);
        if (!$paste instanceof Paste) {
            throw $this->createNotFoundException();
        }

        return $this->render('paste/created.html.twig', ['paste' => $paste, 'paste_url' => $this->generateUrl('app_paste_view', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)]);
    }
}
