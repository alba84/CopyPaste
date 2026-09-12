<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Http;

use App\Access\Domain\AccessDecision;
use App\Access\Domain\AccessLimit;
use App\Access\Domain\AccessLimiter;
use App\Secret\Application\CreateSecret\CreateSecret;
use App\Secret\Application\CreateSecret\CreateSecretHandler;
use App\Secret\Presentation\Http\Form\CreateSecretType;
use App\Secret\Presentation\Http\Input\CreateSecretInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CreateSecretController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CreateSecretHandler $createSecretHandler, AccessLimiter $accessLimiter): Response
    {
        $input = new CreateSecretInput();
        $form = $this->createForm(CreateSecretType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $ip = $request->getClientIp() ?? 'unknown';
            if (AccessDecision::Rejected === $accessLimiter->consume(AccessLimit::CreateSecret, $ip)) {
                $form->addError(new FormError('Слишком много запросов. Попробуйте через минуту.'));
            } elseif ($form->isValid()) {
                $result = $createSecretHandler(new CreateSecret($input->text, $input->hint, $input->secret));

                return $this->redirectToRoute('app_paste_created', ['token' => $result->token->value]);
            }
        }

        return $this->render('paste/create.html.twig', ['form' => $form]);
    }
}
