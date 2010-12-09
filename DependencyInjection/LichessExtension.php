<?php

namespace Bundle\LichessBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class LichessExtension extends Extension
{
    public function configLoad($config, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, __DIR__.'/../Resources/config');
        $loader->load('chess.xml');
        $loader->load('model.xml');
        $loader->load('blamer.xml');
        $loader->load('critic.xml');
        $loader->load('elo.xml');
        $loader->load('controller.xml');
        $loader->load('templating.xml');
        $loader->load('translation.xml');
        $loader->load('form.xml');
        $loader->load('security.xml');
        $loader->load('services.xml');

        if (isset($config['db_driver'])) {
            try {
                $loader->load(sprintf('%s.xml', $config['db_driver']));
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf('The db_driver "%s" is not supported by forum', $config['db_driver']));
            }
        }
        
        if(isset($config['ai']['class'])) {
            $container->setParameter('lichess.ai.class', $config['ai']['class']);
        }

        if(isset($config['storage']['class'])) {
            $container->setParameter('lichess.storage.class', $config['storage']['class']);
        }
        if(isset($config['storage']['options']) && is_array($config['storage']['options'])) {
            foreach ($config['storage']['options'] as $key => $option) {
                if (strpos($key, 'service_') === 0) {
                    $container->getDefinition('lichess_storage')->addArgument(new Reference($option));
                } else {
                    $container->getDefinition('lichess_storage')->addArgument($option);
                }
            }
        }

        if(isset($config['translation']['remote_domain'])) {
            $container->setParameter('lichess.translation.remote_domain', $config['translation']['remote_domain']);
        }

        if (isset($config['class'])) {
            $namespaces = array(
                'model' => 'lichess.model.%s.class',
            );
            $this->remapParametersNamespaces($config['class'], $container, $namespaces);
        }
    }

    protected function remapParametersNamespaces(array $config, ContainerBuilder $container, array $namespaces)
    {
        foreach ($namespaces as $ns => $map) {
            if ($ns) {
                if (!isset($config[$ns])) {
                    continue;
                }
                $namespaceConfig = $config[$ns];
            } else {
                $namespaceConfig = $config;
            }
            if (is_array($map)) {
                $this->remapParameters($namespaceConfig, $container, $map);
            } else {
                foreach ($namespaceConfig as $name => $value) {
                    if(null !== $value) {
                        $container->setParameter(sprintf($map, $name), $value);
                    }
                }
            }
        }
    }

    /**
     * Returns the base path for the XSD files.
     *
     * @return string The XSD base path
     */
    public function getXsdValidationBasePath()
    {
        return null;
    }

    public function getNamespace()
    {
        return 'http://www.symfony-project.org/schema/dic/symfony';
    }

    public function getAlias()
    {
        return 'lichess';
    }

}
