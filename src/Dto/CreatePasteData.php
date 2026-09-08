<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreatePasteData
{
    #[Assert\NotBlank(message: 'Введите текст')]
    #[Assert\Length(max: 100000, maxMessage: 'Текст не должен превышать 100 000 символов')]
    public string $text = '';

    #[Assert\Length(max: 255, maxMessage: 'Подсказка не должна превышать 255 символов')]
    public ?string $hint = null;

    #[Assert\NotBlank(message: 'Введите секретный ключ')]
    #[Assert\Length(max: 255, maxMessage: 'Ключ не должен превышать 255 символов')]
    public string $secret = '';
}
