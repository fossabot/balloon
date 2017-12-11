<?php

declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPL-3.0 https://opensource.org/licenses/GPL-3.0
 */

namespace Balloon\Bootstrap;

use Balloon\Console\Async;
use Balloon\Console\ConsoleInterface;
use Balloon\Console\Migration;
use Composer\Autoload\ClassLoader as Composer;
use GetOpt\GetOpt;
use Micro\Config\Config;
use Micro\Log\Adapter\Stdout;
use Psr\Log\LoggerInterface;

class Cli extends AbstractBootstrap
{
    /**
     * Console modules.
     *
     * @var array
     */
    protected $module = [
        'async' => Async::class,
        'migration' => Migration::class,
    ];

    /**
     * Init bootstrap.
     *
     * @param Composer $composer
     * @param Config   $config
     */
    public function __construct(Composer $composer, ?Config $config = null)
    {
        parent::__construct($composer, $config);
        $this->setExceptionHandler();

        $this->container->add(GetOpt::class, function () {
            return new GetOpt([
                ['v', 'verbose', GetOpt::NO_ARGUMENT, 'Verbose'],
            ]);
        });

        $getopt = $this->container->get(getopt::class);
        $module = $this->getModule($getopt);
        $getopt->process();

        if (null === $module || in_array('help', $_SERVER['argv'], true)) {
            die($this->getHelp($getopt, $module));
        }

        $this->initGlobalOptions($getopt);
        $this->start($module);
    }

    /**
     * Configure cli logger.
     *
     * @return Cli
     */
    protected function configureLogger(?int $level = null): self
    {
        if (null === $level) {
            $level = 4;
        } else {
            $level += 4;
        }

        $logger = $this->container->get(LoggerInterface::class);

        if (!$logger->hasAdapter('stdout')) {
            $logger->injectAdapter(new Stdout([
                'level' => $level,
                'format' => '{date} [{context.category},{level}]: {message} {context.params} {context.exception}', ]), 'stdout');
        } else {
            $logger->getAdapter('stdout')->setOptions(['level' => $level]);
        }

        return $this;
    }

    /**
     * Show help.
     *
     * @param GetOpt           $getopt
     * @param ConsoleInterface $module
     *
     * @return string
     */
    protected function getHelp(GetOpt $getopt, ?ConsoleInterface $module = null): string
    {
        $help = "Balloon\n";
        $help .= "balloon (GLOBAL OPTIONS) [MODULE] (MODULE OPTIONS)\n\n";

        $help .= "Options:\n";
        foreach ($getopt->getOptionObjects() as $option) {
            $help .= '-'.$option->short().' --'.$option->long().' '.$option->description()."\n";
        }

        if (null === $module) {
            $help .= "\nModules:\n";
            $help .= "help (MODULE)\t Displays a reference for a module\n";
            $help .= "async\t\t Handles asynchronous jobs\n";
            $help .= "migration\t Initialize and upgrade\n\n";
        }

        return $help;
    }

    /**
     * Get module.
     *
     * @param GetOpt $getopt
     *
     * @return ConsoleInterface
     */
    protected function getModule(GetOpt $getopt): ?ConsoleInterface
    {
        foreach ($_SERVER['argv'] as $option) {
            if (isset($this->module[$option])) {
                return $this->container->get($this->module[$option]);
            }
        }

        return null;
    }

    /**
     * Parse cmd options.
     *
     * @param GetOpt $getopt
     *
     * @return Cli
     */
    protected function initGlobalOptions(GetOpt $getopt): self
    {
        $this->configureLogger($getopt->getOption('verbose'));

        $logger = $this->container->get(LoggerInterface::class);
        $logger->info('processing incomming cli request', [
            'category' => get_class($this),
            'options' => $getopt->getOptions(),
        ]);

        if (0 === posix_getuid()) {
            $logger->warning('cli should not be executed as root', [
                'category' => get_class($this),
            ]);
        }

        return $this;
    }

    /**
     * Start.
     *
     * @param array $options
     *
     * @return bool
     */
    protected function start(ConsoleInterface $module): bool
    {
        return $module->start();
    }

    /**
     * Set exception handler.
     *
     * @return Cli
     */
    protected function setExceptionHandler(): self
    {
        set_exception_handler(function ($e) {
            echo $e;
            $logger = $this->container->get(LoggerInterface::class);
            $logger->emergency('uncaught exception: '.$e->getMessage(), [
                'category' => get_class($this),
                'exception' => $e,
            ]);
        });

        return $this;
    }
}
