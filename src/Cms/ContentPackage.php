<?php declare(strict_types = 1);

namespace Dms\Package\Content\Cms;

use Dms\Common\Structure\FileSystem\Image;
use Dms\Common\Structure\Web\Html;
use Dms\Core\Exception\NotImplementedException;
use Dms\Core\ICms;
use Dms\Core\Ioc\IIocContainer;
use Dms\Core\Package\Definition\PackageDefinition;
use Dms\Core\Package\Package;
use Dms\Core\Util\IClock;
use Dms\Package\Content\Cms\Definition\ContentConfigDefinition;
use Dms\Package\Content\Cms\Definition\ContentPackageDefinition;
use Dms\Package\Content\Core\ContentConfig;
use Dms\Package\Content\Core\ContentGroup;
use Dms\Package\Content\Core\ContentLoaderService;
use Dms\Package\Content\Core\ContentMetadata;
use Dms\Package\Content\Core\HtmlContentArea;
use Dms\Package\Content\Core\ImageContentArea;
use Dms\Package\Content\Core\Repositories\IContentGroupRepository;
use Dms\Package\Content\Persistence\DbContentGroupRepository;

/**
 * The content package base class.
 *
 * Since the content schema is structured differently for each site
 * as per the design requirements, there is no generic concrete content
 * package.
 *
 * This is a base class where you may define the structure of the content
 * and the modules and backend will be generated accordingly.
 *
 * Example:
 * <code>
 * protected function defineContent(ContentPackageDefinition $content)
 * {
 *      $content
 *          ->withImagesStoredUnder(public_path('content/images'))
 *          ->mappedToUrl(url('content/images'));
 *
 *      $content->module('pages', 'file-text', function (ContentModuleDefinition $content) {
 *          $content->group('template', 'Template')
 *              ->withImage('banner', 'Banner')
 *              ->withHtml('header', 'Header')
 *              ->withHtml('footer', 'Footer');
 *
 *          $content->page('home', 'Home', route('home'))
 *              ->withHtml('info', 'Info', '#info')
 *              ->withImage('banner', 'Banner');
 *
 *      });
 *
 *      $content->module('emails', 'envelope', function (ContentModuleDefinition $content) {
 *          $content->email('home', 'Home')
 *              ->withHtml('info', 'Info');
 *      });
 * }
 * </code>
 *
 *
 * @author Elliot Levin <elliotlevin@hotmail.com>
 */
abstract class ContentPackage extends Package
{
    /**
     * Package constructor.
     *
     * @param IIocContainer $container
     */
    public function __construct(IIocContainer $container)
    {
        parent::__construct($container);

        $this->syncRepoWithCurrentContentSchema($container->get(IContentGroupRepository::class), $container->get(IClock::class));
    }


    /**
     * Boots and configures the package resources and services.
     *
     * @param ICms $cms
     *
     * @return void
     */
    public static function boot(ICms $cms)
    {
        $iocContainer = $cms->getIocContainer();

        $iocContainer->bind(IIocContainer::SCOPE_SINGLETON, IContentGroupRepository::class, DbContentGroupRepository::class);
        $iocContainer->bind(IIocContainer::SCOPE_SINGLETON, ContentLoaderService::class, ContentLoaderService::class);

        $iocContainer->bindCallback(
            IIocContainer::SCOPE_SINGLETON,
            ContentConfig::class,
            function () use ($cms) : ContentConfig {
                $definition = new ContentConfigDefinition();

                static::defineConfig($definition);

                return $definition->finalize();
            }
        );
    }

    /**
     * Defines the config of the content package.
     *
     * @param ContentConfigDefinition $config
     *
     * @return void
     * @throws NotImplementedException
     */
    protected static function defineConfig(ContentConfigDefinition $config)
    {
        throw NotImplementedException::format(
            'Invalid content package class %s: the %s static method must be overridden'
        );
    }

    /**
     * Defines the structure of this cms package.
     *
     * @param PackageDefinition $package
     *
     * @return void
     */
    final protected function define(PackageDefinition $package)
    {
        $package->name('content');

        $package->metadata([
            'icon' => 'cubes',
        ]);

        $contentDefinition = new ContentPackageDefinition($this->getIocContainer()->get(ContentConfig::class));

        $this->defineContent($contentDefinition);

        $contentDefinition->loadPackage($package, $this->getIocContainer());
    }

    /**
     * Defines the structure of the content.
     *
     * @param ContentPackageDefinition $content
     *
     * @return void
     */
    abstract protected function defineContent(ContentPackageDefinition $content);

    private function syncRepoWithCurrentContentSchema(IContentGroupRepository $contentGroupRepository, IClock $clock)
    {
        $namespacedContentGroups = [];
        $contentGroupsToRemove   = [];
        $contentGroupsToCreate   = [];
        $contentGroupsToSync     = [];

        foreach ($contentGroupRepository->getAll() as $contentGroup) {
            $namespacedContentGroups[$contentGroup->namespace][$contentGroup->name] = $contentGroup;
        }

        foreach ($this->loadModules() as $module) {
            /** @var ContentModule $module */
            $contentGroups = $namespacedContentGroups[$module->getName()] ?? [];

            $contentGroupSchemas = $module->getContentGroups();

            $contentGroupsToRemove = array_merge($contentGroupsToRemove, array_diff_key($contentGroups, $contentGroupSchemas));
            $contentGroupsToCreate = array_merge($contentGroupsToCreate, $this->buildNewContentGroups($module, array_diff_key($contentGroupSchemas, $contentGroups), $clock));

            foreach (array_intersect_key($contentGroups, $contentGroupSchemas) as $contentGroup) {
                /** @var ContentGroup $contentGroup */
                $contentGroupsToSync[] = $this->syncContentGroupWithSchema($contentGroup, $contentGroupSchemas[$contentGroup->name]);
            }
        }

        foreach (array_diff_key($namespacedContentGroups, $this->getModuleNames()) as $removedGroups) {
            $contentGroupsToRemove = array_merge($contentGroupsToRemove, array_values($removedGroups));
        }

        $contentGroupRepository->removeAll($contentGroupsToRemove);
        $contentGroupRepository->saveAll(array_merge($contentGroupsToSync, $contentGroupsToCreate));
    }

    private function buildNewContentGroups(ContentModule $module, array $contentGroupSchemas, IClock $clock) : array
    {
        $contentGroups = [];

        foreach ($contentGroupSchemas as $contentGroupSchema) {
            $contentGroup = new ContentGroup($module->getName(), $contentGroupSchema['name'], $clock);

            foreach ($contentGroupSchema['html_areas'] as $area) {
                $contentGroup->htmlContentAreas[] = new HtmlContentArea($area['name'], new Html(''));
            }

            foreach ($contentGroupSchema['images'] as $area) {
                $contentGroup->imageContentAreas[] = new ImageContentArea($area['name'], new Image(''));
            }

            foreach ($contentGroupSchema['metadata'] as $item) {
                $contentGroup->metadata[] = new ContentMetadata($item['name'], '');
            }

            $contentGroups[] = $contentGroup;
        }

        return $contentGroups;
    }

    private function syncContentGroupWithSchema(ContentGroup $contentGroup, array $contentGroupSchema) : ContentGroup
    {
        $contentGroup->htmlContentAreas->removeWhere(function (HtmlContentArea $area) use ($contentGroupSchema) {
            return !isset($contentGroupSchema['html_areas'][$area->name]);
        });

        $htmlNames = $contentGroup->htmlContentAreas->indexBy(function (HtmlContentArea $area) {
            return $area->name;
        })->asArray();

        foreach (array_diff_key($contentGroupSchema['html_areas'], $htmlNames) as $area) {
            $contentGroup->htmlContentAreas[] = new HtmlContentArea($area['name'], new Html(''));
        }

        $contentGroup->imageContentAreas->removeWhere(function (ImageContentArea $area) use ($contentGroupSchema) {
            return !isset($contentGroupSchema['images'][$area->name]);
        });

        $imageNames = $contentGroup->imageContentAreas->indexBy(function (ImageContentArea $area) {
            return $area->name;
        })->asArray();

        foreach (array_diff_key($contentGroupSchema['images'], $imageNames) as $area) {
            $contentGroup->imageContentAreas[] = new ImageContentArea($area['name'], new Image(''));
        }

        $contentGroup->metadata->removeWhere(function (ContentMetadata $metadata) use ($contentGroupSchema) {
            return !isset($contentGroupSchema['metadata'][$metadata->name]);
        });

        $metadataNames = $contentGroup->metadata->indexBy(function (ContentMetadata $content) {
            return $content->name;
        })->asArray();

        foreach (array_diff_key($contentGroupSchema['metadata'], $metadataNames) as $item) {
            $contentGroup->metadata[] = new ContentMetadata($item['name'], '');
        }

        return $contentGroup;
    }
}