<?php

namespace App\Core\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExceptionInterceptor
{
    const EFFECTIVE_PATTERN = '/^\/(api|manage|store|public|officer)\/.*$/';

    private ContainerInterface $container;
    private TranslatorInterface $translator;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;
    private string $env;

    public function __construct(
        ContainerInterface $container,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        string $env
    ) {
        $this->container = $container;
        $this->translator = $translator;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->env = $env;
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        // get environment
        $request = $event->getRequest();

        // check is effective url
        $result = preg_match(self::EFFECTIVE_PATTERN, $request->getPathInfo());
        if(!$result) return;

        if('dev' === $this->env) {
            $exception = $event->getThrowable();
            $this->logger->error($exception->getMessage());
            $this->logger->error($event->getThrowable()->getTraceAsString());

            throw $exception;
        }
        else {
            // you can alternatively set a new Exception
            // $exception = new \Exception('Some special exception');
            // $event->setException($exception);

            $exception = $event->getThrowable();
            $this->logger->error(
                'Exception: ' . $request->getBasePath() . ' => ' . $exception->getMessage()
            );

            $response = [
                'code' => $exception->getCode() ? : -1,
                'message' => $this->translator->trans($exception->getMessage()),
                'class' => get_class($exception),
            ];

            $response = new Response($this->serializer->serialize($response, 'json'));
            $event->setResponse($response);
        }
    }
}
