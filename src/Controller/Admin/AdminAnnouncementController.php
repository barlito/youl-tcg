<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\AnnouncementDraft;
use App\Entity\DiscordUser;
use App\Exception\Notification\NotificationRefusedException;
use App\Form\Admin\AnnouncementType;
use App\Repository\AnnouncementRepository;
use App\Service\Notification\AnnouncementService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin announcements: plain text sent to every player or a selection, live
 * (toast + bell). The full send log is the read-only AnnouncementCrudController.
 */
#[AdminRoute(path: '/announcements', name: 'announcements')]
class AdminAnnouncementController extends AbstractController
{
    private const int HISTORY_SIZE = 10;

    public function __construct(
        private readonly AnnouncementService $announcementService,
        private readonly AnnouncementRepository $announcementRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(AnnouncementType::class, new AnnouncementDraft());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var AnnouncementDraft $draft */
            $draft = $form->getData();
            $author = $this->getUser();

            try {
                $announcement = $this->announcementService->send($draft, $author instanceof DiscordUser ? $author : null);
            } catch (NotificationRefusedException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('admin_announcements');
            }

            $this->addFlash('success', \sprintf(
                'Annonce « %s » envoyée — %s.',
                $announcement->getTitle(),
                $announcement->getTargetLabel(),
            ));

            return $this->redirectToRoute('admin_announcements');
        }

        return $this->render('admin/announcement.html.twig', [
            'form' => $form, // a FormInterface makes an invalid submission answer 422
            'history' => $this->announcementRepository->findLatest(self::HISTORY_SIZE),
        ]);
    }
}
