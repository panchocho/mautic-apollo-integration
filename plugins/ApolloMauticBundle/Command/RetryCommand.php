<?php

namespace Apollo\MauticBundle\Command;

use Apollo\MauticBundle\Service\QueueService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetryCommand extends Command
{
    protected static $defaultName = 'mautic:apollo:retry';

    private QueueService $queueService;
    private LoggerInterface $logger;

    public function __construct(QueueService $queueService, LoggerInterface $logger)
    {
        parent::__construct();
        $this->queueService = $queueService;
        $this->logger       = $logger;
    }

    protected function configure(): void
    {
        $this->setDescription('Reprocess pending Apollo queue items.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $processed = $this->queueService->processPending();
            $output->writeln(sprintf('<info>Processed %d queued payloads.</info>', $processed));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Apollo retry failed', ['exception' => $e]);
            $output->writeln('<error>Retry failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
