<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UnlockPasteData;
use App\Entity\Paste;
use App\Form\UnlockPasteType;
use App\Repository\PasteRepository;
use App\Service\EncryptionService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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

    #[Route('/p/{token}', name: 'app_paste_view', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function view(string $token, Request $request, PasteRepository $repository, EncryptionService $encryption, RateLimiterFactory $unlockPasteLimiter): Response
    {
        $paste = $repository->findActiveByToken($token, new DateTimeImmutable());
        if (null === $paste) {
            throw $this->createNotFoundException('Запись не найдена или срок её хранения истёк');
        }
        $data = new UnlockPasteData();
        $form = $this->createForm(UnlockPasteType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $key = ($request->getClientIp() ?? 'unknown').':'.$token;
            if (!$unlockPasteLimiter->create($key)->consume()->isAccepted()) {
                $form->addError(new FormError('Слишком много попыток. Попробуйте через минуту.'));
            } elseif ($form->isValid()) {
                $plaintext = $encryption->decrypt($paste->getEncryptedPayload(), $data->secret);
                if (null === $plaintext) {
                    $form->get('secret')->addError(new FormError('Неверный ключ'));
                } else {
                    $response = $this->render('paste/revealed.html.twig', ['paste' => $paste, 'plaintext' => $plaintext]);
                    $response->headers->set('Cache-Control', 'no-store');

                    return $response;
                }
            }
        }

        return $this->render('paste/view.html.twig', ['paste' => $paste, 'form' => $form]);
    }
}
