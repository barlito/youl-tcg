<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Enum\Trade\TradeOfferStatusEnum;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Audit of the P2P trades: every offer whatever its outcome, lines in clear
 * (the player-side masking rule does not apply to admins).
 *
 * @extends AbstractReadOnlyCrudController<TradeOffer>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class TradeOfferCrudController extends AbstractReadOnlyCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TradeOffer::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Échange')
            ->setEntityLabelInPlural('Échanges')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['proposer.username', 'proposer.discordId', 'receiver.username', 'receiver.discordId'])
            ->setTimezone('Europe/Paris')
            ->setHelp(Crud::PAGE_INDEX, 'Journal d\'audit des offres d\'échange entre joueurs, quel que soit leur sort. La recherche porte sur les deux joueurs.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('proposer', 'Proposeur'))
            ->add(EntityFilter::new('receiver', 'Destinataire'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->statusChoices()))
            ->add(DateTimeFilter::new('createdAt', 'Proposé le'))
        ;
    }

    /**
     * The lines column reads every card of every offer: fetch-joined, or the
     * listing fires one query per offer plus one per card.
     */
    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // aliases prefixed on purpose: EasyAdmin names its search/sort joins after the property
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->addSelect('tradeLine', 'tradeCard', 'tradeProposer', 'tradeReceiver')
            ->leftJoin('entity.lines', 'tradeLine')
            ->leftJoin('tradeLine.card', 'tradeCard')
            ->leftJoin('entity.proposer', 'tradeProposer')
            ->leftJoin('entity.receiver', 'tradeReceiver')
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt')->setLabel('Proposé le');
        yield TextField::new('proposer.username')->setLabel('Proposeur');
        yield TextField::new('receiver.username')->setLabel('Destinataire');
        // virtual: EasyAdmin stringifies the enum column before any formatter runs
        yield Field::new('statusBadge')
            ->setLabel('Statut')
            ->setVirtual(true)
            ->setSortable(false)
            ->setTemplatePath('admin/field/trade_status.html.twig')
            ->formatValue(fn ($value, $entity): ?array => $entity instanceof TradeOffer ? $this->statusBadge($entity->getStatus()) : null)
        ;
        yield DateTimeField::new('resolvedAt')->setLabel('Résolu le');
        // virtual field: the lines are rendered by our template, per side
        yield Field::new('tradeLines')
            ->setLabel('Cartes')
            ->setSortable(false)
            ->setVirtual(true)
            ->setTemplatePath('admin/field/trade_lines.html.twig')
            ->formatValue(fn ($value, $entity): array => $this->linesData($entity, Crud::PAGE_INDEX === $pageName))
        ;
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
        yield TextField::new('proposer.discordId')->setLabel('Discord ID du proposeur')->onlyOnDetail();
        yield TextField::new('receiver.discordId')->setLabel('Discord ID du destinataire')->onlyOnDetail();
    }

    /**
     * @return array{label: string, badge: string}
     */
    private function statusBadge(TradeOfferStatusEnum $status): array
    {
        return [
            'label' => $status->label(),
            'badge' => match ($status) {
                TradeOfferStatusEnum::PENDING => 'warning',
                TradeOfferStatusEnum::ACCEPTED => 'success',
                TradeOfferStatusEnum::REFUSED => 'danger',
                TradeOfferStatusEnum::CANCELLED, TradeOfferStatusEnum::INVALIDATED => 'secondary',
            },
        ];
    }

    /**
     * @return array<string, string> label => backing value (the enum column compares on it)
     */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (TradeOfferStatusEnum::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }

    /**
     * @return array{compact: bool, offeredCount: int, requestedCount: int, sides: list<array{label: string, cards: list<array{name: string, rarity: string, normal: int, holo: int, unique: bool, url: string}>}>}
     */
    private function linesData(mixed $entity, bool $compact): array
    {
        if (!$entity instanceof TradeOffer) {
            throw new UnexpectedTypeException($entity, TradeOffer::class);
        }

        $count = static fn (array $lines): int => array_sum(array_map(static fn (TradeOfferLine $line): int => $line->getTotalQuantity(), $lines));

        return [
            'compact' => $compact,
            'offeredCount' => $count($entity->getOfferedLines()),
            'requestedCount' => $count($entity->getRequestedLines()),
            'sides' => $compact ? [] : [
                ['label' => \sprintf('Offert par %s', $entity->getProposer()->getUsername()), 'cards' => $this->rows($entity->getOfferedLines())],
                ['label' => \sprintf('Demandé à %s', $entity->getReceiver()->getUsername()), 'cards' => $this->rows($entity->getRequestedLines())],
            ],
        ];
    }

    /**
     * @param list<TradeOfferLine> $lines
     *
     * @return list<array{name: string, rarity: string, normal: int, holo: int, unique: bool, url: string}>
     */
    private function rows(array $lines): array
    {
        return array_map(fn (TradeOfferLine $line): array => [
            'name' => $line->getCard()->getName(),
            'rarity' => $line->getCard()->getRarity()->value,
            'normal' => $line->getNormalQuantity(),
            'holo' => $line->getHoloQuantity(),
            'unique' => $line->getCard()->isUnique(),
            'url' => $this->adminUrlGenerator
                ->setController(CardCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($line->getCard()->getId())
                ->generateUrl(),
        ], $lines);
    }
}
