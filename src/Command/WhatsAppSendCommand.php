<?php

namespace App\Command;

use App\Service\WhatsAppService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:whatsapp:send',
    description: 'Send a WhatsApp text message',
)]
class WhatsAppSendCommand extends Command
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('phone', InputArgument::REQUIRED, 'Recipient phone number (e.g. +380123456789)')
            ->addArgument('message', InputArgument::REQUIRED, 'Message text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phone = $input->getArgument('phone');
        $message = $input->getArgument('message');

        $output->writeln(sprintf('Sending message to %s...', $phone));

        $result = $this->whatsAppService->sendMessage($phone, $message);

        $output->writeln(sprintf('Sent as: %s', $result['sent']));
        $output->writeln('Response:');
        $output->writeln(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
