<?php

namespace Scrutinizer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Scrutinizer\Analyzer\AnalyzerInterface;
use Scrutinizer\Analyzer\LoggerAwareInterface;
use Scrutinizer\Analyzer;
use Scrutinizer\Event\ProjectEvent;
use Scrutinizer\Logger\LoggableProcess;
use Scrutinizer\Model\Profile;
use Scrutinizer\Model\Project;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Scrutinizer.
 *
 * Ties together analyzers, and can be used to easily scrutinize a project directory.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Scrutinizer
{
    const REVISION = '@revision@';

    const EVENT_POST_ANALYSIS = 'post_analysis';

    private $logger;

    /** @var AnalyzerInterface[] */
    private $analyzers = array();
    private $dispatcher;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new NullLogger();
        $this->dispatcher = new EventDispatcher();

        $this->registerAnalyzer(new Analyzer\Puppet\PuppetLintAnalyzer());
        $this->registerAnalyzer(new Analyzer\Javascript\JsHintAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\MessDetectorAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\CsFixerAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\PhpAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\CsAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\SecurityAdvisoryAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\CodeCoverageAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\CopyPasteDetectorAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\LocAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\PDependAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\HhvmAnalyzer());
        $this->registerAnalyzer(new Analyzer\Php\PhpSimilarityAnalyzer());
        $this->registerAnalyzer(new Analyzer\Ruby\RailsBestPracticesAnalyzer());
        $this->registerAnalyzer(new Analyzer\Ruby\RubocopAnalyzer());
        $this->registerAnalyzer(new Analyzer\Ruby\FlayAnalyzer());
        $this->registerAnalyzer(new Analyzer\ExternalCodeCoverageAnalyzer());
        $this->registerAnalyzer(new Analyzer\CustomAnalyzer());

        $this->registerSubscriber(new Event\Php\LocationCompletionSubscriber());
    }

    public function registerSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->dispatcher->addSubscriber($subscriber);
    }

    public function registerAnalyzer(AnalyzerInterface $analyzer)
    {
        if ($analyzer instanceof \Psr\Log\LoggerAwareInterface) {
            $analyzer->setLogger($this->logger);
        }

        $this->analyzers[] = $analyzer;
    }

    public function getAnalyzers()
    {
        return $this->analyzers;
    }

    public function getConfiguration(DefaultConfigRegistry $configRegistry = null)
    {
        return new Configuration($this->analyzers, $configRegistry);
    }

    public function scrutinize($dir, array $paths = array(), Profile $profile = null)
    {
        if ($profile === null) {
            $profile = new Profile();
        }

        if ( ! is_dir($dir)) {
            throw new \InvalidArgumentException(sprintf('The directory "%s" does not exist.', $dir));
        }
        $dir = realpath($dir);

        $rawConfig = array();
        if (is_file($dir.'/.scrutinizer.yml')) {
            $rawConfig = Yaml::parse(file_get_contents($dir.'/.scrutinizer.yml')) ?: array();
        }

        $config = $this->getConfiguration()->process($rawConfig);

        if ( ! empty($config['before_commands'])) {
            $profile->check('commands.before.start');
            $this->logger->info('Executing before commands'."\n");
            foreach ($config['before_commands'] as $cmd) {
                $this->logger->info(sprintf('Running "%s"...'."\n", $cmd));
                $proc = new LoggableProcess($cmd, $dir);
                $proc->setTimeout(900);
                $proc->setIdleTimeout(300);
                $proc->setPty(true);
                $proc->setLogger($this->logger);
                $proc->run();
            }
            $profile->check('commands.before.end');
        }

        $project = new Project($dir, $config, $paths);
        foreach ($this->analyzers as $analyzer) {
            if ( ! $project->isAnalyzerEnabled($analyzer->getName())) {
                continue;
            }

            if ( ! $analyzer instanceof Analyzer\CustomAnalyzer) {
                $this->logger->info(sprintf('Running analyzer "%s"...'."\n", $analyzer->getName()));
            }

            $project->setAnalyzerName($analyzer->getName());

            $profile->beforeAnalysis($analyzer);
            $analyzer->scrutinize($project);
            $profile->afterAnalysis($analyzer);
        }

        $this->dispatcher->dispatch(self::EVENT_POST_ANALYSIS, new ProjectEvent($project));

        if ( ! empty($config['after_commands'])) {
            $profile->check('commands.after.start');
            $this->logger->info('Executing after commands'."\n");
            foreach ($config['after_commands'] as $cmd) {
                $this->logger->info(sprintf('Running "%s"...'."\n", $cmd));
                $proc = new LoggableProcess($cmd, $dir);
                $proc->setTimeout(900);
                $proc->setIdleTimeout(300);
                $proc->setPty(true);
                $proc->setLogger($this->logger);
                $proc->run();
            }
            $profile->check('commands.after.end');
        }

        return $project;
    }
}
