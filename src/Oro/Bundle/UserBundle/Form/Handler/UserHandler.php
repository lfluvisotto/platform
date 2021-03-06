<?php

namespace Oro\Bundle\UserBundle\Form\Handler;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FormBundle\Form\Handler\RequestHandlerTrait;
use Oro\Bundle\OrganizationBundle\Entity\Manager\BusinessUnitManager;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Templating\DelegatingEngine;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Handle User forms
 */
class UserHandler extends AbstractUserHandler
{
    use RequestHandlerTrait;

    /** @var DelegatingEngine */
    protected $templating;

    /** @var \Swift_Mailer */
    protected $mailer;

    /** @var FlashBagInterface */
    protected $flashBag;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var LoggerInterface */
    protected $logger;

    /** @var BusinessUnitManager */
    protected $businessUnitManager;

    /** @var ConfigManager */
    protected $userConfigManager;

    /**
     * @param FormInterface $form
     * @param RequestStack $requestStack
     * @param UserManager $manager
     * @param ConfigManager $userConfigManager
     * @param DelegatingEngine $templating
     * @param \Swift_Mailer $mailer
     * @param FlashBagInterface $flashBag
     * @param TranslatorInterface $translator
     * @param LoggerInterface $logger
     */
    public function __construct(
        FormInterface $form,
        RequestStack $requestStack,
        UserManager $manager,
        ConfigManager $userConfigManager = null,
        DelegatingEngine $templating = null,
        \Swift_Mailer $mailer = null,
        FlashBagInterface $flashBag = null,
        TranslatorInterface $translator = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($form, $requestStack, $manager);

        $this->userConfigManager = $userConfigManager;
        $this->templating = $templating;
        $this->mailer = $mailer;
        $this->flashBag = $flashBag;
        $this->translator = $translator;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function process(User $user)
    {
        $isUpdated = false;
        $this->form->setData($user);

        $request = $this->requestStack->getCurrentRequest();
        if (in_array($request->getMethod(), ['POST', 'PUT'], true)) {
            $this->submitPostPutRequest($this->form, $request);

            if ($this->form->isValid()) {
                $this->onSuccess($user);

                $isUpdated = true;
            }
        }

        // Reloads the user to reset its username. This is needed when the
        // username or password have been changed to avoid issues with the
        // security layer.
        if ($user->getId()) {
            $this->manager->reloadUser($user);
        }

        return $isUpdated;
    }

    /**
     * @param BusinessUnitManager $businessUnitManager
     */
    public function setBusinessUnitManager(BusinessUnitManager $businessUnitManager)
    {
        $this->businessUnitManager = $businessUnitManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function onSuccess(User $user)
    {
        if (null === $user->getAuthStatus()) {
            $this->manager->setAuthStatus($user, UserManager::STATUS_ACTIVE);
        }

        $isNewUser = !$user->getId();
        $plainPassword = $this->handleNewUser($user);

        $this->manager->updateUser($user);

        if ($isNewUser && $this->form->has('inviteUser') && $this->form->get('inviteUser')->getViewData()) {
            try {
                $this->sendInviteMail($user, $plainPassword);
            } catch (\Exception $ex) {
                $this->logger->error('Invitation email sending failed.', ['exception' => $ex]);
                $this->flashBag->add(
                    'warning',
                    $this->translator->trans('oro.user.controller.invite.fail.message')
                );
            }
        }
    }

    /**
     * @param User $user
     * @return string
     */
    protected function handleNewUser(User $user)
    {
        if ($user->getId()) {
            return '';
        }

        $sendPasswordInEmail = $this->userConfigManager &&
            $this->userConfigManager->get('oro_user.send_password_in_invitation_email');

        if (!$sendPasswordInEmail && !$user->getConfirmationToken()) {
            $user->setConfirmationToken($user->generateToken());
        }

        if ($this->form->has('passwordGenerate') && $this->form->get('passwordGenerate')->getData()) {
            $user->setPlainPassword($this->manager->generatePassword(10));
        }

        return $sendPasswordInEmail ? $user->getPlainPassword() : '';
    }

    /**
     * Send invite email to new user
     *
     * @param User $user
     * @param string $plainPassword
     *
     * @throws \RuntimeException
     */
    protected function sendInviteMail(User $user, $plainPassword)
    {
        if (in_array(null, [$this->userConfigManager, $this->mailer, $this->templating], true)) {
            throw new \RuntimeException('Unable to send invitation email, unmet dependencies detected.');
        }
        $senderEmail = $this->userConfigManager->get('oro_notification.email_notification_sender_email');
        $senderName = $this->userConfigManager->get('oro_notification.email_notification_sender_name');

        $message = \Swift_Message::newInstance()
            ->setSubject('Invite user')
            ->setFrom($senderEmail, $senderName)
            ->setTo($user->getEmail())
            ->setBody(
                $this->templating->render(
                    'OroUserBundle:Mail:invite.html.twig',
                    ['user' => $user, 'password' => $plainPassword]
                ),
                'text/html'
            );
        $this->mailer->send($message);
    }
}
