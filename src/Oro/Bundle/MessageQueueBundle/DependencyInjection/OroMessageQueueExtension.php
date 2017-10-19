<?php

namespace Oro\Bundle\MessageQueueBundle\DependencyInjection;

use Oro\Component\MessageQueue\Client\DbalDriver;
use Oro\Component\MessageQueue\Client\NullDriver;
use Oro\Component\MessageQueue\Client\TraceableMessageProducer;
use Oro\Component\MessageQueue\DependencyInjection\TransportFactoryInterface;
use Oro\Component\MessageQueue\Transport\Dbal\DbalConnection;
use Oro\Component\MessageQueue\Transport\Dbal\DbalLazyConnection;
use Oro\Component\MessageQueue\Transport\Null\NullConnection;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class OroMessageQueueExtension extends Extension
{
    /**
     * @var TransportFactoryInterface[]
     */
    private $factories;

    public function __construct()
    {
        $this->factories = [];
    }

    /**
     * @param TransportFactoryInterface $transportFactory
     */
    public function addTransportFactory(TransportFactoryInterface $transportFactory)
    {
        $name = $transportFactory->getName();

        if (empty($name)) {
            throw new \LogicException('Transport factory name cannot be empty');
        }
        if (array_key_exists($name, $this->factories)) {
            throw new \LogicException(sprintf('Transport factory with such name already added. Name %s', $name));
        }

        $this->factories[$name] = $transportFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration($this->factories), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
        $loader->load('log.yml');
        $loader->load('job.yml');

        // @see BAP-12051
        // php pcntl extension available only for UNIX like systems
        if (extension_loaded('pcntl')) {
            $loader->load('optional_services.yml');
        }

        foreach ($config['transport'] as $name => $transportConfig) {
            $this->factories[$name]->createService($container, $transportConfig);
        }

        if (isset($config['client'])) {
            $loader->load('client.yml');

            $driverFactory = $container->getDefinition('oro_message_queue.client.driver_factory');
            $driverFactory->replaceArgument(0, [
                NullConnection::class => NullDriver::class,
                DbalConnection::class => DbalDriver::class,
                DbalLazyConnection::class => DbalDriver::class,
            ]);

            $configDef = $container->getDefinition('oro_message_queue.client.config');
            $configDef->setArguments([
                $config['client']['prefix'],
                $config['client']['router_processor'],
                $config['client']['router_destination'],
                $config['client']['default_destination'],
            ]);

            if (false == empty($config['client']['traceable_producer'])) {
                $producerId = 'oro_message_queue.client.traceable_message_producer';
                $container->register($producerId, TraceableMessageProducer::class)
                    ->setDecoratedService('oro_message_queue.client.message_producer')
                    ->addArgument(new Reference('oro_message_queue.client.traceable_message_producer.inner'))
                ;
            }

            $delayRedeliveredExtension = $container->getDefinition(
                'oro_message_queue.client.delay_redelivered_message_extension'
            );
            $delayRedeliveredExtension->replaceArgument(1, $config['client']['redelivered_delay_time']);
        }

        $this->setPersistenceServicesAndProcessors($config, $container);
        $this->setJobConfigurationProvider($config, $container);
    }

    /**
     * {@inheritdoc}
     *
     * @return Configuration
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        $rc = new \ReflectionClass(Configuration::class);

        $container->addResource(new FileResource($rc->getFileName()));

        return new Configuration($this->factories);
    }

    /**
     * Sets the services that should not be reset during container reset and
     * Message Queue processors that can work without container reset to container reset extension.
     *
     * @param array            $config
     * @param ContainerBuilder $container
     */
    protected function setPersistenceServicesAndProcessors(array $config, ContainerBuilder $container)
    {
        if (!empty($config['persistent_services'])) {
            $container->getDefinition('oro_message_queue.consumption.container_clearer')
                ->addMethodCall('setPersistentServices', [$config['persistent_services']]);
        }
        if (!empty($config['persistent_processors'])) {
            $container->getDefinition('oro_message_queue.consumption.container_reset_extension')
                ->addMethodCall('setPersistentProcessors', [$config['persistent_processors']]);
        }
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    protected function setJobConfigurationProvider(array $config, ContainerBuilder $container)
    {
        if (!empty($config['time_before_stale'])) {
            $jobConfigurationProvider = $container->getDefinition('oro_message_queue.job.configuration_provider');
            $jobConfigurationProvider->addMethodCall('setConfiguration', [$config['time_before_stale']]);
        }
    }
}
