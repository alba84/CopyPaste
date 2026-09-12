<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Http;

use App\Secret\Application\RevealSecret\LoadRevealSecret;
use App\Secret\Application\RevealSecret\LoadRevealSecretHandler;
use App\Secret\Application\RevealSecret\NotFound;
use App\Secret\Application\RevealSecret\Revealed;
use App\Secret\Application\RevealSecret\RevealSecret;
use App\Secret\Application\RevealSecret\RevealSecretHandler;
use App\Secret\Presentation\Http\Form\RevealSecretType;
use App\Secret\Presentation\Http\Input\RevealSecretInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class RevealSecretController extends AbstractController
{
    #[Route('/p/{token}', name: 'app_paste_view', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function __invoke(
        string $token,
        Request $request,
        LoadRevealSecretHandler $loadRevealSecret,
        RevealSecretHandler $revealSecret,
        RateLimiterFactory $unlockPasteLimiter,
    ): Response {
        $view = $loadRevealSecret(new LoadRevealSecret($token));
        if ($view instanceof NotFound) {
            throw $this->createNotFoundException('Запись не найдена или срок её хранения истёк');
        }

        $input = new RevealSecretInput();
        $form = $this->createForm(RevealSecretType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $key = ($request->getClientIp() ?? 'unknown').':'.$token;
            if (!$unlockPasteLimiter->create($key)->consume()->isAccepted()) {
                $form->addError(new FormError('Слишком много попыток. Попробуйте через минуту.'));
            } elseif ($form->isValid()) {
                $result = $revealSecret(new RevealSecret($token, $input->secret));
                if ($result instanceof Revealed) {
                    $response = $this->render('paste/revealed.html.twig', ['plaintext' => $result->plaintext]);
                    $response->headers->set('Cache-Control', 'no-store');

                    return $response;
                }
                if ($result instanceof NotFound) {
                    throw $this->createNotFoundException('Запись не найдена или срок её хранения истёк');
                }
                $form->get('secret')->addError(new FormError('Неверный ключ'));
            }
        }

        return $this->render('paste/view.html.twig', ['paste' => $view, 'form' => $form]);
    }
}
