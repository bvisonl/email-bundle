<?php

namespace Bvisonl\EmailBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteOldEmailsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('bvisonl:email:delete')
            ->setDescription('Delete the emails older than the days specified')
            ->addArgument('days', InputArgument::REQUIRED, 'The amount of days behind of emails to keep.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $days = $input->getArgument('days');

        $sixtyDays = new \DateTime();
        $sixtyDays->sub(new \DateInterval("P".$days."D"));

        /** @var EntityManagerInterface $em */
        $em = $this->getContainer()->get('doctrine')->getManager();
        $qb = $em->createQueryBuilder();
        $qb->delete('BvisonlEmailBundle:Email','e');
        $qb->where('e.date <= :sixtyDays')->setParameter('sixtyDays', $sixtyDays);
        $rows = $qb->getQuery()->execute();
        $output->writeln("Finished deleting emails (".$rows.")");

    }
}