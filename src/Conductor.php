<?php

namespace MyBuilder\Conductor;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Output\OutputInterface;

class Conductor
{
    /**
     * @var Filesystem
     */
    private $fileSystem;

    private $output;

    public function __construct(Filesystem $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    public function updatePackages($paths, PackageZipper $packageZipper)
    {
        $finder = new Finder();
        $finder->files()->exclude('vendor')->name('composer.json')->depth(0);

        // @TODO: Check whether Composer's cache is enabled before enforcing this
        if (empty($_SERVER['HOME'])) {
            throw new \RuntimeException(
                "Unable to determine current user's home directory from " . '$_SERVER["HOME"]'
            );
        }
        // @TODO: Check for custom Composer cache folder
        $baseCachePath = $_SERVER['HOME'] . '/.composer/cache/files/';
        $displayCacheFixInstruction = false;

        $results = array();

        foreach ($finder->in($paths) as $file) {
            if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
                $this->output->writeln('<info>Package file:</info> ' . $file);
            }
            $zip = $packageZipper->zip($file);

            // remove composer cache for custom artifact packages
            // Composer would pick cached ones instead of artifact folder
            if ($this->fileSystem->exists($baseCachePath)) {
                $packageDefinition = json_decode(file_get_contents($file));
                $path = explode("/", $packageDefinition->name);

                $vendorPath = $baseCachePath . $path[0];
                if ($this->fileSystem->exists($vendorPath)) {
                    if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
                        $this->output->writeln('<info>Searching for Composer cache directory:'
                            . $path[1] . ' in ' . $vendorPath . '</info>');
                    }
                    $cacheFinder = new Finder();
                    $folders = $cacheFinder->directories()->depth('== 0')->name($path[1])->in(array($vendorPath));
                    foreach ($folders as $folder) {
                        try {
                            $this->fileSystem->remove($folder);
                            if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
                                $this->output->writeln("<info>Removed '$folder' directory</info>");
                            }
                        } catch (\Exception $e) {
                            $this->output->writeln("<error>" . $e->getMessage()
                                . " You may be getting an outdated artifact package</error>");
                            $displayCacheFixInstruction = true;
                        }
                    }
                }
            }

            $results[] = $zip;
        }

        if ($displayCacheFixInstruction) {
            $userMessage = "";
            if (!empty($_SERVER["USER"])) {
                $userMessage = " (sudo chown -R " . $_SERVER["USER"] . " " . $baseCachePath . ")";
            }
            $this->output->writeln("<bg=red;fg=white;option=blink>Failed to remove some cache files!"
                . "</bg=red;fg=white;option=blink><error> Make sure the Composer cache directory is writable"
                . " by the current user{$userMessage}.</error>");
        }

        return $results;
    }

    public function symlinkPackages($rootPath)
    {
        $finder = new Finder();
        $finder->files()->name('replace_with_symlink.path');

        foreach ($finder->in($rootPath) as $file) {
            if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
                $this->output->writeln('<info>Package symlink path file:</info> ' . $file);
            }
            $this->symlinkPackageToVendor(file_get_contents($file), dirname($file));
        }
    }

    private function symlinkPackageToVendor($packagePath, $vendorPath)
    {
        $relative = $this->fileSystem->makePathRelative(realpath($packagePath), realpath($vendorPath . '/../'));

        $this->fileSystem->rename($vendorPath, $vendorPath . '_linked', true);
        $this->fileSystem->symlink($relative, $vendorPath);
        $this->fileSystem->remove($vendorPath . '_linked');

        if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
            $this->output->writeln('<info>Package path:</info> ' . $packagePath
                . ' <info>Real package path:</info> ' . realpath($packagePath . '/../')
                . ' <info>Vendor path:</info> ' . $vendorPath . '/../'
                . ' <info>Real vendor path:</info> ' . realpath($vendorPath . '/../')
                . ' <info>Relative path:</info> ' . $relative);
        }
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;

        return $this;
    }
}
