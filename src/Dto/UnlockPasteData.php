<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class UnlockPasteData
{
    #[Assert\NotBlank(message: 'Введите секретный ключ')]
    #[Assert\Length(max: 255, maxMessage: 'Ключ не должен превышать 255 символов')]
    public string $secret = '';
}
