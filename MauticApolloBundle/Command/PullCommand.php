<?php

namespace MauticPlugin\MauticApolloBundle\Command;

use MauticPlugin\MauticApolloBundle\Service\ApolloApiClient;
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
    private LoggerInterface $logger;

    public function __construct(
        ApolloApiClient $client,
        SyncService $syncService,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->client       = $client;
        $this->syncService  = $syncService;
        $this->leadModel    = $leadModel;
        $this->companyModel = $companyModel;
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

        try {
            $count = $this->syncService->pullFromApollo();
            $output->writeln(sprintf('<info>Imported/updated %d records.</info>', $count));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Apollo pull failed', ['exception' => $e]);
            $output->writeln('<error>Pull failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
