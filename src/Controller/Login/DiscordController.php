<?php

declare(strict_types=1);

namespace App\Controller\Login;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class DiscordController extends AbstractController
{
    use TargetPathTrait;

    /**
     * Link to this controller to start the "connect" process
     */
    #[Route('/connect/discord', name: 'connect_discord_start')]
    public function connectAction(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry
            ->getClient('discord')
            ->redirect([
                'identify',
            ])
        ;
    }

    /**
     * After going to Discord, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[Route('/connect/discord/check', name: 'connect_discord_check')]
    public function connectCheckAction(Request $request, ClientRegistry $clientRegistry): void
    {
        // ** if you want to *authenticate* the user, then
        // leave this method blank and create a Guard authenticator
        // (read below)
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(): never
    {
        // controller can be blank: it will never be called!

        throw new \Exception('Don\'t forget to activate logout in security.yaml');
    }
}
