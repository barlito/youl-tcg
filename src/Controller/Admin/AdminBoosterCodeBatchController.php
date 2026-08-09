<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Repository\BoosterCodeRepository;
use App\Service\Booster\BoosterCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Batch generation of redeemable codes. A batch is not an entity: the codes
 * share a free-form label, which is enough to list, export and revoke them
 * together — reusing a label simply grows the batch.
 *
 * Registered as a real admin route (#[AdminRoute], path appended to the
 * dashboard's): the pages get plain URLs — /admin/booster-codes/batch — and
 * the admin context, without the legacy ?routeName= redirect.
 */
#[AdminRoute(path: '/booster-codes', name: 'booster_codes')]
class AdminBoosterCodeBatchController extends AbstractController
{
    private const int MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BoosterCodeGenerator $boosterCodeGenerator,
        private readonly BoosterCodeRepository $boosterCodeRepository,
    ) {
    }

    #[AdminRoute(path: '/batch', name: 'batch')]
    public function __invoke(Request $request): Response
    {
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

            return $this->redirectToRoute('admin_booster_codes_batch', ['batch' => $data['batchLabel']]);
        }

        $batchLabel = $request->query->getString('batch');

        return $this->render('admin/booster_code_batch.html.twig', [
            'form' => $form->createView(),
            'batchLabel' => '' !== $batchLabel ? $batchLabel : null,
            'batchCodes' => '' !== $batchLabel ? $this->boosterCodeRepository->findByBatch($batchLabel) : [],
        ]);
    }

    /**
     * CSV of a whole batch — what actually gets pasted into a Discord DM or a
     * giveaway sheet.
     */
    #[AdminRoute(path: '/batch/export', name: 'batch_export')]
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
