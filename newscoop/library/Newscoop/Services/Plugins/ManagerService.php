<?php
/**
 * @package Newscoop
 * @copyright 2013 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\Services\Plugins;

use Doctrine\ORM\EntityManager;
use Newscoop\EventDispatcher\EventDispatcher;
use Newscoop\EventDispatcher\Events\GenericEvent;
use Newscoop\Entity\Plugin;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Composer\Package\PackageInterface\PackageInterface;
use Symfony\Bridge\Monolog\Logger;

/**
 * Plugins Manager Service
 *
 * Manage plugins installation, status and more...
 */
class ManagerService
{
    /** 
     * @var Doctrine\ORM\EntityManager 
     */
    private $em;

    /**
     * @var Newscoop\EventDispatcher\EventDispatcher
     */
    private $dispatcher;

    /**
     * Plugins service
     * @var Newscoop\Services\Plugins\PluginsService
     */
    private $pluginsService;

    /**
     * Logger
     * @var Symfony\Bridge\Monolog\Logger
     */
    private $logger;

    /**
     * Newscoop root directory
     * @var string
     */
    private $newsoopDir;

    /**
     * Plugins directory
     * @var string
     */
    public $pluginsDir;

    /**
     * @param Doctrine\ORM\EntityManager $em
     * @param Newscoop\EventDispatcher\EventDispatcher $dispatcher
     */
    public function __construct(EntityManager $em, $dispatcher, $pluginsService, Logger $logger)
    {
        $this->em = $em;
        $this->dispatcher = $dispatcher;
        $this->pluginsService = $pluginsService;
        $this->logger = $logger;
        $this->newsoopDir = __DIR__ . '/../../../../';
        $this->pluginsDir = $this->newsoopDir . 'plugins';
    }

    public function installPlugin($pluginName, $version, $output = null, $notify = true)
    {
        $this->installComposer();

        $pluginMeta = explode('/', $pluginName);
        if(count($pluginMeta) !== 2) {
            throw new \Exception("Plugin name is invalid, try \"vendor/plugin-name\"", 1);
        }

        $process = new Process('cd ' . $this->newsoopDir . ' && php composer.phar require --no-update ' . $pluginName .':' . $version .' && php composer.phar update ' . $pluginName .'  --prefer-dist --no-dev');

        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use ($output) {
            if ('err' === $type) {
                $output->write('<error>'.$buffer.'</error>');
            } else {
                $output->write('<info>'.$buffer.'</info>');
            }
        });

        if (!$process->isSuccessful()) {
            throw new \Exception("Error with installing plugin", 1);
        }

        $this->saveAvaiablePluginsToCacheFile();

        $this->clearCache($output);

        $cachedPluginMeta = $this->newsoopDir.'/cache/plugins/add_'.str_replace('/', '-', $pluginName).'_package.json';
        if (file_exists($cachedPluginMeta)) {
            $pluginMeta = json_decode(file_get_contents($cachedPluginMeta), true);

            $pluginDetails = file_get_contents($this->pluginsDir.'/'.$pluginMeta['targetDir'].'/composer.json');

            $this->em->getRepository('Newscoop\Entity\Plugin')->addPlugin($pluginMeta, $pluginDetails);

            // clear cache files
            $filesystem = new Filesystem();
            $filesystem->remove($cachedPluginMeta);
        }

        if ($notify) {
            $process = new Process('cd ' . $this->newsoopDir . ' && php application/console plugins:dispatch ' . $pluginName.' install');

            $process->setTimeout(3600);
            $process->run(function ($type, $buffer) use ($output) {
                if ('err' === $type) {
                    $output->write('<error>'.$buffer.'</error>');
                } else {
                    $output->write('<info>'.$buffer.'</info>');
                }
            });

            if (!$process->isSuccessful()) {
                throw new \Exception("Error with dispatching install event", 1);
            }
        }
    }

    public function dispatchEventForPlugin($pluginName, $eventName, $output) {
        $output->writeln('<info>Notify '.$pluginName.' plugin listeners with '.$eventName.' event</info>');
        $this->dispatcher->dispatch('plugin.'.$eventName, new GenericEvent($this, array(
            'plugin_name' => $pluginName
        )));

        $this->dispatcher->dispatch(
            'plugin.'.$eventName.'.'.str_replace('-', '_', str_replace('/', '_', $pluginName)), 
            new GenericEvent($this, array(
                'plugin_name' => $pluginName
            ))
        );
    }

    public function removePlugin($pluginName, $output, $notify = true)
    {
        $this->installComposer();

        /*if (!$this->isInstalled($pluginName)) {
            $output->writeln('<info>Plugin "'.$pluginName.'" is not installed yet</info>');

            return;
        }*/

        $composerFile = $this->newsoopDir . 'composer.json';
        $composerDefinitions = json_decode(file_get_contents($composerFile), true);
        
        foreach ($composerDefinitions['require'] as $package => $version) {
            if ($package == $pluginName) {

                if ($notify) {
                    $output->writeln('<info>Notify '.$pluginName.' plugin listeners</info>');
                    $this->dispatcher->dispatch('plugin.remove', new GenericEvent($this, array(
                        'plugin_name' => $pluginName
                    )));

                    $this->dispatcher->dispatch(
                        'plugin.remove.'.str_replace('-', '_', str_replace('/', '_', $pluginName)), 
                        new GenericEvent($this, array(
                            'plugin_name' => $pluginName
                        ))
                    );
                }

                $output->writeln('<info>Remove "'.$pluginName.'" from composer.json file</info>');
                unset($composerDefinitions['require'][$package]);

                file_put_contents($composerFile, \Newscoop\Gimme\Json::indent(json_encode($composerDefinitions)));

                $process = new Process('cd ' . $this->newsoopDir . ' && php composer.phar update --no-dev ' . $pluginName);
                $process->setTimeout(3600);
                $process->run(function ($type, $buffer) use ($output) {
                    if ('err' === $type) {
                        $output->write('<error>'.$buffer.'</error>');
                    } else {
                        $output->write('<info>'.$buffer.'</info>');
                    }
                });

                if (!$process->isSuccessful()) {
                    throw new \Exception("Error with removing plugin", 1);
                }
            }
        }

        $cachedPluginMeta = $this->newsoopDir.'/cache/plugins/uninstall_'.str_replace('/', '-', $pluginName).'_package.json';

        if (file_exists($cachedPluginMeta)) {
            $pluginMeta = json_decode(file_get_contents($cachedPluginMeta), true);

            $this->em->getRepository('Newscoop\Entity\Plugin')->removePlugin($pluginName);

            // clear cache files
            $filesystem = new Filesystem();
            $filesystem->remove($cachedPluginMeta);
            $filesystem->remove($this->pluginsDir.'/'.$pluginMeta['targetDir'].'/');
        }

        $this->saveAvaiablePluginsToCacheFile();

        $this->clearCache($output);
    }

    public function updatePlugin($pluginName, $version, $output, $notify = true)
    {
        $this->installComposer();

        /*if (!$this->isInstalled($pluginName)) {
            $output->writeln('<info>Plugin "'.$pluginName.'" is not installed yet</info>');

            return;
        }*/
        $output->writeln('<info>Update "'.$pluginName.'"</info>');sleep(10);
        $process = new Process('cd ' . $this->newsoopDir . ' && php composer.phar update --prefer-dist --no-dev ' . $pluginName);
        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use ($output) {
            if ('err' === $type) {
                $output->write('<error>'.$buffer.'</error>');
            } else {
                $output->write('<info>'.$buffer.'</info>');
            }
        });

        if (!$process->isSuccessful()) {
            throw new \Exception("Error with updating plugin", 1);
        }

        $this->saveAvaiablePluginsToCacheFile();

        $this->clearCache($output);

        if ($notify) {
            $process = new Process('cd ' . $this->newsoopDir . ' && php application/console plugins:dispatch ' . $pluginName.' update --env=prod');

            $process->setTimeout(3600);
            $process->run(function ($type, $buffer) use ($output) {
                if ('err' === $type) {
                    $output->write('<error>'.$buffer.'</error>');
                } else {
                    $output->write('<info>'.$buffer.'</info>');
                }
            });

            if (!$process->isSuccessful()) {
                throw new \Exception("Error with dispatching update event", 1);
            }
        }

        $cachedPluginMeta = $this->newsoopDir.'/cache/plugins/update_'.str_replace('/', '-', $pluginName).'_package.json';

        if (file_exists($cachedPluginMeta)) {
            $pluginMeta = json_decode(file_get_contents($cachedPluginMeta), true);
            $pluginDetails = file_get_contents($this->pluginsDir.'/'.$pluginMeta['target']['targetDir'].'/composer.json');

            $this->em->getRepository('Newscoop\Entity\Plugin')->updatePlugin($pluginMeta['target'], $pluginDetails);

            // clear cache files
            $filesystem = new Filesystem();
            $filesystem->remove($cachedPluginMeta);
        }
    }

    public function enablePlugin(Plugin $plugin)
    {
        $this->dispatcher->dispatch('plugin.enable', new GenericEvent($this, array(
            'plugin_name' => $plugin->getName(),
            'plugin' => $plugin
        )));
    }

    public function disablePlugin(Plugin $plugin)
    {
        $this->dispatcher->dispatch('plugin.disable', new GenericEvent($this, array(
            'plugin_name' => $plugin->getName(),
            'plugin' => $plugin
        )));
    }

    public function upgrade()
    {
        //add and install all plugins from avaiable_plugins.json after newscoop upgrade
    }

    public function getInstalledPlugins()
    {
        $cachedAvailablePlugins = $this->pluginsDir . '/avaiable_plugins.json';
        if (!file_exists($cachedAvailablePlugins)) {
            return array();
        }

        return $plugins = json_decode(file_get_contents($cachedAvailablePlugins));
    }

    public function isInstalled($pluginName)
    {
        $installedPlugins = $this->getInstalledPlugins();
    }

    private function clearCache($output)
    {
        $process = new Process('cd '.$this->newsoopDir.' && rm -rf cache/prod/* && php application/console cache:clear --env=prod');
        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) use ($output) {
            if ('err' === $type) {
                $output->write('<error>'.$buffer.'</error>');
            } else {
                $output->write('<info>'.$buffer.'</info>');
            }
        });

        if ($process->isSuccessful()) {
            $output->writeln('<info>Cache cleared</info>');
        }
    }

    public function installComposer()
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->newsoopDir . 'composer.phar')) {
            $installComposer = new Process('cd ' . $this->newsoopDir . ' && curl -s https://getcomposer.org/installer | php');
            $installComposer->setTimeout(3600);
            $installComposer->run();

            if (!$installComposer->isSuccessful()) {
                throw new \Exception("Error with installing composer", 1);
            }
        }
    }

    public function findAvaiablePlugins()
    {
        $plugins = array();
        $finder = new Finder();
        $elements = $finder->directories()->depth('== 0')->in($this->pluginsDir);
        if (count($elements) > 0) {
            foreach ($elements as $element) {
                $vendorName = $element->getFileName();
                $secondFinder = new Finder();
                $directories = $secondFinder->directories()->depth('== 0')->in($element->getPathName());
                foreach ($directories as $directory) {
                    $pluginName = $directory->getFileName();
                    $className = $vendorName . '\\' .$pluginName . '\\' . $vendorName . $pluginName;
                    $pos = strpos($pluginName, 'Bundle');
                    if ($pos !== false) {
                        $plugins[] = $className;
                    }
                }
            }
        }

        return $plugins;
    }

    private function saveAvaiablePluginsToCacheFile()
    {
        $plugins = $this->findAvaiablePlugins();

        file_put_contents($this->pluginsDir . '/avaiable_plugins.json', json_encode($plugins));
    }
}
