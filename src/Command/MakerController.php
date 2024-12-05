<?php

namespace Dakataa\Crud\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'crud:generate', description: 'Create a new CRUD controller')]
class MakerController extends Command
{
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		
		return Command::SUCCESS;
	}
}
