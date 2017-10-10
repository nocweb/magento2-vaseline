<?php
/**
 * Noc Vaseline Cache Warmer
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Noc
 * @package     Noc_Vaseline
 * @copyright   Copyright (c) 2017 Noc Webbyrå AB (http://nocweb.se/)
 * @author      Rikard Wissing - Noc Webbyrå AB
 */
namespace Noc\Vaseline\Commands;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
 
class CacheWarmer extends Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;
    
    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $skipMatchedRegex = '/static|customer|checkout|cart|media/';

    /**
     * @var integer
     */
    protected $maxDepth = 0;

    /**
     * @var integer
     */
    protected $delay = 0;

    /**
     * @var integer
     */
    protected $abortAfterSec = 0;

    /**
     * @var integer
     */
    protected $startTime = 0;

    /**
     * @var integer
     */
    protected $warmedPages = 0;

    /**
     * @var array
     */
    protected $fetchedRoutes = array();


    /**
     * Constructor 
     * 
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
      State $state,
      StoreManagerInterface $storeManager
    ) {
        $this->state = $state;
        $this->storeManager = $storeManager;
        parent::__construct();
    }
 
    /**
     * Configure Command
     */
    protected function configure()
    {
        $this->setName('noc:vaseline:run');
        $this->setDescription('Warms FPC Cache');
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, '', $this->storeManager->getStore(0)->getBaseUrl());
        $this->addOption('max-depth', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('delay', null, InputOption::VALUE_OPTIONAL, '', $this->delay);
        $this->addOption('skip-matched-regex', null, InputOption::VALUE_OPTIONAL, '', $this->skipMatchedRegex);
        $this->addOption('abort-after-sec', null, InputOption::VALUE_OPTIONAL, '', $this->abortAfterSec);

        parent::configure();
    }

    /**
     * Get Routes From Html 
     * 
     * @param string $html
     */
    protected function getRoutesFromHtml($html) {
        $collectedRoutes = array();
        
        $regexSafeUrl = str_replace('/', '\\/', preg_quote( $this->baseUrl));
        preg_match_all('/href="?'.$regexSafeUrl.'([^" \']{1,})/', $html, $matches);

        foreach($matches[1] as $match) {
            if( !empty($this->fetchedRoutes[$match]) or
                ($this->skipMatchedRegex and preg_match($this->skipMatchedRegex, $match))
            ) {
                continue;
            }

            $collectedRoutes[] = $match;
            $this->fetchedRoutes[$match] = true;
        }

        return $collectedRoutes;
    }

    /**
     * Crawl Website 
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $this->baseUrl = rtrim((string) $input->getOption('url'),'/');
        $this->delay = (string) $input->getOption('delay');
        $this->maxDepth = (string) $input->getOption('max-depth');
        $this->skipMatchedRegex = (string) $input->getOption('skip-matched-regex');
        $this->abortAfterSec = (int) $input->getOption('abort-after-sec');
        $this->startTime = microtime(true);

        $this->output->writeln('Setting Base URL: <info>'.$this->baseUrl.'</info>');
        $this->output->writeln('Setting Delay: <info>'.($this->delay).' ms</info>');
        $this->output->writeln('Setting Max Depth: <info>'.($this->maxDepth ? $this->maxDepth : 'unlimited').'</info>');
        $this->output->writeln('Setting Skip Matched Regex: <info>'.$this->skipMatchedRegex.'</info>');
        $this->output->writeln('Setting Abort After Sec: <info>'.$this->abortAfterSec.'</info>');
        $this->output->writeln('');

        $this->crawl();   
    }


    /**
     * Crawl Website 
     */
    protected function crawl() {
        $routeCollection = array('');
        for($i=0; $routeCollection = $this->crawlRouteCollection($routeCollection, $i); $i++) {}
    }


    /**
     * Crawl Collected Routes 
     * 
     * @param array $routeCollection
     * @param int $depth
     */
    protected function crawlRouteCollection($routeCollection, $depth) {
        if($this->maxDepth and $depth > $this->maxDepth) {
            return false;
        }

        $collectedRoutes = array();
        foreach($routeCollection as $route) {
            $url = $this->baseUrl.html_entity_decode($route);
            $totalTimeSpent = microtime(true)-$this->startTime;

            $this->output->write(
                'Count: <info>'.($this->warmedPages++).'</info>, ' .
                ($this->abortAfterSec ? 'Done: <info>'.number_format(100*($totalTimeSpent/$this->abortAfterSec),2).'%</info>, ' : '') .
                'Depth: <info>'.$depth.($this->maxDepth ? '/'.$this->maxDepth : '').'</info>, ' .
                'Fetching: <info>'.$url.'</info>'
            );

            $startTime = microtime(true);
            $html = file_get_contents($url);
            $this->output->write(', Took: <info>' . ( microtime(true) - $startTime ) . ' seconds</info>');
            $this->output->writeLn('');

            usleep($this->delay*1000);

            $collectedRoutes = array_merge($collectedRoutes, $this->getRoutesFromHtml($html));

            if($this->abortAfterSec and $this->abortAfterSec < $totalTimeSpent) {
                return false;
            }
        }

        return $collectedRoutes;
    }
}