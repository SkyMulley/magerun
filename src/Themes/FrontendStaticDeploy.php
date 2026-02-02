<?php
/**
 * Created by Qoliber
 *
 * @author      Lukasz Owczarczuk <lowczarczuk@qoliber.com>
 */

namespace Qoliber\Magerun\Themes;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class FrontendStaticDeploy extends AbstractMagentoCommand
{
    /** @var \Symfony\Component\Console\Output\OutputInterface|null  */
    private ?OutputInterface $output = null;

    /**
     * Known binary names for the elgentos Go static deploy tool
     */
    private const GO_BINARY_NAMES = [
        'static-deploy',
        'magento2-static-deploy',
    ];

    /**
     *  Configure Command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('qoliber:magerun:frontenddeploy')
            ->setDescription('Deploy frontend styles')
            ->addOption(
                'no-go',
                null,
                InputOption::VALUE_NONE,
                'Disable Go binary acceleration (use native Magento deploy)'
            )
            ->addOption(
                'go-binary',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to the elgentos static-deploy Go binary'
            );
    }

    /**
     * Execute Command
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $useGoBinary = !$input->getOption('no-go');
        $goBinaryPath = $input->getOption('go-binary');

        $step = $this->deployStaticContent(AreaCodes::FRONTEND, $useGoBinary, $goBinaryPath);

        if ($step === Command::FAILURE) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function deployStaticContent(
        string $areaCode,
        bool $useGoBinary = true,
        ?string $goBinaryPath = null
    ): int {
        $activeThemes = $this->getActiveThemes($areaCode);
        $activeLocale = $this->getActiveLocale($areaCode);

        if (empty($activeThemes) || empty($activeLocale)) {
            return Command::FAILURE;
        }

        // Try Go binary if enabled
        if ($useGoBinary) {
            $binaryPath = $this->findGoBinary($goBinaryPath);
            if ($binaryPath !== null) {
                $this->output->writeln('<info>Using elgentos Go static-deploy binary for accelerated deployment</info>');
                return $this->deployWithGoBinary($binaryPath, $areaCode, $activeThemes, $activeLocale);
            }
            $this->output->writeln('<comment>Go binary not found, falling back to native Magento deploy</comment>');
        }

        return $this->deployWithMagento($areaCode, $activeThemes, $activeLocale);
    }

    /**
     * Deploy using native Magento command
     */
    private function deployWithMagento(string $areaCode, array $activeThemes, array $activeLocale): int
    {
        $nproc = (int)trim(shell_exec('nproc'));

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
     * Deploy using elgentos Go binary
     *
     * @see https://github.com/elgentos/magento2-static-deploy
     */
    private function deployWithGoBinary(
        string $binaryPath,
        string $areaCode,
        array $activeThemes,
        array $activeLocale
    ): int {
        $magentoRoot = $this->getApplication()->getMagentoRootFolder();

        $command = [
            $binaryPath,
            '-f',
            '-r', $magentoRoot,
            '-a', $areaCode,
        ];

        // Add themes
        foreach ($activeThemes as $theme) {
            $command[] = '-t';
            $command[] = $theme;
        }

        // Add locales as positional arguments at the end
        foreach ($activeLocale as $locale) {
            $command[] = $locale;
        }

        $this->output->writeln('<comment>Running: ' . implode(' ', $command) . '</comment>');

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setTty(Process::isTtySupported());

        $exitCode = $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Find the elgentos Go binary
     */
    private function findGoBinary(?string $configuredPath = null): ?string
    {
        // Check configured path first
        if ($configuredPath !== null && is_executable($configuredPath)) {
            return $configuredPath;
        }

        // Check in Magento root
        $magentoRoot = $this->getApplication()->getMagentoRootFolder();
        foreach (self::GO_BINARY_NAMES as $name) {
            $path = $magentoRoot . '/' . $name;
            if (is_executable($path)) {
                return $path;
            }
        }

        // Check in PATH
        foreach (self::GO_BINARY_NAMES as $name) {
            $which = trim(shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null') ?? '');
            if (!empty($which) && is_executable($which)) {
                return $which;
            }
        }

        return null;
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
        
        // Extract only lines containing --theme flags
        preg_match_all('/--theme\s+([^\s]+)/', $fetchedOutput, $matches);
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
