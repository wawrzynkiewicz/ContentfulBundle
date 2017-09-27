<?php
/**
 * @copyright 2016 Contentful GmbH
 * @license   MIT
 */

namespace Contentful\ContentfulBundle\DependencyInjection;

use Contentful\Asset\Client as AssetClient;
use Contentful\Upload\Client as UploadClient;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;

class ContentfulExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration($container->getParameter('kernel.debug'));
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        if ($container->getParameter('kernel.environment') == 'dev') {
            $loader->load('services_dev.xml');
        } else {
            $loader->load('services.xml');
        }

        if (!empty($config['delivery'])) {
            $this->loadData('delivery', $config['delivery'], $container);
        }

        if (!empty($config['management'])) {
            $this->loadData('management', $config['management'], $container);
        }

        $container
            ->register('contentful.upload.client', UploadClient::class)
            ->addArgument('%contentful.managment.token%')
            ->addArgument('%contentful.managment.space_id%')
        ;

        $container
            ->register('contentful.asset.client', AssetClient::class)
            ->addArgument('%contentful.managment.token%')
            ->addArgument('%contentful.managment.space_id%')
        ;
    }

    protected function loadData($type, array $config, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load("$type.xml");

        if (empty($config['default_client'])) {
            $keys = array_keys($config['clients']);
            $config['default_client'] = reset($keys);
        }

        $container->setParameter("contentful.$type.default_client", $config['default_client']);
        $container->setAlias("contentful.$type", sprintf("contentful.$type.%s_client", $config['default_client']));

        $clients = [];
        foreach ($config['clients'] as $name => $client) {

            if ($type == 'delivery') {
                $api = $client['preview'] ? 'PREVIEW' : 'DELIVERY';
            } else {
                $api = 'MANAGEMENT';
            }

            $clients[$name] = [
                'service' => sprintf("contentful.$type.%s_client", $name),
                'api' => $api,
                'space' => $client['space']
            ];
            $this->loadClient($type, $name, $client, $container);
        }

        $container->setParameter('contentful.clients', $clients);
    }

    protected function loadClient($type, $name, array $client, ContainerBuilder $container)
    {
        $logger = $client['request_logging'] ? 'contentful.logger.array' : 'contentful.logger.null';

        if ($type == 'management') {
            $container
                ->setDefinition(sprintf("contentful.$type.%s_client", $name), new DefinitionDecorator("contentful.$type.client"))
                ->setArguments([
                    $client['token'],
                    $client['space'],
                    $client['preview'],
                    new Reference($logger),
                    new Reference('contentful.delivery')
                ]);
        } else {
            $container
                ->setDefinition(sprintf("contentful.$type.%s_client", $name), new DefinitionDecorator("contentful.$type.client"))
                ->setArguments([
                    $client['token'],
                    $client['space'],
                    $client['preview'],
                    new Reference($logger)
                ]);
        }
    }
}
