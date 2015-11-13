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

        $results = array();
        foreach ($finder->in($paths) as $file) {
            if ($this->output && OutputInterface::VERBOSITY_VERBOSE <= $this->output->getVerbosity()) {
                $this->output->writeln('<info>Package file:</info> ' . $file);
            }
            $zip = $packageZipper->zip($file);

            // remove composer cache for custom artifact packages
            // Composer would pick cached ones instead of artifact folder
            if (!empty($_SERVER['HOME']) && $this->fileSystem->exists($_SERVER['HOME'])) {
                $packageDefinition = json_decode(file_get_contents($file));
                $path = explode("/", $packageDefinition->name);

                $baseCachePath = $_SERVER['HOME'] . '/.composer/cache/files/';
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
                            $this->output->writeln("<error>Failed to remove '{$vendorPath}/{$folder}'"
                                . " directory from Composer cache. You may be getting an outdated artifact package</error>");
                        }
                    }
                }
            }

            $results[] = $zip;
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
