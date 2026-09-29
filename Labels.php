<?php

declare(strict_types=1);

/**
 * Shared constants for AutoTranslate.
 *
 * Tag (label) names are configurable in the extension settings so that the
 * extension works for users of any language. The constants below are only
 * the defaults used when a setting is empty.
 */
final class FreshExtension_AutoTranslate_Labels
{
    public const DEFAULT_PENDING        = 'To translate';
    public const DEFAULT_TRANSLATED     = 'Translated';
    public const DEFAULT_ADVERTISEMENT  = 'Advertisement';

    /**
     * Target languages written in a Cyrillic script. For these a cheap
     * letter-ratio check in the entry_before_add hook allows skipping
     * articles that are already in the target language without any API call.
     */
    public const CYRILLIC_TARGETS = ['ru', 'uk', 'be', 'bg', 'sr', 'mk'];
}
