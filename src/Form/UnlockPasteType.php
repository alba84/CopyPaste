<?php

namespace App\Form;

use App\Dto\UnlockPasteData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UnlockPasteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('secret', PasswordType::class, ['label' => 'Секретный ключ', 'empty_data' => '', 'attr' => ['maxlength' => 255, 'autocomplete' => 'current-password']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UnlockPasteData::class, 'csrf_token_id' => 'unlock_paste']);
    }
}
