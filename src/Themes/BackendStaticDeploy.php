<?php
/**
 * Created by Qoliber
 *
 * @author      Lukasz Owczarczuk <lowczarczuk@qoliber.com>
 */

namespace Qoliber\Magerun\Themes;

use Magento\Framework\App\ObjectManager;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class BackendStaticDeploy extends AbstractMagentoCommand
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
     * Hyvä parent theme paths that indicate a theme is Hyvä-based
     */
    private const HYVA_PARENT_THEMES = [
        'Hyva/default',
        'Hyva/reset',
        'Hyva/default-csp',
        'Hyva/commerce',
    ];

    /**
     *  Configure Command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('qoliber:magerun:backenddeploy')
            ->setDescription('Deploy backend styles')
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

        $step = $this->deployStaticContent(AreaCodes::ADMINHTML, $useGoBinary, $goBinaryPath);

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

        // Expand active themes to include all parent themes
        $allThemes = $this->expandThemesWithParents($activeThemes);
        $this->output->writeln('<info>Themes to deploy (including parents): ' . implode(', ', $allThemes) . '</info>');

        // Try Go binary if enabled
        if ($useGoBinary) {
            $binaryPath = $this->findGoBinary($goBinaryPath);
            if ($binaryPath !== null) {
                $this->output->writeln('<info>Using elgentos Go static-deploy binary for accelerated deployment</info>');
                return $this->deployWithGoBinary($binaryPath, $areaCode, $allThemes, $activeLocale);
            }
            $this->output->writeln('<comment>Go binary not found, falling back to native Magento deploy</comment>');
        }

        return $this->deployWithMagento($areaCode, $allThemes, $activeLocale);
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

        // Separate Hyvä and Luma themes
        $hyvaThemes = [];
        $lumaThemes = [];

        foreach ($activeThemes as $theme) {
            if ($this->isHyvaTheme($theme)) {
                $hyvaThemes[] = $theme;
                $this->output->writeln("<info>Detected Hyvä theme: $theme</info>");
            } else {
                $lumaThemes[] = $theme;
                $this->output->writeln("<comment>Detected Luma theme: $theme</comment>");
            }
        }

        $result = Command::SUCCESS;

        // Deploy Hyvä themes with --no-luma-dispatch for fast file copying
        if (!empty($hyvaThemes)) {
            $this->output->writeln('<info>Deploying Hyvä themes with fast file copying...</info>');
            $command = [
                $binaryPath,
                '-f',
                '-r', $magentoRoot,
                '-a', $areaCode,
                '--no-luma-dispatch',
            ];

            foreach ($hyvaThemes as $theme) {
                $command[] = '-t';
                $command[] = $theme;
            }

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

            if ($exitCode !== 0) {
                $result = Command::FAILURE;
            }
        }

        // Deploy Luma themes (Go binary will dispatch to Magento)
        if (!empty($lumaThemes)) {
            $this->output->writeln('<info>Deploying Luma themes...</info>');
            $command = [
                $binaryPath,
                '-f',
                '-r', $magentoRoot,
                '-a', $areaCode,
            ];

            foreach ($lumaThemes as $theme) {
                $command[] = '-t';
                $command[] = $theme;
            }

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

            if ($exitCode !== 0) {
                $result = Command::FAILURE;
            }
        }

        return $result;
    }

    /**
     * Check if a theme is Hyvä-based by traversing the parent chain
     */
    private function isHyvaTheme(string $themePath): bool
    {
        // Direct match
        if (in_array($themePath, self::HYVA_PARENT_THEMES)) {
            return true;
        }

        try {
            $objectManager = ObjectManager::getInstance();
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();
            $themeTable = $resource->getTableName('theme');

            // Get theme ID and parent ID
            $sql = sprintf(
                'SELECT theme_id, parent_id FROM `%s` WHERE theme_path = ?',
                $themeTable
            );
            $theme = $connection->fetchRow($sql, [$themePath]);

            if (!$theme || empty($theme['parent_id'])) {
                return false;
            }

            // Traverse parent chain (max 10 levels to prevent infinite loops)
            $parentId = $theme['parent_id'];
            $maxDepth = 10;
            $depth = 0;

            while ($parentId && $depth < $maxDepth) {
                $parentSql = sprintf(
                    'SELECT theme_id, parent_id, theme_path FROM `%s` WHERE theme_id = ?',
                    $themeTable
                );
                $parent = $connection->fetchRow($parentSql, [$parentId]);

                if (!$parent) {
                    break;
                }

                // Check if this parent is a Hyvä theme
                if (in_array($parent['theme_path'], self::HYVA_PARENT_THEMES)) {
                    return true;
                }

                $parentId = $parent['parent_id'];
                $depth++;
            }
        } catch (\Exception $e) {
            // If detection fails, assume Luma
            return false;
        }

        return false;
    }

    /**
     * Get all parent themes for a given theme (including the theme itself)
     *
     * @param string $themePath
     * @return string[]
     */
    private function getThemeWithParents(string $themePath): array
    {
        $themes = [$themePath];

        try {
            $objectManager = ObjectManager::getInstance();
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();
            $themeTable = $resource->getTableName('theme');

            // Get theme ID and parent ID
            $sql = sprintf(
                'SELECT theme_id, parent_id FROM `%s` WHERE theme_path = ?',
                $themeTable
            );
            $theme = $connection->fetchRow($sql, [$themePath]);

            if (!$theme || empty($theme['parent_id'])) {
                return $themes;
            }

            // Traverse parent chain (max 10 levels to prevent infinite loops)
            $parentId = $theme['parent_id'];
            $maxDepth = 10;
            $depth = 0;

            while ($parentId && $depth < $maxDepth) {
                $parentSql = sprintf(
                    'SELECT theme_id, parent_id, theme_path FROM `%s` WHERE theme_id = ?',
                    $themeTable
                );
                $parent = $connection->fetchRow($parentSql, [$parentId]);

                if (!$parent || empty($parent['theme_path'])) {
                    break;
                }

                $themes[] = $parent['theme_path'];
                $parentId = $parent['parent_id'];
                $depth++;
            }
        } catch (\Exception $e) {
            // If traversal fails, return what we have
        }

        return $themes;
    }

    /**
     * Expand active themes to include all parent themes
     * Returns themes in correct deployment order: parents first, then children
     *
     * @param array $activeThemes
     * @return array
     */
    private function expandThemesWithParents(array $activeThemes): array
    {
        $allThemeChains = [];

        // Collect all theme chains (each chain goes from child to root parent)
        foreach ($activeThemes as $theme) {
            $themeChain = $this->getThemeWithParents($theme);
            $allThemeChains[] = $themeChain;
        }

        // Build ordered list: parents must come before children
        // We track depth (distance from root) for each theme
        $themeDepths = [];
        foreach ($allThemeChains as $chain) {
            $chainLength = count($chain);
            foreach ($chain as $index => $theme) {
                // Depth is distance from root (last item in chain is root, depth 0)
                $depth = $chainLength - 1 - $index;
                // Keep the maximum depth if theme appears in multiple chains
                if (!isset($themeDepths[$theme]) || $themeDepths[$theme] < $depth) {
                    $themeDepths[$theme] = $depth;
                }
            }
        }

        // Sort by depth ascending (parents/roots first, children last)
        asort($themeDepths);

        return array_keys($themeDepths);
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
