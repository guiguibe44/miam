<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SiteAccessController extends AbstractController
{
    private const SESSION_KEY_UNLOCKED = 'site_access_unlocked';
    private const SESSION_KEY_USER_GREETING = 'site_access_user_greeting';

    /**
     * @var list<string>
     */
    private const ALLOWED_PINS = ['1720', '5678'];

    #[Route('/acces', name: 'app_site_access_unlock', methods: ['GET', 'POST'])]
    public function unlock(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->get(self::SESSION_KEY_UNLOCKED, false) === true) {
            return $this->redirectToRoute('app_planning_index');
        }

        if ($request->isMethod('POST')) {
            $pin = preg_replace('/\D+/', '', (string) $request->request->get('pin', ''));

            if (in_array($pin, self::ALLOWED_PINS, true)) {
                $session->set(self::SESSION_KEY_UNLOCKED, true);
                $session->set(
                    self::SESSION_KEY_USER_GREETING,
                    $pin === '1720' ? 'Salut Alice !' : 'Salut Guigui !'
                );

                return $this->redirectToRoute('app_planning_index');
            }

            $this->addFlash('error', 'Code PIN invalide.');

            return $this->redirectToRoute('app_site_access_unlock');
        }

        return $this->render('security/site_access.html.twig');
    }

    #[Route('/acces/verrouiller', name: 'app_site_access_lock', methods: ['POST'])]
    public function lock(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $session->set(self::SESSION_KEY_UNLOCKED, false);
        $session->remove(self::SESSION_KEY_USER_GREETING);
        $this->addFlash('success', 'Le site est verrouillé.');

        return $this->redirectToRoute('app_site_access_unlock');
    }
}

