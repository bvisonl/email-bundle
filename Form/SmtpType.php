<?php

namespace Bvisonl\EmailBundle\Form;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SmtpType extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add("host", TextType::class, array("label" => "El Hostname / Servidor de correos"))
            ->add("port", TextType::class, array("label" => "Puerto"))
            ->add("encryption", TextType::class, array("label" => "Metodo de encripcion (tls, ssl)"))
            ->add("transport", TextType::class, array("label" => "Transporte (smtp, gmail, mail, sendmail)"))
            ->add("user", TextType::class, array("label" => "Usuario"))
            ->add("password", TextType::class, array("label" => "Contraseña"))
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Bvisonl\EmailBundle\Entity\Smtp',
            'allow_extra_fields' => true,
            'csrf_protection' => false,
            'cascade_validation' => true,
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'bvisonl_smtp_smtp';
    }


}
