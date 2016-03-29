<?php declare(strict_types = 1);

namespace Dms\Package\Content\Cms\Definition;

use Dms\Core\Auth\IAuthSystem;
use Dms\Core\Exception\InvalidOperationException;
use Dms\Core\Ioc\IIocContainer;
use Dms\Core\Package\Definition\PackageDefinition;
use Dms\Package\Content\Core\ContentConfig;
use Dms\Package\Content\Core\Repositories\IContentGroupRepository;

/**
 * The content package definition.
 *
 * @author Elliot Levin <elliotlevin@hotmail.com>
 */
class ContentPackageDefinition
{
    /**
     * @var ContentConfig
     */
    protected $config;

    /**
     * @var ContentModuleDefinition[]
     */
    protected $contentModuleDefinitions = [];

    /**
     * ContentPackageDefinition constructor.
     *
     * @param ContentConfig $config
     */
    public function __construct(ContentConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Defines a module within the content package.
     *
     * Example:
     * <code>
     * $content->module('pages', 'file-text', function (ContentModuleDefinition $content) {
     *      $content->group('template', 'Template')
     *          ->withImage('banner', 'Banner')
     *          ->withHtml('header', 'Header')
     *          ->withHtml('footer', 'Footer');
     *
     *      $content->page('home', 'Home')
     *          ->url(route('home'))
     *          ->withHtml('info', 'Info', '#info')
     *          ->withImage('banner', 'Banner')
     *          ->withMetadata('extra', 'Some Extra Metadata');
     * });
     *
     * $content->module('emails', 'envelope', function (ContentModuleDefinition $content) {
     *      $content->email('home', 'Home')
     *          ->withHtml('info', 'Info');
     * });
     * </code>
     *
     * @param string   $name
     * @param string   $icon
     * @param callable $definitionCallback
     *
     * @throws InvalidOperationException
     */
    public function module(string $name, string $icon, callable $definitionCallback)
    {
        $definition = new ContentModuleDefinition($name, $icon, $this->config);
        $definitionCallback($definition);

        $this->contentModuleDefinitions[$name] = $definition;
    }

    public function loadPackage(PackageDefinition $package, IIocContainer $iocContainer)
    {
        $moduleMap = [];

        foreach ($this->contentModuleDefinitions as $name => $module) {
            $moduleMap[$name] = function () use ($iocContainer, $module) {
                return $module->loadModule($iocContainer->get(IContentGroupRepository::class), $iocContainer->get(IAuthSystem::class));
            };
        }

        $package->modules($moduleMap);
    }
}