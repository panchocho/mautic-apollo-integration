<?php

namespace MauticPlugin\MauticApolloBundle\Command;

use MauticPlugin\MauticApolloBundle\Exception\ApolloQuotaExceededException;
use MauticPlugin\MauticApolloBundle\Service\ApolloApiClient;
use MauticPlugin\MauticApolloBundle\Service\SyncContext;
use MauticPlugin\MauticApolloBundle\Service\SyncService;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PullCommand extends Command
{
    protected static $defaultName = 'mautic:apollo:pull';

    private ApolloApiClient $client;
    private SyncService $syncService;
    private LeadModel $leadModel;
    private CompanyModel $companyModel;
    private SyncContext $syncContext;
    private LoggerInterface $logger;

    public function __construct(
        ApolloApiClient $client,
        SyncService $syncService,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        SyncContext $syncContext,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->client       = $client;
        $this->syncService  = $syncService;
        $this->leadModel    = $leadModel;
        $this->companyModel = $companyModel;
        $this->syncContext  = $syncContext;
        $this->logger       = $logger;
    }

    protected function configure(): void
    {
        $this->setName('mautic:apollo:pull');
        $this->setDescription('Pull incremental contacts/companies from Apollo into Mautic.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->client->isConfigured()) {
            $output->writeln('<comment>Apollo integration not configured.</comment>');
            return Command::FAILURE;
        }

        $this->syncContext->beginApolloImport();

        try {
            $count = $this->syncService->pullFromApollo();
            $output->writeln(sprintf('<info>Imported/updated %d records.</info>', $count));
            $this->syncContext->endApolloImport();
            return Command::SUCCESS;
        } catch (ApolloQuotaExceededException $e) {
            $this->syncContext->endApolloImport();
            $this->logger->warning('Apollo pull paused because credits are exhausted', ['exception' => $e]);
            $output->writeln('<comment>Apollo credits exhausted; pull paused until quota is available again.</comment>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->syncContext->endApolloImport();
            $this->logger->error('Apollo pull failed', ['exception' => $e]);
            $output->writeln('<error>Pull failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
