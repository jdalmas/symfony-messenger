<?php declare(strict_types=1);

namespace Bref\Symfony\Messenger\Service\Sqs;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\Symfony\Messenger\Service\BusDriver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsReceivedStamp;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsXrayTraceHeaderStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class SqsConsumer extends SqsHandler
{
    private const MESSAGE_ATTRIBUTE_NAME = 'X-Symfony-Messenger';

    /** @var MessageBusInterface */
    private $bus;
    /** @var SerializerInterface */
    protected $serializer;
    /** @var string */
    private $transportName;
    /** @var BusDriver */
    private $busDriver;
    /** @var bool */
    private $partialBatchFailure;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        BusDriver $busDriver,
        MessageBusInterface $bus,
        SerializerInterface $serializer,
        string $transportName,
        LoggerInterface $logger = null,
        bool $partialBatchFailure = false
    ) {
        $this->busDriver = $busDriver;
        $this->bus = $bus;
        $this->serializer = $serializer;
        $this->transportName = $transportName;
        $this->logger = $logger ?? new NullLogger();
        $this->partialBatchFailure = $partialBatchFailure;
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $isFifoQueue = null;
        $hasPreviousMessageFailed = false;

        foreach ($event->getRecords() as $record) {
            if ($isFifoQueue === null) {
                $isFifoQueue = \str_ends_with($record->toArray()['eventSourceARN'], '.fifo');
            }

            /*
             * When using FIFO queues, preserving order is important.
             * If a previous message has failed in the batch, we need to skip the next ones and requeue them.
             */
            if ($isFifoQueue && $hasPreviousMessageFailed) {
                $this->markAsFailed($record);
                continue;
            }

            $headers = [];
            $attributes = $record->getMessageAttributes();

            if (isset($attributes[self::MESSAGE_ATTRIBUTE_NAME]) && $attributes[self::MESSAGE_ATTRIBUTE_NAME]['dataType'] === 'String') {
                $headers = json_decode($attributes[self::MESSAGE_ATTRIBUTE_NAME]['stringValue'], true);
                unset($attributes[self::MESSAGE_ATTRIBUTE_NAME]);
            }

            foreach ($attributes as $name => $attribute) {
                if ($attribute['dataType'] !== 'String') {
                    continue;
                }
                $headers[$name] = $attribute['stringValue'];
            }

            try {
                $envelope = $this->serializer->decode(['body' => $record->getBody(), 'headers' => $headers]);

                $stamps = [new AmazonSqsReceivedStamp($record->getMessageId())];

                if ('' !== $context->getTraceId()) {
                    $stamps[] = new AmazonSqsXrayTraceHeaderStamp($context->getTraceId());
                }
                $this->busDriver->putEnvelopeOnBus($this->bus, $envelope->with(...$stamps), $this->transportName);
            } catch (\Throwable $exception) {
                if ($this->partialBatchFailure === false) {
                    throw $exception;
                }

                $this->logger->error(sprintf('SQS record with id "%s" failed to be processed.', $record->getMessageId()));
                $this->logger->error($exception->getMessage());
                $this->markAsFailed($record);
                $hasPreviousMessageFailed = true;
            }
        }
    }
}
