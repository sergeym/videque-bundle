<?php

namespace Sergeym\VidequeBundle\Command;

use Fit\CommentBundle\Model\CommentInterface;
use Fit\NewsBundle\Entity\Comment;
use Sergeym\VidequeBundle\Provider\VidequeProvider;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('videque:test')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'test id', null)
            ->setDescription('Test videque consumer')
            ->setHelp(
                <<<EOT
                    The <info>%command.name%</info> tests ideque consumer.

<info>php %command.full_name% [--id=...]<</info>

EOT
            );
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');

        $this->getContainer()->get('rs_queue.producer')->produce(VidequeProvider::QUEUE_NAME, [
            'id' => $id
        ]);

        $output->writeln('Complete');
    }

}
