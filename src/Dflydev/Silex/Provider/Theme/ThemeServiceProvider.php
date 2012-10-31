<?php

/*
 * This file is a part of dflydev/theme-service-provider.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dflydev\Silex\Provider\Theme;

use Dflydev\Theme\Format\Version0\Version0ThemeFactory;
use Dflydev\Theme\PathLocator\CompositePathLocator;
use Dflydev\Theme\PathLocator\FilesystemPathLocator;
use Dflydev\Theme\PathLocator\PathMapperPathLocator;
use Dflydev\Theme\PathLocator\Psr0ResourceLocatorPathLocator;
use Dflydev\Theme\PathMapper\PatternPathMapper;
use Dflydev\Theme\Registry\ArrayRegistry;
use Dflydev\Theme\ResourceUrlGenerator\SymfonyRoutingResourceUrlGenerator;
use Dflydev\Theme\ThemeManager;
use Dflydev\Theme\ThemeProvider;
use Dflydev\Twig\Extension\Theme\ThemeTwigExtension;
use Silex\Application;
use Silex\ServiceProviderInterface;

/**
 * Admin Service Provider
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class ThemeServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
        if (isset($app['theme.types'])) {
            foreach ($app['theme.types'] as $type) {
                $descriptor = $app[$app['theme.dynamic_typed_descriptor_param.prefix'].$type.$app['theme.dynamic_typed_descriptor_param.suffix']];
                $app['theme.manager']->registerTheme($descriptor, $type);
            }
        }

        if (isset($app[$app['theme.descriptor_param']])) {
            $app['theme.manager']->registerTheme($app[$app['theme.descriptor_param']]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        foreach ($this->getDefaults($app) as $key => $value) {
            if (!isset($app[$key])) {
                $app[$key] = $value;
            }
        }

        $app['theme.path_mapper'] = $app->share(function($app) {
            $pathMapper = new PatternPathMapper($app['theme.docroot']);

            $pathMapper->setThemeUrlTemplate($app['theme.url_template']);
            $pathMapper->setTypedThemeUrlTemplate($app['theme.typed_url_template']);

            return $pathMapper;
        });

        $app['theme.registry'] = $app->share(function($app) {
            return new ArrayRegistry;
        });

        $app['theme.path_locator'] = $app->share(function($app) {
            $pathLocator = new CompositePathLocator(array(
                new PathMapperPathLocator($app['theme.path_mapper']),
                new FilesystemPathLocator,
            ));

            if (isset($app['psr0_resource_locator'])) {
                $pathLocator->addPathLocator(new Psr0ResourceLocatorPathLocator(
                    $app['psr0_resource_locator']
                ));
            }

            return $pathLocator;
        });

        $app['theme.theme_factory'] = $app->share(function($app) {
            switch ($app['theme.format']) {
                case 'version0':
                    return new Version0ThemeFactory;
                    break;

                default:
                    throw new \InvalidArgumentException('Invalid theme format specified.');
                    break;
            }
        });

        $app['theme.manager'] = $app->share(function($app) {
            return new ThemeManager(
                $app['theme.registry'],
                $app['theme.path_locator'],
                $app['theme.theme_factory']
            );
        });

        $app['theme.provider'] = $app->share(function($app) {
            if (!empty($app[$app['theme.typed_descriptor_param']])) {
                $type = $app[$app['theme.typed_descriptor_param']];
                $descriptor = $app[$app['theme.dynamic_typed_descriptor_param.prefix'].$type.$app['theme.dynamic_typed_descriptor_param.suffix']];
            } elseif (!empty($app[$app['theme.descriptor_param']])) {
                $type = null;
                $descriptor = $app[$app['theme.descriptor_param']];
            } else {
                throw new \InvalidArgumentException("Could not determine theme; Looked for ''.");
            }

            return new ThemeProvider($app['theme.manager']->registerTheme($descriptor, $type));
        });

        $app['twig.loader.filesystem'] = $app->share($app->extend('twig.loader.filesystem', function ($loader, $app) {
            if (null === $theme = $app['theme.provider']->provideTheme()) {
                return $loader;
            }

            $templatePath = $theme->rootPath().'/templates';

            if (!file_exists($templatePath)) {
                throw new \InvalidArgumentException("Theme is missing its template directory; Expected to find it at '$templatePath'.");
            }

            $paths = $loader->getPaths();
            $paths[] = $templatePath;
            $loader->setPaths($paths);

            return $loader;
        }));

        $app['theme.resource_url_generator'] = $app->share(function($app) {
            return new SymfonyRoutingResourceUrlGenerator(
                $app['theme.provider'],
                $app['theme.path_mapper'],
                $app['url_generator']
            );
        });

        $app['theme.determine_content_type'] = $app->protect(function($file) use ($app) {
            if (function_exists('finfo_file')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $type = finfo_file($finfo, $file);
                finfo_close($finfo);
            } else {
                $type = 'text/plain';
            }

            if (!$type || in_array($type, array('application/octet-stream', 'text/plain'))) {
                $secondOpinion = exec('file -b --mime-type ' . escapeshellarg($file), $foo, $returnCode);
                if ($returnCode === 0 && $secondOpinion) {
                    $type = $secondOpinion;
                }
            }

            if (in_array($type, array('text/plain', 'text/x-c')) && preg_match('/\.css$/', $file)) {
                $type = 'text/css';
            }

            return $type;
        });

        if (class_exists('Dflydev\Twig\Extension\Theme\ThemeTwigExtension')) {
            $this->configureThemeTwigExtension($app);
        }
    }

    protected function configureThemeTwigExtension(Application $app)
    {
        $app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
            $twig->addExtension(new ThemeTwigExtension($app['theme.resource_url_generator']));

            return $twig;
        }));

        $app->get('/_theme_typed/{type}/{name}/resources/{resource}', function($type, $name, $resource) use ($app) {
            $theme = $app['theme.manager']->findThemeByName($name, $type);
            $filesystemPath = $theme->rootPath().'/public/'.$resource;

            if (!file_exists($filesystemPath)) {
                return $app->abort(404, 'The resource was not found.');
            }

            $type = $app['theme.determine_content_type']($filesystemPath);

            $stream = function () use ($filesystemPath) {
                readfile($filesystemPath);
            };

            return $app->stream($stream, 200, array(
                'Content-Type' => $type,
            ));
        })
        ->assert('name', '.+')
        ->assert('resource', '.+')
        ->bind('_dflydev_typed_theme_handler');

        $app->get('/_theme/{name}/resources/{resource}', function($name, $resource) use ($app) {
            $theme = $app['theme.manager']->findThemeByName($name);
            $filesystemPath = $app['theme.path_mapper']->generatePublicResourceFilesystemPathForTheme($theme, $resource);
            $type = $app['theme.determine_content_type']($filesystemPath);

            print $type;
        })
        ->assert('name', '.+')
        ->assert('resource', '.+')
        ->bind('_dflydev_theme_handler');
    }

    protected function getDefaults()
    {
        return array(
            'theme.format' => 'version0',
            'theme.descriptor_param' => 'theme.descriptor',
            'theme.typed_descriptor_param' => 'theme.type',
            'theme.dynamic_typed_descriptor_param.prefix' => 'theme.descriptor.',
            'theme.dynamic_typed_descriptor_param.suffix' => '',
            'theme.url_template' => '/themes/%name%',
            'theme.typed_url_template' => '/themes/%type%/%name%',
        );
    }
}
