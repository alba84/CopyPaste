<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Http\Input;

use Symfony\Component\Validator\Constraints as Assert;

final class RevealSecretInput
{
    #[Assert\NotBlank(message: 'Введите секретный ключ')]
    #[Assert\Length(max: 255, maxMessage: 'Ключ не должен превышать 255 символов')]
    public string $secret = '';
}
