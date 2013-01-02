<?php
namespace HTS\Command;

use HTS\Processor\HtsProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HtsCommand extends Command
{
    protected function configure()
    {
        $this->setName('hts-crawler');
        $this->setDescription('Crawling to http://hts.usitc.gov/by-chapter.html');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $hts = new HtsProcessor('http://hts.usitc.gov/by_chapter.html');
        $output->writeln(print_r($hts->catchLinksChapters()));
        //$output->writeln($hts->catchLinksChapters());
    }

}
