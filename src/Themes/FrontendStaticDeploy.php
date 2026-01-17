<?php
/**
 * Created by Qoliber
 *
 * @author      Lukasz Owczarczuk <lowczarczuk@qoliber.com>
 */

namespace Qoliber\Magerun\Themes;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class FrontendStaticDeploy extends AbstractMagentoCommand
{
    /** @var \Symfony\Component\Console\Output\OutputInterface|null  */
    private ?OutputInterface $output = null;

    /** @var \Magento\Indexer\Model\Indexer\CollectionFactory|null  */
    private ?CollectionFactory $indexerCollectionFactory = null;

    /** @var array|null  */
    private ?array $nonScheduledIndexers = null;

    /**
     *  Configure Command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('qoliber:magerun:frontenddeploy')
            ->setDescription('Deploy frontend styles');
    }

    /**
     * Execute Command
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $step = $this->deployStaticContent(AreaCodes::FRONTEND);

        if ($step === Command::FAILURE) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function deployStaticContent(
        string $areaCode
    ): int {
        $nproc = (int)trim(shell_exec('nproc'));

        $activeThemes = $this->getActiveThemes($areaCode);
        $activeLocale = $this->getActiveLocale($areaCode);

        if (empty($activeThemes) || empty($activeLocale)) {
            return Command::FAILURE;
        }

        $input = new ArrayInput([
            'command' => 'setup:static-content:deploy',
            '-a'      => $areaCode,
            '-j'      => $nproc,
            '-t'      => $activeThemes,
            '-l'      => $activeLocale,
            '-f',
            '-s'      => 'quick',
        ]);

        return $this->getApplication()->doRun($input, $this->output);
    }

    /**
     * Get Active Themes For Area
     *
     * @param string $areaCode
     *
     * @return int|string[]
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Throwable
     */
    private function getActiveThemes(string $areaCode): int|array
    {
        if ($areaCode !== AreaCodes::FRONTEND
            && $areaCode !== AreaCodes::ADMINHTML) {
            return Command::FAILURE;
        }
        $input = new ArrayInput([
            'command' => 'qoliber:magerun:theme:active',
            '--area'  => $areaCode,
        ]);

        $output = new BufferedOutput();
        $this->getApplication()->doRun($input, $output);
        $fetchedOutput = $output->fetch();
        $this->output->writeln($fetchedOutput);
        
        // Extract only lines containing --theme flags
        preg_match_all('/--theme\s+([^\s]+)/', $fetchedOutput, $matches);
        $this->output->writeln($matches);
        $themes = $matches[1] ?? [];

        return $themes;
    }

    /**
     * Get Active Locales
     *
     * @param string $areaCode
     *
     * @return int|string[]
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Throwable
     */
    private function getActiveLocale(string $areaCode): int|array
    {
        if ($areaCode !== AreaCodes::FRONTEND
            && $areaCode !== AreaCodes::ADMINHTML) {
            return Command::FAILURE;
        }

        $input = new ArrayInput([
            'command' => 'qoliber:magerun:locale:active',
            '--area'  => $areaCode,
        ]);

        $output = new BufferedOutput();
        $this->getApplication()->doRun($input, $output);
        $fetchedOutput = $output->fetch();
        
        // Extract only valid locale codes (e.g., en_US, fr_FR)
        preg_match_all('/[a-z]{2,3}_[A-Z]{2,4}/', $fetchedOutput, $matches);
        $locales = $matches[0] ?? [];

        return $locales;
    }
}
