<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Repository\BoosterCodeRepository;
use App\Service\Booster\BoosterCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Batch generation of redeemable codes. A batch is not an entity: the codes
 * share a free-form label, which is enough to list, export and revoke them
 * together — reusing a label simply grows the batch.
 */
class AdminBoosterCodeBatchController extends AbstractController
{
    private const int MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly BoosterCodeGenerator $boosterCodeGenerator,
        private readonly BoosterCodeRepository $boosterCodeRepository,
    ) {
    }

    /**
     * $batch comes from the request attributes, not the query string: a custom
     * admin route is reached through the dashboard, which re-injects its
     * parameters as route attributes (see EA's AdminRouterSubscriber).
     */
    #[Route('/admin/booster-codes/batch', name: 'admin_booster_codes_batch')]
    public function __invoke(Request $request, ?string $batch = null): Response
    {
        // The template extends the EasyAdmin layout, which needs the admin
        // context: a direct hit bounces through the dashboard (same trick as
        // the card batch page).
        if (!$request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return $this->redirect(
                $this->adminUrlGenerator->setRoute('admin_booster_codes_batch', $request->query->all())->generateUrl(),
            );
        }

        $form = $this->buildForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{booster: Booster, batchLabel: string, count: int, quantity: int, maxUses: int|null, expiresAt: \DateTimeImmutable|null} $data */
            $data = $form->getData();

            foreach ($this->boosterCodeGenerator->generateBatch($data['count']) as $code) {
                $this->entityManager->persist(
                    new BoosterCode()
                        ->setCode($code)
                        ->setBooster($data['booster'])
                        ->setBatchLabel($data['batchLabel'])
                        ->setQuantity($data['quantity'])
                        ->setMaxUses($data['maxUses'])
                        ->setExpiresAt($data['expiresAt']),
                );
            }

            $this->entityManager->flush();

            $this->addFlash('success', \sprintf(
                '%d code(s) généré(s) dans le lot « %s ».',
                $data['count'],
                $data['batchLabel'],
            ));

            return $this->redirect(
                $this->adminUrlGenerator->setRoute('admin_booster_codes_batch', ['batch' => $data['batchLabel']])->generateUrl(),
            );
        }

        return $this->render('admin/booster_code_batch.html.twig', [
            'form' => $form->createView(),
            'batchLabel' => $batch,
            'batchCodes' => null !== $batch ? $this->boosterCodeRepository->findByBatch($batch) : [],
        ]);
    }

    /**
     * CSV of a whole batch — what actually gets pasted into a Discord DM or a
     * giveaway sheet.
     */
    #[Route('/admin/booster-codes/batch/export', name: 'admin_booster_codes_batch_export')]
    public function export(Request $request): Response
    {
        $batchLabel = $request->query->getString('batch');
        $codes = '' !== $batchLabel ? $this->boosterCodeRepository->findByBatch($batchLabel) : [];

        $handle = fopen('php://temp', 'r+b');
        \assert(false !== $handle);

        fputcsv($handle, ['code', 'booster', 'packs', 'max_uses', 'expire_le', 'lot'], ';', '"', '');

        foreach ($codes as $code) {
            fputcsv($handle, [
                $code->getFormattedCode(),
                $code->getBooster()->getDisplayName(),
                $code->getQuantity(),
                $code->getMaxUses() ?? 'illimité',
                $code->getExpiresAt()?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i') ?? '',
                $code->getBatchLabel() ?? '',
            ], ';', '"', '');
        }

        rewind($handle);
        $response = new Response((string) stream_get_contents($handle));
        fclose($handle);

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', \sprintf('codes-%s.csv', $this->slugifyBatchLabel($batchLabel))),
        );

        return $response;
    }

    /**
     * @return FormInterface<array{booster: Booster, batchLabel: string, count: int, quantity: int, maxUses: int|null, expiresAt: \DateTimeImmutable|null}|null>
     */
    private function buildForm(): FormInterface
    {
        return $this->createFormBuilder()
            ->add('booster', EntityType::class, [
                'class' => Booster::class,
                'choice_label' => 'displayName',
                'label' => 'Booster offert',
                'help' => 'Un pack non récupérable sur le hub reste distribuable par code — c\'est le but.',
            ])
            ->add('batchLabel', TextType::class, [
                'label' => 'Libellé du lot',
                'help' => 'Sert à retrouver, exporter et révoquer les codes ensemble. Réutiliser un libellé agrandit le lot existant.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('count', IntegerType::class, [
                'label' => 'Nombre de codes',
                'data' => 10,
                'constraints' => [new Assert\Range(min: 1, max: self::MAX_BATCH_SIZE)],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Packs crédités par code',
                'data' => 1,
                'constraints' => [new Assert\Range(min: 1, max: 100)],
            ])
            ->add('maxUses', IntegerType::class, [
                'label' => 'Utilisations par code',
                'required' => false,
                'data' => 1,
                'help' => '1 = code nominatif. Vide = illimité. Dans tous les cas, un joueur ne peut utiliser un code qu\'une fois.',
                'constraints' => [new Assert\Range(min: 1, max: 100000)],
            ])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expire le',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'view_timezone' => 'Europe/Paris',
                'model_timezone' => 'UTC',
                'help' => 'Heure de Paris. Vide = pas d\'expiration.',
            ])
            ->getForm()
        ;
    }

    private function slugifyBatchLabel(string $batchLabel): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $batchLabel), '-');

        return '' !== $slug ? mb_strtolower($slug) : 'lot';
    }
}
