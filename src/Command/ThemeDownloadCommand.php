<?php

namespace OhMyBrew\Command;

use \Phar;
use \PharData;
use OhMyBrew\BasicShopifyAPI;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ThemeDownloadCommand extends Command
{
    /**
     * The API instance of the shop.
     *
     * @var OhMyBrew\BasicShopifyAPI
     */ 
    protected $api;

    /**
     * The shop's domain.
     *
     * @var string
     */ 
    protected $shop;

    /**
     * The theme ID.
     *
     * @var int
     */ 
    protected $theme;

    /**
     * The output directory.
     *
     * @var string
     */ 
    protected $outputDir;

    /**
     * Constructor for console command to set it up.
     *
     * @return void
     */
    protected function configure() : void
    {
        $this
            ->setName('download')
            ->setDescription('Downloads Shopify themes')
            ->addArgument('shop', InputArgument::REQUIRED, 'The shop domain name')
            ->addArgument('api', InputArgument::REQUIRED, 'The API key:password combo')
            ->addArgument('theme', InputArgument::REQUIRED, 'The theme\'s ID')
            ->addOption('cycle', null, InputOption::VALUE_OPTIONAL, 'Cycle in milliseconds, default: one call per 500ms (result in 2 call per second)', 0.5 * 1000)
            ->addOption('cycle-buffer', null, InputOption::VALUE_OPTIONAL, 'Cycle buffer in milliseconds, default: 100ms per call (appended to cycle)', 0.1 * 1000)
        ;
    }

    /**
     * Constructor for billing plan class.
     *
     * @param InputInterface  $input  The input object.
     * @param OutputInterface $output The output object.
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) : void
    {
        // Save the details of the shop, output directory, theme ID, API credentials
        $this->shop = "{$input->getArgument('shop')}.myshopify.com";
        $this->theme = $input->getArgument('theme');
        $this->outputDir = getcwd()."/{$this->shop}-{$this->theme}";
        $apiCombo = explode(':', $input->getArgument('api'));

        // Setup the API instance
        $this->api = new BasicShopifyAPI(true);
        $this->api->setShop($this->shop);
        $this->api->setApiKey($apiCombo[0]);
        $this->api->setApiPassword($apiCombo[1]);
        $this->api->enableRateLimiting((int) $input->getOption('cycle'), (int) $input->getOption('cycle-buffer'));

        // Setup the output directory
        $this->setupOutputDir($output);

        // Get a list of all assets for the theme
        $assets = $this->api->rest('GET', "/admin/themes/{$this->theme}/assets.json")->body->assets;
        $totalAssets = count($assets);
        $output->writeln("<info>Total assets: {$totalAssets}</info>");

        foreach ($assets as $index => $assetData) {
            // Calculate the percentage complete
            $assetName = $assetData->key;
            $currentIndex = $index + 1;
            $percentComplete = round(($currentIndex / $totalAssets) * 100);

            // Output a message of the status
            $message = "<%s>[{$currentIndex}/{$totalAssets}] {$percentComplete}%% | {$assetName} | %s</%s>";
            $section = $output->section();
            $section->writeln(sprintf($message, 'comment', 'Downloading...', 'comment'));

            // Download the asset
            $this->downloadAsset($assetName);

            // Completed download message
            $section->overwrite(sprintf($message, 'info', 'Downloaded', 'info'));
        }

        // All done, package it up into a tar archive using Phar
        $archiveName = "{$this->shop}-{$this->theme}.tar";
        $archive = new PharData($archiveName);
        $archive->buildFromDirectory($this->outputDir);

        $output->writeln("<info>Completed download, {$archiveName} is available.</info>");
    }

    /**
     * Sets up the output directory.
     *
     * @param OutputInterface $output The output object.
     *
     * @return void
     */
    protected function setupOutputDir(OutputInterface $output) : void
    {
        if (file_exists($this->outputDir)) {
            // Already exists, this is an issue, we need it removed
            $output->writeln('<error>Theme directory already exists, please remove it first</error>');
            exit;
        }

        // Make the directory
        mkdir($this->outputDir);
    }

    /**
     * Downloads the asset to the output directory.
     *
     * @param string $key The asset key.
     *
     * @return object
     */
    protected function downloadAsset($key) : object
    {
        // Grab the asset
        $asset = $this->api->rest('GET', "/admin/themes/{$this->theme}/assets.json", [
            'asset' => [
                'key' => $key,
            ],
        ])->body->asset;

        // Confirm the directory for output exists
        $assetDir = "{$this->outputDir}/".pathinfo($key, PATHINFO_DIRNAME);
        if (!file_exists($assetDir)) {
            mkdir($assetDir, 0777, true);
        }

        // Save the file, use attachment for images, value for other files like Liquid
        file_put_contents(
            "{$this->outputDir}/{$key}",
            property_exists($asset, 'attachment') ? base64_decode($asset->attachment) : $asset->value
        );

        return $asset;
    }
}
