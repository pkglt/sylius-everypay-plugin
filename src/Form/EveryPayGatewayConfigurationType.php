<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Form;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Validator\Constraints\ValidEveryPayCredentials;
use Sylius\Bundle\PaymentBundle\Attribute\AsGatewayConfigurationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin form for the EveryPay gateway configuration ("Gateway" dropdown on
 * the payment method form). Values are encrypted at rest in
 * sylius_gateway_config. Credentials come from the EveryPay merchant portal
 * (Merchant settings -> General) - see docs/everypay-api.md.
 */
#[AsGatewayConfigurationType(type: EveryPayGateway::FACTORY_NAME, label: 'pkg_everypay.ui.gateway_label')]
final class EveryPayGatewayConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(EveryPayGateway::CONFIG_API_USERNAME, TextType::class, [
                'label' => 'pkg_everypay.ui.api_username',
                // Browsers pair a text field followed by a password field as
                // login credentials and offer to save/autofill them - an
                // autofilled secret would silently overwrite the stored one.
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(groups: ['everypay']),
                ],
            ])
            ->add(EveryPayGateway::CONFIG_API_SECRET, PasswordType::class, [
                'label' => 'pkg_everypay.ui.api_secret',
                'help' => 'pkg_everypay.ui.api_secret_help',
                'required' => false,
                // one-time-code = externally issued, pasted in: no autofill,
                // no save prompt, and (unlike new-password) no "suggest
                // strong password" generator.
                'attr' => ['autocomplete' => 'one-time-code'],
                'constraints' => [
                    new NotBlank(groups: ['everypay']),
                ],
            ])
            ->add(EveryPayGateway::CONFIG_ACCOUNT_NAME, TextType::class, [
                'label' => 'pkg_everypay.ui.account_name',
                'help' => 'pkg_everypay.ui.account_name_help',
                'constraints' => [
                    new NotBlank(groups: ['everypay']),
                ],
            ])
            ->add(EveryPayGateway::CONFIG_ENVIRONMENT, ChoiceType::class, [
                'label' => 'pkg_everypay.ui.environment',
                'choices' => [
                    'pkg_everypay.ui.environment_demo' => EveryPayGateway::ENVIRONMENT_DEMO,
                    'pkg_everypay.ui.environment_live' => EveryPayGateway::ENVIRONMENT_LIVE,
                ],
                'empty_data' => EveryPayGateway::ENVIRONMENT_DEMO,
            ])
            ->add(EveryPayGateway::CONFIG_DISPLAY_MODE, ChoiceType::class, [
                'label' => 'pkg_everypay.ui.display_mode',
                'help' => 'pkg_everypay.ui.display_mode_help',
                'choices' => [
                    'pkg_everypay.ui.display_mode_redirect' => EveryPayGateway::DISPLAY_MODE_REDIRECT,
                    'pkg_everypay.ui.display_mode_method_grid' => EveryPayGateway::DISPLAY_MODE_METHOD_GRID,
                ],
                'empty_data' => EveryPayGateway::DISPLAY_MODE_REDIRECT,
            ]);

        // A password input never re-renders its stored value, so an untouched
        // field on the edit form submits as empty - restore the saved secret
        // instead of overwriting it. NotBlank still fires when there is no
        // saved secret to fall back to (a freshly created method).
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            if (!is_array($submitted) || '' !== ($submitted[EveryPayGateway::CONFIG_API_SECRET] ?? '')) {
                return;
            }

            $original = $event->getForm()->getData();
            if (is_array($original) && '' !== ($original[EveryPayGateway::CONFIG_API_SECRET] ?? '')) {
                $submitted[EveryPayGateway::CONFIG_API_SECRET] = $original[EveryPayGateway::CONFIG_API_SECRET];
                $event->setData($submitted);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // The parent GatewayConfigType pins its subtree to the `sylius`
            // group, so the factory-specific groups from
            // sylius_payment.gateway_config.validation_groups never reach
            // this form - declare them here or no `everypay` constraint
            // (including the NotBlank rules above) would ever run.
            'validation_groups' => ['sylius', 'everypay'],
            // Definitive credential rejections fail the save; unreachable
            // EveryPay never does (see the validator).
            'constraints' => [
                new ValidEveryPayCredentials(groups: ['everypay']),
            ],
        ]);
    }
}
