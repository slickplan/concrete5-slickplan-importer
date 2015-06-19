<?php
namespace Concrete\Package\SlickplanImporter;

use \Concrete\Core\Backup\ContentImporter;
use \Concrete\Core\Package\Package;

class Controller extends Package
{

    /**
     * @var string
     */
    protected $pkgHandle = 'slickplan_importer';

    /**
     * @var string
     */
    protected $appVersionRequired = '5.7.3';

    /**
     * @var string
     */
    protected $pkgVersion = '1.0.0';

    /**
     * @return string
     */
    public function getPackageName()
    {
        return t('Slickplan Importer');
    }

    /**
     * @return string
     */
    public function getPackageDescription()
    {
        return t('Import pages from a Slickplan’s XML export file');
    }

    /**
     * Install
     */
    public function install()
    {
        $pkg = parent::install();
        $ci = new ContentImporter();
        $ci->importContentFile($pkg->getPackagePath() . '/config/install.xml');
    }

}
