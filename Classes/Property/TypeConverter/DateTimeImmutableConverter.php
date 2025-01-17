<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Events2\Property\TypeConverter;

use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Property\Exception\InvalidPropertyMappingConfigurationException;
use TYPO3\CMS\Extbase\Property\Exception\TypeConverterException;
use TYPO3\CMS\Extbase\Property\PropertyMappingConfigurationInterface;
use TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter;

/**
 * This is a slightly copy of TYPO3's DateTimeTypeConverter, except the handling of DateTimeImmutable objects.
 *
 * Converter which transforms from different input formats into DateTimeImmutable objects.
 *
 * Source can be either a string or an array. The date string is expected to be formatted
 * according to DEFAULT_DATE_FORMAT.
 *
 * But the default date format can be overridden in the initialize*Action() method like this::
 *
 *  $this->arguments['<argumentName>']
 *    ->getPropertyMappingConfiguration()
 *    ->forProperty('<propertyName>') // this line can be skipped in order to specify the format for all properties
 *    ->setTypeConverterOption(\JWeiland\Events2\Property\TypeConverter\DateTimeImmutableConverter::class, \JWeiland\Events2\Property\TypeConverter\DateTimeConverter::CONFIGURATION_DATE_FORMAT, '<dateFormat>');
 *
 * If the source is of type array, it is possible to override the format in the source::
 *
 *  array(
 *   'date' => '<dateString>',
 *   'dateFormat' => '<dateFormat>'
 *  );
 *
 * By using an array as source you can also override time and timezone of the created DateTime object::
 *
 *  array(
 *   'date' => '<dateString>',
 *   'hour' => '<hour>', // integer
 *   'minute' => '<minute>', // integer
 *   'seconds' => '<seconds>', // integer
 *   'timezone' => '<timezone>', // string, see http://www.php.net/manual/timezones.php
 *  );
 *
 * As an alternative to providing the date as string, you might supply day, month and year as array items each::
 *
 *  array(
 *   'day' => '<day>', // integer
 *   'month' => '<month>', // integer
 *   'year' => '<year>', // integer
 *  );
 */
class DateTimeImmutableConverter extends AbstractTypeConverter
{
    /**
     * @var string
     */
    public const CONFIGURATION_DATE_FORMAT = 'dateFormat';

    /**
     * The default date format is "YYYY-MM-DDT##:##:##+##:##", for example "2005-08-15T15:52:01+00:00"
     * according to the W3C standard @see http://www.w3.org/TR/NOTE-datetime.html
     *
     * @var string
     */
    public const DEFAULT_DATE_FORMAT = \DateTimeInterface::W3C;

    /**
     * @var string[]
     */
    protected $sourceTypes = ['string', 'integer', 'array'];

    /**
     * @var string
     */
    protected $targetType = \DateTimeImmutable::class;

    /**
     * Reduce priority to the lowest possible value. If TYPO3 comes with its own TypoConverter (PRIO: 10)
     * in future the version of TYPO3 has priority.
     *
     * @var int
     */
    protected $priority = 1;

    /**
     * If conversion is possible.
     *
     * @param string|array|int $source
     * @param string $targetType
     * @return bool
     * @internal only to be used within Extbase, not part of TYPO3 Core API.
     */
    public function canConvertFrom($source, string $targetType): bool
    {
        if (!is_callable([$targetType, 'createFromFormat'])) {
            // todo: this check does not make sense as this converter is only called on \DateTime targets
            return false;
        }

        if (is_array($source)) {
            return true;
        }

        if (is_int($source)) {
            return true;
        }

        return is_string($source);
    }

    /**
     * Converts $source to a \DateTime using the configured dateFormat
     *
     * @param string|int|array $source the string to be converted to a \DateTime object
     * @param string $targetType must be "DateTimeImmutable"
     * @param array $convertedChildProperties not used currently
     * @param ?PropertyMappingConfigurationInterface $configuration
     * @return \DateTime|Error|null
     * @throws TypeConverterException|InvalidPropertyMappingConfigurationException
     */
    public function convertFrom($source, string $targetType, array $convertedChildProperties = [], PropertyMappingConfigurationInterface $configuration = null): ?object
    {
        $dateFormat = $this->getDefaultDateFormat($configuration);
        if (is_string($source)) {
            $dateAsString = $source;
        } elseif (is_int($source)) {
            $dateAsString = (string)$source;
        } else {
            if (isset($source['date']) && is_string($source['date'])) {
                $dateAsString = $source['date'];
            } elseif (isset($source['date']) && is_int($source['date'])) {
                $dateAsString = (string)$source['date'];
            } elseif ($this->isDatePartKeysProvided($source)) {
                if ($source['day'] < 1 || $source['month'] < 1 || $source['year'] < 1) {
                    return new Error('Could not convert the given date parts into a DateTime object because one or more parts were 0.', 1333032779);
                }
                $dateAsString = sprintf('%d-%d-%d', $source['year'], $source['month'], $source['day']);
            } else {
                throw new TypeConverterException('Could not convert the given source into a DateTime object because it was not an array with a valid date as a string', 1308003914);
            }
            if (isset($source['dateFormat']) && $source['dateFormat'] !== '') {
                $dateFormat = $source['dateFormat'];
            }
        }
        if ($dateAsString === '') {
            return null;
        }

        if (ctype_digit($dateAsString) && $configuration === null && (!is_array($source) || !isset($source['dateFormat']))) {
            // todo: type converters are never called without a property mapping configuration
            $dateFormat = 'U';
        }
        if (is_array($source) && isset($source['timezone']) && (string)$source['timezone'] !== '') {
            try {
                $timezone = new \DateTimeZone($source['timezone']);
            } catch (\Exception $e) {
                throw new TypeConverterException('The specified timezone "' . $source['timezone'] . '" is invalid.', 1308240974);
            }
            $date = $targetType::createFromFormat($dateFormat, $dateAsString, $timezone);
        } else {
            $date = $targetType::createFromFormat($dateFormat, $dateAsString);
        }
        if ($date === false) {
            return new \TYPO3\CMS\Extbase\Validation\Error('The date "%s" was not recognized (for format "%s").', 1307719788, [$dateAsString, $dateFormat]);
        }
        if (is_array($source)) {
            $date = $this->overrideTimeIfSpecified($date, $source);
        }

        return $date;
    }

    /**
     * Returns whether date information (day, month, year) are present as keys in $source.
     */
    protected function isDatePartKeysProvided(array $source): bool
    {
        return isset($source['day']) && ctype_digit($source['day'])
            && isset($source['month']) && ctype_digit($source['month'])
            && isset($source['year']) && ctype_digit($source['year']);
    }

    /**
     * Determines the default date format to use for the conversion.
     * If no format is specified in the mapping configuration DEFAULT_DATE_FORMAT is used.
     *
     * @throws InvalidPropertyMappingConfigurationException
     */
    protected function getDefaultDateFormat(?PropertyMappingConfigurationInterface $configuration = null): string
    {
        if ($configuration === null) {
            // todo: type converters are never called without a property mapping configuration
            return self::DEFAULT_DATE_FORMAT;
        }
        $dateFormat = $configuration->getConfigurationValue(DateTimeImmutableConverter::class, self::CONFIGURATION_DATE_FORMAT);
        if ($dateFormat === null) {
            return self::DEFAULT_DATE_FORMAT;
        }
        if ($dateFormat !== null && !is_string($dateFormat)) {
            throw new InvalidPropertyMappingConfigurationException('CONFIGURATION_DATE_FORMAT must be of type string, "' . (is_object($dateFormat) ? get_class($dateFormat) : gettype($dateFormat)) . '" given', 1307719569);
        }
        return $dateFormat;
    }

    /**
     * Overrides hour, minute & second of the given date with the values in the $source array
     */
    protected function overrideTimeIfSpecified(\DateTimeImmutable $date, array $source): \DateTimeImmutable
    {
        if (!isset($source['hour']) && !isset($source['minute']) && !isset($source['second'])) {
            return $date;
        }

        $hour = isset($source['hour']) ? (int)$source['hour'] : 0;
        $minute = isset($source['minute']) ? (int)$source['minute'] : 0;
        $second = isset($source['second']) ? (int)$source['second'] : 0;

        return $date->setTime($hour, $minute, $second);
    }
}
