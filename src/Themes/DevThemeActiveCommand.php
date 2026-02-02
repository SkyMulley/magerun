<?php
/**
 * Created by Qoliber
 *
 * @author      Lukasz Owczarczuk <lowczarczuk@qoliber.com>
 */

namespace Qoliber\Magerun\Themes;

use Magento\Framework\App\ObjectManager;
use N98\Magento\Command\AbstractMagentoCommand;
use N98\Util\Console\Helper\Table\Renderer\RendererFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DevThemeActiveCommand extends AbstractMagentoCommand
{
    /**
     * Configure Command
     *
     * @return void
     */
    protected function configure(): void
    {
      $this
          ->setName('qoliber:magerun:theme:active')
          ->setDescription('Get list of used themes')
          ->addOption(
              'format',
              null,
              InputOption::VALUE_OPTIONAL,
              'Output Format. One of [' . implode(',', RendererFactory::getFormats()) . ']'
          )

          ->addOption(
              'area',
              null,
              InputOption::VALUE_OPTIONAL,
              'Area codes. One of [' . implode(',', [AreaCodes::ADMINHTML, AreaCodes::FRONTEND])
              . ']'
          )
      ;
    }

    /**
     * Execute Command
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|void
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output);

        if ($this->initMagento()) {
            $objectManager = ObjectManager::getInstance(); // Instance of object manager
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();

            $themeTableName = $resource->getTableName('theme'); //gives table name with prefix
            $configTableName = $resource->getTableName('core_config_data');
            $area = $input->getOption('area');
            $whereArea = '';
            if (!empty($area) && in_array($area, [AreaCodes::ADMINHTML, AreaCodes::FRONTEND])) {
                $whereArea = ' and `area`=\''.$area.'\'';
            }
            $sql = sprintf('SELECT DISTINCT theme_path FROM `%s` LEFT JOIN `%s` ON `value` = `theme_id` WHERE `path`=\'design/theme/theme_id\'%s',
                $themeTableName, $configTableName, $whereArea);
            $res = $connection->fetchCol($sql);

            if (is_null($area) || $area === AreaCodes::FRONTEND) {
                $hyvaSql = sprintf('SELECT DISTINCT value FROM `%s` where path = \'hyva_theme_fallback/general/theme_full_path\'', $configTableName);
                $hyvaRes = $connection->fetchCol($hyvaSql);

                if (!empty($hyvaRes)) {
                    foreach ($hyvaRes as $hs) {
                        $themeParts = explode('/', $hs);
                        $res[] = sprintf('%s/%s', $themeParts[1],  $themeParts[2]);
                    }
                }

                if (!count($res)) {
                    $res[] = 'Magento/luma';
                }
            }

            // Get admin theme from Design model if adminhtml area is requested
            if (is_null($area) || $area === AreaCodes::ADMINHTML) {
                try {
                    $design = $objectManager->get(\Magento\Theme\Model\View\Design::class);
                    $output->writeln('<comment>[DEBUG] Design class: ' . get_class($design) . '</comment>');

                    $adminTheme = $design->getConfigurationDesignTheme(AreaCodes::ADMINHTML);
                    $output->writeln('<comment>[DEBUG] getConfigurationDesignTheme returned: ' . var_export($adminTheme, true) . '</comment>');
                    $output->writeln('<comment>[DEBUG] Type: ' . gettype($adminTheme) . '</comment>');

                    if ($adminTheme) {
                        // If it's a theme ID, resolve to theme path
                        if (is_numeric($adminTheme)) {
                            $output->writeln('<comment>[DEBUG] adminTheme is numeric, resolving ID: ' . $adminTheme . '</comment>');
                            $themeRepo = $objectManager->get(\Magento\Framework\View\Design\Theme\ThemeProviderInterface::class);
                            $theme = $themeRepo->getThemeById($adminTheme);
                            $output->writeln('<comment>[DEBUG] Theme object: ' . ($theme ? get_class($theme) : 'null') . '</comment>');
                            if ($theme) {
                                $output->writeln('<comment>[DEBUG] Theme path: ' . var_export($theme->getThemePath(), true) . '</comment>');
                            }
                            if ($theme && $theme->getThemePath()) {
                                $res[] = $theme->getThemePath();
                            } else {
                                $output->writeln('<comment>[DEBUG] Falling back to Magento/backend (no theme path)</comment>');
                                $res[] = 'Magento/backend';
                            }
                        } else {
                            $output->writeln('<comment>[DEBUG] adminTheme is string: ' . $adminTheme . '</comment>');
                            $res[] = $adminTheme;
                        }
                    } else {
                        $output->writeln('<comment>[DEBUG] adminTheme is empty/null, falling back to Magento/backend</comment>');
                        $res[] = 'Magento/backend';
                    }

                    // Also check what themes exist in the theme table for adminhtml
                    $adminThemesSql = sprintf('SELECT theme_id, theme_path FROM `%s` WHERE area = \'adminhtml\'', $themeTableName);
                    $adminThemesInDb = $connection->fetchAll($adminThemesSql);
                    $output->writeln('<comment>[DEBUG] Admin themes in DB: ' . json_encode($adminThemesInDb) . '</comment>');

                } catch (\Exception $e) {
                    // Fallback to Magento/backend if detection fails
                    $output->writeln('<error>[DEBUG] Exception: ' . $e->getMessage() . '</error>');
                    $res[] = 'Magento/backend';
                }
            }

            if (!$input->getOption('format')) {
                $out = array();

                foreach ($res as $t) {
                    $out[] = '--theme ' . $t;
                }

                $output->writeln(implode(' ', array_unique($out)));
            }

            if ($input->getOption('format') == 'json') {
                $output->writeln(
                    json_encode($res, JSON_PRETTY_PRINT)
                );
            }
            return Command::SUCCESS;
        } else {
            return Command::FAILURE;
        }
    }
}
