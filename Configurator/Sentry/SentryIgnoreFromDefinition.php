<?php

declare(strict_types=1);


use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SentryIgnoreFromDefinition implements IntegrationInterface
{
    private $options;

    /**
     * Configuration in config/services.yaml:
     *  $options:
     *      ignore_from_definition:
     *          -   level: warning
     *              logger: php
     *              subject_regex: Fooo.*           | Matched via RegEx
     *              file_regex: /vendor/package     | Matched via RegEx | SourceRoot (/vendor... /src...).
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'ignore_from_definition' => [],
        ]);

        $resolver->setAllowedTypes('ignore_from_definition', ['array']);

        $this->options = $resolver->resolve($options);
    }

    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(static function (Event $event): ?Event {
            $integration = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (null !== $integration && $integration->shouldDropEvent($event, $integration->options)) {
                return null;
            }

            return $event;
        });
    }

    private function shouldDropEvent(Event $event, array $options): bool
    {
        if ($this->isIgnored($event, $options)) {
            return true;
        }

        return false;
    }

    private function isIgnored(Event $event, array $options): bool
    {
        // We only drop events with exceptions / others are manually configured
        $exceptions = $event->getExceptions();

        if (empty($exceptions)) {
            return false;
        }

        foreach ($options['ignore_from_definition'] as $option) {
            if ($this->dropOption($event, $option)) {
                return true;
            }
        }

        return false;
    }

    private function dropOption(Event $event, array $option): bool
    {
        $result = [];
        // if level is set, it should match
        if (isset($option['level'])) {
            $result[] = $option['level'] === (string) $event->getLevel();
        }

        // if level is set, it should match
        if (isset($option['logger'])) {
            $result[] = $option['logger'] === $event->getLogger();
        }

        $exceptions = $event->getExceptions();

        if (isset($option['subject_regex'])) {
            $result[] = 1 === preg_match($option['subject_regex'], $exceptions[0]->getValue());
        }

        if (isset($option['file_regex'])) {
            try {
                $stacktrace = $exceptions[0]->getStacktrace()->getFrames();
                $last = $stacktrace[array_key_last($stacktrace)];
            } catch (Throwable) {
                $result[] = false;
            }

            $result[] = 1 === preg_match($option['file_regex'], $last->getFile());
        }

        return !empty($result) && array_product($result);
    }
}
