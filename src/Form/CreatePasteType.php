<?php

namespace App\Form;

use App\Dto\CreatePasteData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CreatePasteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextareaType::class, ['label' => 'Текст', 'empty_data' => '', 'attr' => ['rows' => 12, 'maxlength' => 100000]])
            ->add('hint', TextType::class, ['label' => 'Подсказка', 'required' => false, 'attr' => ['maxlength' => 255]])
            ->add('secret', PasswordType::class, ['label' => 'Секретный ключ', 'empty_data' => '', 'attr' => ['maxlength' => 255, 'autocomplete' => 'new-password']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreatePasteData::class, 'csrf_token_id' => 'create_paste']);
    }
}
