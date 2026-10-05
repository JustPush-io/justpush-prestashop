<?php
/**
 * Copyright © JustPush. MIT License.
 */

namespace JustPush\PrestaShop\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * PrestaShop 9 back-office logins, which go through Symfony security instead of the legacy
 * AdminLoginController hooks. On PrestaShop 8 these events never fire, and the module's
 * actionAdminLoginController* hooks take over.
 */
class AdminLoginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'Symfony\Component\Security\Http\Event\LoginSuccessEvent' => 'onLoginSuccess',
            'Symfony\Component\Security\Http\Event\LoginFailureEvent' => 'onLoginFailure',
        ];
    }

    /**
     * @param \Symfony\Component\Security\Http\Event\LoginSuccessEvent $event
     */
    public function onLoginSuccess($event)
    {
        try {
            $module = $this->module();
            if ($module === null) {
                return;
            }

            $employee = \Context::getContext()->employee;
            $email = $event->getAuthenticatedToken()->getUserIdentifier();
            if (!$employee instanceof \Employee || !\Validate::isLoadedObject($employee) || $employee->email !== $email) {
                $employee = (new \Employee())->getByEmail($email);
            }

            if ($employee instanceof \Employee && \Validate::isLoadedObject($employee)) {
                $module->notifyAdminLogin($employee);
            }
        } catch (\Throwable $e) {
            \JustPush\PrestaShop\Sender::log('Login success: ' . $e->getMessage());
        }
    }

    /**
     * @param \Symfony\Component\Security\Http\Event\LoginFailureEvent $event
     */
    public function onLoginFailure($event)
    {
        try {
            $module = $this->module();
            if ($module === null) {
                return;
            }

            $email = (string) $event->getRequest()->request->get('email', '');
            $passport = $event->getPassport();
            if ($passport !== null && $passport->hasBadge('Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge')) {
                $email = $passport->getBadge('Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge')->getUserIdentifier();
            }

            if (trim($email) !== '') {
                $module->notifyAdminLoginFailed(trim($email));
            }
        } catch (\Throwable $e) {
            \JustPush\PrestaShop\Sender::log('Login failure: ' . $e->getMessage());
        }
    }

    /**
     * @return \JustPush|null
     */
    private function module()
    {
        $module = \Module::getInstanceByName('justpush');

        return $module instanceof \JustPush && $module->active ? $module : null;
    }
}
