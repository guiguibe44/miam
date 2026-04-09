<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SiteAccessSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY_UNLOCKED = 'site_access_unlocked';

    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'app_site_access_unlock',
        'app_site_access_lock',
    ];

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->shouldProtectRequest($request)) {
            return;
        }

        if ($request->getSession()->get(self::SESSION_KEY_UNLOCKED, false) === true) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_site_access_unlock')));
    }

    private function shouldProtectRequest(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || str_starts_with($route, '_')) {
            return false;
        }

        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return false;
        }

        return true;
    }
}

